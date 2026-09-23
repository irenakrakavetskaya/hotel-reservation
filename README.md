# Hotel Reservation System

A modular hotel reservation platform for browsing hotels and rooms, checking availability, booking stays, processing mock payments, and managing inventory.

## Stack

- Backend: Symfony 8, PHP 8.4, Doctrine ORM
- Database: PostgreSQL 18
- Cache: Redis 7
- Frontend: Next.js 16, React 19, TypeScript
- Proxy: Nginx
- Deployment: Docker Compose and GitLab CI

The backend is organized as a Symfony modular monolith. Domain code lives under `backend/src/Domain/{Hotel,Reservation,Rate,Payment,User}`.

## Run With Docker

Start the existing images and services from the repository root:

```bash
docker compose -f docker/docker-compose.yml up
```

For the first run, or after changing a Dockerfile or dependency manifest, rebuild the images:

```bash
docker compose -f docker/docker-compose.yml up --build
```

Run in the background:

```bash
docker compose -f docker/docker-compose.yml up -d
```

The application is available at:

- Frontend: http://localhost:8090/
- API: http://localhost:8090/v1/
- PostgreSQL: `localhost:5432`
- Redis: `localhost:6379`

The HTTP port can be changed without editing the Compose file:

```bash
HTTP_PORT=8091 docker compose -f docker/docker-compose.yml up
```

## Database Setup

After the backend is running, apply migrations:

```bash
docker compose -f docker/docker-compose.yml exec backend \
  php bin/console doctrine:migrations:migrate --no-interaction
```

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
composer test
composer lint
```

Generate local JWT keys when using the backend outside Docker:

```bash
php bin/console lexik:jwt:generate-keypair
```

Users are provisioned through a trusted administrative process. Authentication uses `POST /v1/auth/login` with `email` and `password`.

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
npm run build
```

## Important Invariants

- `reservationID` is client-supplied, is the reservation primary key, and provides idempotency.
- Inventory and reservation writes share one database transaction.
- The database `check_room_count` constraint enforces the 10% overbooking ceiling.
- Stay dates use the half-open interval `[startDate, endDate)`.
- PostgreSQL is the source of truth; Redis is only a read accelerator.

Read [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) for the data model, API contract, concurrency design, pricing, caching, and testing priorities.

Before changing backend or frontend code, read the relevant rules in `.claude/rules/`. For reservation changes, also read `.claude/skills/reservation-domain/SKILL.md`.
