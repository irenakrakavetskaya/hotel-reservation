---
name: api-scaffold
description: Use this skill when adding a new REST endpoint under /v1 to the Symfony backend (hotel, room, reservation, or any future resource), or when the user asks to "add an endpoint," "expose a new API," or "add a CRUD route." Ensures every endpoint follows the same layering, auth, validation, and test conventions instead of each one being scaffolded ad hoc.
---

# API Endpoint Scaffold Skill

Use this checklist for every new `/v1/...` endpoint in the Symfony backend. It exists so endpoints stay consistent — don't skip steps because "this one's simple."

## Steps

1. **Route + controller**: thin controller in the owning domain's directory (`src/Domain/<Domain>/Controller`). Controller does: deserialize → validate → call service → serialize. No business logic here.
2. **Request DTO**: define a request object (or Symfony Validator constraints on an array/DTO) rather than pulling raw fields off the `Request` object inline. This is where field-level validation lives (required fields, date formats, UUID format for any client-supplied ID).
3. **Authorization**: decide the role/voter needed (`ROLE_USER`, `ROLE_STAFF`, `ROLE_ADMIN`) per `docs/IMPLEMENTATION_PLAN.md` §2, and apply it with `#[IsGranted(...)]` — never an inline `if ($user->getRole() !== ...)` check in the controller body.
4. **Service call**: business logic lives in a domain service, not the controller. If the endpoint writes to `room_type_inventory` or `reservation`, stop and apply the `reservation-domain` skill instead of writing ad hoc transaction logic.
5. **Response DTO/serializer**: explicit output shape (Symfony Serializer groups or a dedicated response DTO) — don't dump the Doctrine entity directly, to avoid leaking internal fields and to keep the API contract stable if the entity changes.
6. **Caching consideration**: if this is a read endpoint over hotel/room/rate data, check whether it should read through the Redis cache-aside pattern described in `docs/IMPLEMENTATION_PLAN.md` §5 rather than hitting Postgres directly on every request.
7. **Error mapping**: map domain exceptions to HTTP status codes explicitly (404 not found, 409 conflict for inventory/idempotency conflicts, 422 for validation, 403 for authorization) via an exception listener or per-controller catch — don't let framework default error pages leak through for expected error cases.
8. **Test**: add a `WebTestCase` functional test hitting the real route, covering the happy path, one authorization-denied case, and one validation-failure case.
9. **Update the plan doc** (`docs/IMPLEMENTATION_PLAN.md` §2 API surface) if this endpoint wasn't already listed there, so the doc stays the source of truth.

## When NOT to use this skill

For simple read-only static config endpoints with no auth/business logic (rare in this project), a shorter path is fine — but reservation, hotel, and room endpoints should all go through the full checklist.
