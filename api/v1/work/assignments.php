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

$user = requireMobileAuthentication($db, ['worker']);
$workerId = (int) $user['id'];

try {
  $assignmentStmt = $db->prepare(
    'SELECT a.id AS assignment_id, a.issue_id, a.notes, a.assigned_at, a.completed_at,
            i.title, i.description, i.category, i.severity, i.status, i.address,
            i.lat, i.lng, i.priority_score, i.created_at, i.upvote_count, i.is_recurring,
            (SELECT COUNT(*) FROM issue_reports ir WHERE ir.issue_id = i.id) AS report_count,
            (SELECT COUNT(*) FROM issue_images ii WHERE ii.issue_id = i.id) AS image_count,
            (SELECT ii.file_path FROM issue_images ii WHERE ii.issue_id = i.id ORDER BY ii.id ASC LIMIT 1) AS cover_path
       FROM assignments a
       INNER JOIN issues i ON i.id = a.issue_id
      WHERE a.worker_id = ?
      ORDER BY a.completed_at IS NULL DESC, a.assigned_at DESC, a.id DESC'
  );
  $assignmentStmt->execute([$workerId]);
  $assignments = array_map(static function (array $row) use ($db): array {
    $row['assignment_id'] = (int) $row['assignment_id'];
    $row['issue_id'] = (int) $row['issue_id'];
    $row['severity'] = (int) $row['severity'];
    $row['upvote_count'] = (int) $row['upvote_count'];
    $row['report_count'] = (int) $row['report_count'];
    $row['image_count'] = (int) $row['image_count'];
    $row = enrichIssueWithCommunitySignals($db, $row);
    $row['cover_url'] = issueImageUrl($row['cover_path'] ?? null);
    unset($row['cover_path']);
    return $row;
  }, $assignmentStmt->fetchAll(PDO::FETCH_ASSOC));

  $availableStmt = $db->query(
    "SELECT i.id, i.title, i.category, i.address, i.lat, i.lng, i.severity, i.upvote_count,
            i.priority_score, i.created_at, i.is_recurring,
            COUNT(ir.id) AS report_count
       FROM issues i
       LEFT JOIN issue_reports ir ON ir.issue_id = i.id
       LEFT JOIN assignments active_assignment
         ON active_assignment.issue_id = i.id AND active_assignment.completed_at IS NULL
      WHERE i.status IN ('pending', 'acknowledged', 'in_progress')
        AND active_assignment.id IS NULL
      GROUP BY i.id, i.title, i.category, i.address, i.lat, i.lng, i.severity, i.upvote_count, i.priority_score, i.created_at, i.is_recurring
      ORDER BY i.priority_score DESC, i.created_at DESC
      LIMIT 30"
  );
  $availableIssues = array_map(static function (array $row) use ($db): array {
    $row['id'] = (int) $row['id'];
    $row['report_count'] = (int) $row['report_count'];
    $row['priority_score'] = (float) $row['priority_score'];
    $row = enrichIssueWithCommunitySignals($db, $row);
    return $row;
  }, $availableStmt->fetchAll(PDO::FETCH_ASSOC));

  $requestStmt = $db->prepare(
    'SELECT wr.id, wr.issue_id, wr.message, wr.status, wr.created_at, i.title
       FROM work_requests wr
       LEFT JOIN issues i ON i.id = wr.issue_id
      WHERE wr.worker_id = ?
      ORDER BY wr.created_at DESC, wr.id DESC
      LIMIT 8'
  );
  $requestStmt->execute([$workerId]);
  $workRequests = array_map(static function (array $row): array {
    $row['id'] = (int) $row['id'];
    $row['issue_id'] = $row['issue_id'] === null ? null : (int) $row['issue_id'];
    return $row;
  }, $requestStmt->fetchAll(PDO::FETCH_ASSOC));

  $active = count(array_filter($assignments, static fn (array $assignment): bool => empty($assignment['completed_at'])));
  jsonResponse(200, 'Worker assignments loaded.', [
    'assignments' => $assignments,
    'available_issues' => $availableIssues,
    'work_requests' => $workRequests,
    'stats' => ['active' => $active, 'completed' => count($assignments) - $active],
  ]);
} catch (Throwable $exception) {
  error_log('Mobile worker assignments failed: ' . $exception->getMessage());
  jsonResponse(500, 'Unable to load worker assignments.');
}
