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

$user = requireMobileAuthentication($db, ['citizen']);
$dashboard = fetchCitizenDashboardData($db, (int) $user['id']);
$community = fetchIssueFeed($db, [
  'sort' => 'hot',
  'limit' => 4,
  'viewer_id' => (int) $user['id'],
  'ward' => $user['ward_id'],
]);

// A new citizen may not have ward-tagged issues yet. Keep the dashboard useful
// by falling back to the city-wide community feed in that case.
if (($community['items'] ?? []) === [] && $user['ward_id'] !== null) {
  $community = fetchIssueFeed($db, [
    'sort' => 'hot',
    'limit' => 4,
    'viewer_id' => (int) $user['id'],
  ]);
}

jsonResponse(200, 'Citizen dashboard loaded.', $dashboard + ['community' => $community]);
