# CivicConnect knowledge playbook

This is the working knowledge base for explaining CivicConnect in an SIH pitch, presentation, demo, or technical review.

## The story in one paragraph

People do not experience a civic issue as a database row: they see a dangerous pothole, a blocked drain, or a garbage pile. CivicConnect converts that observation into structured evidence, uses AI to support classification and severity assessment, groups nearby corroborating reports, shows the city-wide signal on an interactive map, and connects the issue to an administrator and field worker. Citizens see what is happening instead of submitting into a black hole.

## The problem in plain language

- The same civic issue may be reported many times without being consolidated.
- A photograph and location are useful evidence, but they are often not structured for action.
- Citizens do not know whether a report is pending, assigned, or resolved.
- Administrators need to understand concentration and priority across the city.
- Field workers need explicit work ownership and a way to ask for more work.

## Current product answer

```text
Citizen photo + location
          |
          v
AI-assisted classification and severity
          |
          v
Nearby same-category reports become one issue cluster
          |
          v
Feed + City Pulse heatmap + priority signal
          |
          v
Admin assignment <-> Worker execution
          |
          v
Citizen-visible status, worker, and admin
```

## What makes the prototype credible

1. The heatmap is database-backed and not a hardcoded demo dataset.
2. The same canonical category/status vocabulary is used in SQL, PHP, API responses, forms, and map filters.
3. The role model is enforced at the server boundary, not only by hiding buttons.
4. The database stores individual submissions separately from grouped operational issues.
5. AI decisions are stored as auditable analyses with confidence and model version.
6. Assignments, work requests, and status changes have accountable actors.

## How to explain the AI

Say: “AI assists intake by suggesting category, severity, confidence, and possible manipulation. The platform stores that decision and uses it to improve triage.”

Do not say: “AI autonomously decides what the municipality must do.” The current service is decision support. A production system needs confidence thresholds, correction feedback, monitoring, and a human review path.

## How to explain the heatmap

Say: “City Pulse turns grouped issue data into a spatial operating picture. The operator can filter, search, fit the map, select a cluster, and open the corresponding community record.”

Do not say: “The map predicts future problems.” The current map describes current database signals. Prediction is a roadmap extension.

## Role language

Use “administrator” for the account that allocates and reviews work. Use “field worker” for the person executing assigned work. Use “citizen” for the reporting and tracking user. Avoid describing “authority” as a fourth role; the old authority route is only a compatibility redirect.

## Recommended demo sequence

1. Show live totals on the landing page.
2. Open the Community Feed and point out grouped report counts and priority.
3. Open City Pulse and filter to one category.
4. Sign in as Citizen and submit evidence.
5. Sign in as Admin and allocate the issue.
6. Sign in as Worker and complete the assignment.
7. Return to the feed and show transparent assignment information.

## Archived innovation gap and honest positioning

The comparative study identified a crowded baseline of photo/GPS reporting, dashboards, maps, AI classification, duplicate grouping, upvotes, notifications, routing, SLA, PWA, voice, and regional language. Its strongest gap areas were omnichannel no-download intake, impact-based priority, verified resolution, recurrence/root-cause intelligence, predictive hotspots, predictive SLA, and anti-spam trust.

The safest current positioning is:

> **From civic complaint management to evidence-backed civic resolution intelligence.**

CivicConnect already demonstrates the evidence, grouping, spatial signal, and role-coordinated resolution foundation. It should present predictive intelligence, omnichannel access, and citizen verification as the next evolution.

## Questions judges may ask

### Why is this different from a complaint portal?

Because the unit of action is a grouped, evidence-backed issue connected to an admin/worker workflow, not an isolated form submission. The heatmap exposes concentration and the feed exposes accountability.

### What if many people report the same pothole?

Nearby same-category submissions are matched using a geohash prefix and attached as reports/evidence to one canonical issue. This preserves corroboration without multiplying operational posts.

### Can a worker change arbitrary issues?

No. Worker status changes require the worker role and an active assignment for the issue. Admin operations are separate and broader.

### How is abuse handled?

Authenticated actions, image validation, AI manipulation signals, upvotes, fake flags, rate-limited login, CSRF, and status history provide a foundation. Reputation and moderation are future hardening work.

### Does the system work if AI is down?

The current UI reports a recoverable service error. A production version should queue analysis or provide a controlled manual classification fallback.

## Metric vocabulary

- **Report:** one citizen submission.
- **Issue:** canonical grouped operational record.
- **Cluster:** map/feed representation of a grouped issue.
- **Corroboration:** additional reports and upvotes supporting an issue.
- **Priority:** a computed signal used to order attention, not a statutory SLA.
- **Assignment:** admin-to-worker allocation.
- **Work request:** worker request awaiting admin review.

## Do-not-overclaim list

Do not claim production-scale municipal deployment, guaranteed resolution, predictive forecasting, automatic departmental routing, WhatsApp/IVR support, citizen verification, or SLA escalation unless those features are added and tested.

## Knowledge-base maintenance

When scope changes, update `PROJECT_REPORT.md`, this playbook, `rules/requirements.md`, the API/data blueprints, and the pitch/presentation documents together. Keep the SQL schema, category vocabulary, role vocabulary, and endpoint names synchronized.
