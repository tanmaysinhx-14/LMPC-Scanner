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

requireMobileAuthentication($db);
$body = json_decode((string) file_get_contents('php://input'), true);
revokeMobileRefreshToken($db, is_array($body) ? ($body['refresh_token'] ?? null) : null);
revokeMobileAccessToken($db);
jsonResponse(200, 'Mobile session ended.');
