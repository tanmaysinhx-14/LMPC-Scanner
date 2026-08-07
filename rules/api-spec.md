# CivicConnect API specification

## Conventions

- JSON endpoints return a predictable envelope: `success`, `message`, and `data` where relevant.
- State-changing requests require the authenticated session and a CSRF token in `X-CSRF-Token` or `csrf_token`.
- Validate role, ownership, and input on the server for every endpoint.
- Use prepared statements and escape values at the HTML/JavaScript rendering boundary.
- Do not expose raw passwords, remember tokens, internal filesystem paths, or unnecessary coordinate precision.
- Errors should be useful to the UI but should not disclose SQL, filesystem, or stack-trace details.

## Endpoints

| Endpoint | Method | Role | Contract |
|---|---|---|---|
| `api/auth/login.php` | POST | Public | Email, password, role, optional remember-me; creates a session. |
| `api/auth/logout.php` | POST/GET flow | Authenticated | Invalidates the current session. |
| `api/auth/session.php` | GET | Public | Returns current session/auth state. |
| `api/issues/analyze.php` | POST | Citizen workflow | Accepts image input and returns AI category/severity/confidence. |
| `api/issues/submit.php` | POST | Citizen | Accepts evidence, location, category, description; persists a grouped/canonical issue. |
| `api/issues/list.php` | GET | Worker/Admin | Returns filtered priority-ordered issues for staff operations. |
| `api/issues/detail.php` | GET | Authenticated | Returns issue detail, images, assignments, and status history. |
| `api/issues/status.php` | POST/PUT | Worker/Admin | Applies a permitted status transition and writes history. |
| `api/issues/upvote.php` | POST | Authenticated | Toggles one user's upvote for an issue. |
| `api/stats/city.php` | GET | Worker/Admin | Returns operational KPIs/category counts. |
| `api/stats/heatmap.php` | GET | Read-only | Returns live grouped geographic points for MapLibre. |
| `api/work/assign.php` | POST | Admin | Assigns an issue to an active worker. |
| `api/work/request.php` | POST | Worker | Creates/cancels a worker work request. |
| `api/work/review-request.php` | POST | Admin | Approves/declines a work request and records review metadata. |

## Workflow response expectations

Assignment responses should include issue id, worker id/name, admin id/name, status, notes, and timestamps where available. Work-request responses should include request status, reviewer, review note, and time. Feed/detail responses should include assignment visibility without exposing unrelated personal data.
