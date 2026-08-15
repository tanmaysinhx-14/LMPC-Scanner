# CivicConnect API specification and mobile contract plan

## Contract conventions

- JSON endpoints return `success`, `message`, and `data` where relevant.
- Browser state-changing requests use the authenticated PHP session and CSRF token.
- Planned mobile endpoints use `Authorization: Bearer <access-token>` and do not depend on browser cookies or CSRF tokens.
- Every endpoint validates role, ownership, input, and resource state on the server.
- Errors expose a stable client-safe `code` and message, not SQL, filesystem, or stack-trace details.
- Use UTC ISO-8601 timestamps and stable numeric IDs.
- Do not expose raw passwords, tokens, internal paths, or unnecessary coordinate precision.

## Existing web endpoints

| Endpoint | Method | Role | Contract |
|---|---|---|---|
| `api/auth/login.php` | POST | Public | Email, password, role, optional remember-me; creates a browser session. |
| `api/auth/logout.php` | POST/GET flow | Authenticated | Invalidates the current browser session. |
| `api/auth/session.php` | GET | Public | Returns current browser session/auth state. |
| `api/issues/analyze.php` | POST | Citizen workflow | Accepts an image and returns AI category/severity/confidence, detections, and annotated preview data. |
| `api/issues/submit.php` | POST | Citizen | Accepts evidence/location/category/description; re-analyzes and persists a grouped issue. |
| `api/issues/list.php` | GET | Worker/Admin | Returns filtered priority-ordered issues for staff operations. |
| `api/issues/detail.php` | GET | Authenticated | Returns issue detail, images, assignments, and status history. |
| `api/issues/status.php` | POST/PUT | Worker/Admin | Applies a permitted status transition and writes history. |
| `api/issues/upvote.php` | POST | Authenticated | Toggles one user's upvote for an issue. |
| `api/stats/city.php` | GET | Worker/Admin | Returns operational KPI/category counts. |
| `api/stats/heatmap.php` | GET | Read-only | Returns live grouped geographic points for MapLibre. |
| `api/work/assign.php` | POST | Admin | Assigns an issue to an active worker. |
| `api/work/request.php` | POST | Worker | Creates/cancels a worker work request. |
| `api/work/review-request.php` | POST | Admin | Approves/declines a work request and records review metadata. |

## Current AI response shape

`api/issues/analyze.php` and final submission responses expose `data.ai` with the following stable fields:

```json
{
  "category": "garbage",
  "confidence": 0.81,
  "severity": 2,
  "low_confidence": false,
  "detections": [
    {
      "class": "garbage",
      "category": "garbage",
      "confidence": 0.81,
      "bbox": [12.5, 44.2, 280.0, 360.4]
    }
  ],
  "detection_count": 1,
  "bbox": [12.5, 44.2, 280.0, 360.4],
  "image_width": 640,
  "image_height": 480,
  "annotated_image": "data:image/jpeg;base64,...",
  "model_version": "civicconnect-ultralytics-detector:train/best.pt"
}
```

`annotated_image` is returned for the upload preview request. It is not stored as a large base64 value in the final raw AI database payload. A missing/empty detection list is a valid uncertain result and must not be treated as proof that the image is clean.

## Mobile API v1 migration surface

The initial native slice now implements `/api/v1/auth/login.php`, `/api/v1/auth/me.php`, and `/api/v1/auth/logout.php`. These endpoints issue short-lived opaque bearer tokens stored as hashes in `mobile_access_tokens`. The remaining routes below are still migration targets unless noted by the implementation.

The broader routes remain migration targets, not a claim that all of them currently exist:

| Endpoint | Method | Role | Purpose |
|---|---|---|---|
| `/api/v1/auth/login` | POST | Public | Return short-lived access token, rotating refresh token, user, role, and capabilities. |
| `/api/v1/auth/refresh` | POST | Public with refresh token | Rotate the refresh token and issue a new access token. |
| `/api/v1/auth/logout` | POST | Authenticated | Revoke the refresh-token family/device session. |
| `/api/v1/auth/me` | GET | Authenticated | Return current user, role, ward, and capabilities. |
| `/api/v1/issues/analyze` | POST multipart | Citizen | Analyze an image and return detections plus annotated preview. |
| `/api/v1/issues` | POST multipart | Citizen | Final server-trusted issue creation with idempotency key. |
| `/api/v1/issues` | GET | Authenticated/public policy | Paginated feed with filters and cursor metadata. |
| `/api/v1/issues/{id}` | GET | Authenticated/public policy | Issue detail, evidence summary, assignment, and history visibility. |
| `/api/v1/issues/{id}/upvote` | POST | Authenticated | Add/remove the current user's corroboration. |
| `/api/v1/issues/{id}/status` | POST | Worker/Admin | Apply a permitted status transition. |
| `/api/v1/stats/heatmap` | GET | Authenticated/public policy | Mobile-safe live City Pulse data with explicit coordinate precision. |
| `/api/v1/work/assignments` | GET | Worker | Worker assignment list. |
| `/api/v1/work/requests` | POST/GET | Worker/Admin | Create, list, review, or cancel work requests. |
| `/api/v1/devices/push-token` | POST/DELETE | Authenticated | Register/revoke a device push token. |

Mobile endpoints should call shared PHP functions and use the same SQL vocabulary as the web endpoints. They should not fork grouping, priority, assignment, or role logic.

## Authentication contract

- Access tokens are short-lived and contain only the minimum subject, role, capability, issuer, audience, issued-at, and expiry claims.
- Refresh tokens are random, rotated on use, hashed before database storage, scoped to a device/session, and revocable.
- Return `401` for missing/expired credentials and `403` for authenticated users lacking the required role/capability.
- The app stores the refresh credential in `expo-secure-store`; it never stores passwords or access tokens in source control.

## Upload contract

- Use `multipart/form-data` with `issueImage`, `latitude`, `longitude`, `issueCategory`, `issueDescription`, and `Idempotency-Key`.
- Accept only validated JPEG/PNG/WebP images up to the current 10 MB limit.
- The server creates the permanent upload path, computes SHA-256, re-runs AI analysis, and persists all related rows transactionally.
- A preview request may return an annotated data URL. A final creation response may return the structured AI data and issue, but persistence must not trust client preview fields.

## Pagination and errors

Collection responses should use a cursor or stable `next_cursor`, not page numbers tied to changing priority data. Errors should follow one shape:

```json
{
  "success": false,
  "message": "A clear user-facing message",
  "error": {"code": "LOCATION_REQUIRED", "retryable": false}
}
```
