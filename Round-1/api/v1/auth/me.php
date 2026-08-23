<?php
declare(strict_types=1);

require __DIR__ . '/../../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  jsonResponse(405, 'GET is required.');
}
if (!($db instanceof PDO)) {
  jsonResponse(503, 'The database is temporarily unavailable.');
}

$user = requireMobileAuthentication($db);
jsonResponse(200, 'Mobile session loaded.', ['user' => $user]);
