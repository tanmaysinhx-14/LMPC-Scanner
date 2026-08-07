# CivicConnect project rules

1. Preserve the three-role model: Citizen, Worker, Admin.
2. Enforce permissions in PHP/API code; UI hiding is not authorization.
3. Keep the public feed and City Pulse database-backed.
4. Use the canonical category/status labels from shared helpers.
5. Add CSRF protection to every state-changing form/API request.
6. Escape database/user/AI content before HTML, popup, or JavaScript insertion.
7. Record status, assignment, and work-request history.
8. Keep AI as decision support and disclose unavailable/uncertain results.
9. Preserve individual evidence when grouping reports.
10. Do not add fake metrics, demo credentials, or claims for roadmap features.
11. Maintain responsive empty/loading/error states.
12. Update `PROJECT_REPORT.md`, relevant `rules/` files, and the SIH playbook when scope changes.
13. Run PHP lint, JavaScript syntax checks, route/link checks, and role-flow smoke tests before handoff.
