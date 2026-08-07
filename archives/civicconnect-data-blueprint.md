# CivicConnect data blueprint

This document describes the current database-backed implementation. The SQL source of truth is `assets/civicconnect.sql`; `assets/mock-heatmap.json` is no longer part of the heatmap data path.

## Canonical issue flow

1. An authenticated citizen submits an image, GPS/location, category, and description to `api/issues/submit.php`.
2. PHP validates the request, CSRF token, image signature/MIME/size, and input lengths.
3. The Python AI service returns normalized category, severity, confidence, manipulation status, model version, and raw output.
4. PHP searches for a nearby issue with the same category using a geohash prefix.
5. A match adds an `issue_reports` row and evidence to the canonical `issues` record; otherwise a new canonical issue is created.
6. The report, image, AI analysis, and related fields are persisted transactionally.
7. `assignments`, `work_requests`, and `status_history` extend the issue into an admin/worker workflow.

## Tables

| Table | Meaning |
|---|---|
| `users` | Three-role identity and account state. |
| `issues` | Grouped operational issue and priority/location/status read model. |
| `issue_reports` | Individual citizen evidence submissions. |
| `issue_images` | Evidence metadata and storage reference. |
| `issue_ai_analyses` | Auditable AI outputs. |
| `assignments` | Admin-to-worker allocation and completion. |
| `work_requests` | Worker requests plus admin review. |
| `status_history` | Status transition audit trail. |
| `upvotes` | Community corroboration. |
| `fake_flags` | Potentially unreliable issue flags. |
| `auth_login_attempts` | Login rate limiting. |
| `remember_tokens` | Hashed persistent-login sessions. |

## Heatmap read model

`GET /api/stats/heatmap.php` reads live issue data from MariaDB/MySQL, groups the visible issues by geohash/location, and returns points containing coordinates, count, category, status, severity, priority/weight, title, address, and density band. The initial bands are:

- green: 1–2 reports;
- yellow: 3–5 reports;
- red: 6 or more reports.

The dedicated `pages/heatmap/index.php` page consumes this response with MapLibre GL. It is not Leaflet. The compact map on `pages/citizen/public-feed.php` uses the same database endpoint.

## Assignment model

`assignments.issue_id` identifies the canonical issue, `worker_id` identifies the executor, and `assigned_by` identifies the administrator. `completed_at` records completion. `work_requests` allows a worker to request either a specific issue or general work; an admin records approval/decline, reviewer, note, and time.

## Privacy and integrity

Coordinates are operationally sensitive. Public responses should expose only the precision required for the product experience. Prepared statements, role checks, CSRF validation, transactions, and escaped rendering are required for every new data path.
