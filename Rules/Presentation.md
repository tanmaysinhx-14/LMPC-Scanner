# CivicConnect presentation plan: current web prototype and mobile migration

## Recommended ten-slide deck

### 1. Title

CivicConnect - evidence-backed civic resolution intelligence. Show the current City Pulse visual and state that the next delivery is a native mobile client on the same backend.

### 2. The problem

Show potholes, garbage, graffiti, damaged roads, blocked drains, and waterlogging alongside the fragmented reporting-to-resolution gap.

### 3. Why existing complaint flows fall short

Explain duplication, weak evidence structure, unclear priority, opaque assignment, and lack of spatial context. Keep comparisons grounded in the archived study.

### 4. The current web solution

Show: report -> server AI assist -> group -> prioritise -> assign -> execute -> update -> transparent status.

### 5. The mobile migration goal

Show the same flow on a phone: capture photo -> obtain GPS -> see local preview -> receive annotated AI preview -> confirm -> submit -> track. Say “planned mobile client” until the native build is tested.

### 6. City Pulse and shared backend

Show the database-backed MapLibre page and explain that the planned mobile map will consume the same live API data through MapLibre React Native.

### 7. Three-role operating model

```text
Citizen                 Admin                    Worker
Report/track            Review/allocate          Execute/complete
Corroborate             Review requests          Request work
See assignment          See city picture         Update assignment
```

### 8. Technology and trust

Show PHP + MariaDB, versioned JSON/multipart APIs, FastAPI + Ultralytics detector, Expo/React Native + TypeScript, MapLibre, geohash grouping, bearer auth for mobile, CSRF for web, transactions, prepared SQL, and audit tables.

### 9. Impact and migration roadmap

Use pilot metrics: acknowledgement time, grouping rate, assignment rate, completion time, classification quality, upload success, and citizen visibility. Show the four migration phases from `MOBILE_MIGRATION_BLUEPRINT.md`.

### 10. Ask/closing

Close with the one-line pitch and the pilot request: a ward-level dataset, worker/admin validation, Android testers, an HTTPS API environment, and outcome measurement.

## Slide design rules

- One message per slide.
- Prefer real product screenshots and a simple current-to-target architecture diagram.
- Use real database-backed demo numbers and label them as prototype data.
- Keep “implemented now”, “migration in progress”, and “roadmap” visually separate.
- Do not show a mobile screenshot until it is produced by a tested mobile build.
- Use accessible contrast, large labels, and no dense unreadable architecture diagram.
