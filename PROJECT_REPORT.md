# CivicConnect: Consolidated Project Report

**Project context:** Smart India Hackathon prototype | **Current scope:** database-backed civic reporting, AI-assisted issue intelligence, interactive city heatmap, and three-role work coordination | **Last reviewed:** 7 August 2026

## 1. Executive summary

CivicConnect is a web platform for turning citizen observations into actionable municipal work. A citizen submits a civic issue with a photograph, description, category, and location. The platform validates the submission, uses an AI service to analyse the image, groups nearby reports of the same problem, calculates a priority signal, and exposes the result through a transparent community feed and an interactive city map. An administrator can review the city-wide backlog, allocate issues to field workers, and review worker requests. A worker can see assigned work, request additional work, and mark an assignment complete. Citizens can see the issue status, assigned worker, and administrator who allocated the work.

The product is intentionally positioned beyond a generic complaint form: **CivicConnect is a community evidence and civic-work coordination layer.** Its strongest presentable differentiators are the combination of AI-assisted intake, geographic grouping, community corroboration, live spatial intelligence, and an explicit Citizen–Worker–Admin workflow in one lightweight web application.

The current implementation is a prototype. It does not yet claim WhatsApp/IVR reporting, citizen resolution verification, SLA automation, predictive maintenance, recurring-issue forecasting, or production municipal integrations. Those are documented as roadmap opportunities rather than implemented features.

## 2. Smart India Hackathon framing

### Problem

Urban civic problems such as potholes, road damage, garbage accumulation, open drains, waterlogging, damaged streetlights, encroachment, graffiti, fallen trees, and similar infrastructure failures are often reported through fragmented channels. Reports can be duplicated, poorly classified, difficult to prioritise, and opaque after submission. Citizens do not know whether a problem was acknowledged, who owns the work, or whether it was completed. Municipal teams lack one shared view of the spatial concentration and evidence behind complaints.

### Proposed solution

CivicConnect provides a closed-loop digital workflow:

```text
Citizen evidence
      |
      v
Validate -> AI analyse -> Group nearby reports -> Prioritise
      |                                      |
      +-------------------------------> Admin review
                                             |
                              Allocate / approve worker requests
                                             |
                                             v
                                   Worker executes the work
                                             |
                                             v
                             Status and assignment visible to citizens
```

### SIH-aligned value

- **Transparency:** issue status, evidence count, upvotes, assignment, and assigning administrator are visible in the feed.
- **Efficiency:** nearby reports are consolidated and prioritised instead of creating an entirely separate operational item for every submission.
- **Accountability:** assignments, work requests, and status changes are tied to authenticated accounts and status history.
- **Data-driven governance:** the feed, city statistics, category distribution, and heatmap show where problems are concentrated.
- **Accessibility of deployment:** the prototype uses a browser-based flow and does not require a native application install.

## 3. Product scope

### Implemented capabilities

1. Public landing page with live database-backed issue, resolution, and resolution-rate metrics.
2. Account creation for the three supported roles: Citizen, Worker, and Admin.
3. Secure login, logout, remember-me sessions, password hashing, CSRF protection, session refresh, and login-rate limiting.
4. Citizen issue submission with photograph, description, GPS/location, category, and optional details.
5. Server-side image validation, generated upload paths, SHA-256 metadata, and transactional persistence.
6. Python AI service integration for category, severity, confidence, manipulation checks, model version, and raw analysis storage.
7. Canonical issue creation and nearby same-category grouping using geohash prefixes.
8. Public community feed with search, category/status filters, sorting, upvotes, image/report counts, issue details, and pagination.
9. Database-backed MapLibre city pulse page with category/status filters, cluster list, search, fit-to-data, geolocation control, density glow, and issue popups.
10. Compact MapLibre preview on the community feed.
11. Admin dashboard with city-wide visibility and work-management tools.
12. Admin assignment of issues to active workers, with notes and assignment history.
13. Worker assignment view with status updates, completion action, and work-request form.
14. Admin review of worker requests with approve/decline decisions and review notes.
15. Citizen-facing visibility of assignment state, assigned worker, assigning administrator, and completion state.
16. Profile and password-change pages for all three roles.
17. Compatibility redirects for retired authority and legacy password routes.

### Deliberately not implemented yet

The archived SIH comparison identified several valuable extensions. They should be described as future work, not as current features:

- WhatsApp, SMS, IVR, QR, voice, and regional-language intake.
- Citizen confirmation of resolution, before/after evidence, and dispute/reopen flow.
- Automated SLA timers, escalation rules, departmental routing, and external municipal work-order integrations.
- Recurring issue detection, root-cause analysis, predictive hotspots, predictive SLA, and budget/maintenance forecasting.
- Advanced anti-spam reputation, device intelligence, and human moderation queues.
- Native mobile/PWA offline mode and push notifications.

## 4. Users and role model

### Citizen

The citizen creates an account, reports a problem, attaches evidence, sees AI feedback, browses the community feed, upvotes corroborating reports, views the heatmap, and tracks status and assignment information. A citizen cannot allocate work or change an issue into an operational state directly.

### Worker

The worker has a separate account and workspace. The worker sees assignments made by an administrator, can move assigned work through the permitted operational statuses, can mark work completed, and can request work from an administrator. A worker cannot assign work to another worker and cannot perform administrative review.

### Admin

The administrator sees the city-wide issue picture, reviews priorities, assigns issues to active workers, approves or declines work requests, and can update issue status. Admin actions are recorded against the authenticated administrator. The first admin can bootstrap the system; subsequent staff-account creation is restricted to an admin or a configured staff registration key.

### Authority compatibility

The former authority route is retained only as a compatibility redirect. Authority is not a fourth active role. The supported role enum is exactly `citizen`, `worker`, and `admin`.

## 5. Core user journeys

### Citizen reporting journey

1. Open the landing page or registration page.
2. Create a Citizen account and sign in.
3. Open **Report Issue**.
4. Allow location access or refresh GPS and add a photograph.
5. Enter/select the issue category and optional description.
6. Review the AI category/severity/confidence result.
7. Submit the report.
8. PHP validates and stores the evidence, creates or joins a nearby issue cluster, and returns the result.
9. Follow the issue in the dashboard or public feed.

### Admin allocation journey

1. Sign in as Admin.
2. Review city statistics, priority issues, and the interactive heatmap.
3. Open Work Management.
4. Select an unassigned issue and an active worker.
5. Add an operational note and assign the work.
6. The issue moves to the appropriate workflow state and the assignment is visible to the citizen.
7. Review incoming worker requests and approve or decline them with a note.

### Worker completion journey

1. Sign in as Worker.
2. Review assigned issues, locations, priorities, and admin notes.
3. Update the permitted status while executing the work.
4. Mark the assignment complete.
5. The citizen feed shows the worker, administrator, and completion state; the issue status is kept consistent with the assignment workflow.

## 6. Functional architecture

```text
Browser pages (PHP + Bootstrap + custom CSS)
        |
        +--> PHP session/auth + CSRF + role checks
        |
        +--> JSON APIs (issues, work, stats, auth)
                      |
                      +--> MariaDB / MySQL civicconnect database
                      |
                      +--> Python AI service (HTTP)
                      |       +--> ONNX model
                      |       +--> image validation/inference helpers
                      |
                      +--> MapLibre GL + CARTO raster tiles
```

### Request boundaries

- `bootstrap.php` loads database, authentication, validation, issue, location, AI, utility, response, and toast helpers.
- PHP pages handle server-rendered views and session-protected forms.
- JSON endpoints use consistent response helpers and enforce role/CSRF rules for state-changing operations.
- The AI service is a separate process and is called by PHP during issue analysis/submission.
- MapLibre renders the client-side interactive map; heatmap data comes from the PHP database endpoint, not from a static JSON file.

## 7. Database model

The SQL source of truth is [`assets/civicconnect.sql`](assets/civicconnect.sql). The principal tables are:

| Table | Purpose |
|---|---|
| `users` | Citizen, worker, and admin identities, role, city/ward, active state, and password hash. |
| `issues` | Canonical grouped civic issue, location, category, severity, status, priority, AI confidence, and verification flags. |
| `issue_reports` | Every citizen submission, including reports grouped into an existing issue. |
| `issue_images` | Evidence metadata and stored upload path for each image. |
| `issue_ai_analyses` | Auditable AI category, severity, confidence, manipulation result, model version, and raw output. |
| `assignments` | Admin-to-worker allocation, notes, timestamps, and completion state. |
| `work_requests` | Worker requests for work and admin review decisions. |
| `status_history` | Who changed an issue status, from which state to which state, and the note/time. |
| `upvotes` | One-user-per-issue community corroboration records. |
| `fake_flags` | Authenticated reports of potentially unreliable issues. |
| `auth_login_attempts` | Login-rate limiting state. |
| `remember_tokens` | Hashed persistent-login tokens. |

### Canonical issue categories

`pothole`, `garbage`, `streetlight`, `waterlogging`, `road_damage`, `encroachment`, `graffiti`, `open_drain`, `fallen_tree`, `other` (with `unknown` retained for AI/legacy compatibility where applicable).

### Status model

The database supports `pending`, `acknowledged`, `in_progress`, `resolved`, and `rejected`. Public UI labels are derived from the canonical values. Workers are restricted to valid operational transitions; admins have broader control. Resolving an issue through the admin path closes its active assignments, preventing an apparently completed issue from retaining open work.

### Grouping model

On submission, the server normalizes the category and searches for a nearby issue with the same category using a geohash prefix. A matching issue receives another `issue_reports` row and image; otherwise a new canonical `issues` row is created. This preserves individual evidence while giving the city one operational cluster.

## 8. API surface

| Endpoint | Method | Purpose | Access |
|---|---|---|---|
| `api/auth/login.php` | POST | Authenticate a user and establish a session. | Public |
| `api/auth/logout.php` | POST/GET flow | End the current session. | Authenticated |
| `api/auth/session.php` | GET | Read session state. | Public/auth-aware |
| `api/issues/analyze.php` | POST | Request image analysis. | Citizen workflow |
| `api/issues/submit.php` | POST | Persist an issue report and evidence. | Citizen + CSRF |
| `api/issues/list.php` | GET | Return priority-ordered issue feed data. | Worker/admin API access |
| `api/issues/detail.php` | GET | Return issue detail, images, and history. | Authenticated/controlled |
| `api/issues/status.php` | POST/PUT | Change issue status. | Worker/admin + CSRF |
| `api/issues/upvote.php` | POST | Add/remove one user upvote. | Authenticated + CSRF |
| `api/stats/city.php` | GET | City KPIs and category counts. | Staff |
| `api/stats/heatmap.php` | GET | Database-backed geographic issue points/clusters. | Public/read-only |
| `api/work/assign.php` | POST | Allocate or manage worker assignments. | Admin + CSRF |
| `api/work/request.php` | POST | Create or cancel a worker work request. | Worker + CSRF |
| `api/work/review-request.php` | POST | Approve or decline a work request. | Admin + CSRF |

All output should be treated as untrusted at the rendering boundary. JSON values are escaped in HTML templates and client-side popup content is escaped before insertion. State-changing requests require the session CSRF token.

## 9. AI and data intelligence

The Python service uses the project ONNX model and helper modules under `ai-service/`. The PHP integration records the AI result rather than treating it as an invisible side effect. A typical result includes:

- normalized category;
- severity from 1 to 5;
- confidence;
- manipulation/suspicious-image flag;
- model version;
- raw detections/output for audit and debugging.

The AI result is decision support. A citizen may review the category in the form, and administrators remain responsible for allocation. Production deployment should add model monitoring, a confidence threshold policy, manual correction capture, and a fallback queue when the service is unavailable.

## 10. Heatmap and interactive map

The dedicated route is `pages/heatmap/index.php`. It uses MapLibre GL rather than Leaflet and loads live data from `api/stats/heatmap.php`. The page provides:

- dark, map-first “City Pulse” presentation;
- MapLibre navigation and geolocation controls;
- density halos and count labels;
- category and status filters;
- place/title search;
- cluster list synchronized with map points;
- fit-to-visible-data and reset controls;
- popup links back to the community feed;
- database source badge and loading/error state.

The feed retains a compact MapLibre preview for context. The full heatmap is the preferred presentation surface for a pitch, demo, or operations review. Coordinates are operational data and should be rounded or access-controlled in a production deployment according to the privacy policy.

## 11. Security and reliability controls

- Passwords are stored with `password_hash()` and verified with `password_verify()`.
- Login attempts are rate-limited by hashed identity state.
- Sessions are regenerated on login and can be restored only through hashed remember tokens.
- CSRF tokens protect state-changing forms and JSON requests.
- Role checks are performed server-side; hiding a button is not treated as authorization.
- Worker assignment ownership is checked before worker status changes.
- Admin-only allocation/review endpoints reject citizens and workers.
- Upload MIME/signature/size checks and generated storage paths reduce file-upload risk.
- PDO prepared statements are used for database input.
- User-visible output is escaped with `htmlspecialchars()` or equivalent client-side escaping.
- Database writes for a report and its evidence are transactional; failed persistence cleans up the uploaded file.
- Status changes and assignment actions leave an auditable record.

## 12. UI and interaction system

The operational pages use Bootstrap 5.3.3, Inter/DM Sans/Space Grotesk, and the shared `assets/css/civic-ui.css`/toast styles. The landing page keeps its marketing visual language in `assets/css/style.css`; the feed and City Pulse pages use purpose-built layouts. Theme state is synchronized through both `data-theme` (custom CSS) and `data-bs-theme` (Bootstrap), with local storage persistence.

Important UX conventions:

- citizen actions are visible only to citizens;
- worker/admin dashboard destinations are role-aware;
- status badges use the canonical status value;
- labels use the canonical category/status helpers;
- empty, loading, and error states explain what the user can do next;
- the heatmap is a dedicated destination rather than a crowded landing-page widget.

## 13. Setup and local runbook

### Prerequisites

- PHP 8.1+ with PDO MySQL and common upload/session extensions;
- MariaDB/MySQL;
- Python 3.10+ for the AI service;
- the project ONNX model under `ai-service/models/`;
- a web server or PHP built-in server configured with the project as document root.

### Database

1. Create a database named `civicconnect` (or change the application configuration).
2. Import `assets/civicconnect.sql`.
3. Confirm the `users.role` enum contains only `citizen`, `worker`, `admin`.
4. Confirm `work_requests` and assignment indexes exist.
5. Never use the sample SQL password hashes as production credentials.

### Application configuration

Database connection settings are read by the existing database helper/environment configuration. For controlled staff onboarding, set `CIVIC_STAFF_REGISTRATION_KEY`; otherwise create the first admin during bootstrap and let that admin create staff accounts.

### Start the services

```text
php -S 127.0.0.1:8087 -t .
python ai-service/main.py
```

The exact AI host/port must match the PHP AI-service configuration. If the AI service is offline, issue analysis/submission should present a clear recoverable error rather than silently inventing a classification.

## 14. Demo and pitch script outline

### Recommended five-minute demo

1. Start on the landing page and show live database metrics.
2. Open the community feed and point out grouped evidence, priority, and transparent assignment metadata.
3. Open City Pulse, filter a category, select a cluster, and jump to the feed.
4. Sign in as Citizen and submit one photo-based report; show AI analysis and location capture.
5. Sign in as Admin, show the backlog, allocate the issue to a Worker, and approve a request.
6. Sign in as Worker, open the assignment, move it through work, and mark it complete.
7. Return to the citizen/feed view to show the worker, admin, and status visibility.

### One-sentence pitch

**CivicConnect converts fragmented civic complaints into evidence-backed, location-aware work items that administrators can allocate, workers can complete, and citizens can transparently track.**

### Innovation framing

The archived comparative study recommends presenting CivicConnect as a step toward **predictive civic intelligence**, while being precise that the current prototype already delivers verified/grouped civic signals and role-based resolution coordination; predictive forecasting and omnichannel intake are future extensions.

## 15. Suggested evaluation metrics

For a pilot, measure:

- median time from report submission to administrator acknowledgement;
- percentage of reports successfully classified by AI;
- duplicate/grouping rate;
- percentage of issues receiving an assignment;
- median time from assignment to completion;
- worker request approval time;
- citizen visibility of assignment/status updates;
- number of repeat reports reduced by grouping;
- map/feed query latency and API error rate;
- false-positive or incorrect-category rate requiring manual correction.

## 16. Risks and mitigations

| Risk | Mitigation in prototype | Production next step |
|---|---|---|
| Incorrect AI category | Human-visible result and normalized category list | Confidence thresholds and correction feedback loop |
| Duplicate or inaccurate reports | Geohash grouping, upvotes, fake flags | Reputation, moderation, and stronger spatial similarity |
| Location privacy | Controlled map response and public-facing address | Coordinate rounding, policy, consent, and access tiers |
| Unavailable AI service | Clear error path | Queue-based asynchronous analysis and fallback classification |
| Worker accountability gap | Assignments and status history | Evidence of completion, citizen verification, SLA escalation |
| Static map tile dependency | MapLibre raster source | Approved tile provider, caching, usage monitoring |
| Staff-account misuse | Admin/key-gated staff registration | Verified institutional onboarding and audit review |

## 17. Roadmap

### Phase 1: prototype hardening

Add automated tests for every role and status transition, improve upload security headers, add operational logs/health checks, and add a user-visible AI outage fallback.

### Phase 2: verified resolution

Add worker completion evidence, before/after images, citizen confirmation, reopen/dispute, and admin escalation.

### Phase 3: omnichannel access

Add progressive web app support, WhatsApp/SMS/IVR/voice intake, regional language support, and accessible low-bandwidth forms.

### Phase 4: predictive governance

Use historical clusters to identify recurrence, root causes, seasonal patterns, expected resolution duration, and preventive maintenance hotspots. These models should be evaluated against held-out city data and should assist—not replace—municipal decisions.

## 18. Project map

```text
index.php                         Marketing landing page
bootstrap.php                     Shared initialization and RBAC gate
pages/register/                   Three-role account creation
pages/login/                      Role-aware authentication
pages/citizen/report.php          Citizen dashboard/report compatibility area
pages/report/                     Citizen submission UI and AI feedback
pages/citizen/public-feed.php     Database-backed community feed
pages/heatmap/                    Full MapLibre City Pulse page
pages/admin/                      Admin dashboard and work management
pages/worker/                     Worker assignments and requests
pages/account/                    Profile and password management
api/issues/                       Issue analysis, submission, detail, status, voting
api/work/                         Assignment and work-request workflow
api/stats/                        City and heatmap read models
functions/                        Auth, database, issue, AI, location, validation helpers
ai-service/                       Python inference service and ONNX model integration
assets/civicconnect.sql           Schema, indexes, and seed data
assets/css/                       Shared and page-specific styles
archives/                         SIH source context and data blueprint
rules/                            Current project rules and pitch/presentation guidance
```

## 19. Final project status

CivicConnect is a functioning prototype of a database-backed civic issue intelligence and work-coordination platform. The active role model is complete for the requested Citizen, Worker, and Admin workflows. The interactive heatmap is separated into its own database-backed page, and the major navigation/category/theme inconsistencies have been addressed. The next meaningful engineering milestone is verified resolution plus automated test coverage, followed by the predictive and omnichannel extensions described above.
