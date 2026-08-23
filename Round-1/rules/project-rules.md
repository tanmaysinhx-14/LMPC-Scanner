# CivicConnect project rules

1. Preserve the three-role model: Citizen, Worker, Admin.
2. Enforce permissions in PHP/API code; UI hiding is not authorization.
3. Keep the public feed and City Pulse database-backed.
4. Use the canonical category/status labels from shared helpers.
5. Add CSRF protection to every state-changing browser request; use bearer-token authorization for the planned native API.
6. Escape database/user/AI content before HTML, popup, or JavaScript insertion.
7. Record status, assignment, and work-request history.
8. Keep AI as decision support and disclose unavailable/uncertain results.
9. Preserve individual evidence when grouping reports.
10. Do not add fake metrics, demo credentials, or claims for roadmap features.
11. Maintain responsive web and touch-friendly mobile loading/empty/error states.
12. Keep the PHP/MariaDB backend authoritative; clients never connect directly to the database.
13. Keep web endpoints backward-compatible while adding versioned `/api/v1/` mobile contracts.
14. Treat mobile authentication, refresh-token rotation, push tokens, and idempotency as security-sensitive server features.
15. Keep the AI model server-side until on-device inference is justified by a separate evaluation.
16. Update `MOBILE_MIGRATION_BLUEPRINT.md`, relevant `rules/` files, the SIH playbook, pitch/presentation documents, and API/data contracts together when scope changes.
17. Run PHP lint, Python checks, JavaScript/TypeScript syntax checks, API contract tests, route/link checks, and role-flow smoke tests before handoff.
