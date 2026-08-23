<?php
declare(strict_types=1);

function fetchDashboardPageData(?PDO $db, array $viewer, string $role): array
{
  $data = [
    'dashboard' => ['stats' => ['total_reports' => 0, 'resolved' => 0, 'active' => 0, 'upvotes' => 0], 'rank' => 0, 'activity' => []],
    'community' => ['items' => [], 'pagination' => ['total' => 0]],
    'assignments' => [], 'availableIssues' => [], 'workRequests' => [], 'assignmentStats' => ['active' => 0, 'completed' => 0],
    'stats' => getIssueStats($db), 'feed' => ['items' => [], 'pagination' => ['total' => 0]],
    'userStats' => ['total' => 0, 'active' => 0, 'citizen' => 0, 'worker' => 0, 'admin' => 0],
    'aiStats' => ['analysed' => 0, 'average_confidence' => 0], 'assignmentCount' => 0,
  ];
  if (!$db instanceof PDO) return $data;

  try {
    if ($role === 'citizen') {
      $data['dashboard'] = fetchCitizenDashboardData($db, (int) $viewer['id']);
      $data['community'] = fetchIssueFeed($db, ['limit' => 4, 'sort' => 'hot', 'viewer_id' => (int) $viewer['id'], 'ward' => $viewer['ward_id'] ?? null]);
      if ($data['community']['items'] === [] && ($viewer['ward_id'] ?? null) !== null) {
        $data['community'] = fetchIssueFeed($db, ['limit' => 4, 'sort' => 'hot', 'viewer_id' => (int) $viewer['id']]);
      }
    } elseif ($role === 'worker') {
      $data = array_merge($data, fetchAssignmentsPageData($db, (int) $viewer['id']));
    } else {
      $data['feed'] = fetchIssueFeed($db, ['page' => 1, 'limit' => 10, 'sort' => 'hot']);
      $userRow = $db->query("SELECT COUNT(*) AS total, SUM(is_active = 1) AS active, SUM(role = 'citizen') AS citizen, SUM(role = 'worker') AS worker, SUM(role = 'admin') AS admin")->fetch(PDO::FETCH_ASSOC) ?: [];
      foreach ($data['userStats'] as $key => $value) $data['userStats'][$key] = (int) ($userRow[$key] ?? 0);
      $data['aiStats'] = $db->query('SELECT COUNT(*) AS analysed, COALESCE(AVG(confidence), 0) AS average_confidence FROM issue_ai_analyses')->fetch(PDO::FETCH_ASSOC) ?: $data['aiStats'];
      $data['assignmentCount'] = (int) $db->query('SELECT COUNT(*) FROM assignments WHERE completed_at IS NULL')->fetchColumn();
    }
  } catch (Throwable $exception) {
    error_log('Dashboard page data failed: ' . $exception->getMessage());
  }
  return $data;
}
