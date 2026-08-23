<?php
/**
 * Application authentication and session primitives.
 *
 * The PHP session contains only a small, non-sensitive user context. The
 * browser never receives a password, user record, or database session data.
 * Remember-me credentials are opaque, rotating tokens whose hashes are kept
 * in the database.
 */

const APP_REMEMBER_COOKIE = 'civicconnect_remember';
const APP_REMEMBER_DAYS = 30;
const APP_SESSION_IDLE_SECONDS = 1800;

function appIsHttps(): bool
{
  return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

function appCookieOptions(int $expires = 0): array
{
  return [
    'expires' => $expires,
    'path' => '/',
    'secure' => appIsHttps(),
    'httponly' => true,
    'samesite' => 'Lax',
  ];
}

function appSessionCookieParams(): array
{
  return [
    'lifetime' => 0,
    'path' => '/',
    'secure' => appIsHttps(),
    'httponly' => true,
    'samesite' => 'Lax',
  ];
}

function startAppSession(): void
{
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }

  ini_set('session.use_only_cookies', '1');
  ini_set('session.use_strict_mode', '1');
  ini_set('session.use_trans_sid', '0');
  ini_set('session.cookie_httponly', '1');
  ini_set('session.cookie_secure', appIsHttps() ? '1' : '0');
  ini_set('session.cookie_samesite', 'Lax');
  ini_set('session.gc_maxlifetime', (string) APP_SESSION_IDLE_SECONDS);

  session_name('civicconnect_session');
  session_set_cookie_params(appSessionCookieParams());
  session_start();

  if (($_SESSION['logged_in'] ?? false) === true
    && !empty($_SESSION['last_activity'])
    && time() - (int) $_SESSION['last_activity'] > APP_SESSION_IDLE_SECONDS) {
    // Expire only the idle session. A valid remember token can still restore
    // the account on the next request, subject to its own rotation checks.
    $_SESSION = [];
    session_regenerate_id(true);
  }
  $_SESSION['last_activity'] = time();
}

function appRememberCookieValue(): ?array
{
  $value = $_COOKIE[APP_REMEMBER_COOKIE] ?? '';
  if (!is_string($value) || !preg_match('/^[a-f0-9]{36}\.[a-f0-9]{64}$/', $value)) {
    return null;
  }

  [$selector, $validator] = explode('.', $value, 2);
  return ['selector' => $selector, 'validator' => $validator];
}

function clearRememberCookie(): void
{
  setcookie(APP_REMEMBER_COOKIE, '', appCookieOptions(time() - 3600));
  unset($_COOKIE[APP_REMEMBER_COOKIE]);
}

function loginRateLimitKey(string $email): string
{
  return hash('sha256', strtolower(trim($email)) . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function isLoginRateLimited(PDO $db, string $email): bool
{
  try {
    $stmt = $db->prepare(
      'SELECT attempts, window_started_at, blocked_until
         FROM auth_login_attempts WHERE identity_hash = ? LIMIT 1'
    );
    $stmt->execute([loginRateLimitKey($email)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return false;
    }

    if (!empty($row['blocked_until']) && strtotime($row['blocked_until']) > time()) {
      return true;
    }
    if (empty($row['window_started_at']) || strtotime($row['window_started_at']) <= time() - 900) {
      $db->prepare('DELETE FROM auth_login_attempts WHERE identity_hash = ?')
        ->execute([loginRateLimitKey($email)]);
    }
  } catch (Throwable $exception) {
    // A missing optional throttle table must not turn a login failure into a
    // fatal error during an incremental deployment.
    return false;
  }
  return false;
}

function recordLoginFailure(PDO $db, string $email): void
{
  try {
    $identityHash = loginRateLimitKey($email);
    $stmt = $db->prepare(
      'SELECT attempts, window_started_at FROM auth_login_attempts WHERE identity_hash = ? LIMIT 1'
    );
    $stmt->execute([$identityHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['window_started_at']) || strtotime($row['window_started_at']) <= time() - 900) {
      $db->prepare(
        'INSERT INTO auth_login_attempts (identity_hash, attempts, window_started_at, blocked_until)
         VALUES (?, 1, NOW(), NULL)
         ON DUPLICATE KEY UPDATE attempts = 1, window_started_at = NOW(), blocked_until = NULL'
      )->execute([$identityHash]);
      return;
    }

    $attempts = (int) $row['attempts'] + 1;
    $blockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
    $db->prepare(
      'UPDATE auth_login_attempts SET attempts = ?, blocked_until = ? WHERE identity_hash = ?'
    )->execute([$attempts, $blockedUntil, $identityHash]);
  } catch (Throwable $exception) {
    error_log('Login throttle update failed: ' . $exception->getMessage());
  }
}

function clearLoginFailures(PDO $db, string $email): void
{
  try {
    $db->prepare('DELETE FROM auth_login_attempts WHERE identity_hash = ?')
      ->execute([loginRateLimitKey($email)]);
  } catch (Throwable $exception) {
    error_log('Login throttle cleanup failed: ' . $exception->getMessage());
  }
}

function issueRememberToken(PDO $db, int $userId): void
{
  $selector = bin2hex(random_bytes(18));
  $validator = bin2hex(random_bytes(32));
  $tokenHash = hash('sha256', $validator);
  $expiresAt = date('Y-m-d H:i:s', time() + (APP_REMEMBER_DAYS * 86400));

  $stmt = $db->prepare(
    'INSERT INTO remember_tokens
      (user_id, selector, token_hash, expires_at, user_agent_hash, created_at, last_used_at)
     VALUES (:user_id, :selector, :token_hash, :expires_at, :user_agent_hash, NOW(), NOW())'
  );
  $stmt->execute([
    'user_id' => $userId,
    'selector' => $selector,
    'token_hash' => $tokenHash,
    'expires_at' => $expiresAt,
    'user_agent_hash' => hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
  ]);

  setcookie(
    APP_REMEMBER_COOKIE,
    $selector . '.' . $validator,
    appCookieOptions(time() + (APP_REMEMBER_DAYS * 86400))
  );
}

function restoreRememberedLogin(PDO $db): void
{
  startAppSession();

  if (($_SESSION['logged_in'] ?? false) === true) {
    return;
  }

  $remember = appRememberCookieValue();
  if ($remember === null) {
    return;
  }

  try {
    $stmt = $db->prepare(
      'SELECT rt.id AS remember_id, rt.user_id, rt.token_hash,
              u.id, u.name, u.email, u.role, u.ward_id, u.city, u.is_active
         FROM remember_tokens rt
         INNER JOIN users u ON u.id = rt.user_id
        WHERE rt.selector = :selector
          AND rt.expires_at > NOW()
        LIMIT 1'
    );
    $stmt->execute(['selector' => $remember['selector']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $exception) {
    error_log('Remember-me lookup failed: ' . $exception->getMessage());
    clearRememberCookie();
    return;
  }

  if (!$row || (int) $row['is_active'] !== 1 || !hash_equals(
    (string) $row['token_hash'],
    hash('sha256', $remember['validator'])
  )) {
    if ($row) {
      $db->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([(int) $row['remember_id']]);
    }
    clearRememberCookie();
    return;
  }

  // Rotate the token after every successful use so a stolen token has a
  // narrow replay window and cannot be reused indefinitely.
  $db->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([(int) $row['remember_id']]);
  loginUser($db, $row, true);
}

function refreshSessionUser(PDO $db): void
{
  startAppSession();
  if (($_SESSION['logged_in'] ?? false) !== true || empty($_SESSION['user_id'])) {
    return;
  }

  $stmt = $db->prepare(
    'SELECT id, name, email, role, ward_id, city, is_active FROM users WHERE id = ? LIMIT 1'
  );
  $stmt->execute([(int) $_SESSION['user_id']]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user || (int) $user['is_active'] !== 1) {
    logoutUser($db);
    return;
  }

  $_SESSION['user_name'] = $user['name'];
  $_SESSION['user_email'] = $user['email'];
  $_SESSION['user_role'] = $user['role'];
  $_SESSION['user_ward_id'] = $user['ward_id'] ?? null;
  $_SESSION['user_city'] = $user['city'] ?? null;
}

function loginUser(PDO $db, array $user, bool $rememberMe = false): void
{
  startAppSession();
  session_regenerate_id(true);

  $_SESSION = [
    'logged_in' => true,
    'user_id' => (int) $user['id'],
    'user_name' => (string) ($user['name'] ?? $user['full_name'] ?? ''),
    'user_email' => (string) $user['email'],
    'user_role' => (string) $user['role'],
    'user_ward_id' => $user['ward_id'] ?? null,
    'user_city' => $user['city'] ?? null,
    'auth_time' => time(),
    'last_activity' => time(),
    'csrf_token' => bin2hex(random_bytes(32)),
  ];

  if ($rememberMe) {
    try {
      issueRememberToken($db, (int) $user['id']);
    } catch (Throwable $exception) {
      // Login remains usable if an existing installation has not applied the
      // remember-token migration yet; do not silently create a weak cookie.
      error_log('Remember-me token creation failed: ' . $exception->getMessage());
      clearRememberCookie();
    }
  } else {
    clearRememberCookie();
  }
}

function logoutUser(?PDO $db = null): void
{
  startAppSession();

  $remember = appRememberCookieValue();
  if ($db && $remember !== null) {
    $stmt = $db->prepare('DELETE FROM remember_tokens WHERE selector = ?');
    $stmt->execute([$remember['selector']]);
  }
  clearRememberCookie();

  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', appCookieOptions(time() - 42000));
  }
  session_destroy();
}

function isLoggedIn(): bool
{
  return (($_SESSION['logged_in'] ?? false) === true) && !empty($_SESSION['user_id']);
}

function sessionUser(): ?array
{
  if (!isLoggedIn()) {
    return null;
  }

  return [
    'id' => (int) $_SESSION['user_id'],
    'name' => (string) ($_SESSION['user_name'] ?? ''),
    'email' => (string) ($_SESSION['user_email'] ?? ''),
    'role' => (string) ($_SESSION['user_role'] ?? ''),
    'ward_id' => isset($_SESSION['user_ward_id']) ? (int) $_SESSION['user_ward_id'] : null,
    'city' => $_SESSION['user_city'] ?? null,
  ];
}

function csrfToken(): string
{
  startAppSession();
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string) $_SESSION['csrf_token'];
}

function isValidCsrfToken(?string $token): bool
{
  return is_string($token)
    && $token !== ''
    && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
}

function requireCsrfToken(bool $json = false): void
{
  if (function_exists('mobileSessionAuthenticated') && mobileSessionAuthenticated()) {
    return;
  }

  $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
  if (isValidCsrfToken(is_string($token) ? $token : null)) {
    return;
  }

  if ($json && function_exists('jsonResponse')) {
    jsonResponse(419, 'The form expired. Please refresh and try again.');
  }

  http_response_code(419);
  exit('The form expired. Please refresh and try again.');
}

function requireAuthentication(?PDO $db = null, array $allowedRoles = []): array
{
  if ($db && function_exists('mobileBearerToken') && mobileBearerToken() !== null
    && (!function_exists('mobileSessionAuthenticated') || !mobileSessionAuthenticated())) {
    if (function_exists('jsonResponse')) {
      jsonResponse(401, 'The mobile access token is invalid or expired.');
    }
    http_response_code(401);
    exit('Authentication required.');
  }

  if ($db) {
    restoreRememberedLogin($db);
    refreshSessionUser($db);
  }

  $user = sessionUser();
  if ($user === null || ($allowedRoles !== [] && !in_array($user['role'], $allowedRoles, true))) {
    http_response_code(401);
    if (function_exists('jsonResponse')) {
      jsonResponse(401, 'Authentication required.');
    }
    exit('Authentication required.');
  }

  return $user;
}

startAppSession();
