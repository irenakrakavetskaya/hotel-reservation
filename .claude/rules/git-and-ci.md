# Rules — Git & CI

- **Migrations are one-way in shared branches.** Once a migration touching `room_type_inventory` or `reservation` is merged, don't edit it — write a new migration. These tables carry the correctness guarantees; a rewritten migration on a shared branch can silently drop the CHECK constraint for anyone who already ran it.
- **Every PR touching the reservation write path must include or update the concurrency test** described in `docs/IMPLEMENTATION_PLAN.md` §9. CI should fail if that test file is untouched while `src/Domain/Reservation/**` changed — flag this to the user if it isn't automated yet.
- **`.gitlab-ci.yml` stage order**: `lint → test → build → deploy`. Don't reorder to put `build` before `test` even for speed — a broken build reaching the registry is worse than a slower pipeline.
- **Deploy stage is manual-gated for production** (`when: manual` in GitLab CI syntax) until there's an agreed rollback story.
- **Commit messages**: action-verb-led, one logical change per commit (matches the maintainer's existing writing preference — apply it to commit messages too, not just prose).
