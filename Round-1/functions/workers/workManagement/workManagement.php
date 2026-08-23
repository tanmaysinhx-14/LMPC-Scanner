<?php
declare(strict_types=1);

function fetchWorkManagementPageData(?PDO $db): array
{
  $data = ['workers' => [], 'availableIssues' => [], 'activeAssignments' => [], 'requests' => []];
  if (!$db instanceof PDO) return $data;
  try {
    $data['workers'] = $db->query(
      "SELECT u.id, u.name, u.email, u.city, u.department,
              (SELECT COUNT(*) FROM assignments a WHERE a.worker_id = u.id AND a.completed_at IS NULL) AS active_assignments
         FROM users u WHERE u.role = 'worker' AND u.is_active = 1 ORDER BY u.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $data['availableIssues'] = $db->query(
      "SELECT i.id, i.title, i.category, i.department, i.status, i.address, i.severity, i.priority_score, i.created_at,
              COUNT(ir.id) AS report_count
         FROM issues i LEFT JOIN issue_reports ir ON ir.issue_id = i.id
         LEFT JOIN assignments active_assignment ON active_assignment.issue_id = i.id AND active_assignment.completed_at IS NULL
        WHERE i.status IN ('pending', 'acknowledged', 'in_progress') AND active_assignment.id IS NULL
        GROUP BY i.id, i.title, i.category, i.department, i.status, i.address, i.severity, i.priority_score, i.created_at
        ORDER BY i.priority_score DESC, i.created_at DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);
    $data['activeAssignments'] = $db->query(
      "SELECT a.id, a.assigned_at, a.notes, i.id AS issue_id, i.title, i.category, i.status, i.address,
              worker.name AS worker_name, administrator.name AS assigned_by_name
         FROM assignments a INNER JOIN issues i ON i.id = a.issue_id INNER JOIN users worker ON worker.id = a.worker_id
         LEFT JOIN users administrator ON administrator.id = a.assigned_by
        WHERE a.completed_at IS NULL ORDER BY i.priority_score DESC, a.assigned_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $data['requests'] = $db->query(
      "SELECT wr.id, wr.message, wr.created_at, wr.issue_id,
              worker.name AS worker_name, worker.email AS worker_email, i.title, i.address, i.status AS issue_status
         FROM work_requests wr INNER JOIN users worker ON worker.id = wr.worker_id LEFT JOIN issues i ON i.id = wr.issue_id
        WHERE wr.status = 'pending' ORDER BY wr.created_at ASC, wr.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $exception) {
    error_log('Work management page data failed: ' . $exception->getMessage());
  }
  return $data;
}
