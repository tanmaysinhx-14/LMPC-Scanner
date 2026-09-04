from __future__ import annotations

from datetime import datetime

import session_cookie


class _FakeController:
    def __init__(self):
        self.values = {}
        self.set_calls = []
        self.remove_calls = []

    def get(self, name):
        return self.values.get(name)

    def set(self, name, value, **options):
        self.values[name] = value
        self.set_calls.append((name, value, options))

    def remove(self, name, **options):
        self.values.pop(name, None)
        self.remove_calls.append((name, options))


def test_set_token_uses_long_lived_secure_cookie(monkeypatch):
    controller = _FakeController()
    monkeypatch.setattr(session_cookie, "_controller", lambda: controller)
    monkeypatch.setenv("LMPC_COOKIE_SECURE", "1")

    assert session_cookie.set_token("selector.validator")
    name, value, options = controller.set_calls[0]
    assert name == session_cookie.COOKIE_NAME
    assert value == "selector.validator"
    assert options["secure"] is True
    assert options["same_site"] == "lax"
    assert options["max_age"] == session_cookie.COOKIE_TTL_SECONDS
    assert isinstance(options["expires"], datetime)
    assert options["expires"].tzinfo is not None


def test_get_token_prefers_request_cookie(monkeypatch):
    controller = _FakeController()
    controller.values[session_cookie.COOKIE_NAME] = "component-token"
    monkeypatch.setattr(session_cookie, "_controller", lambda: controller)
    monkeypatch.setattr(session_cookie, "_request_cookie", lambda: "request-token")

    assert session_cookie.get_token() == "request-token"


def test_clear_token_removes_cookie(monkeypatch):
    controller = _FakeController()
    controller.values[session_cookie.COOKIE_NAME] = "selector.validator"
    monkeypatch.setattr(session_cookie, "_controller", lambda: controller)

    assert session_cookie.clear_token()
    assert controller.values == {}
    assert controller.remove_calls[0][0] == session_cookie.COOKIE_NAME
