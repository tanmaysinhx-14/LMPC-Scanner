# CivicConnect database rules and mobile migration data

## Source of truth

Use `assets/civicconnect.sql` for the current schema and seed data. Any schema change must also be represented in a forward migration process before production deployment. Do not make the mobile app a direct database client and do not reintroduce a static mock heatmap as a runtime data source.

## Current roles and vocabulary

`users.role` supports exactly `citizen`, `worker`, and `admin`. Authority is not an active fourth role. Canonical issue categories are `pothole`, `garbage`, `streetlight`, `waterlogging`, `road_damage`, `encroachment`, `graffiti`, `open_drain`, `fallen_tree`, and `other`. Statuses are `pending`, `acknowledged`, `in_progress`, `resolved`, and `rejected`.

The current AI checkpoint detects `pothole`, `garbage`, and `graffiti`. Model labels may expand later, but API and SQL categories must be normalized through shared server helpers.

## Current data ownership

- `issues` is the canonical grouped operational record.
- `issue_reports` stores every citizen submission, including corroborating evidence.
- `issue_images` stores validated image metadata, file paths, hashes, and raw AI output references.
- `issue_ai_analyses` stores category, severity, confidence, manipulation state, model version, raw output, and analysis time.
- `status_history` and assignment/work-request records provide accountable operational history.
- Coordinates/geohashes support grouping and City Pulse; public read models must apply the required precision policy.

## Native-auth data

The initial `/api/v1/auth/*` slice uses the explicit `assets/migrations/2026-08-14-mobile-access-tokens.sql` migration and the `mobile_access_tokens` table below. Do not create schema opportunistically from the mobile app. Refresh-token, device, and idempotency tables remain future migrations:

### `mobile_access_tokens`

- `id`, `user_id`, `token_hash`, `expires_at`, `device_name`, `created_at`, `last_used_at`, `revoked_at`
- Unique hash and user/expiry indexes

Store only a hash of the short-lived access token. Revoke it on logout or expiry; do not expose the hash or token in issue responses.

### `mobile_refresh_tokens`

- `id`, `user_id`, `family_id`, `device_id`
- `token_hash`, `issued_at`, `expires_at`, `rotated_at`, `revoked_at`, `replaced_by_id`
- Indexes on `user_id`, `family_id`, active `token_hash`, and expiry

Store only a hash of the refresh token. Rotation must revoke the used token and invalidate the token family on reuse detection.

### `mobile_devices`

- `id`, `user_id`, `platform`, `device_installation_id`
- `push_token`, `app_version`, `last_seen_at`, `revoked_at`
- Unique constraint on active user/device installation identity

Push tokens are credentials and must be revocable. Do not expose them in public issue responses.

### `api_idempotency_keys`

- `user_id`, `idempotency_key`, `request_hash`, `response_status`, `response_body`, `created_at`, `expires_at`
- Unique constraint on user plus key

Use this for final mobile issue creation so network retries cannot duplicate a report or image.

## Migration integrity rules

- Reuse current grouping, priority, status, and assignment transactions from shared PHP functions.
- Add foreign keys and indexes before enabling the corresponding mobile endpoint.
- Keep old browser session tables and flows until the web client is retired deliberately.
- Never migrate passwords or browser remember tokens into the mobile app.
- Store only the minimum device/account data needed for refresh, push, auditing, and revocation.
- Treat latitude/longitude, uploaded images, phone numbers, push tokens, and account metadata as sensitive.
- Retain raw AI output for audit, but do not store large transient annotated base64 previews in database JSON.
- Define deletion/retention behavior for device tokens, expired refresh tokens, and uploaded evidence before production launch.
