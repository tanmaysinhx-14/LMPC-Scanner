# CivicConnect SIH pitch script: web foundation to mobile client

## One-line pitch

**CivicConnect turns fragmented civic complaints into grouped, evidence-backed work items that administrators can allocate, field workers can complete, and citizens can transparently track - first through a working web prototype and next through a native mobile experience.**

## Three-minute script

Every day, citizens see potholes, garbage piles, graffiti, broken streetlights, blocked drains, and waterlogging. The problem is not only how to submit a complaint. The problem is what happens after the report: duplicates are scattered across channels, priorities are unclear, work ownership is invisible, and citizens do not know whether anything changed.

CivicConnect creates one shared evidence-to-action workflow. Today, a citizen opens the web app, adds a photograph, location, category, and description, and submits the issue. Our server-side AI detector assists with category, severity, confidence, manipulation signals, and visible bounding boxes in an annotated preview. The final upload is re-analyzed on the server and stored for audit.

The platform then looks for nearby reports of the same category. Instead of creating another isolated complaint, it preserves the new evidence inside one canonical issue cluster. The Community Feed shows evidence count, upvotes, priority, status, and assignment information. City Pulse turns live grouped database records into an interactive MapLibre map.

The workflow has exactly three active roles. Citizens report and track. Administrators see the city-wide backlog, allocate issues to active workers, and review worker requests. Field workers see assignments, request additional work, update permitted statuses, and mark work complete. Citizens can see assignment and completion state, so the process is not a black box.

The next delivery is a mobile client, not a second backend. We will use Expo React Native with TypeScript, Expo Router, native camera/location/notification modules, MapLibre React Native, and a versioned bearer-authenticated API. The PHP/MariaDB backend and FastAPI detector remain authoritative. This allows citizen and worker workflows to move to Android/iOS without duplicating grouping, priority, security, or audit logic.

The value is the connection between community evidence and operational ownership. We reduce duplicate operational work, improve triage, and create an auditable path from observation to action. The current prototype demonstrates the web foundation; the migration plan turns that foundation into a maintainable mobile product.

## Proof points to show live

1. Landing metrics are read from the database.
2. A feed issue contains grouped report/evidence counts.
3. City Pulse loads from `api/stats/heatmap.php`, not a static mock file.
4. Uploading an image produces a real detection response and annotated preview.
5. An admin assignment appears to a worker and then to the citizen-facing feed.
6. A worker status/completion action is rejected without an active assignment.
7. The migration blueprint maps the same workflows to the planned mobile API.

## Claims to avoid

Do not claim native mobile release, offline writes, push delivery, predictive forecasting, automatic municipal routing, citizen verification, WhatsApp/IVR, SLA escalation, or production deployment unless separately implemented and tested.
