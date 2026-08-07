# CivicConnect requirements

## Functional requirements

### Identity and access

- Support Citizen, Worker, and Admin accounts.
- Allow citizen self-registration.
- Allow first-admin bootstrap and admin/key-gated staff registration.
- Authenticate by email/password and role.
- Provide logout, remember-me sessions, profile, and password change.

### Citizen

- Submit photo, location, category, and description.
- Receive AI analysis feedback where the service is available.
- View own submissions and public grouped issues.
- Search/filter/sort the community feed.
- Upvote corroborating issues.
- View current status, assignment worker, assigning admin, and completion state.

### Admin

- View city-wide issue and category statistics.
- Explore the database-backed interactive heatmap.
- Review issue details and priority.
- Assign issues to active workers with notes.
- Review and decide worker work requests.
- Change permitted issue states and preserve status history.

### Worker

- View assignments, location, priority, notes, and status.
- Request work from an admin.
- Update assigned work through permitted states.
- Mark assigned work completed.

### Intelligence

- Store AI category, severity, confidence, manipulation flag, model version, and raw output.
- Group nearby same-category reports.
- Expose live map/feed statistics from the database.

## Non-functional requirements

- Server-side authorization and CSRF protection.
- Password hashing and login-rate limiting.
- Prepared SQL statements and escaped rendering.
- Responsive layouts for desktop and mobile.
- Clear error/loading/empty states.
- Transactional report/evidence writes.
- Auditable status and assignment actions.

## Out of scope for this prototype

Omnichannel intake, citizen resolution verification, SLA/escalation automation, predictive forecasting, native offline application, and production municipal integrations.
