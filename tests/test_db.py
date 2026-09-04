from __future__ import annotations

import sqlite3

import pytest

import db


@pytest.fixture
def isolated_database(monkeypatch, tmp_path):
    monkeypatch.delenv("LMPC_DB_BACKEND", raising=False)
    monkeypatch.setattr(db, "DB_PATH", tmp_path / "scanner.db")
    db.DATABASE_INIT_ERROR = None
    assert db.ensure_database()
    return tmp_path / "scanner.db"


def test_session_token_is_opaque_persistent_and_revocable(isolated_database):
    user = db.authenticate_user("inspector", "pass123")
    assert user is not None

    token = db.create_session(user["user_id"], ttl_days=1)
    assert token.count(".") == 1
    assert db.get_session(token)["username"] == "inspector"

    with sqlite3.connect(isolated_database) as connection:
        selector = token.split(".", 1)[0]
        row = connection.execute(
            "SELECT validator_hash FROM SessionTokens WHERE selector = ?", (selector,)
        ).fetchone()
    assert row is not None
    assert row[0] != token.split(".", 1)[1]
    assert db.revoke_session(token) is True
    assert db.get_session(token) is None
    assert db.revoke_session(token) is False


def test_expired_session_is_rejected(isolated_database):
    user = db.authenticate_user("inspector", "pass123")
    token = db.create_session(user["user_id"], ttl_days=1)
    selector = token.split(".", 1)[0]
    with sqlite3.connect(isolated_database) as connection:
        connection.execute(
            "UPDATE SessionTokens SET expires_at = ? WHERE selector = ?",
            ("2000-01-01T00:00:00+00:00", selector),
        )
    assert db.get_session(token) is None


def test_deactivating_user_revokes_all_sessions(isolated_database):
    inspector = db.authenticate_user("inspector", "pass123")
    admin = db.authenticate_user("admin", "pass123")
    token = db.create_session(inspector["user_id"])
    assert db.set_user_active(admin["user_id"], inspector["user_id"], False)
    assert db.get_session(token) is None
    assert db.authenticate_user("inspector", "pass123") is None
    assert db.set_user_active(admin["user_id"], inspector["user_id"], True)


def test_rbac_restricts_inspector_from_verifier_and_admin_operations(isolated_database):
    inspector = db.authenticate_user("inspector", "pass123")
    assert inspector is not None
    with pytest.raises(PermissionError):
        db.get_pending_scans(inspector["user_id"])
    with pytest.raises(PermissionError):
        db.get_analytics(inspector["user_id"])
    with pytest.raises(PermissionError):
        db.list_users(inspector["user_id"])


def test_backend_aliases_are_explicit(monkeypatch):
    monkeypatch.setenv("LMPC_DB_BACKEND", "xampp")
    assert db.database_backend() == "mysql"
    monkeypatch.setenv("LMPC_DB_BACKEND", "sqlite")
    assert db.database_backend() == "sqlite"


def test_mysql_sql_adapter_translates_placeholders_and_casts():
    sql = db._mysql_sql(
        "SELECT CAST(s.scan_id AS TEXT) FROM Scans WHERE status = ? LIMIT ?"
    )
    assert "CAST(s.scan_id AS CHAR)" in sql
    assert sql.endswith("status = %s LIMIT %s")
