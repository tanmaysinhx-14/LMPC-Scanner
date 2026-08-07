# CivicConnect data blueprint

`issues` is the canonical public/assignable post. `issue_reports` stores every
citizen submission, including submissions grouped into an existing nearby
post. `issue_images` stores evidence and `issue_ai_analyses` stores each
auditable model decision.

## Flow

1. An authenticated citizen submits an image, CSRF token, GPS coordinates,
   category, and description to `api/issues/submit.php`.
2. PHP validates the image MIME/signature/size and generates a random path.
3. The Python service receives the relative filepath and returns category,
   severity, confidence, manipulation status, and raw detections.
4. PHP normalizes the category and searches the same category in a nearby
   geohash prefix. A match adds a report and image to the existing post;
   otherwise a new canonical issue is created.
5. The database write is transactional. Failed persistence removes the image.

## Heatmap

`GET /api/stats/heatmap.php` groups active issues by geohash and returns
`lat`, `lng`, `count`, `severity`, `weight`, and `band`. The initial display
bands are green for 1–2 reports, yellow for 3–5, and red for 6 or more.
`weight` can be passed to Leaflet.heat.

## Feed and workflow APIs

- `GET /api/issues/list.php`: priority-ordered grouped feed with image/report counts.
- `GET /api/issues/detail.php?id=42`: images and status history.
- `POST /api/issues/upvote.php`: one vote per authenticated user.
- `POST`/`PUT /api/issues/status.php`: worker, authority, or admin status changes.
- `GET /api/stats/city.php`: city KPIs and category counts.

State-changing requests require `X-CSRF-Token` or a `csrf_token` form field.
Exact coordinates should remain internal; public responses should use rounded
coordinates and production deployments should protect uploaded media.
