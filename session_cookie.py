"""Small browser-cookie adapter for the Streamlit authentication session.

The cookie contains only an opaque database-backed session token. User identity,
role and revocation state stay in ``db.py``. The component is optional at import
time so the application can still start in a minimal offline environment, but
the normal requirements install it for refresh-safe login state.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
import os
from typing import Any


COOKIE_NAME = "lmpc_session"
COOKIE_CONTROLLER_KEY = "lmpc_cookie_controller"
COOKIE_TTL_SECONDS = max(3600, int(os.environ.get("LMPC_SESSION_TTL_DAYS", "30")) * 86400)


def _controller() -> Any | None:
    try:
        from streamlit_cookies_controller import CookieController
    except ImportError:
        return None
    try:
        return CookieController(key=COOKIE_CONTROLLER_KEY)
    except Exception:
        return None


def _request_cookie() -> str | None:
    try:
        import streamlit as st

        cookies = st.context.cookies
        value = cookies.get(COOKIE_NAME) if cookies is not None else None
        return str(value) if value else None
    except Exception:
        return None


def get_token() -> str | None:
    value = _request_cookie()
    if value:
        return value
    controller = _controller()
    if controller is None:
        return None
    try:
        value = controller.get(COOKIE_NAME)
    except Exception:
        return None
    return str(value) if value else None


def set_token(token: str) -> bool:
    controller = _controller()
    if controller is None:
        return False
    secure = os.environ.get("LMPC_COOKIE_SECURE", "0").strip().lower() in {
        "1", "true", "yes", "on"
    }
    try:
        controller.set(
            COOKIE_NAME,
            token,
            path="/",
            expires=datetime.now(timezone.utc) + timedelta(seconds=COOKIE_TTL_SECONDS),
            max_age=COOKIE_TTL_SECONDS,
            secure=secure,
            same_site="lax",
        )
    except Exception:
        return False
    return True


def clear_token() -> bool:
    controller = _controller()
    if controller is None:
        return False
    try:
        controller.remove(COOKIE_NAME, path="/")
    except Exception:
        return False
    return True
