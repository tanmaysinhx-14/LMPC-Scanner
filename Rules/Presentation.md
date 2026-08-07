# CivicConnect presentation plan

## Recommended ten-slide deck

### 1. Title

CivicConnect — evidence-backed civic resolution intelligence. Show the City Pulse visual and the one-line pitch.

### 2. The problem

Show common urban issues and the fragmented reporting-to-resolution gap. State the citizen, administrator, and worker consequences.

### 3. Why existing complaint flows fall short

Explain duplication, weak classification, unclear priority, opaque assignment, and lack of spatial context. Keep the comparison grounded in the archived study.

### 4. The solution

Show: report → AI assist → group → prioritise → assign → execute → transparent status.

### 5. Citizen experience

Show the report form, image/location evidence, AI result, community feed, and issue tracking.

### 6. City Pulse

Show the database-backed MapLibre page, category/status filters, dense clusters, and feed deep link. Say “current signal,” not “prediction.”

### 7. Three-role operating model

Use a three-column diagram:

```text
Citizen                 Admin                    Worker
Report/track            Review/allocate          Execute/complete
Corroborate             Review requests          Request work
See assignment          See city picture         Update assignment
```

### 8. Technology and trust

Show PHP + MariaDB, Python/ONNX AI, MapLibre, geohash grouping, CSRF/RBAC, transactions, prepared SQL, and audit tables.

### 9. Impact and roadmap

Pilot metrics: acknowledgement time, grouping rate, assignment rate, completion time, classification quality, and citizen visibility. Roadmap: verified resolution, omnichannel access, SLA, recurrence, and prediction.

### 10. Ask/closing

Close with the one-line pitch and the pilot request: a ward-level dataset, worker/admin validation, and outcome measurement.

## Slide design rules

- One message per slide.
- Prefer product screenshots and a simple workflow diagram to paragraphs.
- Use real database-backed demo numbers and label them as prototype data.
- Keep “implemented now” and “roadmap” visually separate.
- Use accessible contrast, large labels, and no dense unreadable architecture diagram.
