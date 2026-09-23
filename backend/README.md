# Backend

## Local setup

1. Run `composer install` from this directory.
2. Generate JWT keys without committing them:
   `php bin/console lexik:jwt:generate-keypair`.
3. Start PostgreSQL and Redis with `docker compose -f ../docker/docker-compose.yml up -d postgres redis`.
4. Run `php bin/console doctrine:migrations:migrate`.
5. Run `php bin/console app:inventory:prepopulate --backfill`, then
   `php bin/console app:rates:recompute`.

The API uses `POST /v1/auth/login` with JSON fields `email` and `password`.
Users must be provisioned through a trusted administrative process until the
separate registration/onboarding flow is designed.

Mock payment settlement is available at `POST /v1/payments` with body fields
`reservationID` (UUID) and `approved` (boolean). It updates reservation status
from `pending` to `paid` or `rejected`.

Room types are managed via `/v1/hotels/{hotelId}/room-types` (GET list/get are
public; POST/PUT/DELETE require `ROLE_STAFF`).

Customer booking re-validation is available at
`GET /v1/hotels/{hotelId}/room-types/{roomTypeId}/availability` with query
params `startDate`, `endDate`, and optional `roomCount` (default `1`).

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
