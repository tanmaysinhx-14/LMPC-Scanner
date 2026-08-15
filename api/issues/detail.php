<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') jsonResponse(405, 'GET is required.');
if (!($db instanceof PDO)) jsonResponse(503, 'The database is temporarily unavailable.');
$issueId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($issueId === false) jsonResponse(422, 'A valid issue id is required.');
$stmt = $db->prepare('SELECT i.*, u.name AS reporter_name, (SELECT COUNT(*) FROM issue_reports ir WHERE ir.issue_id = i.id) AS report_count FROM issues i LEFT JOIN users u ON u.id = i.user_id WHERE i.id = ? LIMIT 1');
$stmt->execute([(int) $issueId]);
$issue = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$issue) jsonResponse(404, 'Issue not found.');
$viewer = sessionUser();
$isStaff = in_array((string) ($_SESSION['user_role'] ?? ''), ['worker', 'admin'], true);
if (!$isStaff) {
  $issue['lat'] = round((float) ($issue['lat'] ?? 0), 3);
  $issue['lng'] = round((float) ($issue['lng'] ?? 0), 3);
}
$images = $db->prepare('SELECT id, file_path, original_name, mime_type, file_size, created_at FROM issue_images WHERE issue_id = ? ORDER BY created_at ASC, id ASC');
$images->execute([(int) $issueId]);
$issue['images'] = array_map(static function (array $image): array { $image['url'] = '/' . ltrim((string) $image['file_path'], '/'); return $image; }, $images->fetchAll(PDO::FETCH_ASSOC));
$history = $db->prepare('SELECT sh.old_status, sh.new_status, sh.note, sh.created_at, u.name AS changed_by_name FROM status_history sh LEFT JOIN users u ON u.id = sh.changed_by WHERE sh.issue_id = ? ORDER BY sh.created_at ASC, sh.id ASC');
$history->execute([(int) $issueId]);
$issue['status_history'] = $history->fetchAll(PDO::FETCH_ASSOC);
$assignment = $db->prepare(
  'SELECT assignment.id, assignment.assigned_at, assignment.completed_at,
          assignment.citizen_verified_at, assignment.citizen_reopen_reason, assignment.after_image_path,
          worker.name AS worker_name, administrator.name AS assigned_by_name
     FROM assignments assignment
     INNER JOIN users worker ON worker.id = assignment.worker_id
     LEFT JOIN users administrator ON administrator.id = assignment.assigned_by
    WHERE assignment.issue_id = ?
    ORDER BY assignment.completed_at IS NULL DESC, assignment.assigned_at DESC, assignment.id DESC
    LIMIT 1'
);
$assignment->execute([(int) $issueId]);
$issue['assignment'] = $assignment->fetch(PDO::FETCH_ASSOC) ?: null;
$issue['viewer_reported'] = false;
$issue['viewer_upvoted'] = false;
if ($viewer !== null) {
  $reported = $db->prepare('SELECT 1 FROM issue_reports WHERE issue_id = ? AND reporter_id = ? LIMIT 1');
  $reported->execute([(int) $issueId, (int) $viewer['id']]);
  $issue['viewer_reported'] = (bool) $reported->fetchColumn();
  $upvoted = $db->prepare('SELECT 1 FROM upvotes WHERE issue_id = ? AND user_id = ? LIMIT 1');
  $upvoted->execute([(int) $issueId, (int) $viewer['id']]);
  $issue['viewer_upvoted'] = (bool) $upvoted->fetchColumn();
}
$issue['id'] = (int) $issue['id'];
$issue['severity'] = (int) $issue['severity'];
$issue['upvote_count'] = (int) $issue['upvote_count'];
$issue['report_count'] = (int) $issue['report_count'];
$issue['image_count'] = count($issue['images']);
$issue['cover_url'] = issueImageUrl($issue['images'][0]['file_path'] ?? null);
$issue['report_count'] = (int) $issue['report_count'];
$issue = enrichIssueWithCommunitySignals($db, $issue);
jsonResponse(200, 'Issue loaded.', ['issue' => $issue]);
