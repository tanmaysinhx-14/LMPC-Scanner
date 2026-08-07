<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  jsonResponse(405, 'POST is required.');
}

$user = requireAuthentication($db instanceof PDO ? $db : null, ['worker']);
requireCsrfToken(json: true);
if (!($db instanceof PDO)) {
  jsonResponse(503, 'The database is temporarily unavailable.');
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : $_POST;
$issueId = filter_var($body['issue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$message = substr(trim((string) ($body['message'] ?? '')), 0, 1000);

if ($issueId === false || $message === '') {
  jsonResponse(422, 'Choose an issue and add a short note for the administrator.');
}

try {
  $issue = $db->prepare(
    "SELECT i.id, i.title
       FROM issues i
      WHERE i.id = ? AND i.status IN ('pending', 'acknowledged', 'in_progress')
      LIMIT 1"
  );
  $issue->execute([(int) $issueId]);
  $issueRow = $issue->fetch(PDO::FETCH_ASSOC);
  if (!$issueRow) {
    jsonResponse(404, 'That issue is no longer available for assignment.');
  }

  $activeAssignment = $db->prepare(
    'SELECT id FROM assignments WHERE issue_id = ? AND completed_at IS NULL LIMIT 1'
  );
  $activeAssignment->execute([(int) $issueId]);
  if ($activeAssignment->fetchColumn()) {
    jsonResponse(409, 'That issue is already assigned to a worker.');
  }

  $pending = $db->prepare(
    "SELECT id FROM work_requests
       WHERE worker_id = ? AND issue_id = ? AND status = 'pending'
       LIMIT 1"
  );
  $pending->execute([(int) $user['id'], (int) $issueId]);
  if ($pending->fetchColumn()) {
    jsonResponse(409, 'You already have a pending request for this issue.');
  }

  $insert = $db->prepare(
    'INSERT INTO work_requests (worker_id, issue_id, message, status, created_at)
     VALUES (?, ?, ?, \'pending\', NOW())'
  );
  $insert->execute([(int) $user['id'], (int) $issueId, $message]);

  jsonResponse(201, 'Work request sent to the administrator.', [
    'request_id' => (int) $db->lastInsertId(),
    'issue_id' => (int) $issueId,
    'issue_title' => $issueRow['title'],
  ]);
} catch (Throwable $exception) {
  error_log('Work request creation failed: ' . $exception->getMessage());
  jsonResponse(500, 'Unable to send the work request.');
}
