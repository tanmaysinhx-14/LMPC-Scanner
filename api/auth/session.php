<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  jsonResponse(405, 'GET is required.');
}

jsonResponse(200, 'Session state loaded.', [
  'authenticated' => isLoggedIn(),
  'user' => sessionUser(),
  'csrf_token' => csrfToken(),
]);
