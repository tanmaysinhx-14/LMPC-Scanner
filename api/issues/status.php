<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT'], true)) jsonResponse(405, 'POST or PUT is required.');
$user = requireAuthentication($db instanceof PDO ? $db : null, ['worker', 'authority', 'admin']);
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
  $db->prepare("UPDATE issues SET status = ?, resolved_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE NULL END WHERE id = ?")->execute([$newStatus, $newStatus, (int) $issueId]);
  $db->prepare('INSERT INTO status_history (issue_id, changed_by, old_status, new_status, note, created_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([(int) $issueId, $user['id'], $oldStatus, $newStatus, $note]);
  $db->commit();
  jsonResponse(200, 'Issue status updated.', ['issue_id' => (int) $issueId, 'old_status' => $oldStatus, 'new_status' => $newStatus]);
} catch (Throwable $exception) {
  if ($db->inTransaction()) $db->rollBack();
  error_log('Issue status update failed: ' . $exception->getMessage());
  jsonResponse(500, 'Unable to update issue status.');
}
