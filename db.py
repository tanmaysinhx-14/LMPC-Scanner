from __future__ import annotations

from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
from typing import Any, Iterator


DB_PATH = Path(__file__).resolve().with_name("lmpc_scanner.db")
ALLOWED_ROLES = ("Inspector", "Verifier", "Admin")
ALLOWED_SCAN_STATUSES = ("PENDING", "APPROVED", "OVERRIDDEN")
PASSWORD_ITERATIONS = 310_000
SESSION_TTL_DAYS = max(1, int(os.environ.get("LMPC_SESSION_TTL_DAYS", "30")))
MYSQL_DATABASE_RE = re.compile(r"^[A-Za-z0-9_]+$")


def database_backend() -> str:
    """Return the configured persistence backend.

    SQLite remains the safe default for an offline demo. Set
    ``LMPC_DB_BACKEND=mysql`` to use the XAMPP/MySQL connection settings below.
    """

    value = os.environ.get("LMPC_DB_BACKEND", "sqlite").strip().lower()
    return "mysql" if value in {"mysql", "mariadb", "xampp"} else "sqlite"


def database_description() -> str:
    if database_backend() == "mysql":
        host = os.environ.get("LMPC_MYSQL_HOST", "127.0.0.1")
        port = os.environ.get("LMPC_MYSQL_PORT", "3306")
        name = os.environ.get("LMPC_MYSQL_DATABASE", "lmpc_scanner")
        return f"MySQL {host}:{port}/{name}"
    return f"SQLite {DB_PATH.name}"


class _CompatRow(dict):
    """Dictionary row that also supports SQLite-style numeric indexing."""

    def __getitem__(self, key: Any) -> Any:
        if isinstance(key, int):
            return tuple(self.values())[key]
        return super().__getitem__(key)


def _mysql_sql(sql: str) -> str:
    """Translate the small SQLite-compatible SQL subset used by this module."""

    sql = sql.replace("?", "%s")
    return re.sub(r"CAST\(([^)]+) AS TEXT\)", r"CAST(\1 AS CHAR)", sql, flags=re.IGNORECASE)


class _MySQLCursor:
    def __init__(self, cursor: Any):
        self._cursor = cursor

    @property
    def lastrowid(self) -> int | None:
        return self._cursor.lastrowid

    @property
    def rowcount(self) -> int:
        return self._cursor.rowcount

    def execute(self, sql: str, params: Any = ()) -> "_MySQLCursor":
        self._cursor.execute(_mysql_sql(sql), params)
        return self

    def executemany(self, sql: str, params: Any) -> "_MySQLCursor":
        self._cursor.executemany(_mysql_sql(sql), params)
        return self

    @staticmethod
    def _row(value: Any) -> _CompatRow | None:
        return _CompatRow(value) if isinstance(value, dict) else value

    def fetchone(self) -> _CompatRow | Any | None:
        return self._row(self._cursor.fetchone())

    def fetchall(self) -> list[_CompatRow | Any]:
        return [self._row(row) for row in self._cursor.fetchall()]


class _MySQLConnection:
    def __init__(self, connection: Any):
        self._connection = connection

    def _cursor(self) -> _MySQLCursor:
        return _MySQLCursor(self._connection.cursor(dictionary=True, buffered=True))

    def execute(self, sql: str, params: Any = ()) -> _MySQLCursor:
        return self._cursor().execute(sql, params)

    def executemany(self, sql: str, params: Any) -> _MySQLCursor:
        return self._cursor().executemany(sql, params)

    def executescript(self, script: str) -> None:
        for statement in script.split(";"):
            statement = statement.strip()
            if statement:
                self.execute(statement)

    def commit(self) -> None:
        self._connection.commit()

    def rollback(self) -> None:
        self._connection.rollback()

    def close(self) -> None:
        self._connection.close()


def _mysql_connection() -> _MySQLConnection:
    try:
        import mysql.connector
    except ImportError as error:
        raise RuntimeError(
            "MySQL backend selected but mysql-connector-python is not installed. "
            "Run: python -m pip install mysql-connector-python"
        ) from error

    database = os.environ.get("LMPC_MYSQL_DATABASE", "lmpc_scanner").strip()
    if not MYSQL_DATABASE_RE.fullmatch(database):
        raise ValueError("LMPC_MYSQL_DATABASE may contain only letters, numbers, and underscores")
    options: dict[str, Any] = {
        "host": os.environ.get("LMPC_MYSQL_HOST", "127.0.0.1"),
        "port": int(os.environ.get("LMPC_MYSQL_PORT", "3306")),
        "user": os.environ.get("LMPC_MYSQL_USER", "root"),
        "password": os.environ.get("LMPC_MYSQL_PASSWORD", ""),
        "connection_timeout": int(os.environ.get("LMPC_MYSQL_CONNECT_TIMEOUT", "5")),
    }
    try:
        connection = mysql.connector.connect(database=database, **options)
    except Exception as error:
        # XAMPP commonly starts MySQL without a pre-created application schema.
        # Create only the validated identifier, then retry the normal connection.
        if getattr(error, "errno", None) != 1049:
            raise RuntimeError(f"Could not connect to {database_description()}: {error}") from error
        try:
            server = mysql.connector.connect(**options)
            cursor = server.cursor()
            cursor.execute(
                f"CREATE DATABASE IF NOT EXISTS `{database}` "
                "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            )
            server.commit()
            cursor.close()
            server.close()
            connection = mysql.connector.connect(database=database, **options)
        except Exception as create_error:
            raise RuntimeError(f"Could not create/connect to {database_description()}: {create_error}") from create_error
    return _MySQLConnection(connection)


@contextmanager
def _connection() -> Iterator[Any]:
    if database_backend() == "mysql":
        connection = _mysql_connection()
        try:
            yield connection
            connection.commit()
        except Exception:
            connection.rollback()
            raise
        finally:
            connection.close()
        return

    connection = sqlite3.connect(str(DB_PATH), timeout=10)
    connection.row_factory = sqlite3.Row
    connection.execute("PRAGMA foreign_keys = ON")
    connection.execute("PRAGMA journal_mode = WAL")
    try:
        yield connection
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


def _now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _hash_password(password: str) -> str:
    salt = secrets.token_bytes(16)
    digest = hashlib.pbkdf2_hmac(
        "sha256", password.encode("utf-8"), salt, PASSWORD_ITERATIONS
    )
    return f"pbkdf2_sha256${PASSWORD_ITERATIONS}${salt.hex()}${digest.hex()}"


def _verify_password(password: str, stored_password: str) -> bool:
    parts = stored_password.split("$")
    if len(parts) == 4 and parts[0] == "pbkdf2_sha256":
        try:
            iterations = int(parts[1])
            salt = bytes.fromhex(parts[2])
            expected = bytes.fromhex(parts[3])
        except (TypeError, ValueError):
            return False
        actual = hashlib.pbkdf2_hmac(
            "sha256", password.encode("utf-8"), salt, iterations
        )
        return hmac.compare_digest(actual, expected)
    return hmac.compare_digest(stored_password, password)


def _ensure_column(
    connection: Any,
    table_name: str,
    column_name: str,
    column_definition: str,
) -> None:
    if database_backend() == "mysql":
        columns = {
            str(row["COLUMN_NAME"])
            for row in connection.execute(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
                "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                (table_name,),
            ).fetchall()
        }
    else:
        columns = {
            str(row[1])
            for row in connection.execute(f"PRAGMA table_info({table_name})").fetchall()
        }
    if column_name not in columns:
        if database_backend() == "mysql":
            mysql_definitions = {
                ("Users", "active"): "TINYINT(1) NOT NULL DEFAULT 1",
                ("Users", "created_at"): "VARCHAR(40) NOT NULL DEFAULT ''",
                ("Scans", "product_name"): "VARCHAR(500) NOT NULL DEFAULT ''",
                ("Scans", "evidence_notes"): "TEXT NOT NULL",
                ("Scans", "evidence_hashes"): "LONGTEXT NOT NULL",
                ("Scans", "reviewer_id"): "BIGINT UNSIGNED NULL",
                ("Scans", "reviewed_at"): "VARCHAR(40) NULL",
                ("Scans", "review_reason"): "TEXT NOT NULL",
                ("ScanResults", "reason"): "TEXT NOT NULL",
                ("ScanResults", "parsed_data"): "LONGTEXT NOT NULL",
            }
            column_definition = mysql_definitions.get(
                (table_name, column_name), column_definition
            )
        connection.execute(
            f"ALTER TABLE {table_name} ADD COLUMN {column_name} {column_definition}"
        )


def _is_integrity_error(error: BaseException) -> bool:
    if isinstance(error, sqlite3.IntegrityError):
        return True
    return getattr(error, "errno", None) in {1062, 1451, 1452}


_SQLITE_SCHEMA = """
            CREATE TABLE IF NOT EXISTS Users (
                user_id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                role TEXT NOT NULL CHECK (role IN ('Inspector', 'Verifier', 'Admin')),
                active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
                created_at TEXT NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS Scans (
                scan_id INTEGER PRIMARY KEY AUTOINCREMENT,
                inspector_id INTEGER NOT NULL,
                image_path TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'PENDING'
                    CHECK (status IN ('PENDING', 'APPROVED', 'OVERRIDDEN')),
                timestamp TEXT NOT NULL,
                product_name TEXT NOT NULL DEFAULT '',
                evidence_notes TEXT NOT NULL DEFAULT '',
                evidence_hashes TEXT NOT NULL DEFAULT '[]',
                reviewer_id INTEGER,
                reviewed_at TEXT,
                review_reason TEXT NOT NULL DEFAULT '',
                FOREIGN KEY (inspector_id) REFERENCES Users(user_id),
                FOREIGN KEY (reviewer_id) REFERENCES Users(user_id)
            );

            CREATE TABLE IF NOT EXISTS ScanResults (
                result_id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                rule_class TEXT NOT NULL,
                extracted_text TEXT NOT NULL DEFAULT '',
                is_compliant INTEGER NOT NULL CHECK (is_compliant IN (0, 1)),
                penalty_amount INTEGER NOT NULL DEFAULT 0,
                reason TEXT NOT NULL DEFAULT '',
                parsed_data TEXT NOT NULL DEFAULT '{}',
                FOREIGN KEY (scan_id) REFERENCES Scans(scan_id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS AuditEvents (
                event_id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_id INTEGER,
                scan_id INTEGER,
                action TEXT NOT NULL,
                details TEXT NOT NULL DEFAULT '',
                timestamp TEXT NOT NULL,
                FOREIGN KEY (actor_id) REFERENCES Users(user_id),
                FOREIGN KEY (scan_id) REFERENCES Scans(scan_id) ON DELETE SET NULL
            );

            CREATE INDEX IF NOT EXISTS idx_scans_status ON Scans(status);
            CREATE INDEX IF NOT EXISTS idx_scans_timestamp ON Scans(timestamp);
            CREATE INDEX IF NOT EXISTS idx_scan_results_scan_id
                ON ScanResults(scan_id);
            CREATE INDEX IF NOT EXISTS idx_audit_events_timestamp
                ON AuditEvents(timestamp);
            CREATE TABLE IF NOT EXISTS SessionTokens (
                token_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                selector TEXT NOT NULL UNIQUE,
                validator_hash TEXT NOT NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                last_used_at TEXT,
                revoked_at TEXT,
                FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_session_tokens_user ON SessionTokens(user_id);
            CREATE INDEX IF NOT EXISTS idx_session_tokens_expiry ON SessionTokens(expires_at);
"""


_MYSQL_SCHEMA = """
            CREATE TABLE IF NOT EXISTS Users (
                user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(16) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at VARCHAR(40) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS Scans (
                scan_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                inspector_id BIGINT UNSIGNED NOT NULL,
                image_path LONGTEXT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
                timestamp VARCHAR(40) NOT NULL,
                product_name VARCHAR(500) NOT NULL DEFAULT '',
                evidence_notes TEXT NOT NULL,
                evidence_hashes LONGTEXT NOT NULL,
                reviewer_id BIGINT UNSIGNED NULL,
                reviewed_at VARCHAR(40) NULL,
                review_reason TEXT NOT NULL,
                CONSTRAINT fk_scans_inspector FOREIGN KEY (inspector_id) REFERENCES Users(user_id),
                CONSTRAINT fk_scans_reviewer FOREIGN KEY (reviewer_id) REFERENCES Users(user_id),
                INDEX idx_scans_status (status),
                INDEX idx_scans_timestamp (timestamp)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS ScanResults (
                result_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                scan_id BIGINT UNSIGNED NOT NULL,
                rule_class VARCHAR(80) NOT NULL,
                extracted_text TEXT NOT NULL,
                is_compliant TINYINT(1) NOT NULL,
                penalty_amount BIGINT NOT NULL DEFAULT 0,
                reason TEXT NOT NULL,
                parsed_data LONGTEXT NOT NULL,
                CONSTRAINT fk_scan_results_scan FOREIGN KEY (scan_id) REFERENCES Scans(scan_id) ON DELETE CASCADE,
                INDEX idx_scan_results_scan_id (scan_id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS AuditEvents (
                event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                actor_id BIGINT UNSIGNED NULL,
                scan_id BIGINT UNSIGNED NULL,
                action VARCHAR(80) NOT NULL,
                details TEXT NOT NULL,
                timestamp VARCHAR(40) NOT NULL,
                CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES Users(user_id),
                CONSTRAINT fk_audit_scan FOREIGN KEY (scan_id) REFERENCES Scans(scan_id) ON DELETE SET NULL,
                INDEX idx_audit_events_timestamp (timestamp)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS SessionTokens (
                token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                selector VARCHAR(64) NOT NULL UNIQUE,
                validator_hash CHAR(64) NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                expires_at VARCHAR(40) NOT NULL,
                last_used_at VARCHAR(40) NULL,
                revoked_at VARCHAR(40) NULL,
                CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
                INDEX idx_session_tokens_user (user_id),
                INDEX idx_session_tokens_expiry (expires_at)
            ) ENGINE=InnoDB;

"""


def initialize_database() -> None:
    with _connection() as connection:
        connection.executescript(_MYSQL_SCHEMA if database_backend() == "mysql" else _SQLITE_SCHEMA)

        _ensure_column(connection, "Users", "active", "INTEGER NOT NULL DEFAULT 1")
        _ensure_column(connection, "Users", "created_at", "TEXT NOT NULL DEFAULT ''")
        _ensure_column(connection, "Scans", "product_name", "TEXT NOT NULL DEFAULT ''")
        _ensure_column(connection, "Scans", "evidence_notes", "TEXT NOT NULL DEFAULT ''")
        _ensure_column(connection, "Scans", "evidence_hashes", "TEXT NOT NULL DEFAULT '[]'")
        _ensure_column(connection, "Scans", "reviewer_id", "INTEGER")
        _ensure_column(connection, "Scans", "reviewed_at", "TEXT")
        _ensure_column(connection, "Scans", "review_reason", "TEXT NOT NULL DEFAULT ''")
        _ensure_column(connection, "ScanResults", "reason", "TEXT NOT NULL DEFAULT ''")
        _ensure_column(connection, "ScanResults", "parsed_data", "TEXT NOT NULL DEFAULT '{}'")

        connection.execute(
            "UPDATE Users SET created_at = ? WHERE created_at IS NULL OR created_at = ''",
            (_now(),),
        )
        user_count = connection.execute("SELECT COUNT(*) FROM Users").fetchone()[0]
        if user_count == 0:
            created_at = _now()
            connection.executemany(
                """
                INSERT INTO Users (username, password, role, active, created_at)
                VALUES (?, ?, ?, 1, ?)
                """,
                (
                    ("inspector", _hash_password("pass123"), "Inspector", created_at),
                    ("verifier", _hash_password("pass123"), "Verifier", created_at),
                    ("admin", _hash_password("pass123"), "Admin", created_at),
                ),
            )


def _user_row(connection: sqlite3.Connection, user_id: int) -> sqlite3.Row | None:
    return connection.execute(
        "SELECT user_id, username, role, active FROM Users WHERE user_id = ?",
        (int(user_id),),
    ).fetchone()


def _require_roles(
    connection: sqlite3.Connection,
    actor_id: int,
    roles: tuple[str, ...],
) -> sqlite3.Row:
    row = _user_row(connection, actor_id)
    if row is None or not bool(row["active"]):
        raise PermissionError("The account is unavailable")
    if str(row["role"]) not in roles:
        raise PermissionError(f"This action requires one of these roles: {', '.join(roles)}")
    return row


def authenticate_user(username: str, password: str) -> dict[str, Any] | None:
    if not isinstance(username, str) or not isinstance(password, str):
        return None
    initialize_database()
    with _connection() as connection:
        row = connection.execute(
            """
            SELECT user_id, username, password, role, active
            FROM Users
            WHERE username = ?
            """,
            (username.strip(),),
        ).fetchone()
    if (
        row is None
        or not bool(row["active"])
        or not _verify_password(password, str(row["password"]))
    ):
        return None
    return {
        "user_id": int(row["user_id"]),
        "username": str(row["username"]),
        "role": str(row["role"]),
        "active": bool(row["active"]),
    }


def _session_token_parts(token: Any) -> tuple[str, str] | None:
    if not isinstance(token, str) or not token or len(token) > 512:
        return None
    selector, separator, validator = token.partition(".")
    if not separator or not selector or not validator:
        return None
    if len(selector) > 64 or len(validator) > 256:
        return None
    return selector, validator


def create_session(user_id: int, ttl_days: int | None = None) -> str:
    """Create an opaque, revocable browser session token.

    Only a selector and SHA-256 validator hash are stored server-side. The raw
    validator is returned once to the browser cookie and is never persisted.
    """

    days = SESSION_TTL_DAYS if ttl_days is None else max(1, int(ttl_days))
    selector = secrets.token_urlsafe(18)
    validator = secrets.token_urlsafe(32)
    expires_at = (datetime.now(timezone.utc) + timedelta(days=days)).isoformat(
        timespec="seconds"
    )
    initialize_database()
    with _connection() as connection:
        user = _require_roles(connection, int(user_id), ALLOWED_ROLES)
        connection.execute(
            """
            INSERT INTO SessionTokens
                (user_id, selector, validator_hash, created_at, expires_at, last_used_at)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            (
                int(user["user_id"]),
                selector,
                hashlib.sha256(validator.encode("utf-8")).hexdigest(),
                _now(),
                expires_at,
                _now(),
            ),
        )
    return f"{selector}.{validator}"


def get_session(token: Any) -> dict[str, Any] | None:
    """Validate a browser token and return its current active user."""

    parts = _session_token_parts(token)
    if parts is None:
        return None
    selector, validator = parts
    initialize_database()
    with _connection() as connection:
        row = connection.execute(
            """
            SELECT t.token_id, t.validator_hash, t.expires_at,
                   u.user_id, u.username, u.role, u.active
            FROM SessionTokens AS t
            JOIN Users AS u ON u.user_id = t.user_id
            WHERE t.selector = ? AND t.revoked_at IS NULL
            """,
            (selector,),
        ).fetchone()
        if row is None:
            return None
        if not bool(row["active"]):
            connection.execute(
                "UPDATE SessionTokens SET revoked_at = ? WHERE token_id = ?",
                (_now(), int(row["token_id"])),
            )
            return None
        try:
            expires_at = datetime.fromisoformat(str(row["expires_at"]))
            if expires_at.tzinfo is None:
                expires_at = expires_at.replace(tzinfo=timezone.utc)
        except (TypeError, ValueError):
            return None
        if expires_at <= datetime.now(timezone.utc):
            connection.execute(
                "UPDATE SessionTokens SET revoked_at = ? WHERE token_id = ?",
                (_now(), int(row["token_id"])),
            )
            return None
        expected = str(row["validator_hash"])
        actual = hashlib.sha256(validator.encode("utf-8")).hexdigest()
        if not hmac.compare_digest(actual, expected):
            return None
        connection.execute(
            "UPDATE SessionTokens SET last_used_at = ? WHERE token_id = ?",
            (_now(), int(row["token_id"])),
        )
    return {
        "user_id": int(row["user_id"]),
        "username": str(row["username"]),
        "role": str(row["role"]),
        "active": True,
    }


def revoke_session(token: Any) -> bool:
    parts = _session_token_parts(token)
    if parts is None:
        return False
    selector, validator = parts
    initialize_database()
    validator_hash = hashlib.sha256(validator.encode("utf-8")).hexdigest()
    with _connection() as connection:
        cursor = connection.execute(
            """
            UPDATE SessionTokens
            SET revoked_at = ?
            WHERE selector = ? AND validator_hash = ? AND revoked_at IS NULL
            """,
            (_now(), selector, validator_hash),
        )
    return cursor.rowcount > 0


def revoke_user_sessions(user_id: int) -> int:
    initialize_database()
    with _connection() as connection:
        cursor = connection.execute(
            """
            UPDATE SessionTokens SET revoked_at = ?
            WHERE user_id = ? AND revoked_at IS NULL
            """,
            (_now(), int(user_id)),
        )
    return max(0, int(cursor.rowcount))


def create_user(
    actor_id: int,
    username: str,
    password: str,
    role: str,
) -> int:
    normalized_username = str(username).strip()
    normalized_role = str(role).strip().title()
    if not 3 <= len(normalized_username) <= 64:
        raise ValueError("username must contain between 3 and 64 characters")
    if len(password) < 6:
        raise ValueError("password must contain at least 6 characters")
    if normalized_role not in ALLOWED_ROLES:
        raise ValueError(f"role must be one of {ALLOWED_ROLES}")
    initialize_database()
    with _connection() as connection:
        _require_roles(connection, actor_id, ("Admin",))
        try:
            cursor = connection.execute(
                """
                INSERT INTO Users (username, password, role, active, created_at)
                VALUES (?, ?, ?, 1, ?)
                """,
                (
                    normalized_username,
                    _hash_password(password),
                    normalized_role,
                    _now(),
                ),
            )
        except Exception as error:
            if not _is_integrity_error(error):
                raise
            raise ValueError("username already exists") from error
        user_id = int(cursor.lastrowid)
        connection.execute(
            """
            INSERT INTO AuditEvents (actor_id, action, details, timestamp)
            VALUES (?, 'CREATE_USER', ?, ?)
            """,
            (int(actor_id), f"Created {normalized_role} account {normalized_username}", _now()),
        )
    return user_id


def list_users(actor_id: int) -> list[dict[str, Any]]:
    initialize_database()
    with _connection() as connection:
        _require_roles(connection, actor_id, ("Admin",))
        rows = connection.execute(
            """
            SELECT user_id, username, role, active, created_at
            FROM Users
            ORDER BY CASE role WHEN 'Admin' THEN 1 WHEN 'Verifier' THEN 2 ELSE 3 END,
                     username
            """
        ).fetchall()
    return [
        {
            "user_id": int(row["user_id"]),
            "username": str(row["username"]),
            "role": str(row["role"]),
            "active": bool(row["active"]),
            "created_at": str(row["created_at"] or ""),
        }
        for row in rows
    ]


def set_user_active(actor_id: int, user_id: int, active: bool) -> bool:
    initialize_database()
    with _connection() as connection:
        _require_roles(connection, actor_id, ("Admin",))
        target = _user_row(connection, user_id)
        if target is None:
            raise LookupError(f"User {user_id} was not found")
        if int(actor_id) == int(user_id) and not active:
            raise ValueError("An administrator cannot deactivate the current account")
        if str(target["role"]) == "Admin" and not active:
            active_admins = connection.execute(
                "SELECT COUNT(*) FROM Users WHERE role = 'Admin' AND active = 1"
            ).fetchone()[0]
            if int(active_admins) <= 1:
                raise ValueError("At least one active administrator is required")
        cursor = connection.execute(
            "UPDATE Users SET active = ? WHERE user_id = ?",
            (int(bool(active)), int(user_id)),
        )
        if cursor.rowcount == 0:
            raise LookupError(f"User {user_id} was not found")
        if not active:
            connection.execute(
                "UPDATE SessionTokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL",
                (_now(), int(user_id)),
            )
        connection.execute(
            """
            INSERT INTO AuditEvents (actor_id, action, details, timestamp)
            VALUES (?, 'UPDATE_USER_STATUS', ?, ?)
            """,
            (int(actor_id), f"User {user_id} active={bool(active)}", _now()),
        )
    return True


def _json_text(value: Any, fallback: str) -> str:
    try:
        return json.dumps(value, ensure_ascii=False, default=str)
    except (TypeError, ValueError):
        return fallback


def _normalize_results(results_dict: Any) -> list[dict[str, Any]]:
    if isinstance(results_dict, list):
        raw_results = results_dict
    elif isinstance(results_dict, dict):
        raw_results = []
        for rule_class, details in results_dict.items():
            if isinstance(details, dict):
                item = dict(details)
                item.setdefault("rule_class", rule_class)
                raw_results.append(item)
    else:
        raise TypeError("results_dict must be a list or dictionary")

    normalized: list[dict[str, Any]] = []
    control_keys = {
        "rule_class",
        "extracted_text",
        "is_compliant",
        "penalty_amount",
        "reason",
        "parsed_data",
    }
    for item in raw_results:
        if not isinstance(item, dict):
            continue
        rule_class = str(item.get("rule_class", "")).strip()
        if not rule_class:
            continue
        is_compliant = bool(item.get("is_compliant", False))
        penalty = item.get("penalty_amount", 0)
        try:
            penalty_amount = max(0, int(penalty))
        except (TypeError, ValueError):
            penalty_amount = 0
        parsed_data = item.get("parsed_data")
        if not isinstance(parsed_data, dict):
            parsed_data = {
                key: value for key, value in item.items() if key not in control_keys
            }
        reason = str(item.get("reason") or "").strip()
        if not reason:
            reason = (
                "All detected declarations passed the configured checks."
                if is_compliant
                else "The declaration is missing, unreadable, or failed a configured check."
            )
        normalized.append(
            {
                "rule_class": rule_class,
                "extracted_text": str(item.get("extracted_text", "") or ""),
                "is_compliant": is_compliant,
                "penalty_amount": penalty_amount,
                "reason": reason,
                "parsed_data": parsed_data,
            }
        )
    return normalized


def _normalize_image_paths(image_paths: Any) -> list[str]:
    if isinstance(image_paths, (str, Path)):
        image_paths = [str(image_paths)]
    if not isinstance(image_paths, (list, tuple)):
        raise TypeError("image_paths must be a list of paths")
    normalized = [str(path).strip() for path in image_paths if str(path).strip()]
    if not normalized:
        raise ValueError("image_paths must contain at least one path")
    return normalized


def _deserialize_image_paths(serialized_paths: Any) -> list[str]:
    if isinstance(serialized_paths, (list, tuple)):
        return _normalize_image_paths(serialized_paths)
    serialized = str(serialized_paths or "").strip()
    if not serialized:
        return []
    try:
        decoded = json.loads(serialized)
    except json.JSONDecodeError:
        decoded = None
    if isinstance(decoded, list):
        return [str(path).strip() for path in decoded if str(path).strip()]
    return [path.strip() for path in serialized.split(",") if path.strip()]


def _hash_file(path: str) -> str:
    try:
        digest = hashlib.sha256()
        with Path(path).open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()
    except (OSError, ValueError):
        return ""


def insert_scan(
    inspector_id: int,
    image_paths: list[str],
    results_dict: Any,
    evidence_notes: str = "",
) -> int:
    normalized_image_paths = _normalize_image_paths(image_paths)
    normalized_results = _normalize_results(results_dict)
    image_hashes = [_hash_file(path) for path in normalized_image_paths]
    product_name = ""
    for result in normalized_results:
        if result["rule_class"] in {"product_name", "product_name_region"}:
            product_name = result["extracted_text"]
            break
    initialize_database()
    with _connection() as connection:
        inspector = _require_roles(connection, inspector_id, ("Inspector",))
        cursor = connection.execute(
            """
            INSERT INTO Scans
                (inspector_id, image_path, status, timestamp, product_name,
                 evidence_notes, evidence_hashes)
            VALUES (?, ?, 'PENDING', ?, ?, ?, ?)
            """,
            (
                int(inspector_id),
                _json_text(normalized_image_paths, "[]"),
                _now(),
                product_name,
                str(evidence_notes or "").strip(),
                _json_text(image_hashes, "[]"),
            ),
        )
        scan_id = int(cursor.lastrowid)
        connection.executemany(
            """
            INSERT INTO ScanResults
                (scan_id, rule_class, extracted_text, is_compliant,
                 penalty_amount, reason, parsed_data)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            """,
            [
                (
                    scan_id,
                    result["rule_class"],
                    result["extracted_text"],
                    int(result["is_compliant"]),
                    result["penalty_amount"],
                    result["reason"],
                    _json_text(result["parsed_data"], "{}"),
                )
                for result in normalized_results
            ],
        )
        connection.execute(
            """
            INSERT INTO AuditEvents (actor_id, scan_id, action, details, timestamp)
            VALUES (?, ?, 'SUBMIT_SCAN', ?, ?)
            """,
            (
                int(inspector["user_id"]),
                scan_id,
                f"Submitted {len(normalized_image_paths)} evidence image(s)",
                _now(),
            ),
        )
    return scan_id


def _scan_from_rows(rows: list[sqlite3.Row]) -> list[dict[str, Any]]:
    scans: dict[int, dict[str, Any]] = {}
    for row in rows:
        scan_id = int(row["scan_id"])
        scan = scans.setdefault(
            scan_id,
            {
                "scan_id": scan_id,
                "inspector_id": int(row["inspector_id"]),
                "inspector_username": str(row["inspector_username"]),
                "image_path": _deserialize_image_paths(row["image_path"]),
                "image_paths": _deserialize_image_paths(row["image_path"]),
                "evidence_hashes": _deserialize_image_paths(row["evidence_hashes"]),
                "product_name": str(row["product_name"] or ""),
                "evidence_notes": str(row["evidence_notes"] or ""),
                "status": str(row["status"]),
                "timestamp": str(row["timestamp"]),
                "reviewer_id": int(row["reviewer_id"]) if row["reviewer_id"] else None,
                "reviewed_at": str(row["reviewed_at"] or ""),
                "review_reason": str(row["review_reason"] or ""),
                "results": [],
            },
        )
        if row["result_id"] is not None:
            parsed_data: Any = {}
            try:
                parsed_data = json.loads(str(row["parsed_data"] or "{}"))
            except json.JSONDecodeError:
                parsed_data = {}
            scan["results"].append(
                {
                    "result_id": int(row["result_id"]),
                    "rule_class": str(row["rule_class"]),
                    "extracted_text": str(row["extracted_text"] or ""),
                    "is_compliant": bool(row["is_compliant"]),
                    "penalty_amount": int(row["penalty_amount"]),
                    "reason": str(row["reason"] or ""),
                    "parsed_data": parsed_data,
                }
            )
    return list(scans.values())


def _scan_select() -> str:
    return """
        SELECT s.scan_id, s.inspector_id, u.username AS inspector_username,
               s.image_path, s.evidence_hashes, s.product_name,
               s.evidence_notes, s.status, s.timestamp, s.reviewer_id,
               s.reviewed_at, s.review_reason,
               r.result_id, r.rule_class, r.extracted_text,
               r.is_compliant, r.penalty_amount, r.reason, r.parsed_data
        FROM Scans AS s
        JOIN Users AS u ON u.user_id = s.inspector_id
        LEFT JOIN ScanResults AS r ON r.scan_id = s.scan_id
    """


def get_pending_scans(actor_id: int | None = None) -> list[dict[str, Any]]:
    initialize_database()
    with _connection() as connection:
        if actor_id is not None:
            _require_roles(connection, actor_id, ("Verifier", "Admin"))
        rows = connection.execute(
            _scan_select()
            + " WHERE s.status = 'PENDING' ORDER BY s.timestamp ASC, s.scan_id ASC, r.result_id ASC"
        ).fetchall()
    return _scan_from_rows(rows)


def get_scan(scan_id: int, actor_id: int | None = None) -> dict[str, Any] | None:
    initialize_database()
    with _connection() as connection:
        scan_row = connection.execute(
            "SELECT inspector_id FROM Scans WHERE scan_id = ?",
            (int(scan_id),),
        ).fetchone()
        if scan_row is None:
            return None
        if actor_id is not None:
            actor = _user_row(connection, actor_id)
            if actor is None or not bool(actor["active"]):
                raise PermissionError("The account is unavailable")
            if str(actor["role"]) == "Inspector" and int(actor_id) != int(scan_row["inspector_id"]):
                raise PermissionError("Inspectors can only access their own scans")
            if str(actor["role"]) not in ALLOWED_ROLES:
                raise PermissionError("The account has no scanner permissions")
        rows = connection.execute(
            _scan_select()
            + " WHERE s.scan_id = ? ORDER BY r.result_id ASC",
            (int(scan_id),),
        ).fetchall()
    scans = _scan_from_rows(rows)
    return scans[0] if scans else None


def get_scan_history(
    actor_id: int,
    query: str = "",
    status: str = "ALL",
    limit: int = 200,
) -> list[dict[str, Any]]:
    normalized_status = str(status).upper().strip() or "ALL"
    if normalized_status != "ALL" and normalized_status not in ALLOWED_SCAN_STATUSES:
        raise ValueError(f"status must be ALL or one of {ALLOWED_SCAN_STATUSES}")
    safe_limit = max(1, min(int(limit), 1000))
    initialize_database()
    with _connection() as connection:
        actor = _user_row(connection, actor_id)
        if actor is None or not bool(actor["active"]):
            raise PermissionError("The account is unavailable")
        clauses = ["1 = 1"]
        params: list[Any] = []
        if str(actor["role"]) == "Inspector":
            clauses.append("s.inspector_id = ?")
            params.append(int(actor_id))
        elif str(actor["role"]) not in {"Verifier", "Admin"}:
            raise PermissionError("The account has no scanner permissions")
        if normalized_status != "ALL":
            clauses.append("s.status = ?")
            params.append(normalized_status)
        normalized_query = str(query or "").strip()
        if normalized_query:
            clauses.append(
                "(CAST(s.scan_id AS TEXT) LIKE ? OR u.username LIKE ? OR "
                "s.product_name LIKE ? OR s.evidence_notes LIKE ?)"
            )
            wildcard = f"%{normalized_query}%"
            params.extend([wildcard, wildcard, wildcard, wildcard])
        params.append(safe_limit * 20)
        rows = connection.execute(
            _scan_select()
            + f" WHERE {' AND '.join(clauses)} "
            + "ORDER BY s.timestamp DESC, s.scan_id DESC, r.result_id ASC LIMIT ?",
            params,
        ).fetchall()
    return _scan_from_rows(rows)[:safe_limit]


def update_scan_status(
    scan_id: int,
    new_status: str,
    actor_id: int | None = None,
    review_reason: str = "",
) -> bool:
    normalized_status = str(new_status).upper().strip()
    if normalized_status not in ALLOWED_SCAN_STATUSES:
        raise ValueError(f"new_status must be one of {ALLOWED_SCAN_STATUSES}")
    initialize_database()
    with _connection() as connection:
        current = connection.execute(
            "SELECT status FROM Scans WHERE scan_id = ?",
            (int(scan_id),),
        ).fetchone()
        if current is None:
            raise LookupError(f"Scan {scan_id} was not found")
        reviewer_id = None
        if actor_id is None:
            raise PermissionError("actor_id is required to change a scan status")
        actor = _require_roles(connection, actor_id, ("Verifier", "Admin"))
        reviewer_id = int(actor["user_id"])
        if str(current["status"]) != "PENDING" and normalized_status != str(current["status"]):
            raise ValueError("Only pending scans can be moved to a new review status")
        if normalized_status == "OVERRIDDEN" and not str(review_reason or "").strip():
            raise ValueError("An override requires a review reason")
        reviewed_at = _now() if normalized_status != "PENDING" else None
        connection.execute(
            """
            UPDATE Scans
            SET status = ?, reviewer_id = ?, reviewed_at = ?, review_reason = ?
            WHERE scan_id = ?
            """,
            (
                normalized_status,
                reviewer_id,
                reviewed_at,
                str(review_reason or "").strip(),
                int(scan_id),
            ),
        )
        connection.execute(
            """
            INSERT INTO AuditEvents (actor_id, scan_id, action, details, timestamp)
            VALUES (?, ?, ?, ?, ?)
            """,
            (
                reviewer_id,
                int(scan_id),
                f"SCAN_{normalized_status}",
                str(review_reason or "").strip(),
                _now(),
            ),
        )
    return True


def get_analytics(actor_id: int | None = None) -> dict[str, Any]:
    initialize_database()
    with _connection() as connection:
        if actor_id is not None:
            _require_roles(connection, actor_id, ("Admin",))
        total_scans = int(connection.execute("SELECT COUNT(*) FROM Scans").fetchone()[0])
        failed_scans = int(
            connection.execute(
                """
                SELECT COUNT(DISTINCT scan_id)
                FROM ScanResults
                WHERE is_compliant = 0
                """
            ).fetchone()[0]
        )
        total_violations = int(
            connection.execute(
                "SELECT COUNT(*) FROM ScanResults WHERE is_compliant = 0"
            ).fetchone()[0]
        )
        total_penalty = int(
            connection.execute(
                """
                SELECT COALESCE(SUM(penalty_amount), 0)
                FROM ScanResults
                WHERE is_compliant = 0
                """
            ).fetchone()[0]
        )
        status_rows = connection.execute(
            "SELECT status, COUNT(*) AS count FROM Scans GROUP BY status"
        ).fetchall()
        rule_rows = connection.execute(
            """
            SELECT rule_class, COUNT(*) AS total,
                   SUM(CASE WHEN is_compliant = 0 THEN 1 ELSE 0 END) AS violations
            FROM ScanResults
            GROUP BY rule_class
            ORDER BY violations DESC, rule_class
            """
        ).fetchall()
        active_users = int(
            connection.execute("SELECT COUNT(*) FROM Users WHERE active = 1").fetchone()[0]
        )
    failure_rate = (failed_scans / total_scans * 100) if total_scans else 0.0
    status_counts = {str(row["status"]): int(row["count"]) for row in status_rows}
    violations_by_rule = [
        {
            "rule_class": str(row["rule_class"]),
            "total": int(row["total"]),
            "violations": int(row["violations"] or 0),
        }
        for row in rule_rows
    ]
    return {
        "total_scans": total_scans,
        "failed_scans": failed_scans,
        "total_violations": total_violations,
        "compliance_failure_rate": failure_rate,
        "total_potential_penalty_revenue": total_penalty,
        "status_counts": status_counts,
        "violations_by_rule": violations_by_rule,
        "active_users": active_users,
    }


def get_all_scans(actor_id: int | None = None) -> list[dict[str, Any]]:
    if actor_id is None:
        initialize_database()
        with _connection() as connection:
            rows = connection.execute(
                """
                SELECT s.scan_id, s.inspector_id, u.username AS inspector_username,
                       s.image_path, s.status, s.timestamp, s.product_name,
                       s.evidence_notes, s.evidence_hashes, s.reviewer_id,
                       s.reviewed_at, s.review_reason
                FROM Scans AS s JOIN Users AS u ON u.user_id = s.inspector_id
                ORDER BY s.scan_id DESC
                """
            ).fetchall()
        return [dict(row) for row in rows]
    return get_scan_history(actor_id, limit=1000)


def get_all_scan_results(actor_id: int | None = None) -> list[dict[str, Any]]:
    initialize_database()
    with _connection() as connection:
        if actor_id is not None:
            _require_roles(connection, actor_id, ("Admin",))
        rows = connection.execute(
            """
            SELECT result_id, scan_id, rule_class, extracted_text,
                   is_compliant, penalty_amount, reason, parsed_data
            FROM ScanResults
            ORDER BY result_id DESC
            """
        ).fetchall()
    records = []
    for row in rows:
        record = dict(row)
        try:
            record["parsed_data"] = json.loads(str(record["parsed_data"] or "{}"))
        except json.JSONDecodeError:
            record["parsed_data"] = {}
        record["is_compliant"] = bool(record["is_compliant"])
        records.append(record)
    return records


def get_audit_events(actor_id: int, limit: int = 200) -> list[dict[str, Any]]:
    initialize_database()
    with _connection() as connection:
        _require_roles(connection, actor_id, ("Admin",))
        rows = connection.execute(
            """
            SELECT e.event_id, e.actor_id, u.username AS actor_username,
                   e.scan_id, e.action, e.details, e.timestamp
            FROM AuditEvents AS e
            LEFT JOIN Users AS u ON u.user_id = e.actor_id
            ORDER BY e.event_id DESC
            LIMIT ?
            """,
            (max(1, min(int(limit), 1000)),),
        ).fetchall()
    return [dict(row) for row in rows]


DATABASE_INIT_ERROR: str | None = None


def ensure_database() -> bool:
    """Initialize the selected backend and retain a UI-friendly error message."""

    global DATABASE_INIT_ERROR
    try:
        initialize_database()
    except Exception as error:
        DATABASE_INIT_ERROR = str(error)
        return False
    DATABASE_INIT_ERROR = None
    return True


ensure_database()
