<?php
declare(strict_types=1);

require __DIR__ . '/../../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  jsonResponse(405, 'POST is required.');
}
if (!($db instanceof PDO)) {
  jsonResponse(503, 'The database is temporarily unavailable.');
}

$body = json_decode((string) file_get_contents('php://input'), true);
$refreshToken = is_array($body) ? trim((string) ($body['refresh_token'] ?? '')) : '';
if ($refreshToken === '') {
  jsonResponse(422, 'A refresh token is required.');
}

try {
  $credentials = rotateMobileRefreshToken($db, $refreshToken);
  jsonResponse(200, 'Mobile session refreshed.', $credentials);
} catch (MobileAuthException $exception) {
  jsonResponse(401, $exception->getMessage());
} catch (Throwable $exception) {
  error_log('Mobile refresh failed: ' . $exception->getMessage());
  jsonResponse(503, 'The mobile session could not be refreshed.');
}
