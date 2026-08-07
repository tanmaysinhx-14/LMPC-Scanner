<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') jsonResponse(405, 'POST is required.');
$user = requireAuthentication($db instanceof PDO ? $db : null);
requireCsrfToken(json: true);
if (!($db instanceof PDO)) jsonResponse(503, 'The database is temporarily unavailable.');
$body = json_decode((string) file_get_contents('php://input'), true);
$issueId = filter_var($_POST['issue_id'] ?? (is_array($body) ? ($body['issue_id'] ?? null) : null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($issueId === false) jsonResponse(422, 'A valid issue id is required.');
try {
  $db->beginTransaction();
  $exists = $db->prepare("SELECT id FROM issues WHERE id = ? AND status <> 'rejected' FOR UPDATE");
  $exists->execute([(int) $issueId]);
  if (!$exists->fetchColumn()) { $db->rollBack(); jsonResponse(404, 'Issue not found.'); }
  $vote = $db->prepare('SELECT id FROM upvotes WHERE issue_id = ? AND user_id = ? LIMIT 1');
  $vote->execute([(int) $issueId, $user['id']]);
  $voteId = $vote->fetchColumn();
  if ($voteId) {
    $db->prepare('DELETE FROM upvotes WHERE id = ?')->execute([(int) $voteId]);
    $upvoted = false;
    $db->prepare('UPDATE issues SET upvote_count = GREATEST(0, upvote_count - 1), priority_score = (severity * 20) + GREATEST(0, upvote_count - 1) WHERE id = ?')->execute([(int) $issueId]);
  } else {
    $db->prepare('INSERT INTO upvotes (issue_id, user_id, created_at) VALUES (?, ?, NOW())')->execute([(int) $issueId, $user['id']]);
    $upvoted = true;
    $db->prepare('UPDATE issues SET upvote_count = upvote_count + 1, priority_score = (severity * 20) + upvote_count WHERE id = ?')->execute([(int) $issueId]);
  }
  $count = $db->prepare('SELECT upvote_count FROM issues WHERE id = ?');
  $count->execute([(int) $issueId]);
  $upvoteCount = (int) $count->fetchColumn();
  $db->commit();
  jsonResponse(200, $upvoted ? 'Issue upvoted.' : 'Upvote removed.', ['issue_id' => (int) $issueId, 'upvoted' => $upvoted, 'upvote_count' => $upvoteCount]);
} catch (Throwable $exception) {
  if ($db->inTransaction()) $db->rollBack();
  error_log('Issue upvote failed: ' . $exception->getMessage());
  jsonResponse(500, 'Unable to update the upvote.');
}
