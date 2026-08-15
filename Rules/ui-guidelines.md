# CivicConnect web and mobile UI guidelines

## Surfaces

- **Landing:** explain the product and route users to registration, login, feed, and City Pulse.
- **Community Feed:** readable issue stream with filters, evidence, status, assignment, and compact map preview.
- **City Pulse:** map-first operational exploration with search, filters, clusters, and popups.
- **Citizen report:** photo/location capture, local preview, AI annotated preview, confirmation, and submission status.
- **Citizen tracking:** personal reports, grouped issue detail, status, assignment, and corroboration.
- **Worker workspace:** assignments, requests, notes, status transitions, and completion evidence.
- **Admin workspace:** city visibility, allocation, request review, and audit information. Keep this web-first during the first mobile release.

## Shared visual system

- The web client uses Bootstrap 5.3.3, `assets/css/civic-ui.css`, and page styles.
- The mobile client should share the same color tokens, category colors, status labels, icon meaning, and content vocabulary through a small typed theme module.
- Use Inter for readable UI text; DM Sans/Space Grotesk may support the City Pulse identity.
- Use semantic success, warning, danger, and primary meanings consistently across web and mobile.
- Keep primary actions obvious and role-appropriate.
- Use a shared toast/banner pattern for server feedback, adapted to native accessibility semantics.
- Theme switches must remain synchronized on web; mobile must respect system light/dark mode and preserve contrast.

## Mobile interaction rules

- Use bottom tabs for the stable top-level citizen/worker destinations and stacks for detail flows.
- Use protected route groups for signed-out, citizen, worker, and future admin surfaces.
- Respect safe areas, keyboard avoidance, dynamic text sizes, touch targets of at least 44 points, and screen-reader labels.
- Ask for camera, photo-library, location, and notification permission only at the point of need, after explaining why.
- Always provide loading, empty, offline, permission-denied, retry, and server-error states.
- Disable an action while its request is in flight and make retry behavior explicit.
- Confirm destructive or irreversible actions.
- Never put raw bearer tokens, database data, or sensitive coordinates in logs or screenshots.

## AI preview rules

- Show the local selected/captured image immediately while analysis is pending.
- Show the server annotated image only as AI output, with category, confidence, severity, and the number of boxes.
- Use red for pothole, amber for garbage, purple for graffiti, and a neutral color for unknown output.
- Make it clear that boxes are model detections, not a guarantee or a final municipal decision.
- Show low-confidence or no-detection states honestly; never replace an uncertain result with a fake positive.
- Allow the citizen to correct/confirm the category before final submission, while the server re-analyzes before persistence.

## Map rules

- Web City Pulse uses MapLibre GL JS; mobile City Pulse uses MapLibre React Native.
- Use the same style, attribution, filters, category colors, and database-backed heatmap response.
- Keep markers, clusters, and popups usable with touch. Do not require hover.
- Provide a list fallback for users who cannot use the map.
- Do not expose more coordinate precision than the viewer's role requires.

## Content and role language

Use “administrator” and “field worker”; do not present Authority as an active fourth role. Use “issue” for the canonical grouped record and “report” for an individual citizen submission. Never display fake operational metrics or imply migration roadmap features are live.

## Responsive and device checks

- Test the web navbar, filters, maps, modals, and toasts at narrow widths.
- Test mobile on a physical Android device for camera, GPS, notification permission, poor network, rotation, app backgrounding, and large text.
- Test iOS permission copy, camera/library behavior, safe areas, and push registration before store release.
- Verify both platforms can recover after an interrupted upload or process restart.
