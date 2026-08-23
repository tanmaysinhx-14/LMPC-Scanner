# CivicConnect mobile client

This is the installable native client for CivicConnect. It lives in its own `mobile/` workspace so the existing PHP web/admin application remains deployable while both clients use the same backend and database.

## Native workflows

- Expo SDK 57, React Native, TypeScript, and Expo Router.
- App-wide bearer authentication uses short-lived access tokens and rotating, revocable refresh tokens in SecureStore.
- Citizens get a native home dashboard, report flow, community feed, issue detail, upvotes, assignment tracking, status history, and resolution verification/reopen.
- Field workers get native assignments, location/context, status transitions, available work requests, request history, feed, and City Pulse.
- City Pulse renders the database-backed `GET /api/stats/heatmap.php` response with a keyless MapLibre native heatmap, cluster signals, and a filtered list fallback. The tab includes an explicit full-screen draggable map route (`/pulse-map`) with search, filters, reset view, tap-to-inspect, and five-second live refresh. It uses the same Carto/OpenStreetMap visual language as the web pulse page and does not require a Google Maps API key.
- The existing web portal remains the administrator surface for assignment, request approval, analytics, and account operations.

The mobile app does not contain the trained `.pt` file. A report image is uploaded to PHP, PHP calls the FastAPI detector, and the response includes category, confidence, severity, detections, and an annotated preview. Final persistence remains server-authoritative.

## Generated files

`.expo/` contains Expo CLI's local project state and packager metadata. Folders
such as `.expo-export-check*`, `.expo-exports/`, `dist/`, and `node_modules/`
are generated export/build/dependency output. They are not app source and are
ignored by both `mobile/.gitignore` and the repository `.gitignore`. The local
`.env` is also ignored; commit only `.env.example`.

## Run locally

From this directory:

```powershell
npm install
Copy-Item .env.example .env
# Edit .env and set EXPO_PUBLIC_API_BASE_URL to the reachable PHP app URL. Do not use Expo's Metro port 8081.
npm start
```

## Daily mobile UI development loop

For the VS Code equivalent of browser hot reload, install the development
build once on the emulator or phone:

```powershell
npx --yes eas-cli@21.8.0 build --platform android --profile development
```

Then keep the installed development build and run the JavaScript/TypeScript
bundler from this folder:

```powershell
npx expo start --dev-client
```

Press `a` for the Android emulator or scan the displayed QR code from the
development build on a physical phone. Saving a screen, component, style, or
API client file in VS Code triggers Fast Refresh; a new native build is only
needed after changing `app.json`, Expo config plugins, permissions, SDK
versions, or a library with native code. The development server and PHP API
must be reachable from the device. A preview/production APK is standalone and
does not connect to this bundler.

For a physical phone, use the laptop's LAN IP, for example `http://192.168.1.20/SIH-2026-Prototype`. Do not use `127.0.0.1` or `localhost` on the phone; those point to the phone itself. The PHP server and FastAPI service must be reachable from the laptop/server that hosts the shared backend. The phone should never connect directly to FastAPI or to MariaDB.

## Local demonstration sequence

1. Start MariaDB and the PHP web application.
2. Confirm the web Community Feed and City Pulse show data.
3. Set `EXPO_PUBLIC_API_BASE_URL` to that same web/API origin, for example `http://192.168.1.13` for the current Apache setup.
4. Start the AI service once from the repository root with `& .\\ai-service\\start.ps1`. If it is already running, the script reports the healthy process instead of attempting a second bind to port 8000.
5. Run the Expo client and open Overview, Feed, City Pulse, and Report.

## Multi-device demonstration data

The full database dump at `../assets/civicconnect.sql` contains six accounts for
each role and only six canonical issue posts with seven child reports, so real
upvotes, grouped reports, worker status changes, and dashboard statistics are
easy to observe across phones.

- Citizens: `citizen.one@civicconnect.test` through `citizen.six@civicconnect.test` / `Citizen@123`
- Workers: `worker.one@civicconnect.test` through `worker.six@civicconnect.test` / `Worker@123`
- Administrators: `admin.one@civicconnect.test` through `admin.six@civicconnect.test` / `Admin@123`

Mobile Overview, Feed, issue details, City Pulse, and the worker queue poll the
database every five seconds while open. This is near-real-time polling rather
than a WebSocket: an upvote, grouped report, assignment, or status change made
on one device appears on the others after the next refresh.

Priority is calculated by the PHP backend, not guessed by the image model. The
model identifies category/severity; the backend adds same-location evidence,
nearby same-category reports, nearby different-category reports, citywide
category volume, upvotes, recency, and recurrence. Same-location duplicates
receive the strongest boost, nearby different issues receive the smallest
geographic boost, and scores are capped at 100.
6. Build/install the preview APK or open the development client, sign in as a citizen, upload a civic image, capture GPS, confirm the AI result, and submit.
7. Open the web admin portal, assign the reported issue to a worker, and approve/request work if needed.
8. Sign out of the mobile client, sign in as the worker, open Assignments, start work, and mark the issue resolved.
9. Return to the citizen mobile session, open the issue detail, and verify or reopen the resolution. Pull-to-refresh the web portal to see the same status history.

The native upload uses Expo's `File` Blob implementation. Do not replace it with the legacy React Native `{ uri, name, type }` FormData object; SDK 57 rejects that object with `Unsupported FormDataPart implementation`.

## Building an installable Android app

Expo Go is not the deliverable. The `preview` EAS profile produces an installable Android APK and the `production` profile produces a store-oriented build:

```powershell
cd mobile
npx --yes eas-cli@21.8.0 login
npx --yes eas-cli@21.8.0 build:configure
npx --yes eas-cli@21.8.0 build --platform android --profile preview
```

Before building, set `EXPO_PUBLIC_API_BASE_URL` to the final HTTPS PHP/API origin. The APK cannot use `localhost`, a private LAN IP, or Metro port 8081 if it is meant to work on anyone's phone. If FastAPI remains on this laptop, the deployed PHP host must reach it through a secured private tunnel and set `CIVICCONNECT_AI_URL` plus the same `CIVICCONNECT_AI_TOKEN` configured for FastAPI. The mobile app never connects directly to FastAPI or MariaDB.

EAS builds do not use the ignored local `.env` file. Set the build environment explicitly, then build:

```powershell
npx --yes eas-cli@21.8.0 env:set --environment preview --name EXPO_PUBLIC_API_BASE_URL --value http://192.168.1.13 --visibility plaintext
npx --yes eas-cli@21.8.0 build --platform android --profile preview
```

The repository's current preview variable points at the laptop LAN address for the local demonstration. Replace it with the deployed HTTPS origin before distributing the APK outside that Wi-Fi network.

The local preview build enables Android cleartext traffic because the demonstration API is served over `http://192.168.1.13`. This is required for a physical phone to connect to the laptop over the same Wi-Fi; the setting is applied to the installed Android APK by `expo-build-properties`. For a public or store build, use an HTTPS API origin and remove that preview-only allowance before building.

See [`../MOBILE_MIGRATION_BLUEPRINT.md`](../MOBILE_MIGRATION_BLUEPRINT.md) for the full migration contract, sequencing, security rules, and Definition of Done.
