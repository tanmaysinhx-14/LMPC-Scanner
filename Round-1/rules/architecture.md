# CivicConnect architecture and migration boundary

## Current system

```text
Browser web UI
    -> server-rendered PHP pages and progressive-enhancement JavaScript
    -> PHP JSON endpoints and shared domain helpers
    -> MariaDB/MySQL

PHP issue upload endpoints
    -> FastAPI AI service
    -> Ultralytics YOLO object detector
    -> structured detections and optional annotated preview

City Pulse web page
    <- database-backed heatmap/stat endpoints
    <- MariaDB grouped issue read models
```

The current prototype is a working web client, not a mobile client. The web UI remains the reference implementation while the native client is introduced incrementally.

## Target system after migration

```text
Expo React Native mobile app (TypeScript)
    -> versioned bearer-authenticated mobile API: /api/v1/*
    -> shared PHP domain functions and authorization policies
    -> MariaDB/MySQL

Mobile and web report flows
    -> PHP upload/analysis boundary
    -> FastAPI detector
    -> category, severity, detections, bounding boxes, annotated preview

Mobile and web City Pulse
    <- live heatmap/stat APIs
    <- MapLibre web or MapLibre React Native renderer
```

## Layer ownership

### Client layer

- `pages/` remains the browser client during the migration.
- `mobile/` will contain the Expo Router application once scaffolding begins.
- Clients render state and collect input. They never connect directly to MariaDB or decide authorization.
- Client-specific navigation and presentation may differ, but category, role, status, issue, report, and detection vocabulary must stay shared.

### API/application layer

- Existing PHP endpoints remain backward-compatible for the web client.
- New mobile endpoints should be versioned under `/api/v1/` and use JSON plus multipart upload contracts designed for native clients.
- Shared validation, grouping, priority, assignment, and authorization logic belongs in `functions/`, not duplicated inside mobile endpoints.
- Native authentication must use bearer access tokens and rotating refresh tokens; do not make a mobile app depend on PHP browser cookies or CSRF tokens.

### Data layer

- MariaDB/MySQL remains the source of truth.
- `issues` is the canonical grouped operational record.
- `issue_reports` preserves each citizen submission.
- `issue_images` and `issue_ai_analyses` preserve evidence and AI audit data.
- New mobile-only tables, such as refresh-token and device-push-token storage, require explicit SQL migrations and indexes.

### AI layer

- `ai-service/main.py` loads the current detector at `civic-dataset/runs/detect/train/weights/best.pt` when present, with a previous-checkpoint fallback and deployment-path override.
- The detector currently exposes `pothole`, `garbage`, and `graffiti` categories.
- `/analyze` returns structured detections with confidence and `xyxy` bounding boxes; `include_preview=true` adds an annotated image data URL.
- Preview output is informational. Final submission always re-analyzes the stored file on the server.
- AI remains decision support. Low confidence, unavailable service, and manipulation signals must be visible and recoverable.

### Mapping layer

- Web City Pulse continues to use MapLibre GL JS.
- The mobile client should use MapLibre React Native with the same style, attribution, category vocabulary, and database-backed heatmap data.
- Map tiles, styles, and public coordinate precision are product configuration; they are not hardcoded as a new mobile-only source of truth.

## Migration rules

1. Build the mobile client as a new `mobile/` application; do not rewrite the working PHP web client in place.
2. Keep the backend and SQL schema authoritative while the mobile API contract is introduced.
3. Version native-client endpoints under `/api/v1/`; do not silently change the browser endpoint contract.
4. Reuse PHP domain functions for grouping, priority, assignments, status transitions, and role checks.
5. Keep the admin web workspace during the first mobile release; add an admin mobile surface only after citizen and worker workflows are stable.
6. Keep AI inference server-side initially. Do not ship the model inside the app until latency, privacy, binary size, and device support are separately evaluated.
7. Every migration phase must have a working web fallback, an API contract test, and a rollback path.
