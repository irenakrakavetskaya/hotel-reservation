# Rules — Backend (Symfony)

Read this before touching anything under `/backend`.

- **Domain-Driven layout.** New code goes in `src/Domain/{Hotel,Reservation,Rate,Payment}/...`. Don't add controllers directly to a flat `src/Controller` — each domain owns its own controllers, entities, repositories, and services.
- **Doctrine entities mirror the schema in `docs/IMPLEMENTATION_PLAN.md` §1 exactly.** If a migration changes a column, update the entity and the plan doc in the same change.
- **Never bypass the `check_room_count` CHECK constraint.** If a migration touches `room_type_inventory`, the constraint must be present in the same migration. Catch `Doctrine\DBAL\Exception\CheckConstraintViolationException` (or the underlying `UniqueConstraintViolationException`/driver exception, whichever Doctrine version surfaces) in the repository and convert it to a domain-level `InsufficientInventoryException` → 409.
- **`reservation.id` is always client-supplied.** Never annotate it `#[ORM\GeneratedValue]`. Validate it's a well-formed UUID at the controller boundary before it reaches the repository.
- **Reservation writes use `INSERT ... ON CONFLICT (id) DO NOTHING` semantics.** In Doctrine, this typically means dropping to a native query / `Connection::executeStatement` for the insert rather than `EntityManager::persist()`, since Doctrine's unit-of-work doesn't natively express upsert-with-ignore. Wrap it in an explicit transaction with the inventory update.
- **Staff-only endpoints use voters**, not inline role checks in controllers (`#[IsGranted('HOTEL_EDIT', subject: 'hotel')]` style). Keep authorization logic testable and out of controllers.
- **No business logic in controllers.** Controllers: deserialize request → call a domain service → serialize response. Validation and orchestration live in services.
- **Money/prices**: store as integer minor units (cents) in the DB, never floats. Convert at the API boundary only if the contract requires decimal strings.
- **Every new endpoint gets a PHPUnit functional test** hitting the actual route (WebTestCase), not just a unit test of the service.
- **Static analysis**: code must pass `phpstan` at the project's configured level before being considered done — don't suppress errors with `@phpstan-ignore` unless there's a comment explaining why.
