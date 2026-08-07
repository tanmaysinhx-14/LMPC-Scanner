<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  jsonResponse(405, 'GET is required.');
}
if (!($db instanceof PDO)) {
  jsonResponse(503, 'The database is temporarily unavailable.');
}

try {
  $data = fetchIssueFeed($db, [
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'limit' => min(50, max(1, (int) ($_GET['limit'] ?? 20))),
    'sort' => $_GET['sort'] ?? 'hot',
    'ward' => $_GET['ward'] ?? null,
    'category' => $_GET['cat'] ?? null,
    'status' => $_GET['status'] ?? null,
    'query' => $_GET['q'] ?? null,
    'viewer_id' => isLoggedIn() ? (int) $_SESSION['user_id'] : 0,
  ]);

  $isStaff = in_array((string) ($_SESSION['user_role'] ?? ''), ['worker', 'authority', 'admin'], true);
  if (!$isStaff) {
    foreach ($data['items'] as &$item) {
      $item['lat'] = round((float) ($item['lat'] ?? 0), 3);
      $item['lng'] = round((float) ($item['lng'] ?? 0), 3);
    }
    unset($item);
  }

  jsonResponse(200, 'Issue feed loaded.', $data);
} catch (Throwable $exception) {
  error_log('Issue feed API failed: ' . $exception->getMessage());
  jsonResponse(500, 'Unable to load the issue feed.');
}
