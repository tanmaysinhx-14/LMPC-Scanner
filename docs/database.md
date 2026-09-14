# Database & Persistence Architecture: SIH26034

This document details the data layer for the Legal Metrology Compliance Scanner. The persistence architecture is designed to support both fully disconnected edge operations and centralized, multi-user deployments without requiring changes to the application's data-access API[cite: 1, 8].

---

## 1. Dual-Backend Architecture

The system abstracts data operations through a unified API in the `db.py` module, supporting two discrete backends[cite: 1, 8]:

* **SQLite (Offline Default):** Utilizes a local `lmpc_scanner.db` file operating in WAL (Write-Ahead Logging) mode with foreign keys enabled[cite: 8]. This is the default configuration for offline, field-based Edge deployments[cite: 1, 7].
* **XAMPP/MySQL (Centralized):** A relational backend activated by setting the `LMPC_DB_BACKEND=mysql` environment variable[cite: 1, 7]. It automatically creates and bootstraps the schema upon the first connection using documented `LMPC_MYSQL_*` variables (Host, Port, Database, User, Password)[cite: 1, 7].

---

## 2. Implemented Schema

Regardless of the active backend, the system automatically migrates and utilizes the following highly normalized core tables[cite: 7, 8]:

* **`Users`**: Stores user identity, password representations, role assignments (Inspector, Verifier, Admin), and account activity states[cite: 8].
* **`Scans`**: Records the initiating Inspector, timestamps, status (`PENDING`, approved, etc.), reviewer metadata, and serialized image paths[cite: 8].
* **`ScanResults`**: Stores the output of the rule engine per compliance section, including the rule class, extracted OCR text, parsed data, human-readable reason, PASS/FAIL compliance result, and estimated penalty amount[cite: 8].
* **`AuditEvents`**: An append-only ledger for workflow traceability that records the actor, scan ID, action performed, specific details, and timestamp[cite: 8].
* **`SessionTokens`**: Manages refresh-safe web authentication by storing a session selector, validator hash, expiry timestamp, last-use data, and revocation state[cite: 8].

---

## 3. Data Serialization & Cryptography

* **Password Hashing:** User passwords are not stored in plaintext; they are seeded and verified using salted PBKDF2-HMAC-SHA256 with 310,000 iterations[cite: 2, 8].
* **Evidence Integrity:** To guarantee chain-of-custody, every submitted panel image is cryptographically hashed using SHA-256 at the time of insertion[cite: 2, 8].
* **Multi-Image Serialization:** Instead of requiring a separate table for image-to-scan mapping, multiple image paths for a single package are serialized as JSON arrays within the `Scans` table[cite: 8]. The API retains backward compatibility to read legacy comma-separated values[cite: 8].

---

## 4. Refresh-Safe Session Management

The database manages web sessions to persist Role-Based Access Control (RBAC) securely across browser refreshes[cite: 1, 7].

* **Token Generation:** Upon successful password verification, the server generates a random selector and validator pair.
* **Secure Storage:** Only the SHA-256 hash of the validator is saved in the `SessionTokens` table[cite: 1, 7].
* **Cookie Issuance:** An opaque bearer token is issued to the browser via the `lmpc_session` cookie (managed via `streamlit-cookies-controller`)[cite: 1, 7]. No usernames, passwords, or roles are stored in the browser cookie.
* **Token Revocation:** Active sessions are instantly revoked in the database upon explicit user logout, session expiry, or if an Administrator deactivates the account[cite: 1, 7].

---

## 5. Role-Based Access Control (RBAC) Enforcement

Role constraints are enforced directly within the `db.py` data queries rather than merely hiding Streamlit UI tabs[cite: 8].

* **Inspector Constraints:** Can only insert new scans and retrieve their own historical scan records[cite: 7, 8]. They cannot alter scan statuses or view scans from other officers[cite: 8].
* **Verifier Constraints:** Can retrieve pending scans globally and change review statuses[cite: 7, 8]. The database layer enforces that an override/rejection state transition requires a non-empty written reason[cite: 8].
* **Admin Constraints:** Can retrieve raw event logs from the `AuditEvents` table, access system-wide analytics, and manage the active/inactive state of records in the `Users` table[cite: 7, 8]. 

---

## 6. MySQL Configuration Runbook

To configure the MySQL backend, the following environment variables must be defined prior to starting the application[cite: 7]:

```powershell
$env:LMPC_DB_BACKEND = "mysql"
$env:LMPC_MYSQL_HOST = "127.0.0.1"
$env:LMPC_MYSQL_PORT = "3306"
$env:LMPC_MYSQL_DATABASE = "lmpc_scanner"
$env:LMPC_MYSQL_USER = "root"
$env:LMPC_MYSQL_PASSWORD = ""