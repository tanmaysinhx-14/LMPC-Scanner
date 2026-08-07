<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT'], true)) jsonResponse(405, 'POST or PUT is required.');
$user = requireAuthentication($db instanceof PDO ? $db : null, ['worker', 'admin']);
requireCsrfToken(json: true);
if (!($db instanceof PDO)) jsonResponse(503, 'The database is temporarily unavailable.');
$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : $_POST;
$issueId = filter_var($body['issue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$newStatus = strtolower(trim((string) ($body['status'] ?? '')));
$note = substr(trim((string) ($body['note'] ?? '')), 0, 2000);
$validStatuses = ['pending', 'acknowledged', 'in_progress', 'resolved', 'rejected'];
if ($issueId === false || !in_array($newStatus, $validStatuses, true)) jsonResponse(422, 'A valid issue id and status are required.');
try {
  $db->beginTransaction();
  $stmt = $db->prepare('SELECT status FROM issues WHERE id = ? FOR UPDATE');
  $stmt->execute([(int) $issueId]);
  $oldStatus = $stmt->fetchColumn();
  if ($oldStatus === false) { $db->rollBack(); jsonResponse(404, 'Issue not found.'); }

  // Workers may only update issues that are currently assigned to them. An
  // administrator can update any issue within this endpoint.
  if ($user['role'] === 'worker') {
    if (!in_array($newStatus, ['in_progress', 'resolved'], true)) {
      $db->rollBack();
      jsonResponse(403, 'Workers can only move assigned issues to in-progress or completed.');
    }
    $assignment = $db->prepare(
      'SELECT id FROM assignments
        WHERE issue_id = ? AND worker_id = ? AND completed_at IS NULL
        LIMIT 1'
    );
    $assignment->execute([(int) $issueId, (int) $user['id']]);
    if (!$assignment->fetchColumn()) {
      $db->rollBack();
      jsonResponse(403, 'This issue is not assigned to you.');
    }
  }

  $db->prepare("UPDATE issues SET status = ?, resolved_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE NULL END WHERE id = ?")->execute([$newStatus, $newStatus, (int) $issueId]);
  if ($user['role'] === 'worker') {
    $db->prepare(
      "UPDATE assignments
          SET completed_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE NULL END
        WHERE issue_id = ? AND worker_id = ? AND completed_at IS NULL"
    )->execute([$newStatus, (int) $issueId, (int) $user['id']]);
  } elseif ($newStatus === 'resolved') {
    // Keep the worker queue consistent when an administrator closes an issue.
    $db->prepare(
      'UPDATE assignments SET completed_at = NOW() WHERE issue_id = ? AND completed_at IS NULL'
    )->execute([(int) $issueId]);
  }
  $db->prepare('INSERT INTO status_history (issue_id, changed_by, old_status, new_status, note, created_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([(int) $issueId, $user['id'], $oldStatus, $newStatus, $note]);
  $db->commit();
  jsonResponse(200, 'Issue status updated.', ['issue_id' => (int) $issueId, 'old_status' => $oldStatus, 'new_status' => $newStatus]);
} catch (Throwable $exception) {
  if ($db->inTransaction()) $db->rollBack();
  error_log('Issue status update failed: ' . $exception->getMessage());
  jsonResponse(500, 'Unable to update issue status.');
}
