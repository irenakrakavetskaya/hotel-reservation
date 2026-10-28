# Implementation Plan — Hotel Reservation System

## 0. Foundational decision: monolith-first

Symfony modular monolith with clear domain boundaries (`Hotel`, `Rate`, `Reservation`, `Payment`), not five microservices, for v1. Reasons:

- Reservation + Inventory must share a transaction/DB anyway (see Concurrency, below) — splitting them into separate services buys nothing and adds network hops to the hottest path.
- Hotel/Rate data is read-heavy and cacheable regardless of whether it's a separate deployable.
- You can extract a service later (e.g., Payment, once a real processor is integrated) once the domain boundary has proven stable.

Module boundaries are enforced by directory structure + Symfony service visibility, not network calls, until there's a concrete reason (independent scaling, independent deploy cadence, separate team ownership) to split one out.

## 1. Data model

### `hotel`
`id, name, address, city, country, star_rating, description, amenities (jsonb), created_at, updated_at`

### `app_user`
`id, email, roles (jsonb), password` — identity store for API authentication.
`roles` always receives `ROLE_USER` at runtime. Public registration only ever
creates `ROLE_USER`; `ROLE_STAFF` / `ROLE_ADMIN` are granted by an admin through
the user-management API (§2) or bootstrapped with
`php bin/console app:user:promote <email> --role=ROLE_ADMIN|ROLE_STAFF`.
Assignable roles are limited to `ROLE_STAFF` and `ROLE_ADMIN`
(`User::ASSIGNABLE_ROLES`). No migration was needed: `roles` was already jsonb.

### `room_type`
`id, hotel_id (FK), name, description, max_occupancy, base_price, amenities (jsonb)`

### `room`
`id, hotel_id (FK), room_type_id (FK), room_number, floor, status (active/maintenance/inactive)`
Individual physical rooms exist for admin/maintenance purposes; **reservations are against room *types*, not specific physical rooms**, per the API design (`roomTypeID`, `roomCount`). Physical-room assignment (if needed at check-in) is a separate, later concern.

### `room_type_rate`
`hotel_id, room_type_id, date, price` — composite PK `(hotel_id, room_type_id, date)`. Populated by the pricing job (§4).

### `room_type_inventory`
`hotel_id, room_type_id, date, total_inventory, total_reserved` — composite PK `(hotel_id, room_type_id, date)`.

```sql
CREATE TABLE room_type_inventory (
    hotel_id BIGINT NOT NULL,
    room_type_id BIGINT NOT NULL,
    date DATE NOT NULL,
    total_inventory INT NOT NULL,
    total_reserved INT NOT NULL DEFAULT 0,
    PRIMARY KEY (hotel_id, room_type_id, date),
    CONSTRAINT check_room_count CHECK (total_reserved <= (total_inventory * 1.1)::int)
);
```

Pre-populated 2 years out by a scheduled job (Symfony Messenger + cron, or a `symfony/scheduler` recurring message); extended daily as dates roll forward.

### `reservation`
`id (= client-supplied reservationID, UUID), user_id, hotel_id, room_type_id, start_date, end_date, room_count, status, total_price, created_at, updated_at`
`id` is the **primary key and the idempotency key** — no separate idempotency table needed.

`status` enum: `pending, paid, refunded, canceled, rejected`.

## 2. API surface

Matches the spec given, implemented as Symfony controllers under `/v1`:

- `POST /v1/auth/login` — returns a JWT for a registered user.
- `POST /v1/auth/register` — public sign-up (`email`, `password`,
  `passwordConfirmation`); always creates `ROLE_USER`.
- `GET /v1/admin/users?search&limit&offset` — admin-only user listing.
- `PUT /v1/admin/users/{id}/roles` — admin-only; body `{"roles": ["ROLE_STAFF"]}`.
  Guarded by `UserManagementVoter` (`USER_MANAGE`, requires `ROLE_ADMIN`).
  Demoting the last admin returns 409 (`RoleChangeNotAllowedException`); the
  admin count is read with a row lock so concurrent demotions cannot leave zero
  admins. Unknown user returns 404.
- `GET/POST/PUT/DELETE /v1/hotels[/{id}]` — staff-only for write.
- `GET/POST/PUT/DELETE /v1/hotels/{hotelId}/room-types[/{id}]` — staff-only for write.
- `GET/POST/PUT/DELETE /v1/hotels/{hotelId}/rooms[/{id}]` — staff-only for write.
- `GET /v1/hotels/{hotelId}/room-types/{roomTypeId}/availability?startDate=...&endDate=...&roomCount=...` —
  customer booking read-model combining inventory + precomputed per-date rates.
- `GET /v1/reservations` — current user's history.
- `GET /v1/reservations/{id}`
- `POST /v1/reservations` — body includes client-supplied `reservationID`.
- `DELETE /v1/reservations/{id}` — cancel; releases inventory.
- `POST /v1/payments` — mock v1 payment settlement for a reservation:
  `approved=true` transitions `pending -> paid`, `approved=false` transitions
  `pending -> rejected`.

Auth: Symfony Security with role-based voters (`ROLE_USER`, `ROLE_STAFF`, `ROLE_ADMIN`). JWT (LexikJWTAuthenticationBundle) for the API, since Next.js is a separate origin.

`security.yaml` defines `role_hierarchy: ROLE_ADMIN: ROLE_STAFF`, so admins pass
every staff check. The JWT payload is `{iat, exp, roles, username}` with a 1h
TTL, and `User::getRoles()` returns the stored roles plus `ROLE_USER` (no
hierarchy expansion), so the frontend treats `ROLE_ADMIN` as staff itself.
**Role changes take effect at the user's next login**; the backend still
re-loads the user from the DB on each request.

Domain exceptions are mapped to HTTP codes in `DomainExceptionListener`
(e.g. `UserNotFoundException` → 404, `RoleChangeNotAllowedException` → 409).

## 3. Concurrency & idempotency (the core of the system)

**Double-click / retry protection:** `reservation.id` is client-generated (UUID v4 or v7) and is the primary key. `POST /v1/reservations` does an `INSERT ... ON CONFLICT (id) DO NOTHING RETURNING *`; if zero rows return, fetch and return the existing reservation instead of erroring. This makes the endpoint safely retryable.

**Concurrent booking protection (the last-room race):** Two layers, not one:

1. **Database CHECK constraint** (`check_room_count`, above) is the actual safety net — it is impossible to violate the 110% ceiling no matter what the application does or doesn't check first.
2. **Transaction pattern** for the write path:
   ```sql
   BEGIN;
   INSERT INTO reservation (...) VALUES (...)
   ON CONFLICT (id) DO NOTHING;
   -- Stop here and return the existing row when the insert affected no rows.
   UPDATE room_type_inventory
   SET total_reserved = total_reserved + :roomCount
   WHERE hotel_id = :hotelId AND room_type_id = :roomTypeId
     AND date >= :startDate AND date < :endDate;
   -- if the CHECK constraint is violated on any row, Postgres raises and the whole
   -- transaction rolls back automatically
   COMMIT;
   ```
   The insert precedes the inventory update so a retry that hits the
   idempotency conflict cannot increment inventory twice.
   Catch the constraint-violation exception in the Symfony repository layer and translate it to a 409 Conflict ("not enough inventory") rather than a 500.
3. Do **not** rely on `SELECT ... FOR UPDATE` as the primary mechanism — it works but serializes all bookings for a given date range and is harder to reason about under Doctrine's unit-of-work. Use the constraint as the guarantee and treat any explicit locking as an optional throughput optimization if profiling shows contention.

All stay date ranges are half-open (`[start_date, end_date)`), so the checkout
date is not held or billed. The transaction must reject a partial inventory
window; an update affecting fewer rows than the number of stay nights rolls
back together with the reservation insert.

**Cancellation** decrements `total_reserved` in the same transactional pattern and sets `reservation.status = 'canceled'`.

## 4. Dynamic pricing

- `PriceCalculator` service: given `(hotelId, roomTypeId, date)`, looks up projected occupancy (`total_reserved / total_inventory` for that date) and applies a pricing curve to `room_type.base_price` (e.g., linear or tiered multiplier — start simple, tune later).
- A daily scheduled job recomputes and upserts `room_type_rate` for the rolling 2-year window as occupancy shifts.
- `GET /v1/hotels/{id}/rooms/{id}` (and the room-search query) reads from `room_type_rate`, not from a live calculation, so page loads stay fast.
- Cache `room_type_rate` reads in Redis (`rate:{hotelId}:{roomTypeId}:{date}`), invalidated by the recompute job.

## 5. Caching layer (Redis)

- **Inventory cache**: `key = hotelID_roomTypeID_date → available_rooms` (i.e., `total_inventory*1.1 - total_reserved`, floored). Used for the read-heavy "is this bookable" check on the room/booking pages.
- **Update strategy**: application-level, synchronous-after-commit. After a reservation transaction commits, update the cache in the same request (or via a Symfony Messenger async message if you want the request to return faster — acceptable since Postgres remains the source of truth and a brief cache staleness window only affects the optimistic "shown available count," never the actual booking guarantee, which the DB constraint enforces regardless).
- **Hotel/room static data**: cache-aside, longer TTL (minutes), invalidated on admin writes.
- Do not implement CDC/Debezium for v1 — it's the right answer at scale but is unjustified complexity before there's evidence the app-level cache update is a bottleneck.

## 6. Frontend (Next.js 16)

- Hotel detail page and room detail page: Server Components, fetch from `GET /v1/hotels/{id}` and room-availability query — these are the two read-heavy, cacheable pages, so lean on Next's data cache / ISR.
- Booking page: Client Component (form state — dates, guest count, payment fields) that calls the reservation-service query endpoint to re-validate availability before showing "Book," then `POST /v1/reservations` with a UUID generated client-side as `reservationID` on submit.
- Admin panel (implemented): `/admin/...`, see §6.1.
- State/data fetching: React Query (or Next's built-in fetch caching) for the booking flow; keep the "book" button disabled during the in-flight request to reduce (not eliminate — the idempotency key is what actually prevents double-booking) accidental double-clicks.

### 6.1 Admin panel (implemented)

Routes under `frontend/app/admin/`:

| Route | Access | Purpose |
|---|---|---|
| `/admin` | staff, admin | dashboard / entry points |
| `/admin/hotels`, `/new`, `/[hotelId]/edit` | staff, admin | hotel CRUD |
| `/admin/hotels/[hotelId]/room-types` (+ `/new`, `/[id]/edit`) | staff, admin | room type CRUD |
| `/admin/hotels/[hotelId]/rooms` (+ `/new`, `/[id]/edit`) | staff, admin | physical room CRUD |
| `/admin/users` | admin only | search users, toggle `ROLE_STAFF` / `ROLE_ADMIN` |

Design decisions:

- **Server-side guard.** `app/admin/layout.tsx` decodes the `access_token`
  cookie (`lib/session.ts`: `decodeSession`, `isStaff`, `isAdmin`) and redirects
  anonymous visitors to `/login?next=/admin` (`lib/safe-redirect.ts` rejects
  open redirects) or returns 404 for signed-in users without the role. The
  decode does **not** verify the signature; it only gates UI. The backend
  voters/role checks are the real authorization.
- **Nav.** `components/AuthNav.tsx` (server component) shows an "Admin" link for
  staff and admins.
- **Reads** happen in server components via `lib/admin-data.ts` with
  `cache: 'no-store'`.
- **Writes** go from the browser (`lib/admin-client.ts`) to a same-origin proxy,
  `app/api/admin/[...path]/route.ts`. It whitelists paths by regex, allows only
  POST/PUT/DELETE, attaches the Bearer token from the HttpOnly cookie (the
  token is never exposed to client JS) and calls `revalidatePath('/hotels',
  'layout')` on success so the `revalidate: 60` public pages refresh.
- **Forms.** `components/admin/AdminForm.tsx` is driven by a `resource` key
  (`hotel | roomType | room`) with field definitions and mappers in
  `lib/admin-forms.ts` (prices entered in dollars, sent as integer cents).
  Backend 422 `violations` are mapped to field errors; 401/403 get friendly
  messages. `DeleteButton` and `RoleToggles` cover delete and role changes.
- **Auth pages.** Login/register do a full navigation after success
  (`lib/navigation.ts` `hardNavigate`) so the server-rendered nav picks up the
  new cookie; the login page honours `?next=`.

Tests: `frontend/tests/` covers session decoding, safe redirect, form mappers,
the proxy route, the admin layout guard, `AuthNav`, and the admin components
(125 tests in total). Backend: `UserAdminControllerTest` (8 tests) covers
auth, voter, last-admin protection and validation.

## 7. Deployment

- `docker/Dockerfile.backend` — multi-stage: composer install → PHP-FPM + Nginx (or FrankenPHP) runtime.
- `docker/Dockerfile.frontend` — multi-stage: `npm ci && npm run build` → `next start` (or standalone output) runtime image.
- `docker-compose.yml` — `backend`, `frontend`, `scheduler`, `postgres`, `redis`, `nginx` (reverse proxy on `:8090`), for local dev.
  - The `frontend` image is a build, not source-mounted: rebuild with
    `docker compose up -d --build frontend` after frontend changes. The backend
    mounts its source.
  - `docker/nginx/default.conf` routes `/v1/*` and `/_profiler|_wdt` to
    PHP-FPM and everything else to Next.js. It uses Docker's DNS resolver with
    upstreams held in variables, so recreating `backend`/`frontend` does not
    leave nginx pointing at a stale IP (previously a 502).
- Git hooks in `.githooks/`: `pre-commit` runs `composer lint` and frontend
  lint/typecheck; `pre-push` runs `composer test` and `npm test`. Both load nvm
  when `npm` is not on PATH (git GUIs skip the login shell). PHP files use LF
  endings (`.gitattributes`), which PER-CS requires.
- `.gitlab-ci.yml` stages: `lint → test → build → deploy`.
  - `lint`: php-cs-fixer + phpstan (backend), eslint + tsc (frontend).
  - `test`: PHPUnit (with a throwaway Postgres service container) + frontend unit tests.
  - `build`: build & push both Docker images to the GitLab registry, tagged with commit SHA.
  - `deploy`: environment-gated (manual for prod), pulls tagged images.

## 8. Build order (suggested milestones)

**Status:** steps 1–9 are done: the reservation write path, concurrency tests,
hotel/room/room-type CRUD, rates, Redis caching, the customer frontend,
cancellation and mock payments. Step 8 (admin panel) is now implemented as
described in §6.1, together with registration and role management. Step 10 is
in place for local Docker and git hooks. Remaining ideas: a real payment
processor, physical-room assignment at check-in, and token refresh so role
changes apply without re-login.

1. Symfony skeleton + Doctrine entities + migrations for all tables above (no business logic yet).
2. `room_type_inventory` pre-population job + the two-year rolling window job.
3. Reservation write path end-to-end (idempotent insert + constraint-protected inventory update) with tests specifically simulating the concurrent-last-room race (two parallel requests in a test).
4. Hotel/Room CRUD + staff auth.
5. Rate calculation job + `room_type_rate` read path.
6. Redis caching layer for inventory reads and hotel/room static data.
7. Next.js hotel/room/booking pages against the real API.
8. Admin panel.
9. Cancellation flow + inventory release.
10. Docker + CI pipeline (can be stood up in parallel with step 1, iterated throughout — don't leave it to the end).

## 9. Testing priorities

- **Concurrency test is the highest-value test in the whole system**: spin up N parallel `POST /v1/reservations` requests against a room type with `total_inventory=1` (or few) and assert exactly `total_inventory * 1.1` (rounded down) succeed, the rest get 409s, and the DB constraint is never violated even if the application logic has a bug.
- Idempotency test: same `reservationID` submitted twice (sequentially and concurrently) → exactly one reservation row, same response both times.
- Overbooking boundary test: exactly at 110% succeeds, one more fails.
- Admin/role tests: only admins can list users and change roles, the last admin
  cannot be demoted, plain users get 403/404 on `/admin` and the proxy.

Run backend tests against the **test** database only (they truncate tables):
`APP_ENV=test` with `DATABASE_URL` pointing at `hotel_reservations_test`. If
tests run in the container, remove the www-data-owned `var/cache/test`
afterwards, or host-side `composer test` (used by the pre-push hook) fails.
