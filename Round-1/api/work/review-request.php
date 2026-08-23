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
$requestId = filter_var($body['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim((string) ($body['action'] ?? '')));
$note = substr(trim((string) ($body['note'] ?? '')), 0, 1000);

if ($requestId === false || !in_array($action, ['approve', 'decline'], true)) {
  jsonResponse(422, 'A valid request and review action are required.');
}

try {
  $db->beginTransaction();
  $request = $db->prepare(
    "SELECT wr.id, wr.worker_id, wr.issue_id, wr.status, wr.message,
            worker.name AS worker_name, i.title, i.status AS issue_status
       FROM work_requests wr
       INNER JOIN users worker ON worker.id = wr.worker_id
       LEFT JOIN issues i ON i.id = wr.issue_id
      WHERE wr.id = ? AND wr.status = 'pending'
        AND worker.role = 'worker' AND worker.is_active = 1
      LIMIT 1 FOR UPDATE"
  );
  $request->execute([(int) $requestId]);
  $requestRow = $request->fetch(PDO::FETCH_ASSOC);
  if (!$requestRow) {
    $db->rollBack();
    jsonResponse(404, 'That work request is no longer pending.');
  }

  if ($action === 'decline') {
    $db->prepare(
      "UPDATE work_requests
          SET status = 'declined', reviewed_by = ?, review_note = ?, reviewed_at = NOW()
        WHERE id = ?"
    )->execute([(int) $user['id'], $note !== '' ? $note : 'Request declined by administrator.', (int) $requestId]);
    $db->commit();
    jsonResponse(200, 'Work request declined.', ['request_id' => (int) $requestId, 'status' => 'declined']);
  }

  if (empty($requestRow['issue_id']) || !in_array($requestRow['issue_status'], ['pending', 'acknowledged', 'in_progress'], true)) {
    $db->rollBack();
    jsonResponse(422, 'This request does not point to an available issue.');
  }

  $active = $db->prepare(
    'SELECT id FROM assignments WHERE issue_id = ? AND completed_at IS NULL LIMIT 1 FOR UPDATE'
  );
  $active->execute([(int) $requestRow['issue_id']]);
  if ($active->fetchColumn()) {
    $db->rollBack();
    jsonResponse(409, 'The requested issue has already been assigned.');
  }

  $db->prepare(
    'INSERT INTO assignments (issue_id, worker_id, assigned_by, notes, assigned_at)
     VALUES (?, ?, ?, ?, NOW())'
  )->execute([(int) $requestRow['issue_id'], (int) $requestRow['worker_id'], (int) $user['id'], $requestRow['message']]);

  if ($requestRow['issue_status'] === 'pending') {
    $db->prepare("UPDATE issues SET status = 'acknowledged', updated_at = NOW() WHERE id = ?")
      ->execute([(int) $requestRow['issue_id']]);
    $db->prepare(
      'INSERT INTO status_history (issue_id, changed_by, old_status, new_status, note, created_at)
       VALUES (?, ?, ?, ?, ?, NOW())'
    )->execute([(int) $requestRow['issue_id'], (int) $user['id'], 'pending', 'acknowledged', 'Worker request approved and assigned.']);
  }

  $db->prepare(
    "UPDATE work_requests
        SET status = 'approved', reviewed_by = ?, review_note = ?, reviewed_at = NOW()
      WHERE id = ?"
  )->execute([(int) $user['id'], $note !== '' ? $note : 'Request approved and assigned.', (int) $requestId]);

  $db->commit();
  jsonResponse(200, 'Work request approved and assigned.', [
    'request_id' => (int) $requestId,
    'status' => 'approved',
    'issue_id' => (int) $requestRow['issue_id'],
    'worker_id' => (int) $requestRow['worker_id'],
  ]);
} catch (Throwable $exception) {
  if ($db->inTransaction()) {
    $db->rollBack();
  }
  error_log('Work request review failed: ' . $exception->getMessage());
  jsonResponse(500, 'Unable to review the work request.');
}
