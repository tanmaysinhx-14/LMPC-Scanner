<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  jsonResponse(405, 'POST is required.');
}
requireCsrfToken(json: true);
logoutUser($db instanceof PDO ? $db : null);
jsonResponse(200, 'Logout successful.', ['authenticated' => false]);
