# CivicConnect requirements and migration acceptance criteria

## Current product requirements

### Identity and access

- Support exactly Citizen, Worker, and Admin roles.
- Allow citizen self-registration and protected staff registration.
- Authenticate browser users by email/password and role.
- Provide logout, remember-me sessions, profile, password change, CSRF, and login-rate limiting.

### Citizen

- Submit a photo, location, category, and description.
- See an upload-triggered AI result, including category, confidence, severity, detections, and an annotated preview when available.
- View own submissions and public grouped issues.
- Search/filter/sort the community feed.
- Upvote corroborating issues.
- View current status, assignment worker, assigning admin, and completion state.

### Admin

- View city-wide issue and category statistics.
- Explore the database-backed City Pulse map.
- Review issue details and priority.
- Assign issues to active workers with notes.
- Review and decide worker work requests.
- Change permitted issue states and preserve status history.

### Worker

- View assignments, location, priority, notes, and status.
- Request work from an admin.
- Update assigned work through permitted states.
- Mark assigned work completed.

### Intelligence and data

- Store AI category, severity, confidence, manipulation flag, model version, detections, and raw output.
- Draw server-generated bounding boxes for the current pothole, garbage, and graffiti detector.
- Group nearby same-category reports.
- Expose live map/feed statistics from the database.

## Mobile migration requirements

### Mobile application

- Deliver one role-aware Expo/React Native TypeScript app under `mobile/`.
- Use protected route groups for unauthenticated, citizen, worker, and future admin surfaces.
- Support Android first and iOS through the same codebase; do not make iOS-only behavior part of the core workflow.
- Use native camera/library selection, foreground GPS, secure token storage, touch-sized controls, safe-area handling, and accessible loading/error/empty states.
- Show the original local image immediately, then replace or supplement it with the server annotated preview after AI analysis.
- Keep the app usable on a slow connection. The first release is online-first with cached read data; queued offline writes are a later phase.

### Native API contract

- Add versioned `/api/v1/` endpoints without breaking the existing browser endpoint contract.
- Use short-lived bearer access tokens and rotating refresh tokens stored hashed server-side. Store only the refresh credential in platform secure storage.
- Return stable JSON envelopes, typed error codes, pagination metadata, server timestamps, and role/capability data.
- Use multipart uploads for evidence; enforce the current 10 MB image limit, MIME/signature validation, GPS validation, and server-side re-analysis.
- Require an idempotency key for final issue creation so retries cannot create duplicate reports.
- Never trust category, severity, detections, preview, or hidden client fields for persistence.

### Mobile report acceptance flow

1. Request camera or photo-library permission only after the user taps the relevant action.
2. Capture/select an image and show a local preview immediately.
3. Acquire current GPS with an explicit permission/error state.
4. Send the image and optional context to the mobile analysis endpoint.
5. Display category, confidence, severity, detections, bounding boxes, and the annotated preview.
6. Let the citizen correct/confirm the category and description.
7. Submit the final multipart request with an idempotency key.
8. Re-analyze on the server, persist the canonical issue/report/image/AI rows transactionally, and return the grouped issue.
9. Show the resulting issue status and provide a route to feed/detail tracking.

## Non-functional requirements

- Enforce authorization at the PHP/API boundary; UI hiding is never authorization.
- Require HTTPS outside local development.
- Use prepared SQL, escaped rendering, audit history, and least-privilege database credentials.
- Do not log passwords, bearer tokens, raw image data, or precise citizen coordinates unnecessarily.
- Keep public coordinate precision and image access policy explicit.
- Define API contract tests before mobile implementation consumes an endpoint.
- Test Android physical-device camera/GPS behavior; test iOS before store release.
- Run PHP lint, Python compilation/tests, TypeScript checks, API contract tests, mobile component tests, and device smoke tests before handoff.
- Maintain a web fallback until mobile parity and operational acceptance are complete.

## Phased scope

### Phase 1: mobile foundation

Scaffold Expo, authentication, role routing, API client, theme, error/loading states, and build profiles.

### Phase 2: citizen mobile MVP

Report creation, image upload, AI annotated preview, GPS, feed, issue detail, upvotes, and status tracking.

### Phase 3: worker operations

Assignments, work requests, notes, permitted status transitions, completion evidence, and push notifications.

### Phase 4: admin and offline hardening

Admin read/triage screens where justified, cached City Pulse, queued offline actions, retry/conflict policy, analytics, and release hardening.

## Explicitly not claimed yet

Native mobile, offline writes, push delivery, biometric login, citizen resolution verification, SLA/escalation automation, predictive forecasting, WhatsApp/IVR, regional-language voice intake, and external municipal integrations are migration work or roadmap items until implemented and tested.
