# CivicConnect database rules

## Source of truth

Use `assets/civicconnect.sql` for schema changes and fresh-environment setup. Apply the same change to a migration process before production deployment. Do not reintroduce a static mock heatmap as a runtime data source.

## Roles

`users.role` supports exactly `citizen`, `worker`, and `admin`. Authority is retired as an active role. New code must use the three-role vocabulary and should reject unknown roles.

## Issue vocabulary

Canonical categories are `pothole`, `garbage`, `streetlight`, `waterlogging`, `road_damage`, `encroachment`, `graffiti`, `open_drain`, `fallen_tree`, and `other`. Statuses are `pending`, `acknowledged`, `in_progress`, `resolved`, and `rejected`.

## Integrity

- Keep `issues` as the canonical grouped operational record.
- Keep every citizen submission in `issue_reports`.
- Keep evidence and AI decisions auditable in their dedicated tables.
- Use foreign keys/indexes for issue, worker, admin, and request lookups.
- Use transactions for multi-table report creation and assignment state changes.
- Never store plaintext passwords or persistent-login tokens.
- Use prepared statements for all external input.

## Privacy

Treat latitude/longitude, uploaded images, phone numbers, and account metadata as sensitive. Public read models should expose only the precision and fields needed by the feed/map experience.
