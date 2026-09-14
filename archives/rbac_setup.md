# XAMPP MySQL and refresh-safe RBAC

This guide configures the SIH26034 Streamlit scanner to use a real local XAMPP MySQL database and preserve the signed-in user across a browser refresh.

## What changed

The scanner now has a database backend switch:

- SQLite remains the default offline fallback (`lmpc_scanner.db`).
- XAMPP MySQL is enabled with `LMPC_DB_BACKEND=mysql`.
- The schema is created automatically in the configured database (`lmpc_scanner` by default).
- Existing Inspector, Verifier, Admin, scan, result and audit operations use the same API on either backend.

Authentication now uses a server-side session table. After a successful password check:

1. The server creates a random selector and validator.
2. Only the validator's SHA-256 hash is stored in `SessionTokens`.
3. The browser receives an opaque `lmpc_session` cookie containing the token.
4. A refresh validates that token against the selected database and restores the user and role.
5. Sign out revokes the token in the database and removes the cookie.

No username, role or password is stored in the browser cookie. Deactivating an account revokes all of that account's active sessions.

## XAMPP setup on Windows

1. Open the XAMPP Control Panel.
2. Start **MySQL**. Apache is not required for the Streamlit scanner itself.
3. From the repository root, install the project dependencies:

   ```powershell
   python -m pip install -r requirements.txt
   ```

4. Configure the MySQL backend in the same PowerShell window:

   ```powershell
   $env:LMPC_DB_BACKEND = "mysql"
   $env:LMPC_MYSQL_HOST = "127.0.0.1"
   $env:LMPC_MYSQL_PORT = "3306"
   $env:LMPC_MYSQL_DATABASE = "lmpc_scanner"
   $env:LMPC_MYSQL_USER = "root"
   $env:LMPC_MYSQL_PASSWORD = ""
   ```

   XAMPP commonly uses `root` with an empty password on a local installation. If you configured a MySQL password, set it instead of the empty string.

5. Start the application:

   ```powershell
   python -m streamlit run app.py
   ```

The first start creates the `lmpc_scanner` database and these tables:

`Users`, `Scans`, `ScanResults`, `AuditEvents`, and `SessionTokens`.

The application sidebar shows the active backend, for example `MySQL 127.0.0.1:3306/lmpc_scanner`. If MySQL is unavailable, the login page is not shown as if it were working; it displays the connection error and the SQLite fallback instruction.

## Verify the backend without opening the UI

Use a disposable database name for a smoke test so the real demo data is not changed:

```powershell
$env:LMPC_DB_BACKEND = "mysql"
$env:LMPC_MYSQL_DATABASE = "lmpc_scanner_smoke"
python -c "import db; print(db.database_description(), db.DATABASE_INIT_ERROR); u=db.authenticate_user('inspector','pass123'); t=db.create_session(u['user_id'],ttl_days=1); print(bool(db.get_session(t))); print(db.revoke_session(t), db.get_session(t))"
```

Drop that disposable schema after the test in phpMyAdmin or the MySQL client. The command should report a successful database initialization, `True` for session validation, and `True None` for revocation followed by an invalid session.

## Verify refresh persistence in the UI

1. Start MySQL and the Streamlit app with the variables above.
2. Sign in as an Inspector, Verifier or Admin.
3. Refresh the browser page (`Ctrl+R`).
4. Confirm the same role workspace is still open without entering the password again.
5. Sign out and refresh. The login screen must remain visible because the server-side token was revoked and the cookie was removed.
6. In the Admin workspace, deactivate a test account, then refresh that account's browser session. It must no longer be accepted.

The `streamlit-cookies-controller` dependency is required to write the browser cookie. If it is missing, the app still permits a current Streamlit-session login but displays a warning that refresh persistence is unavailable. Run the requirements installation command before the demo.

## Role contract

| Role | Allowed workflow |
|---|---|
| Inspector | Authenticate, capture/analyze evidence, submit scans as `PENDING`, view own scan history |
| Verifier | View pending/reviewed scans, inspect evidence, approve or override with a reason, search history |
| Admin | View analytics and raw audit data, search all scans, create/deactivate users, inspect audit events |

The role checks are enforced in `db.py`, not only by hiding Streamlit tabs. An Inspector cannot call the verifier/admin operations by changing the UI state. Only active users can create sessions; only Verifier/Admin actors can change review status; and an override requires a written reason.

## Cookie and deployment security

- Keep the application on `localhost` or behind HTTPS. Set `LMPC_COOKIE_SECURE=1` when the public origin is HTTPS.
- Replace the demonstration passwords (`inspector`, `verifier`, `admin`, all `pass123`) before exposing the app to another device.
- The cookie contains an opaque bearer token and is not an identity cookie, but the current Streamlit component is browser-script readable. TLS, short token lifetimes and logout/revocation are still required.
- `SessionTokens` stores only a validator hash; a database leak does not directly reveal the raw cookie value.
- Set `LMPC_SESSION_TTL_DAYS` to a shorter value for field deployments. Account deactivation and explicit logout revoke tokens immediately.
- XAMPP's MySQL should be bound to the local interface for a demo. Do not expose port 3306 to the Internet.

## Switch back to SQLite

Close the current Streamlit process or use a new PowerShell window, then unset the backend variables:

```powershell
Remove-Item Env:LMPC_DB_BACKEND -ErrorAction SilentlyContinue
Remove-Item Env:LMPC_MYSQL_HOST -ErrorAction SilentlyContinue
Remove-Item Env:LMPC_MYSQL_PORT -ErrorAction SilentlyContinue
Remove-Item Env:LMPC_MYSQL_DATABASE -ErrorAction SilentlyContinue
Remove-Item Env:LMPC_MYSQL_USER -ErrorAction SilentlyContinue
Remove-Item Env:LMPC_MYSQL_PASSWORD -ErrorAction SilentlyContinue
python -m streamlit run app.py
```

SQLite and MySQL are separate stores. A scan submitted to one is not automatically copied to the other. If existing SQLite demo data must be migrated, export it deliberately and import it into MySQL after reviewing the evidence paths and user identities.

## Verification commands used for this change

```powershell
python -m py_compile db.py session_cookie.py app.py
python -m pytest tests/test_db.py -q
python -m pytest tests -q
```

The current regression suite passes **365 tests**. The real XAMPP smoke test was run against `127.0.0.1:3306` using `mysql-connector-python`, including schema creation, seeded authentication, session validation and revocation.
