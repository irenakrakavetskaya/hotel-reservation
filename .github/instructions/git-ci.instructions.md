---
applyTo: "**/.gitlab-ci.yml,backend/migrations/**,backend/src/Domain/Reservation/**"
---

# Rules — Git & CI

- **Migrations are one-way in shared branches.** Once a migration touching `room_type_inventory` or `reservation` is merged, don't edit it — write a new migration. These tables carry the correctness guarantees; a rewritten migration on a shared branch can silently drop the CHECK constraint for anyone who already ran it.
- **Every PR touching the reservation write path must include or update the concurrency test** described in `docs/IMPLEMENTATION_PLAN.md` §9. CI should fail if that test file is untouched while `src/Domain/Reservation/**` changed — flag this to the user if it isn't automated yet.
- **`.gitlab-ci.yml` stage order**: `lint → test → build → deploy`. Don't reorder to put `build` before `test` even for speed — a broken build reaching the registry is worse than a slower pipeline.
- **Deploy stage is manual-gated for production** (`when: manual` in GitLab CI syntax) until there's an agreed rollback story.
- **OpenAPI snapshot gate**: the lint stage runs `composer openapi:check`; the committed root `openapi.json` and `frontend/lib/openapi.d.ts` must be regenerated in the same change as any API contract change.
- **Docker vendor volume**: `backend_vendor` is a named volume shadowing the image's `/app/vendor` and is not refreshed on rebuild. The `migrate` service runs `composer install` as root (`COMPOSER_ALLOW_SUPERUSER=1`) then re-chowns `var/cache` and `var/log` to `www-data`; keep that when editing `docker/docker-compose.yml`. A "Class ... not found" after adding a bundle means the volume is stale: rerun `docker compose run --rm --no-deps migrate`.
- **Line endings**: `AGENTS.md` and `backend/composer.json` are CRLF; use `git -c core.whitespace=cr-at-eol diff --check` to avoid false whitespace errors.