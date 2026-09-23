# Hotel Reservation System — Project Guide

This file is the shared, always-on guide for coding agents in this repository. Keep it accurate; update it when architecture decisions or verified commands change.

## What this is

A multi-hotel chain reservation platform: customers browse hotels/rooms, book rooms, and cancel; staff manage hotel/room inventory. Core hard problems: **no double-booking under concurrency**, **10% overbooking allowance**, **date-based dynamic pricing**, **idempotent reservation writes**.

## Stack

| Layer | Tech |
|---|---|
| Backend API | Symfony 8+ (PHP) |
| Database | PostgreSQL 18+ |
| Cache / inventory fast-path | Redis |
| Frontend | Next.js 16 |
| Deployment | Docker + `.gitlab-ci.yml` |

## Services (logical, may start as modules in one Symfony app — see `docs/IMPLEMENTATION_PLAN.md` Phase 0 for the monolith-first decision)

- **Hotel Service** — hotel/room CRUD, static-ish data, cacheable.
- **Rate Service** — per-room-type, per-date pricing based on projected occupancy.
- **Reservation Service** — reservation writes, inventory tracking, cancellations. Owns `room_type_inventory` and `reservation` tables in the same DB (hybrid approach — do not split these into separate databases without a strong reason; cross-DB transactions defeat the constraint-based concurrency guarantee).
- **Payment Service** — stub/mock for v1 unless a real processor is specified; must move reservation `status` between `pending → paid | rejected`.
- **Hotel Management Service** — staff-only endpoints layered over Hotel + Reservation services with role checks.

## Non-negotiable invariants

1. **`reservationID` is the idempotency key and the reservation table's primary key.** Never generate it server-side from an auto-increment; it arrives from the client (or a prior "create draft reservation" call) so retries are safe.
2. **Overbooking ceiling is enforced in the database**, not just application code:
   `CHECK (total_reserved <= total_inventory * 1.1)` (or the equivalent generated-column form Postgres 18 prefers — see `docs/IMPLEMENTATION_PLAN.md` §Data Model). Application-level checks are a fast-fail UX nicety; the constraint is the actual guarantee.
3. **Inventory writes are row-locked or constraint-protected, never read-then-write-without-protection.** Use `SELECT ... FOR UPDATE` on the date range or rely purely on the CHECK constraint + retry-on-violation. Pick one strategy per `docs/IMPLEMENTATION_PLAN.md` §Concurrency and don't mix both without reason.
4. **Redis inventory cache is a read accelerator, Postgres is the source of truth.** Cache invalidation/update happens after the DB commit succeeds, never before.
5. **Dynamic pricing is computed from the Rate Service, cached per (hotel, room type, date), and recomputed on a schedule** — not on every request.

## Repo layout

```
/backend            Symfony app
  /src/Domain/{Hotel,Reservation,Rate,Payment}
  /src/Infrastructure
  /migrations
/frontend            Next.js app stub; implementation is not scaffolded yet
/docker              Compose and backend/frontend Dockerfiles
  docker-compose.yml
  Dockerfile.backend
  Dockerfile.frontend
/.gitlab-ci.yml
/docs
  IMPLEMENTATION_PLAN.md
```

## Verified commands

- Backend setup: from `backend/`, run `composer install`, generate JWT keys with
  `php bin/console lexik:jwt:generate-keypair`, then run migrations with
  `php bin/console doctrine:migrations:migrate`.
- Backend data jobs: `php bin/console app:inventory:prepopulate --backfill` and
  `php bin/console app:rates:recompute`.
- Backend tests: from `backend/`, run `composer test` or `vendor/bin/phpunit`.
- Backend lint and static analysis: from `backend/`, run `composer lint`.
- Full local stack: from `docker/`, run `docker compose up`.
- Frontend CI commands (`npm ci`, `npm run lint`, `npx tsc --noEmit`, and
  `npm test -- --ci`) are declared in `.gitlab-ci.yml`, but `frontend/` is
  currently only a Docker build stub.

For local backend prerequisites and API notes, read [backend/README.md](backend/README.md).
For the data model, API contract, concurrency design, and testing priorities, read
[docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md).

## Agent workflow

- Before backend changes, read `.claude/rules/backend-symfony.md`; before frontend
  changes, read `.claude/rules/frontend-nextjs.md`.
- For reservation or inventory changes, also read `.claude/skills/reservation-domain/SKILL.md`.
- For a new `/v1` endpoint, read `.claude/skills/api-scaffold/SKILL.md`.
- Read `.claude/rules/git-and-ci.md` before changing migrations, reservation writes,
  or CI configuration.
- Keep controllers thin and preserve domain ownership under
  `backend/src/Domain/{Hotel,Reservation,Rate,Payment,User}`.
- Ask before adding a dependency to `backend/composer.json` or the future frontend
  `package.json`.

## When working in this repo, Claude should

- Never weaken the overbooking CHECK constraint or the reservation-ID-as-PK idempotency pattern to "make a test pass."
- Preserve one-way migration history for shared database constraints; add a new
  migration instead of editing an applied migration.
- Keep changes focused and validate with the narrowest relevant command before
  widening the test scope.
