<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  jsonResponse(405, 'POST is required.');
}

$user = requireAuthentication($db instanceof PDO ? $db : null, ['admin']);
requireCsrfToken(json: true);
if (!($db instanceof PDO)) {
  jsonResponse(503, 'The database is temporarily unavailable.');
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : $_POST;
$issueId = filter_var($body['issue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$workerId = filter_var($body['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$notes = substr(trim((string) ($body['notes'] ?? '')), 0, 2000);
$requestId = filter_var($body['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($issueId === false || $workerId === false) {
  jsonResponse(422, 'A valid issue and worker are required.');
}

try {
  $db->beginTransaction();

  $worker = $db->prepare(
    "SELECT id, name FROM users WHERE id = ? AND role = 'worker' AND is_active = 1 LIMIT 1"
  );
  $worker->execute([(int) $workerId]);
  $workerRow = $worker->fetch(PDO::FETCH_ASSOC);
  if (!$workerRow) {
    $db->rollBack();
    jsonResponse(404, 'The selected worker is not active.');
  }

  $issue = $db->prepare(
    "SELECT id, title, status FROM issues
      WHERE id = ? AND status IN ('pending', 'acknowledged', 'in_progress')
      LIMIT 1 FOR UPDATE"
  );
  $issue->execute([(int) $issueId]);
  $issueRow = $issue->fetch(PDO::FETCH_ASSOC);
  if (!$issueRow) {
    $db->rollBack();
    jsonResponse(404, 'That issue is not available for assignment.');
  }

  $active = $db->prepare(
    'SELECT id FROM assignments WHERE issue_id = ? AND completed_at IS NULL LIMIT 1 FOR UPDATE'
  );
  $active->execute([(int) $issueId]);
  if ($active->fetchColumn()) {
    $db->rollBack();
    jsonResponse(409, 'That issue already has an active assignment.');
  }

  $db->prepare(
    'INSERT INTO assignments (issue_id, worker_id, assigned_by, notes, assigned_at)
     VALUES (?, ?, ?, ?, NOW())'
  )->execute([(int) $issueId, (int) $workerId, (int) $user['id'], $notes !== '' ? $notes : null]);

  if ($issueRow['status'] === 'pending') {
    $db->prepare("UPDATE issues SET status = 'acknowledged', updated_at = NOW() WHERE id = ?")
      ->execute([(int) $issueId]);
    $db->prepare(
      'INSERT INTO status_history (issue_id, changed_by, old_status, new_status, note, created_at)
       VALUES (?, ?, ?, ?, ?, NOW())'
    )->execute([(int) $issueId, (int) $user['id'], 'pending', 'acknowledged', 'Assigned to a field worker.']);
  }

  if ($requestId !== false && $requestId !== null) {
    $requestUpdate = $db->prepare(
      "UPDATE work_requests
          SET status = 'approved', reviewed_by = ?, review_note = ?, reviewed_at = NOW()
        WHERE id = ? AND worker_id = ? AND issue_id = ? AND status = 'pending'"
    );
    $requestUpdate->execute([(int) $user['id'], $notes !== '' ? $notes : 'Approved and assigned.', (int) $requestId, (int) $workerId, (int) $issueId]);
  }

  $db->commit();
  jsonResponse(201, 'Issue assigned to ' . $workerRow['name'] . '.', [
    'assignment_id' => (int) $db->lastInsertId(),
    'issue_id' => (int) $issueId,
    'worker_id' => (int) $workerId,
  ]);
} catch (Throwable $exception) {
  if ($db->inTransaction()) {
    $db->rollBack();
  }
  error_log('Issue assignment failed: ' . $exception->getMessage());
  jsonResponse(500, 'Unable to assign the issue.');
}
