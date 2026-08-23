<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  jsonResponse(405, 'GET is required.');
}
requireAuthentication($db instanceof PDO ? $db : null, ['admin']);
if (!($db instanceof PDO)) {
  jsonResponse(503, 'The database is temporarily unavailable.');
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : date('Y-m-d');
$format = strtolower((string) ($_GET['format'] ?? 'csv')) === 'json' ? 'json' : 'csv';

try {
  $stmt = $db->prepare(
    "SELECT i.id, i.title, i.category, i.department, i.severity, i.status, i.priority_score,
            i.is_recurring, i.recurrence_of, i.address, i.ward_id, i.lat, i.lng, i.created_at, i.resolved_at,
            i.ai_confidence, i.is_manipulated,
            reporter.name AS reporter_name,
            worker.name AS worker_name, administrator.name AS assigned_by_name,
            assignment.assigned_at, assignment.completed_at,
            TIMESTAMPDIFF(HOUR, i.created_at, i.resolved_at) AS resolution_hours
       FROM issues i
       LEFT JOIN users reporter ON reporter.id = i.user_id
       LEFT JOIN assignments assignment ON assignment.id = (
         SELECT latest.id FROM assignments latest WHERE latest.issue_id = i.id ORDER BY latest.assigned_at DESC, latest.id DESC LIMIT 1
       )
       LEFT JOIN users worker ON worker.id = assignment.worker_id
       LEFT JOIN users administrator ON administrator.id = assignment.assigned_by
      WHERE i.created_at >= ? AND i.created_at < DATE_ADD(?, INTERVAL 1 DAY)
      ORDER BY i.created_at DESC, i.id DESC"
  );
  $stmt->execute([$from, $to]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="civicconnect-export-' . $from . '-to-' . $to . '.json"');
    echo json_encode(['from' => $from, 'to' => $to, 'issues' => $rows], JSON_UNESCAPED_SLASHES);
    exit;
  }

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="civicconnect-export-' . $from . '-to-' . $to . '.csv"');
  $output = fopen('php://output', 'wb');
  $headers = array_keys($rows[0] ?? [
    'id' => null, 'title' => null, 'category' => null, 'department' => null, 'severity' => null,
    'status' => null, 'priority_score' => null, 'is_recurring' => null, 'recurrence_of' => null,
    'address' => null, 'ward_id' => null, 'lat' => null, 'lng' => null, 'created_at' => null,
    'resolved_at' => null, 'ai_confidence' => null, 'is_manipulated' => null, 'reporter_name' => null,
    'worker_name' => null, 'assigned_by_name' => null, 'assigned_at' => null, 'completed_at' => null,
    'resolution_hours' => null,
  ]);
  fputcsv($output, $headers);
  foreach ($rows as $row) fputcsv($output, $row);
  fclose($output);
  exit;
} catch (Throwable $exception) {
  error_log('Export query failed: ' . $exception->getMessage());
  jsonResponse(500, 'The export could not be generated.');
}
