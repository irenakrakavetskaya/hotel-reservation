# Rules — Report Worker (Go)

Read this before touching anything under `/report-worker`.

- **Keep report state in Symfony.** The worker reads reservations with the provisioned read-only database role and publishes results; it must not write reservation or report tables.
- **Preserve the message contract.** Validate versioned `report.generate.v1` jobs and publish `report.result.v1` results. Coordinate changes to fields, statuses, routing keys, exchange/queue topology and validation with the Symfony serializer and consumers; add focused tests on both sides when the contract changes.
- **Expect redelivery.** Keep manual acknowledgements, persistent messages, publisher confirmations, and the existing retry, requeue and dead-letter paths. For valid jobs, acknowledge only after the appropriate result or retry has been confirmed; reject unidentifiable malformed jobs to the dead-letter queue. Preserve safe handling of duplicate jobs and results. Publisher confirms alone do not guarantee routing or exactly-once delivery.
- **Preserve report semantics.** Filter reservation creation dates using inclusive UTC calendar days, read counts and paid totals from one read-only repeatable-read snapshot, and calculate money in integer cents. Status counts and paid totals describe current state, not historical revenue.
- **Publish PDFs safely.** Validate the report UUID before constructing `report-{id}.pdf`; write to a temporary file in the report directory, clean it up on failure, and atomically rename it after rendering. Preserve group-readable permissions (`0640`) and API access to the shared volume.
- **Handle failures and shutdown explicitly.** Preserve bounded database operations, context cancellation, connection cleanup, the dependency-aware health endpoint, and structured error logging. Do not log credentials or silently acknowledge failed work.
- **Validate changes.** Format changed Go files with `gofmt` and run `go test ./...` from `report-worker/`. Add focused tests for changed validation, delivery/failure handling, aggregation or PDF behavior; use isolated services and a dedicated test database for integration tests, never the main development database.
