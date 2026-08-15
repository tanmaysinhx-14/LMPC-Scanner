<?php
declare(strict_types=1);

const MOBILE_ACCESS_TOKEN_TTL_SECONDS = 1800;
const MOBILE_REFRESH_TOKEN_TTL_SECONDS = 2592000;

class MobileAuthException extends RuntimeException
{
}

function mobileBearerToken(): ?string
{
  $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTHORIZATION'] ?? '');
  if ($header === '' && function_exists('getallheaders')) {
    foreach (getallheaders() ?: [] as $name => $value) {
      if (strtolower((string) $name) === 'authorization') {
        $header = (string) $value;
        break;
      }
    }
  }
  if (preg_match('/^Bearer\s+([A-Za-z0-9_-]{64,128})$/', $header, $matches) !== 1) {
    return null;
  }

  return $matches[1];
}

function mobileBearerTokenHash(?string $token = null): ?string
{
  $token ??= mobileBearerToken();
  return $token === null ? null : hash('sha256', $token);
}

function mobileSessionAuthenticated(): bool
{
  return !empty($_SESSION['mobile_authenticated']) && mobileBearerTokenHash() !== null
    && hash_equals((string) ($_SESSION['mobile_token_hash'] ?? ''), (string) mobileBearerTokenHash());
}

function authenticateMobileBearer(PDO $db): ?array
{
  $token = mobileBearerToken();
  $tokenHash = mobileBearerTokenHash($token);
  if ($tokenHash === null) {
    return null;
  }

  try {
    $stmt = $db->prepare(
      'SELECT mat.token_hash, u.id, u.name, u.email, u.role, u.ward_id, u.city
         FROM mobile_access_tokens mat
         INNER JOIN users u ON u.id = mat.user_id
        WHERE mat.token_hash = ?
          AND mat.revoked_at IS NULL
          AND mat.expires_at > NOW()
          AND u.is_active = 1
        LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $exception) {
    error_log('Mobile token lookup failed: ' . $exception->getMessage());
    return null;
  }

  if (!$user) {
    return null;
  }

  startAppSession();
  $_SESSION['logged_in'] = true;
  $_SESSION['user_id'] = (int) $user['id'];
  $_SESSION['user_name'] = (string) $user['name'];
  $_SESSION['user_email'] = (string) $user['email'];
  $_SESSION['user_role'] = (string) $user['role'];
  $_SESSION['user_ward_id'] = $user['ward_id'] ?? null;
  $_SESSION['user_city'] = $user['city'] ?? null;
  $_SESSION['auth_time'] = time();
  $_SESSION['last_activity'] = time();
  $_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
  $_SESSION['mobile_authenticated'] = true;
  $_SESSION['mobile_token_hash'] = $tokenHash;

  try {
    $db->prepare('UPDATE mobile_access_tokens SET last_used_at = NOW() WHERE token_hash = ?')
      ->execute([$tokenHash]);
  } catch (Throwable $exception) {
    error_log('Mobile token timestamp update failed: ' . $exception->getMessage());
  }

  return sessionUser();
}

function createMobileAccessToken(PDO $db, array $user): array
{
  $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
  $tokenHash = hash('sha256', $token);
  $expiresAt = date('Y-m-d H:i:s', time() + MOBILE_ACCESS_TOKEN_TTL_SECONDS);

  $db->prepare(
    'INSERT INTO mobile_access_tokens
      (user_id, token_hash, expires_at, device_name, created_at, last_used_at)
     VALUES (?, ?, ?, ?, NOW(), NOW())'
  )->execute([
    (int) $user['id'],
    $tokenHash,
    $expiresAt,
    substr(trim((string) ($_SERVER['HTTP_X_DEVICE_NAME'] ?? 'Expo mobile')), 0, 120),
  ]);

  return [
    'access_token' => $token,
    'token_type' => 'Bearer',
    'expires_in' => MOBILE_ACCESS_TOKEN_TTL_SECONDS,
    'expires_at' => gmdate('c', strtotime($expiresAt)),
  ];
}

function createMobileSession(PDO $db, array $user): array
{
  $refreshToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
  $refreshHash = hash('sha256', $refreshToken);
  $familyId = bin2hex(random_bytes(16));
  $refreshExpiresAt = date('Y-m-d H:i:s', time() + MOBILE_REFRESH_TOKEN_TTL_SECONDS);

  $db->beginTransaction();
  try {
    $db->prepare(
      'INSERT INTO mobile_refresh_tokens
        (user_id, family_id, token_hash, device_id, expires_at, created_at, last_used_at)
       VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([
      (int) $user['id'],
      $familyId,
      $refreshHash,
      substr(trim((string) ($_SERVER['HTTP_X_DEVICE_ID'] ?? $_SERVER['HTTP_X_DEVICE_NAME'] ?? 'Expo mobile')), 0, 120),
      $refreshExpiresAt,
    ]);
    $credentials = createMobileAccessToken($db, $user);
    $db->commit();
  } catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
  }

  return $credentials + [
    'refresh_token' => $refreshToken,
    'refresh_expires_in' => MOBILE_REFRESH_TOKEN_TTL_SECONDS,
    'refresh_expires_at' => gmdate('c', strtotime($refreshExpiresAt)),
  ];
}

function rotateMobileRefreshToken(PDO $db, string $refreshToken): array
{
  $refreshHash = hash('sha256', $refreshToken);
  $db->beginTransaction();
  try {
    $stmt = $db->prepare(
      'SELECT mrt.*, u.id AS user_id, u.name, u.email, u.role, u.ward_id, u.city, u.is_active
         FROM mobile_refresh_tokens mrt
         INNER JOIN users u ON u.id = mrt.user_id
        WHERE mrt.token_hash = ?
        LIMIT 1
        FOR UPDATE'
    );
    $stmt->execute([$refreshHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) $row['is_active'] !== 1) {
      throw new MobileAuthException('The refresh session is invalid. Please sign in again.');
    }
    if ($row['revoked_at'] !== null || $row['rotated_at'] !== null) {
      // Reuse detection: invalidate the entire family, not just the replayed token.
      $db->prepare('UPDATE mobile_refresh_tokens SET revoked_at = NOW() WHERE family_id = ? AND revoked_at IS NULL')
        ->execute([(string) $row['family_id']]);
      $db->commit();
      throw new MobileAuthException('The refresh session was reused. Please sign in again.');
    }
    if (strtotime((string) $row['expires_at']) <= time()) {
      throw new MobileAuthException('The refresh session has expired. Please sign in again.');
    }

    $newRefreshToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $newRefreshHash = hash('sha256', $newRefreshToken);
    $refreshExpiresAt = date('Y-m-d H:i:s', time() + MOBILE_REFRESH_TOKEN_TTL_SECONDS);
    $insert = $db->prepare(
      'INSERT INTO mobile_refresh_tokens
        (user_id, family_id, token_hash, device_id, expires_at, created_at, last_used_at)
       VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $insert->execute([
      (int) $row['user_id'],
      (string) $row['family_id'],
      $newRefreshHash,
      (string) ($row['device_id'] ?? 'Expo mobile'),
      $refreshExpiresAt,
    ]);
    $newId = (int) $db->lastInsertId();
    $db->prepare('UPDATE mobile_refresh_tokens SET rotated_at = NOW(), replaced_by_id = ?, last_used_at = NOW() WHERE id = ?')
      ->execute([$newId, (int) $row['id']]);

    $user = [
      'id' => (int) $row['user_id'],
      'name' => (string) $row['name'],
      'email' => (string) $row['email'],
      'role' => (string) $row['role'],
      'ward_id' => $row['ward_id'] === null ? null : (int) $row['ward_id'],
      'city' => $row['city'],
    ];
    $credentials = createMobileAccessToken($db, $user);
    $db->commit();
  } catch (MobileAuthException $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
  } catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
  }

  return $credentials + [
    'refresh_token' => $newRefreshToken,
    'refresh_expires_in' => MOBILE_REFRESH_TOKEN_TTL_SECONDS,
    'refresh_expires_at' => gmdate('c', strtotime($refreshExpiresAt)),
    'user' => $user,
  ];
}

function revokeMobileRefreshToken(PDO $db, ?string $refreshToken): void
{
  if (!is_string($refreshToken) || $refreshToken === '') return;
  $db->prepare('UPDATE mobile_refresh_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL')
    ->execute([hash('sha256', $refreshToken)]);
}

function revokeMobileAccessToken(PDO $db): void
{
  $tokenHash = mobileBearerTokenHash();
  if ($tokenHash === null) {
    return;
  }

  $db->prepare('UPDATE mobile_access_tokens SET revoked_at = NOW() WHERE token_hash = ?')
    ->execute([$tokenHash]);
}

function requireMobileAuthentication(PDO $db, array $allowedRoles = []): array
{
  $user = authenticateMobileBearer($db);
  if ($user === null) {
    jsonResponse(401, 'A valid mobile access token is required.');
  }

  if ($allowedRoles !== [] && !in_array($user['role'], $allowedRoles, true)) {
    jsonResponse(403, 'Your account cannot perform this action.');
  }

  return $user;
}
