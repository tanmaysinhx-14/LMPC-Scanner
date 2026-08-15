# CivicConnect problem statement and mobile evolution

## Urban challenge

Citizens encounter potholes, damaged roads, garbage accumulation, graffiti, waterlogging, open drains, broken streetlights, encroachment, fallen trees, and other public infrastructure failures every day. Reporting is often fragmented across phone calls, social media, messaging groups, and disconnected portals. The same problem may be reported repeatedly while other problems remain invisible to decision-makers.

The operational gap is not only “how to submit a complaint.” It is the lack of a shared evidence-to-action workflow:

```text
Observe -> report -> capture evidence -> analyze -> corroborate
    -> prioritize -> allocate -> execute -> update -> learn
```

Citizens need acknowledgement and visibility. Administrators need a trusted city-wide backlog and spatial priorities. Field workers need clear assignments, notes, and a way to communicate their capacity. A useful solution must connect these perspectives without requiring the citizen to understand municipal department structures.

## Current CivicConnect response

CivicConnect is a browser-based, database-backed civic issue reporting and work-coordination prototype. Citizens submit a photo, description, category, and location. The platform validates the evidence, calls a FastAPI Ultralytics YOLO detector for category/severity/confidence/manipulation analysis, returns visible bounding boxes and an annotated preview when available, groups nearby reports of the same category, and calculates a priority signal.

The current trained detector has three classes: `pothole`, `garbage`, and `graffiti`. The model runs server-side; the browser never receives the model file and final persistence always re-analyzes the stored upload.

The role model has three active roles:

- **Citizen:** submit and follow reports, corroborate issues, and view assignment/status information.
- **Worker:** view allocated work, request work, update assigned issues, and mark assignments complete.
- **Admin:** view city-wide problems, allocate work, review worker requests, and coordinate status changes.

## Mobile migration problem

The web prototype proves the backend and workflow, but citizens and field workers need camera-first, GPS-aware, touch-friendly access. A direct rewrite would duplicate security, grouping, priority, AI, and audit logic. The migration must therefore move the client experience while preserving the PHP/MariaDB/FastAPI system of record.

The target is one role-aware Expo React Native TypeScript app under `mobile/`, backed by versioned `/api/v1/` endpoints. The existing web and admin surfaces remain available during the transition.

## Objectives

1. Preserve one canonical issue/report/evidence model across web and mobile.
2. Let a citizen capture an image, obtain GPS, see AI detections, and submit from a phone.
3. Give workers an efficient mobile assignment/status workflow.
4. Keep City Pulse database-backed and consistent between MapLibre web and mobile maps.
5. Add native authentication without weakening current browser sessions and CSRF protections.
6. Provide a staged path to push notifications and carefully designed offline support.
7. Keep every migration step testable, reversible, and honest about what is live.

## Scope boundary

The current prototype does not yet implement the native app, mobile bearer-token API, push delivery, offline writes, citizen resolution verification, SLA escalation, predictive forecasting, WhatsApp/IVR, regional-language voice intake, or external municipal integrations. These are migration or roadmap items, not current claims.

## Expected impact

The migration should make evidence capture faster, reduce failed/duplicate submissions, improve worker response time, and preserve citizen visibility. Measure acknowledgement time, grouping rate, assignment rate, completion time, AI detection quality, upload success, notification delivery, and mobile task completion - not only app installs.
