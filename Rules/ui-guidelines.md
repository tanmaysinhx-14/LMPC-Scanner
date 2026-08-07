# CivicConnect UI guidelines

## Surfaces

- **Landing:** explain the product and route users to registration, login, feed, and City Pulse.
- **Community Feed:** readable public issue stream with filters, evidence, status, assignment, and compact map preview.
- **City Pulse:** map-first operational exploration with search, filters, clusters, and popups.
- **Citizen dashboard/report:** personal reporting and tracking.
- **Admin workspace:** city visibility, allocation, and request review.
- **Worker workspace:** assigned work, requests, status, and completion.

## Visual system

- Operational pages use Bootstrap 5.3.3 plus `assets/css/civic-ui.css` and page styles.
- Use Inter for readable UI text; DM Sans/Space Grotesk may support the City Pulse identity.
- Use semantic Bootstrap colors for success, warning, danger, and primary actions.
- Keep primary actions obvious and role-appropriate.
- Use the shared toast system for server feedback.
- Theme toggles must update both `data-theme` and `data-bs-theme`.

## Interaction rules

- Do not show a citizen-only report action to workers/admins.
- Always provide a meaningful loading, empty, and error state.
- Disable a submit/action control while its request is in flight.
- Confirm destructive or irreversible actions.
- Preserve keyboard focus and visible focus styles.
- Use `aria-label`, `aria-live`, and semantic buttons for map/feed controls.
- Escape user, AI, and database text before inserting it into HTML or map popups.

## Content rules

Use “administrator” and “field worker”; do not present Authority as an active fourth role. Use “issue” for the canonical grouped record and “report” for an individual citizen submission. Never display fake operational metrics or imply roadmap features are already live.

## Responsive checks

Test the navbar and action buttons at narrow widths, make filters stack cleanly, keep the map usable on touch screens, prevent long addresses/titles from overflowing, and ensure modals/toasts remain readable in both themes.
