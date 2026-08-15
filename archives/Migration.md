# CivicConnect mobile migration blueprint

**Status:** citizen/worker native client implemented; release deployment remains environment-gated

**Date:** 2026-08-14

**Target repository:** `C:\Users\tanma\Desktop\GitHub\SIH-2026-Prototype`

## 1. Decision in one sentence

Build a new Expo React Native TypeScript client under `mobile/`, preserve the PHP/MariaDB/FastAPI backend, add a versioned bearer-authenticated `/api/v1/` contract, and migrate citizen and worker workflows incrementally while the web/admin client remains operational.

## 2. Current baseline

The current application already provides:

- PHP server-rendered pages and JSON endpoints.
- MariaDB/MySQL persistence through PDO.
- Three active roles: `citizen`, `worker`, and `admin`.
- Citizen image/GPS reporting and database-backed grouping.
- Community Feed and MapLibre GL JS City Pulse.
- Admin allocation, worker requests, assignment status, and audit history.
- FastAPI AI inference with the current detector at `civic-dataset/runs/detect/train/weights/best.pt`.
- Current AI labels: `pothole`, `garbage`, and `graffiti`.
- Server-generated bounding boxes and optional annotated preview data URLs.
- Server-side final re-analysis before issue persistence.

The backend is the valuable part to preserve. The current browser session/CSRF contract is not a suitable native-client contract by itself, so the native client uses bearer authentication while the browser flow remains unchanged.

## 3. Native client implemented now

The `mobile/` workspace is now a real citizen/field-worker client connected to the existing PHP/MariaDB/FastAPI system:

- Protected role routing sends citizens to reporting/tracking screens and workers to assignments/work requests; administrators remain web-first.
- Native auth uses short-lived bearer access tokens plus rotating, hashed, revocable refresh tokens stored in SecureStore.
- Citizen dashboard, report upload, server AI preview, final trusted submission, community feed, issue detail, upvotes, status history, assignment visibility, and resolution verify/reopen are implemented.
- Worker assignments, available work requests, request history, issue context, status transitions, feed, City Pulse, and profile are implemented.
- City Pulse reads the database-backed heatmap response and opens issue details from markers/list cards.
- The existing web portal remains the administrator surface for assignment, work-request approval, analytics, and account operations.
- The mobile app never connects directly to MariaDB or FastAPI. The trained `.pt` model remains on the laptop FastAPI host.

Live verification completed against the current LAN backend: citizen login/dashboard/refresh/logout, worker assignments, issue detail, database heatmap, FastAPI health, and bearer-authenticated multipart AI analysis. A labeled archive image returned a detected `garbage` result with confidence `0.9233` and an annotated preview.

Run it from `mobile/` with `npm install`, copy `.env.example` to `.env`, set `EXPO_PUBLIC_API_BASE_URL` to the reachable PHP application URL, and run `npm start`. For a physical phone, use the laptop's LAN IP; `127.0.0.1` and `localhost` point to the phone itself.

## 4. Chosen stack

### Mobile client

- Expo SDK 57, React Native, TypeScript.
- Expo Router for file-based, typed, deep-linkable navigation.
- EAS Build, EAS Submit, and EAS Update for development, internal distribution, and stores.
- `@maplibre/maplibre-react-native` for the installable City Pulse heatmap/cluster experience, using the same keyless Carto/OpenStreetMap raster style as the web pulse page.
- `expo-image-picker` and optionally `expo-camera` for capture.
- `expo-location` for foreground GPS.
- `expo-notifications` for push registration and handling.
- `expo-secure-store` for refresh credentials.
- `expo-sqlite` for a later cache/outbox phase.
- TanStack Query for server data caching/invalidation and Zod for input/payload validation.

### Backend

- Keep PHP and MariaDB/MySQL as the system of record.
- Add `/api/v1/` wrappers that reuse shared PHP domain functions.
- Use short-lived bearer access tokens and rotating, hashed refresh tokens for native auth.
- Keep the existing browser session and CSRF flow unchanged until web retirement is a separate decision.
- Keep FastAPI/Ultralytics inference server-side; do not ship `.pt` weights in the mobile binary.

### Official references

- [Expo Router](https://docs.expo.dev/router/introduction/)
- [EAS Build](https://docs.expo.dev/build/introduction/)
- [Expo ImagePicker](https://docs.expo.dev/versions/latest/sdk/imagepicker/)
- [Expo Location](https://docs.expo.dev/versions/latest/sdk/location/)
- [Expo Notifications](https://docs.expo.dev/versions/latest/sdk/notifications/)
- [Expo SecureStore](https://docs.expo.dev/versions/latest/sdk/securestore/)
- [Expo SQLite](https://docs.expo.dev/versions/latest/sdk/sqlite/)
- [MapLibre React Native](https://maplibre.org/maplibre-react-native/docs/setup/getting-started/)
- [React Native New Architecture](https://reactnative.dev/architecture/landing-page)
- [FastAPI bearer/JWT security concepts](https://fastapi.tiangolo.com/tutorial/security/oauth2-jwt/)

## 5. Target repository layout

```text
SIH-2026-Prototype/
├── api/                         # existing browser APIs; preserve compatibility
├── ai-service/                  # existing FastAPI detector
├── assets/                      # SQL/schema and static assets
├── functions/                   # shared PHP domain/auth/location logic
├── pages/                       # existing web client and admin workspace
├── rules/                       # synchronized product/engineering rules
├── archives/                    # problem/pitch/knowledge documents
├── mobile/                      # new Expo app; EAS root
│   ├── app/                     # Expo Router route groups
│   │   ├── (auth)/
│   │   ├── (citizen)/
│   │   ├── (worker)/
│   │   └── (admin)/             # add only when mobile admin scope is accepted
│   ├── src/api/                 # typed API client and endpoint adapters
│   ├── src/auth/                # session, refresh, role/capability guards
│   ├── src/components/          # shared mobile UI primitives
│   ├── src/features/            # report, feed, map, assignments, profile
│   ├── src/storage/             # SecureStore and later SQLite cache/outbox
│   ├── app.config.ts
│   ├── eas.json
│   └── package.json
└── MOBILE_MIGRATION_BLUEPRINT.md
```

EAS commands should run from `mobile/`, where `eas.json`, app identifiers, and mobile build secrets belong.

## 6. Exact product flows

### 6.1 Authentication and app startup

```text
App launch
  -> read refresh credential from SecureStore
  -> no credential: show login/register
  -> credential: POST /api/v1/auth/refresh
  -> store rotated refresh credential
  -> GET /api/v1/auth/me
  -> load role/capabilities
  -> redirect to citizen, worker, or admin route group
```

Rules:

- Access tokens stay in memory where possible and are never logged.
- Refresh tokens are rotated, hashed on the server, scoped to a device/session, and revoked on logout or reuse detection.
- `401` triggers one refresh attempt; repeated failure clears local session and returns to login.
- `403` shows an authorization state; it must not be “fixed” by hiding a button only.

### 6.2 Citizen report flow

```text
Tap Report
  -> request camera/photo permission
  -> capture/select image
  -> show local image preview immediately
  -> request foreground location
  -> POST /api/v1/issues/analyze (multipart)
  -> show category, confidence, severity, detections, boxes, annotated preview
  -> citizen confirms/corrects category and adds description
  -> POST /api/v1/issues (multipart + Idempotency-Key)
  -> server stores upload and re-runs AI
  -> server groups or creates canonical issue transactionally
  -> return issue/report/AI summary
  -> navigate to issue detail and tracking
```

The mobile app may display the preview response, but the server is authoritative for category, severity, grouping, image hash, manipulation state, and persistence.

### 6.3 Feed and City Pulse flow

```text
Open Feed or City Pulse
  -> read cached data if available
  -> request current paginated issues/heatmap data
  -> render loading/error/empty states
  -> apply category/status/search filters locally only where safe
  -> request new server data when filters affect the source query
  -> open issue detail from marker, cluster list, or feed card
```

The mobile map uses the same database-backed heatmap semantics as the web map. A list view is always available as an accessible map fallback.

### 6.4 Worker flow

```text
Worker login
  -> GET /api/v1/work/assignments
  -> open assignment detail
  -> view location, priority, evidence, notes, and history
  -> POST permitted status transition
  -> optionally POST work request
  -> upload completion evidence if the accepted workflow requires it
  -> server validates active assignment and writes status history
```

### 6.5 Notification flow

```text
Mobile permission granted
  -> obtain Expo/device push token
  -> POST /api/v1/devices/push-token
  -> server associates token with user/device
  -> assignment/status event occurs
  -> notification provider sends event
  -> app deep-links to issue/assignment detail
```

Push delivery is not current functionality. It needs server/provider credentials, token cleanup, opt-out behavior, and delivery testing.

## 7. API migration map

Do not rename or break the browser endpoints first. Add native wrappers that call the same PHP domain functions.

| Current web contract | Planned native contract | Migration action |
|---|---|---|
| `api/auth/login.php` | `POST /api/v1/auth/login` | Add bearer-token response while preserving browser session login |
| `api/auth/session.php` | `GET /api/v1/auth/me` | Return user/role/capabilities for native route guards |
| browser logout flow | `POST /api/v1/auth/logout` | Revoke refresh-token session/family |
| `api/issues/analyze.php` | `POST /api/v1/issues/analyze` | Reuse upload validation and AI call; return native-safe AI response |
| `api/issues/submit.php` | `POST /api/v1/issues` | Add idempotency, bearer auth, and stable response envelope; re-analyze server-side |
| `api/issues/list.php` / feed queries | `GET /api/v1/issues` | Add cursor pagination and mobile-safe filtering |
| `api/issues/detail.php` | `GET /api/v1/issues/{id}` | Define visibility policy and stable nested data |
| `api/issues/upvote.php` | `POST /api/v1/issues/{id}/upvote` | Reuse vote logic and return current vote/count state |
| `api/issues/status.php` | `POST /api/v1/issues/{id}/status` | Reuse worker/admin state machine and history |
| `api/stats/heatmap.php` | `GET /api/v1/stats/heatmap` | Preserve database source and coordinate precision policy |
| `api/work/*` | `GET/POST /api/v1/work/*` | Add worker/admin capability checks and mobile pagination |

## 8. Implementation phases

### Phase 0 - freeze the current web contract

Tasks:

1. Keep the current web prototype runnable and record a baseline of PHP lint, API smoke responses, AI `/health`, and report preview behavior.
2. Treat `assets/civicconnect.sql`, current category/status vocabulary, and current role permissions as the contract baseline.
3. Record the current AI response fields, including detections and `annotated_image` behavior.
4. Decide the production API hostname, HTTPS certificate, upload size, CORS policy if needed, and environment naming.

Done when: the web demo works from a clean documented start sequence and a regression test can detect a broken backend before mobile work begins.

### Phase 1 - scaffold the mobile project

Status: complete for the current native implementation.

The workspace uses Expo SDK 57, React Native, TypeScript, Expo Router, SecureStore, React Query, and native maps. Its screens are connected to live shared data and the citizen/worker workflows:

- `mobile/app/(tabs)/index.tsx` loads feed and heatmap data together.
- `mobile/app/(tabs)/feed.tsx` uses the existing public feed endpoint.
- `mobile/app/(tabs)/pulse.tsx` uses the existing database-backed heatmap endpoint.
- `mobile/app/(tabs)/report.tsx` signs in a citizen, selects/captures an image, captures GPS, previews server AI, and submits through the existing trusted PHP transaction.

To run the current slice:

```powershell
cd mobile
npm install
Copy-Item .env.example .env
# Set EXPO_PUBLIC_API_BASE_URL to the reachable PHP app URL.
npm start
```

Verification: `npm run typecheck`, `npx expo-doctor`, PHP lint, live endpoint checks, and AI multipart analysis pass. A physical EAS build cannot be generated from this workspace until an Expo account/project is supplied; no Android SDK is installed for a local build.

### Phase 2 - add the mobile API contract and auth

Status: implemented.

1. PHP bearer-token middleware and `/api/v1/auth/*` endpoints are implemented, backed by `mobile_access_tokens` and `mobile_refresh_tokens`.
2. Refresh-token/device migration and hash/rotate/revoke/reuse-detection logic is implemented.
3. `/api/v1/auth/me` returns the native user/role identity used by route guards.
4. The typed client retries one expired access token through refresh and persists the rotated credentials.
5. Live `200` login/me/dashboard/worker/refresh/logout checks pass; a dedicated automated contract-test suite remains release hardening.

Done when: a user can sign in, restart the app, refresh the session, route to the right role group, and log out from two devices independently.

### Phase 3 - build the citizen mobile MVP

Status: implemented online-first.

1. Implement image capture/library selection and client-side resize/compression within the current 10 MB server limit.
2. Implement GPS permission, timeout, accuracy display, and manual retry.
3. Implement `POST /api/v1/issues/analyze` multipart upload.
4. Render the local preview, server annotated preview, detections, confidence, severity, low-confidence state, and AI failure state.
5. Implement category correction and description entry.
6. Implement final `POST /api/v1/issues` with `Idempotency-Key`.
7. Implement feed, issue detail, upvotes, current status, assignment, and image access policy.

The citizen flow is implemented against the current backend and verified through live API calls. Physical-device and interrupted-upload testing remain release gates; offline outbox/idempotency is intentionally not implied.

### Phase 4 - add mobile City Pulse and worker operations

Status: core operations implemented.

1. Native MapLibre heatmap/cluster/list behavior, filters, and issue-detail navigation are implemented. Pixel-level parity with the web MapLibre styling is not claimed.
2. The list fallback and existing role-aware coordinate precision are preserved.
3. Worker assignments, detail, work requests, notes, status transitions, and completion state are implemented. Worker completion-photo evidence is not yet ported.
4. Verify server-side assignment/state authorization with both valid and invalid workers.

Done when: an issue can travel from citizen report to admin allocation to worker update while the citizen sees the status change.

### Phase 5 - add notifications and controlled offline support

1. Register/revoke device push tokens and define event types.
2. Deliver assignment/status notifications through a chosen provider and deep-link them into the app.
3. Add SQLite read cache for feed, issue detail, assignments, and map metadata.
4. Only then design an outbox for offline report/status actions with idempotency keys, retry limits, conflict policy, and user-visible pending state.

Done when: notification opt-in/out, token cleanup, deep links, cache invalidation, retry, and conflict outcomes are tested on physical devices.

### Phase 6 - beta, release, and web transition

Status: blocked only by external release/deployment setup, not by the native source implementation.

1. Run security, performance, accessibility, device-matrix, and API compatibility checks.
2. Distribute internal Android/iOS builds through EAS after an Expo account/project and production API origin are supplied.
3. Pilot with citizens, admins, and workers in one ward or controlled dataset.
4. Measure upload success, AI latency, grouping rate, assignment time, completion time, crash-free sessions, and notification delivery.
5. Fix operational issues before expanding roles or removing web workflows.
6. Retire web routes only through a separate decision after mobile parity, data export, support, and rollback requirements are satisfied.

## 9. Testing matrix

### Backend/API

- Contract tests for every `/api/v1/` response and error code.
- Auth tests for expiry, refresh rotation, reuse detection, revocation, and role capabilities.
- Upload tests for MIME spoofing, size limit, corrupted images, GPS bounds, duplicate idempotency keys, and AI unavailable states.
- Database transaction tests for grouped and new issue paths.
- Authorization tests for citizen ownership, worker assignment ownership, and admin-only operations.

### Mobile

- Unit/component tests for auth state, route guards, form validation, preview rendering, detection list, retry, and offline state.
- Physical Android tests for camera, image picker, GPS, app backgrounding, upload interruption, permissions, and large text.
- Physical iOS tests for permission copy, safe areas, camera/library behavior, push registration, and store entitlements.
- E2E flow: login -> report -> AI preview -> final submit -> feed/detail -> admin assignment -> worker completion.

### Release gates

- No critical auth/data-loss issue open.
- No client path directly accesses MariaDB.
- No production build contains secrets or model weights.
- All AI/unavailable/low-confidence states are visible.
- Web regression smoke tests pass.
- API and database migrations have a rollback plan.

## 10. Security and privacy decisions

- Enforce HTTPS in staging and production.
- Use short-lived access tokens and rotating hashed refresh tokens.
- Store refresh credentials only in `expo-secure-store`.
- Do not log tokens, passwords, raw images, or precise GPS unnecessarily.
- Keep upload validation and final AI analysis on the server.
- Apply role/capability checks to every native endpoint.
- Redact public coordinates and protect image URLs according to role.
- Add rate limits to login, refresh, image analysis, final submit, and push-token registration.
- Document image/token/device retention before pilot launch.

## 11. Main risks and mitigations

| Risk | Mitigation |
|---|---|
| Mobile client drifts from PHP behavior | Versioned API contracts, shared PHP functions, contract tests |
| Native map library requires rebuild/new architecture | Use an Expo development build early; verify MapLibre before building screens around it |
| Network interruption duplicates reports | Idempotency keys, server transaction, visible pending/retry state |
| Token theft or stale sessions | SecureStore, short access expiry, refresh rotation/reuse detection, device revocation |
| AI latency or outage | Show local preview first, bounded annotated response, timeout/retry state, controlled manual fallback decision |
| Offline writes create conflicts | Start online-first; introduce SQLite outbox only with explicit conflict policy |
| App-store or permission rejection | Configure purpose strings, test permissions on physical devices, use EAS release profiles |
| Admin workflow becomes awkward on mobile | Keep admin web-first; add only high-value mobile admin actions after user validation |

## 12. Definition of migration complete

The migration is complete only when:

1. Citizen and worker core workflows pass on supported Android and iOS builds.
2. The native app uses `/api/v1/` bearer authentication and never browser-cookie assumptions.
3. Final issue persistence remains server-trusted and transactionally auditable.
4. City Pulse data and category/status semantics match the web implementation.
5. Push/offline behavior is either fully tested or explicitly disabled, not implied.
6. Web fallback, data migration, monitoring, support, store release, and rollback procedures are documented.
7. The current documentation files and this blueprint agree about what is live versus planned.
