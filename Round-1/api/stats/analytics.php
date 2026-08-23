<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  jsonResponse(405, 'GET is required.');
}
requireAuthentication($db instanceof PDO ? $db : null, ['admin']);
if (!($db instanceof PDO)) {
  jsonResponse(503, 'The database is temporarily unavailable.');
}

try {
  $historyRows = $db->query(
    "SELECT issue_id, old_status, new_status, created_at
       FROM status_history
      ORDER BY issue_id ASC, created_at ASC, id ASC"
  )->fetchAll(PDO::FETCH_ASSOC);
  $transitionTotals = [
    'pending_to_acknowledged' => ['minutes' => 0.0, 'count' => 0],
    'acknowledged_to_in_progress' => ['minutes' => 0.0, 'count' => 0],
    'in_progress_to_resolved' => ['minutes' => 0.0, 'count' => 0],
  ];
  $previousByIssue = [];
  foreach ($historyRows as $row) {
    $issueKey = (int) $row['issue_id'];
    $key = match ((string) $row['old_status'] . '>' . (string) $row['new_status']) {
      'pending>acknowledged' => 'pending_to_acknowledged',
      'acknowledged>in_progress' => 'acknowledged_to_in_progress',
      'in_progress>resolved' => 'in_progress_to_resolved',
      default => null,
    };
    if ($key === null) {
      $previousByIssue[$issueKey] = $row['created_at'];
      continue;
    }
    $previous = $previousByIssue[$issueKey] ?? null;
    // The previous state timestamp is stored in the same history stream. The
    // query is ordered, so use the immediately preceding event for each issue.
    if ($previous !== null) {
      $minutes = max(0.0, (strtotime((string) $row['created_at']) - strtotime((string) $previous)) / 60);
      $transitionTotals[$key]['minutes'] += $minutes;
      $transitionTotals[$key]['count']++;
    }
    $previousByIssue[$issueKey] = $row['created_at'];
  }
  $transitions = [];
  foreach ($transitionTotals as $key => $value) {
    $transitions[$key] = [
      'samples' => $value['count'],
      'average_minutes' => $value['count'] > 0 ? round($value['minutes'] / $value['count'], 1) : 0,
      'average_hours' => $value['count'] > 0 ? round($value['minutes'] / $value['count'] / 60, 2) : 0,
    ];
  }

  $workers = $db->query(
    "SELECT u.id, u.name, u.department,
            COUNT(a.id) AS assigned_count,
            SUM(a.completed_at IS NOT NULL) AS completed_count,
            COALESCE(AVG(CASE WHEN a.completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, a.assigned_at, a.completed_at) END), 0) AS average_completion_hours
       FROM users u
       LEFT JOIN assignments a ON a.worker_id = u.id
      WHERE u.role = 'worker'
      GROUP BY u.id, u.name, u.department
      ORDER BY completed_count DESC, assigned_count DESC, u.name ASC"
  )->fetchAll(PDO::FETCH_ASSOC);
  foreach ($workers as &$worker) {
    $worker['id'] = (int) $worker['id'];
    $worker['assigned_count'] = (int) $worker['assigned_count'];
    $worker['completed_count'] = (int) $worker['completed_count'];
    $worker['average_completion_hours'] = round((float) $worker['average_completion_hours'], 1);
  }
  unset($worker);

  $categoryTrend = $db->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, submitted_category AS category, COUNT(*) AS report_count
       FROM issue_reports
      WHERE created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH)
      GROUP BY DATE_FORMAT(created_at, '%Y-%m'), submitted_category
      ORDER BY month ASC, report_count DESC"
  )->fetchAll(PDO::FETCH_ASSOC);
  foreach ($categoryTrend as &$row) $row['report_count'] = (int) $row['report_count'];
  unset($row);

  $wardRates = $db->query(
    "SELECT COALESCE(CAST(ward_id AS CHAR), 'unassigned') AS ward,
            COUNT(*) AS issue_count,
            SUM(status = 'resolved') AS resolved_count,
            ROUND(100 * SUM(status = 'resolved') / NULLIF(COUNT(*), 0), 1) AS resolution_rate
       FROM issues
      WHERE status <> 'rejected'
      GROUP BY ward_id
      ORDER BY resolution_rate DESC, issue_count DESC"
  )->fetchAll(PDO::FETCH_ASSOC);
  foreach ($wardRates as &$row) {
    $row['issue_count'] = (int) $row['issue_count'];
    $row['resolved_count'] = (int) $row['resolved_count'];
    $row['resolution_rate'] = (float) $row['resolution_rate'];
  }
  unset($row);

  jsonResponse(200, 'Analytics loaded.', [
    'transitions' => $transitions,
    'workers' => $workers,
    'category_trends' => $categoryTrend,
    'ward_resolution' => $wardRates,
    'priority_formula' => civicPriorityFormula(),
    'priority_thresholds' => civicPriorityConfig(),
  ]);
} catch (Throwable $exception) {
  error_log('Analytics query failed: ' . $exception->getMessage());
  jsonResponse(500, 'Analytics could not be loaded.');
}
