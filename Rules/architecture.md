# CivicConnect architecture

## Layers

```text
PHP pages -> shared bootstrap/helpers -> JSON APIs -> MariaDB/MySQL
                                      \-> Python AI service -> ONNX model
MapLibre pages <- heatmap/stat APIs <- database read models
```

### Presentation layer

Server-rendered PHP pages live under `pages/`. Bootstrap 5.3.3, shared civic styles, page-specific CSS, and small progressive-enhancement scripts provide the UI. The landing page is marketing-oriented; the feed, dashboards, report page, and City Pulse are operational surfaces.

### Application layer

`bootstrap.php` initializes database/session/auth, imports shared helpers, and applies login/role gates. Business logic is kept in `functions/` and endpoint files should remain explicit about validation, authorization, persistence, and response.

### Data layer

MariaDB/MySQL stores identities, canonical issues, individual reports, evidence, AI analyses, assignments, worker requests, votes, flags, and audit history. `assets/civicconnect.sql` is the reproducible schema/seed artifact.

### AI layer

The PHP service calls the Python process in `ai-service/`. The Python process performs image analysis using the project ONNX model and returns a structured result. PHP normalizes and stores the result.

### Mapping layer

MapLibre GL renders the interactive maps. `api/stats/heatmap.php` reads live database data. The client filters and renders points; it must not become the source of truth.

## Architectural rules

1. Authorization is server-side and role-based.
2. Canonical database vocabulary is shared by forms, APIs, and UI labels.
3. State changes are auditable.
4. Evidence persistence is transactional.
5. External AI/tile dependencies fail visibly and recoverably.
6. Public map/feed output must not reveal more personal/location data than required.
