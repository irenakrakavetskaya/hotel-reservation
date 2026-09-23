# Backend

## Local setup

1. Run `composer install` from this directory.
2. Generate JWT keys without committing them:
   `php bin/console lexik:jwt:generate-keypair`.
3. Start PostgreSQL and Redis with `docker compose -f ../docker/docker-compose.yml up -d postgres redis`.
4. Run `php bin/console doctrine:migrations:migrate`.
5. Run `php bin/console app:inventory:prepopulate --backfill`, then
   `php bin/console app:rates:recompute`.

For deterministic local data, run `php bin/console app:demo:seed`. This creates
demo customer and staff users, hotels, room types, rooms, inventory, and rates.
The command is idempotent and does not delete existing records. Demo credentials
are `demo@example.com` / `demo-password` and `staff@example.com` /
`staff-password`.

The API uses `POST /v1/auth/login` with JSON fields `email` and `password`.
Public registration is available at `POST /v1/auth/register` with JSON fields
`email`, `password`, and `passwordConfirmation`. Passwords must be 8-255
characters, and registration always assigns `ROLE_USER`. Staff/admin accounts
remain trusted-provisioned.

The Next.js frontend stores the returned JWT in an HttpOnly, SameSite=Lax
cookie. Set `AUTH_COOKIE_SECURE=false` for local HTTP and `true` for HTTPS.

Mock payment settlement is available at `POST /v1/payments` with body fields
`reservationID` (UUID) and `approved` (boolean). It updates reservation status
from `pending` to `paid` or `rejected`.

Room types are managed via `/v1/hotels/{hotelId}/room-types` (GET list/get are
public; POST/PUT/DELETE require `ROLE_STAFF`).

Customer booking re-validation is available at
`GET /v1/hotels/{hotelId}/room-types/{roomTypeId}/availability` with query
params `startDate`, `endDate`, and optional `roomCount` (default `1`). This
availability read is public; creating, paying for, and canceling reservations
still requires an authenticated JWT user.

The availability endpoint itself is public, so anonymous users can check dates
and prices. Reservation creation uses the client-supplied `reservationID` UUID
and requires an authenticated user. The frontend preserves that UUID across
retries. Reservation history is available at `GET /v1/reservations`; payment
settlement uses `POST /v1/payments`; cancellation uses
`DELETE /v1/reservations/{id}`.

Redis-backed cache-aside is enabled for hotel/room/room-type GET endpoints,
availability reads, per-date rates, and per-date inventory availability.
Admin writes and reservation/rate/inventory write paths bump cache versions or
refresh per-day keys after successful DB writes.

## Reservation invariant

Stays and all inventory/rate queries use the half-open interval
`[startDate, endDate)`: the checkout date is not a booked night. Reservation
creation is idempotent by client-supplied UUID, updates inventory only after a
new reservation insert, and relies on `check_room_count` for the overbooking
guarantee. Cancellation validates that inventory release touched the full stay
window; if not, the transaction rolls back and returns a conflict.
