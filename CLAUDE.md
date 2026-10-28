# Claude Code entry point

Use [AGENTS.md](AGENTS.md) as the canonical project guide. It contains the current
architecture, verified commands, domain-specific customization links, and invariants.

Detailed design belongs in [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md);
layer-specific behavior belongs in `.claude/rules/` and `.claude/skills/`.

Quick reminders (details in AGENTS.md):

- Backend tests need the `*_test` DB; run them in Docker with the command in AGENTS.md, then `composer lint` on the host.
- Frontend: `npm run lint`, `npm run typecheck`, `npm test` from `frontend/`.
- Never edit applied migrations (latest: `Version20260101000009`, the `reservation.user_id` FK); add a new one.
- Nothing is committed unless asked; commit messages are action-verb-led with the Co-authored-by trailer.
