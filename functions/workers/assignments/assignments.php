<?php
declare(strict_types=1);

function fetchAssignmentsPageData(?PDO $db, int $workerId): array
{
  $data = ['assignments' => [], 'availableIssues' => [], 'workRequests' => [], 'assignmentStats' => ['active' => 0, 'completed' => 0]];
  if (!$db instanceof PDO) return $data;

  try {
    $statement = $db->prepare(
      'SELECT a.id AS assignment_id, a.issue_id, a.notes, a.assigned_at, a.completed_at,
              i.title, i.description, i.category, i.severity, i.status, i.address,
              i.lat, i.lng, i.priority_score, i.created_at, i.upvote_count, i.is_recurring,
              (SELECT COUNT(*) FROM issue_reports ir WHERE ir.issue_id = i.id) AS report_count,
              (SELECT COUNT(*) FROM issue_images ii WHERE ii.issue_id = i.id) AS image_count
         FROM assignments a INNER JOIN issues i ON i.id = a.issue_id
        WHERE a.worker_id = ? ORDER BY a.completed_at IS NULL DESC, a.assigned_at DESC, a.id DESC'
    );
    $statement->execute([$workerId]);
    $data['assignments'] = array_map(static function (array $row) use ($db): array {
      $row['report_count'] = (int) $row['report_count'];
      return enrichIssueWithCommunitySignals($db, $row);
    }, $statement->fetchAll(PDO::FETCH_ASSOC));
    $data['assignmentStats']['active'] = count(array_filter($data['assignments'], static fn (array $item): bool => empty($item['completed_at'])));
    $data['assignmentStats']['completed'] = count($data['assignments']) - $data['assignmentStats']['active'];

    $available = $db->query(
      "SELECT i.id, i.title, i.category, i.address, i.lat, i.lng, i.severity, i.upvote_count,
              i.priority_score, i.created_at, i.is_recurring, COUNT(ir.id) AS report_count
         FROM issues i LEFT JOIN issue_reports ir ON ir.issue_id = i.id
         LEFT JOIN assignments active_assignment ON active_assignment.issue_id = i.id AND active_assignment.completed_at IS NULL
        WHERE i.status IN ('pending', 'acknowledged', 'in_progress') AND active_assignment.id IS NULL
        GROUP BY i.id, i.title, i.category, i.address, i.lat, i.lng, i.severity, i.upvote_count, i.priority_score, i.created_at, i.is_recurring
        ORDER BY i.priority_score DESC, i.created_at DESC LIMIT 30"
    );
    $data['availableIssues'] = array_map(static function (array $row) use ($db): array {
      $row['report_count'] = (int) $row['report_count'];
      return enrichIssueWithCommunitySignals($db, $row);
    }, $available->fetchAll(PDO::FETCH_ASSOC));

    $request = $db->prepare(
      'SELECT wr.id, wr.message, wr.status, wr.created_at, i.title FROM work_requests wr
       LEFT JOIN issues i ON i.id = wr.issue_id WHERE wr.worker_id = ?
       ORDER BY wr.created_at DESC, wr.id DESC LIMIT 8'
    );
    $request->execute([$workerId]);
    $data['workRequests'] = $request->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $exception) {
    error_log('Assignments page data failed: ' . $exception->getMessage());
  }
  return $data;
}
