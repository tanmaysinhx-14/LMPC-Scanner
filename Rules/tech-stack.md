# CivicConnect technology stack

## Current backend and web stack

| Layer | Technology | Status |
|---|---|---|
| Web runtime | PHP 8.1+ | Current |
| Server-rendered UI | PHP templates, Bootstrap 5.3.3, shared CSS/JavaScript | Current |
| Database | MariaDB/MySQL through PDO | Current source of truth |
| Web authentication | PHP sessions, password hashing, CSRF, remember tokens | Current browser contract |
| Mapping | MapLibre GL JS 4.7.1, CARTO/OSM-compatible styles and attribution | Current web map |
| AI service | FastAPI, Uvicorn, PyTorch, Ultralytics | Current |
| AI model | YOLO object detector; current checkpoint has `pothole`, `garbage`, `graffiti` | Current |
| AI preview | Server-generated annotated JPEG data URL plus structured detections | Current |
| Grouping | Self-contained geohash helper under `functions/location/` | Current |
| Schema/seed | `assets/civicconnect.sql` | Current reference artifact |

## Chosen mobile stack

| Layer | Decision | Reason |
|---|---|---|
| Mobile framework | Expo SDK 57 with React Native and TypeScript | Reuses the project's JavaScript/TypeScript direction, supports Android and iOS, and reduces native build/setup overhead |
| Navigation | Expo Router | File-based, typed, deep-linkable route structure suited to citizen, worker, and admin route groups |
| Native builds/release | EAS Build, EAS Submit, and EAS Update | Repeatable Android/iOS development, preview, and store builds |
| Map | `@maplibre/maplibre-react-native` v11+ | Preserves the existing MapLibre direction and supports native map layers, markers, querying, and styles |
| Camera/media | `expo-image-picker`, with `expo-camera` only if an in-app camera surface is needed | Uses native camera/library permissions and produces a local URI for immediate preview |
| Location | `expo-location` | Native foreground location permission and coordinate acquisition |
| Notifications | `expo-notifications` | Native push-token and notification handling; delivery still needs a server/provider integration |
| Secrets | `expo-secure-store` | Stores refresh credentials in platform-protected storage rather than ordinary app storage |
| Local persistence | `expo-sqlite` for a later cache/outbox phase | Enables controlled offline reads and queued writes without making offline behavior a Phase 1 blocker |
| Server data | Versioned PHP JSON/multipart APIs with TanStack Query on mobile | Reuses the current backend while adding cache, retry, and invalidation behavior for mobile screens |
| Validation | TypeScript types generated or maintained from the API contract plus Zod at input boundaries | Prevents web/mobile payload drift and keeps camera/GPS/upload validation explicit |
| Testing | Jest/React Native Testing Library for units/components, API contract tests, and device smoke/E2E tests | Covers both native interaction and backend compatibility |

### Why this stack

Expo is the recommended fit for this repository because the existing product is JavaScript-heavy, the migration needs camera/location/notifications quickly, and the first mobile release should preserve the PHP backend. Expo Router supplies the navigation model and EAS supplies repeatable builds. MapLibre React Native keeps City Pulse aligned with the existing MapLibre web implementation.

This is a project decision, not a universal claim that Expo is best for every mobile app. Flutter is not selected because it introduces a second language and UI ecosystem for a project whose existing client and API integration are JavaScript/PHP. Ionic/Capacitor is not selected because the core citizen experience needs native camera, GPS, notification, secure-storage, and map behavior rather than a wrapped browser surface. Bare React Native CLI is a viable fallback if a native dependency cannot work with Expo development builds, but it adds setup and release work before the product workflow is proven.

## Version policy

- Start with the current Expo SDK 57 template and pin the exact SDK/package versions in `mobile/package.json`.
- Use `npx expo install` for Expo modules so compatible versions are selected together.
- MapLibre React Native v11 requires the React Native New Architecture and a native rebuild; use a development build, not Expo Go, for map integration.
- Re-check official release notes before every SDK upgrade, store submission, or native dependency upgrade.
- Do not put API keys, signing credentials, database credentials, model files, or refresh tokens in the mobile repository.

## Official implementation references

- [Expo Router](https://docs.expo.dev/router/introduction/)
- [EAS Build](https://docs.expo.dev/build/introduction/)
- [Expo ImagePicker](https://docs.expo.dev/versions/latest/sdk/imagepicker/)
- [Expo Location](https://docs.expo.dev/versions/latest/sdk/location/)
- [Expo Notifications](https://docs.expo.dev/versions/latest/sdk/notifications/)
- [Expo SecureStore](https://docs.expo.dev/versions/latest/sdk/securestore/)
- [Expo SQLite](https://docs.expo.dev/versions/latest/sdk/sqlite/)
- [MapLibre React Native setup](https://maplibre.org/maplibre-react-native/docs/setup/getting-started/)
- [React Native New Architecture](https://reactnative.dev/architecture/landing-page)
