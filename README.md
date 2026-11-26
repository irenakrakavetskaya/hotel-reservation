# Hotel Reservation System

A modular hotel reservation platform for browsing hotels and rooms, checking availability, booking stays, processing mock payments, and managing inventory.

## Stack

- Backend: Symfony 8, PHP 8.4, Doctrine ORM
- Database: PostgreSQL 18
- Cache: Redis 7
- Frontend: Next.js 16, React 19, TypeScript
- Proxy: Nginx
- Reports: RabbitMQ and a Go PDF worker
- Report notifications: Centrifugo WebSockets
- Deployment: Docker Compose and GitLab CI

The backend is organized as a Symfony modular monolith. Domain code lives under
`backend/src/Domain/{Hotel,Reservation,Rate,Payment,User,Report}`.

## GitLab CI

The pipeline in [`.gitlab-ci.yml`](./.gitlab-ci.yml) runs `lint → test → build → deploy`.
Merge requests get their own pipelines; duplicate branch pipelines are
suppressed for pushes when an open merge request already exists. On other
branches and merge requests, backend and frontend lint/test jobs are selected
by changed paths; both areas are checked on the default branch and tags.

- Backend checks use PHP 8.4. PHPUnit runs against the PostgreSQL 18 service
  and the dedicated `hotel_reservations_test` database.
- Frontend lint and tests use Node 22. The frontend Docker image also defaults
  to Node 22.
- Docker images are built and pushed only on the default branch and tags. They
  are tagged with the short commit SHA or Git tag; `latest` is updated only on
  default-branch builds.
- Staging and production deployment jobs are manual placeholders. They do not
  deploy to a server; if run, they fail with a clear not-configured message
  and do not block the rest of the pipeline.

Before real deployment is enabled, choose and provision a deployment target,
set its real environment URLs, and replace both placeholder scripts with the
target-specific deployment mechanism. Configure deployment credentials as
protected GitLab CI/CD variables (prefer environment-scoped variables and file
variables where appropriate), not in source or in the images. The deployment
must provide the runtime settings listed in `.gitlab-ci.yml`, pull the
commit/tag-specific images, and define database migration, health-check, and
rollback procedures. Keep production manual-gated until a rollback approach
has been agreed.

## Run With Docker

For the first run, build and start the services from the repository root:

```bash
docker compose -f docker/docker-compose.yml up -d --build
```

On subsequent runs, start the existing images with:

```bash
docker compose -f docker/docker-compose.yml up -d
```

After a deployment or changes to a Dockerfile or dependency manifest, rebuild:

```bash
docker compose -f docker/docker-compose.yml up -d --build
```

The one-shot `migrate` service waits for PostgreSQL, applies pending migrations
and provisions the read-only report database role before the app and workers
start. Each `up` runs the service again; Doctrine applies only new migrations.
An error stops dependent services from starting. Existing running services are
not stopped while migrations run, so deployments must use migrations compatible
with the currently running application.

The application is available at:

- Frontend: http://localhost:8090/
- API: http://localhost:8090/v1/
- PostgreSQL: `localhost:5432`
- Redis: `localhost:6379`

The HTTP port can be changed without editing the Compose file:

```bash
HTTP_PORT=8091 docker compose -f docker/docker-compose.yml up
```

For local HTTP development, keep the auth cookie non-secure:

```bash
AUTH_COOKIE_SECURE=false docker compose -f docker/docker-compose.yml up
```

Set `AUTH_COOKIE_SECURE=true` when serving the frontend over HTTPS.

## Logs

Compose automatically copies service output to `logs/compose-<project>.log`
(`logs/compose-docker.log` by default). The file is Git-ignored and readable
by the owner of the `logs/` directory. It includes backend, frontend, report
worker, scheduler, broker, database, proxy and other Compose service output;
browser console messages and Docker daemon logs are not included.

Logs rotate at 10 MiB by default, keeping three numbered backups. Set
`LOG_MAX_BYTES` to a positive byte limit to change the rotation threshold.
A single oversized line can exceed the limit. On collector restart, Docker's
retained service logs are replayed, so some lines can appear more than once.
Logs may contain sensitive data; restrict access and do not commit or share them.

The collector mounts the Docker socket. This grants it broad control over Docker
and potentially the host, even though the socket mount is read-only. Use this
development setup only on a trusted host. Existing `docker compose logs` output
remains available; inspect collector failures with:

```bash
docker compose -f docker/docker-compose.yml logs log-collector
```

## Database Setup

Compose applies migrations and runs the report-reader seed automatically.
The seed updates the role's password from `REPORT_DB_PASSWORD` and grants
`SELECT` on `public.reservation`. Set `REPORT_DB_USER` and
`REPORT_DB_PASSWORD` consistently for the migrate service and report worker;
rerun `docker compose up -d` after changing them. The database provisioning
account needs permission to create or alter the reader role and grant access.

Optional data jobs:

```bash
docker compose -f docker/docker-compose.yml exec backend \
  php bin/console app:inventory:prepopulate --backfill

docker compose -f docker/docker-compose.yml exec backend \
  php bin/console app:rates:recompute
```

Seed deterministic demo data (safe to run repeatedly):

```bash
docker compose -f docker/docker-compose.yml exec backend \
  php bin/console app:demo:seed
```

The seed adds demo users, hotels, rooms, inventory, rates, and four reservations
without deleting existing records. It requires the Redis extension; use the
container if the host PHP runtime does not have it.

Demo credentials:

- Customer: `demo@example.com` / `demo-password`
- Staff: `staff@example.com` / `staff-password`

Generate JWT keys before testing login:

```bash
docker compose -f docker/docker-compose.yml exec backend \
  php bin/console lexik:jwt:generate-keypair
```

Stop the stack with:

```bash
docker compose -f docker/docker-compose.yml down
```

## Backend Development

From `backend/`:

```bash
composer install
composer lint
```

Generate JWT keys and run `php bin/console doctrine:migrations:migrate` when
setting up the backend outside Docker. Backend integration tests truncate
tables and must use a dedicated `*_test` database; run `composer test` only
with `DATABASE_URL` pointing to that database. The Docker test command is
documented in [AGENTS.md](AGENTS.md).

Git hooks live in `.githooks/`. Enable them once per clone:

```bash
git config core.hooksPath .githooks
```

`pre-commit` runs `composer lint` when backend files are staged and
`npm run lint` + `npm run typecheck` when frontend files are staged; `pre-push` runs
`composer test` (needs the `hotel_reservations_test` database) and `npm test`.
Bypass with `--no-verify` when necessary.

## API and Authentication

Authentication uses `POST /v1/auth/login` with `email` and `password`.
Public registration is available through
`POST /v1/auth/register` with `email`, `password`, and
`passwordConfirmation`. Passwords must be 8–255 characters. Registration
always creates `ROLE_USER`; staff and admin roles are provisioned by trusted
administrators. Login is limited to five failed attempts per minute per
username and IP; registration is limited to ten requests per 15 minutes per client IP.
Registration returns 429 with `Retry-After` when limited. Both limiters use
the app cache (Redis).

`ROLE_ADMIN` inherits `ROLE_STAFF`. Admins can manage roles using
`GET /v1/admin/users?search&limit&offset` and
`PUT /v1/admin/users/{id}/roles` with `{"roles":["ROLE_STAFF"]}`. The last
admin cannot be demoted (409). Bootstrap the first admin with
`php bin/console app:user:promote <email> --role=ROLE_ADMIN`. The frontend
reflects role changes at the next login because its JWT contains roles; the
API reloads current roles from the database for each request.

Room types are available at `/v1/hotels/{hotelId}/room-types`: reads are
public, while writes require staff access. Public availability checks use
`GET /v1/hotels/{hotelId}/room-types/{roomTypeId}/availability` with
`startDate`, `endDate`, and optional `roomCount` (default `1`).

Reservation creation, history, payment and cancellation require authentication.
The client supplies `reservationID` when creating a reservation and reuses it
for retries. History is `GET /v1/reservations`, mock payment is
`POST /v1/payments` with `reservationID` and boolean `approved`, and
cancellation is `DELETE /v1/reservations/{id}`. Payment transitions pending
reservations to paid or rejected.

Redis provides cache-aside reads for hotels, room types, rooms, availability,
rates, and inventory. Writes invalidate or refresh affected cached data after
successful database writes.

## Reservation Reports

Staff and admins can generate PDF summaries at `/admin/reports`. Choose an
inclusive UTC date range of at most one year; the end date cannot be in the
future. The report filters by reservation creation date, counts current
reservation statuses, and totals the current `paid` reservations in USD. It
does not represent historical payment or revenue figures.

The staff-only API provides:

- `POST /v1/admin/reports` with `{"from":"YYYY-MM-DD","to":"YYYY-MM-DD"}`
  to request a report.
- `GET /v1/admin/reports` to list the latest 50 reports and
  `GET /v1/admin/reports/{id}` to fetch one report's status.
- `GET /v1/admin/reports/{id}/download` to download a completed PDF; an
  unfinished report returns 409.
- `GET /v1/admin/realtime/token` to obtain a short-lived connection token.

Report creation records an outbox entry with the job. The scheduler publishes
pending entries to RabbitMQ every 10 seconds, the Go worker generates the PDF,
and the Symfony results consumer updates the report status (`pending`,
`processing`, `done`, or `failed`). PDFs are stored in the shared
`reports_data` volume, mounted read-only by the API. The worker writes files
with group-read access for PHP-FPM's `www-data` group ID 82; other deployments
must grant the API equivalent access.

The Compose stack supplies development defaults for `RABBITMQ_USER`,
`RABBITMQ_PASSWORD`, `REPORT_DB_USER`, and `REPORT_DB_PASSWORD`; configure
them for deployment. RabbitMQ's AMQP port is bound to localhost by default.
See [Database Setup](#database-setup) for automatic report-reader provisioning.

After committed report changes, Symfony sends best-effort invalidations to
Centrifugo. Staff browsers receive them over WebSockets and fetch authoritative
report state over HTTP; the page also reconciles every 30 seconds and on
reconnection. The separate connection token expires after two minutes.
Existing WebSocket tokens remain usable until expiry after logout or a role
change; token refresh checks current API authorization. Immediate revocation
requires an additional disconnect/revocation mechanism.

The local Centrifugo allowed origin follows `HTTP_PORT`. If using another
hostname or HTTPS, set `CENTRIFUGO_ALLOWED_ORIGINS` to the exact public browser
origin. For deployment, configure distinct, strong `CENTRIFUGO_API_KEY` and
`CENTRIFUGO_TOKEN_SECRET` values; the defaults are for development only.
Centrifugo's HTTP API is private to the Compose network; Nginx exposes only
its WebSocket route.

Run the Go worker tests from `report-worker/` with `go test ./...`.

## Frontend Development

The frontend is a minimal Next.js application and can be run directly from `frontend/`:

```bash
npm install
npm run dev
```

Useful checks:

```bash
npm run lint
npm run typecheck
npm test        # Vitest + React Testing Library (jsdom)
npm run build
```

Tests live in `frontend/tests/`, mirroring the source tree (`*.test.ts(x)`); `npm run test:watch` re-runs them on change.

The frontend includes these user flows:

- `/hotels` and hotel/room-type detail pages
- `/hotels/{hotelId}/room-types/{roomTypeId}/availability` for public availability checks
- `/register` and `/login`
- `/reservations` for authenticated history, payment, and cancellation states
- `/admin` for staff management and `/admin/reports` for PDF reports

JWTs are never stored in browser local storage. Next.js route handlers keep the
JWT in an HttpOnly, SameSite=Lax cookie and redirect successful auth to
`/reservations`.

## Important Invariants

- `reservationID` is client-supplied, is the reservation primary key, and provides idempotency.
- Inventory and reservation writes share one database transaction.
- The database `check_room_count` constraint enforces the 10% overbooking ceiling.
- Stay dates use the half-open interval `[startDate, endDate)`.
- PostgreSQL is the source of truth; Redis is only a read accelerator.
- Cancellation rejects a partial inventory release and rolls back the transaction.

Read [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) for the data model, API contract, concurrency design, pricing, caching, and testing priorities.

Before changing backend or frontend code, read the relevant rules in `.claude/rules/`. For reservation changes, also read `.claude/skills/reservation-domain/SKILL.md`.
