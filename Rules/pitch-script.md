# CivicConnect SIH pitch script

## One-line pitch

**CivicConnect turns fragmented civic complaints into grouped, evidence-backed work items that administrators can allocate, field workers can complete, and citizens can transparently track.**

## Three-minute script

Every day, citizens see potholes, garbage piles, broken streetlights, blocked drains, and waterlogging. The problem is not only that people cannot report them. The problem is what happens after the report: duplicates are scattered across channels, priorities are unclear, work ownership is invisible, and citizens do not know whether anything changed.

CivicConnect creates one shared evidence-to-action workflow. A citizen opens the web app, adds a photograph, location, category, and description, and submits the issue. Our AI service assists with category, severity, confidence, and possible image manipulation. The platform stores that analysis for audit, then looks for nearby reports of the same category. Instead of creating another isolated complaint, it preserves the new evidence inside one canonical issue cluster.

That cluster is visible in two ways. The Community Feed shows evidence count, upvotes, priority, status, and assignment information. City Pulse turns the live database into an interactive MapLibre map where an administrator can search, filter, select a dense area, and open the underlying issue. This turns a list of complaints into a spatial operating picture.

The workflow has exactly three active roles. Citizens report and track. Administrators see the city-wide backlog, allocate issues to active workers, and review worker requests. Field workers see their assignments, request additional work, update permitted statuses, and mark work complete. Citizens can see the assigned worker, assigning administrator, and current completion state, so the process is not a black box.

The value is the connection between community evidence and operational ownership. We reduce duplicate operational work, improve triage, and create an auditable path from observation to action. The prototype already demonstrates database-backed grouping, AI-assisted intake, geographic intelligence, role-based authorization, and a complete admin-worker-citizen loop.

Our roadmap is deliberately honest. The next stages are citizen-verified resolution with before/after evidence, SLA and escalation integrations, omnichannel access through WhatsApp/voice/low-bandwidth channels, and predictive recurrence/root-cause intelligence. Today, CivicConnect is the foundation: **from complaint management toward predictive civic resolution intelligence.**

## Proof points to show live

1. Landing metrics are read from the database.
2. A feed issue contains grouped report/evidence counts.
3. City Pulse loads from `api/stats/heatmap.php`, not a static mock file.
4. An admin assignment appears to a worker and then to the citizen-facing feed.
5. A worker status/completion action is rejected when the worker has no active assignment.

## Claims to avoid

Do not claim predictive forecasting, automatic municipal routing, citizen verification, WhatsApp/IVR, SLA escalation, or production deployment unless separately implemented and tested.
