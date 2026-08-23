# CivicConnect knowledge playbook: current product and mobile migration

This is the working knowledge base for explaining CivicConnect in an SIH pitch, presentation, demo, technical review, or migration discussion.

## The story in one paragraph

People do not experience a civic issue as a database row: they see a dangerous pothole, a garbage pile, or graffiti on public property. CivicConnect converts that observation into structured evidence, uses server-side AI to suggest categories and highlight detected objects, groups nearby corroborating reports, shows the city-wide signal on an interactive map, and connects the issue to an administrator and field worker. The current web prototype proves the workflow; the mobile migration will make capture and execution native without replacing the backend of record.

## Current product answer

```text
Citizen photo + location
          |
          v
Server AI detection + annotated preview
          |
          v
Nearby same-category reports become one issue cluster
          |
          v
Feed + City Pulse map + priority signal
          |
          v
Admin assignment <-> Worker execution
          |
          v
Citizen-visible status, worker, and admin
```

The current AI checkpoint detects `pothole`, `garbage`, and `graffiti`. The FastAPI service returns detections, confidence, bounding boxes, severity, manipulation signals, and an optional annotated JPEG data URL. The preview helps a person understand the model; it is not an autonomous decision.

## What makes the prototype credible

1. The heatmap reads live grouped records from the database, not a runtime mock fixture.
2. The same category/status vocabulary is used in SQL, PHP, APIs, forms, and map filters.
3. The three-role model is enforced at the server boundary.
4. Individual submissions are preserved separately from grouped operational issues.
5. AI decisions are stored as auditable analyses with confidence and model version.
6. Assignments, work requests, and status changes have accountable actors.
7. Final issue persistence re-runs AI on the server rather than trusting browser preview fields.

## The migration decision

Use an Expo React Native TypeScript app under `mobile/`, with Expo Router, EAS Build/Submit/Update, MapLibre React Native, Expo camera/media/location/notifications/secure-storage modules, and a versioned PHP `/api/v1/` contract. Keep PHP/MariaDB/FastAPI as the backend and keep the admin web workspace until mobile parity is proven.

The migration is incremental:

```text
Freeze contracts -> scaffold mobile -> add native auth API
    -> citizen report MVP -> feed/map -> worker operations
    -> notifications/offline hardening -> beta -> store release
```

The exact phase checklist is in `MOBILE_MIGRATION_BLUEPRINT.md`.

## How to explain the AI

Say: “AI assists intake by detecting potholes, garbage, or graffiti, suggesting severity and confidence, and showing where the model found an object. The server re-analyzes the final upload and stores the decision for audit.”

Do not say: “AI autonomously decides what the municipality must do.” The current service is decision support. A production system needs confidence thresholds, correction feedback, monitoring, and human review.

## How to explain the mobile app

Say: “The mobile app is a new client for the existing workflow. It uses native camera and GPS capture, a secure token session, a server API, and the same database-backed issue model. The web/admin surface remains during migration so operations do not depend on an unproven mobile build.”

Do not say: “The native app is already released,” “it works offline,” or “push notifications are live” until those capabilities are implemented and tested on physical devices.

## How to explain the heatmap

Say: “City Pulse turns grouped issue data into a spatial operating picture. Web and mobile clients use MapLibre renderers over the same live API data; operators can filter, search, select a dense area, and open the corresponding community record.”

Do not say: “The map predicts future problems.” The current map describes current database signals. Prediction is a later capability.

## Role language

Use “administrator” for the account that allocates and reviews work. Use “field worker” for the person executing assigned work. Use “citizen” for the reporting and tracking user. Avoid describing Authority as a fourth role; the old authority route is only a compatibility redirect.

## Recommended current web demo

1. Show live totals on the landing page.
2. Open Community Feed and point out grouped report counts and priority.
3. Open City Pulse and filter one category.
4. Sign in as Citizen and upload evidence to show the annotated AI preview.
5. Sign in as Admin and allocate the issue.
6. Sign in as Worker and update or complete the assignment.
7. Return to the feed and show transparent assignment information.
8. Show the migration blueprint and explain that the same flow is being moved into a native client.

## Questions judges may ask

### Why is this different from a complaint portal?

Because the unit of action is a grouped, evidence-backed issue connected to an admin/worker workflow, not an isolated form submission. The heatmap exposes concentration and the feed exposes accountability.

### What if many people report the same pothole?

Nearby same-category submissions are matched using the current geohash-prefix grouping policy and attached as reports/evidence to one canonical issue. This preserves corroboration without multiplying operational posts.

### Why migrate if the web app already works?

The backend workflow is reusable, but camera/GPS capture, field-worker updates, notifications, and touch interaction are better delivered through a native client. The migration is incremental so the proven web/admin workflow remains a fallback.

### How will a mobile user authenticate?

The browser keeps PHP sessions and CSRF. The planned mobile API uses short-lived bearer access tokens and rotating refresh tokens; only the refresh credential is kept in platform secure storage.

### Does the system work if AI is down?

The current UI shows a recoverable AI error. The mobile contract should preserve that state and, after a deliberate product decision, support controlled manual classification or queued retry rather than silently inventing a result.

## Metric vocabulary

- **Report:** one citizen submission.
- **Issue:** canonical grouped operational record.
- **Cluster:** map/feed representation of a grouped issue.
- **Corroboration:** additional reports and upvotes supporting an issue.
- **Detection:** one model output with category, confidence, and bounding box.
- **Annotated preview:** a visual explanation of model detections; not a persistence authority.
- **Priority:** a computed signal used to order attention, not a statutory SLA.
- **Assignment:** admin-to-worker allocation.
- **Work request:** worker request awaiting admin review.
- **Mobile API:** versioned bearer-authenticated contract for the native client.

## Do-not-overclaim list

Do not claim production-scale municipal deployment, guaranteed resolution, predictive forecasting, automatic departmental routing, WhatsApp/IVR support, citizen verification, offline writes, push delivery, or SLA escalation unless those features are added and tested.

## Knowledge-base maintenance

When scope changes, update `MOBILE_MIGRATION_BLUEPRINT.md`, this playbook, `rules/requirements.md`, `rules/architecture.md`, `rules/tech-stack.md`, `rules/api-spec.md`, `rules/database.md`, and the pitch/presentation documents together. Keep the SQL schema, category vocabulary, role vocabulary, endpoint names, AI response fields, and mobile route plan synchronized.
