---
name: reservation-domain
description: Use this skill whenever writing, reviewing, or modifying code in the Reservation domain of the hotel reservation system — anything touching room_type_inventory, the reservation table, the POST/DELETE /v1/reservations endpoints, overbooking limits, or double-booking/idempotency concerns. Also use it when the user asks to "add a reservation feature," "fix a booking bug," "change the overbooking percentage," or reviews a PR touching inventory or booking logic. This skill encodes the concurrency-safety pattern this project depends on — always check it before touching this code, even for what looks like a small change.
---

# Reservation Domain Skill

This project's core correctness guarantee — no double-booking, bounded overbooking — depends on getting three things right together. Do not implement or modify reservation/inventory code without applying all three.

## The three-part pattern

1. **Idempotency key = primary key.** `reservation.id` is a client-supplied UUID, never server-generated. Every write path is `INSERT ... ON CONFLICT (id) DO NOTHING`, and a conflict means "return the existing reservation," not "error."
2. **DB CHECK constraint is the real guarantee.** `total_reserved <= total_inventory * 1.1` lives on the table itself. Application-level availability checks before the write are a UX nicety (fail fast, show a nice error) — they are never sufficient on their own, because two concurrent requests can both pass the check before either commits.
3. **Constraint violations are caught and translated**, not allowed to surface as 500s. Catch the driver-level check-violation exception in the repository/service layer and raise a domain exception (`InsufficientInventoryException` or similar) that the controller maps to `409 Conflict`.

## When adding a new reservation-related feature

Ask, in order:
1. Does this change touch `room_type_inventory` or `reservation` writes? If yes, the transaction must include both the inventory update and the reservation insert/update in one DB transaction — never two separate transactions with logic in between.
2. Does this change the overbooking percentage or add a new constraint? Update the migration, the CHECK constraint, `CLAUDE.md`, and `docs/IMPLEMENTATION_PLAN.md` together — these must never drift from each other.
3. Does this add a new way to create/modify a reservation (e.g., a staff-side "book for customer" endpoint)? It must go through the same transactional helper/service as the customer-facing path — do not duplicate the transaction logic inline in a new controller.

## Red flags to stop and flag to the user

- A read-then-write pattern for inventory with no DB constraint backing it up.
- Any reservation ID generated with `AUTO_INCREMENT` / Doctrine `GeneratedValue`.
- A migration that alters `room_type_inventory` without including or preserving `check_room_count`.
- Business logic that "checks availability" and treats that check as sufficient, without the write itself being constraint-protected.
- Splitting inventory and reservation writes across two services/databases (breaks the single-transaction guarantee) — see `CLAUDE.md` for why this project deliberately keeps them together.

## Reference

Full design rationale: `docs/IMPLEMENTATION_PLAN.md`, sections 1 ("Data model"), 3 ("Concurrency & idempotency"), and 9 ("Testing priorities"). The concurrency test described there (parallel requests against a nearly-full room type) is the acceptance test for any change to this domain — run or update it before calling a reservation-path change done.
