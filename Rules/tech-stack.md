# CivicConnect technology stack

| Layer | Technology |
|---|---|
| Web runtime | PHP 8.1+ |
| Server-rendered UI | PHP templates, Bootstrap 5.3.3, custom CSS |
| Icons/fonts | Font Awesome, Inter/DM Sans/Space Grotesk |
| Database | MariaDB/MySQL through PDO |
| Authentication | PHP sessions, password hashing, CSRF, remember tokens |
| Mapping | MapLibre GL JS 4.7.1 with CARTO raster tiles and OpenStreetMap attribution |
| AI service | Python HTTP service with ONNX inference |
| Model artifact | `ai-service/models/best_int8.onnx` |
| Geometry/grouping | Geohash helper under `functions/location/` |
| Data exchange | JSON APIs |
| Seed/schema | `assets/civicconnect.sql` |

Avoid adding a second frontend framework or a second map library without a clear migration decision. The current map direction is MapLibre, not Leaflet.
