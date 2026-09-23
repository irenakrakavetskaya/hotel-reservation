# Rules — Frontend (Next.js 16)

Read this before touching anything under `/frontend`.

- **Server Components by default.** Hotel detail, room detail, and search/listing pages fetch data server-side. Only mark a component `"use client"` when it needs interactivity or browser state (the booking form, the admin CRUD forms).
- **The booking flow generates `reservationID` client-side** (UUID v4/v7) the moment the user lands on the confirm-booking step, stores it in component/form state, and reuses the *same* ID on retry if the initial `POST /v1/reservations` call fails due to a network error. Never generate a new ID on retry — that defeats the idempotency guarantee.
- **Disable the "Book" button on submit** and keep it disabled until a response (success or error) comes back. This is a UX nicety, not the actual double-booking defense — the backend's idempotency key is — but it reduces accidental duplicate requests and confusing UI states.
- **Data fetching**: use Next's built-in fetch caching / route segment config for the read-heavy pages (hotel/room detail — these can tolerate short revalidation windows, e.g. `revalidate: 60`). Use client-side fetching (React Query or `useEffect` + fetch) only for the availability re-check on the booking page, since that must reflect near-real-time inventory.
- **Admin routes live under `/admin`** in their own route group with a layout that enforces `ROLE_STAFF`/`ROLE_ADMIN` via a server-side session/JWT check — never gate admin UI with client-side checks alone.
- **API client**: one shared typed fetch wrapper (e.g. `lib/api.ts`) that attaches the JWT and handles 401/409 consistently — don't call `fetch()` ad hoc from components.
- **Styling/component conventions**: match whatever the project's design system doc says once one exists; until then, keep components small and colocate a component's styles with it.
- **No `any` in TypeScript.** Define request/response types that mirror the backend's OpenAPI/route contracts.
