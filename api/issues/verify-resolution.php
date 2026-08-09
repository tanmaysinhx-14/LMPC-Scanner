<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  jsonResponse(405, 'POST is required.');
}

$user = requireAuthentication($db instanceof PDO ? $db : null, ['citizen']);
requireCsrfToken(json: true);
if (!($db instanceof PDO)) {
  jsonResponse(503, 'The database is temporarily unavailable.');
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : $_POST;
$issueId = filter_var($body['issue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim((string) ($body['action'] ?? '')));
$reason = substr(trim((string) ($body['reason'] ?? '')), 0, 2000);
if ($issueId === false || !in_array($action, ['verify', 'reopen'], true)) {
  jsonResponse(422, 'A valid issue and resolution action are required.');
}
if ($action === 'reopen' && $reason === '') {
  jsonResponse(422, 'Tell the city why the issue needs more work.');
}

$storedAfterImage = null;
try {
  $assignmentStmt = $db->prepare(
    "SELECT a.id, a.issue_id, a.completed_at, i.status
       FROM assignments a
       INNER JOIN issues i ON i.id = a.issue_id
      WHERE a.issue_id = ? AND a.completed_at IS NOT NULL AND i.status = 'resolved'
        AND EXISTS (
          SELECT 1 FROM issue_reports ir WHERE ir.issue_id = i.id AND ir.reporter_id = ?
        )
      ORDER BY a.completed_at DESC, a.id DESC
      LIMIT 1 FOR UPDATE"
  );

  $db->beginTransaction();
  $assignmentStmt->execute([(int) $issueId, (int) $user['id']]);
  $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
  if (!$assignment) {
    $db->rollBack();
    jsonResponse(404, 'There is no completed resolution for one of your reports.');
  }

  if ($action === 'verify' && isset($_FILES['after_image']) && ($_FILES['after_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['after_image'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
      $db->rollBack();
      jsonResponse(400, 'The after-photo could not be uploaded.');
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 10 * 1024 * 1024) {
      $db->rollBack();
      jsonResponse(413, 'The after-photo must be smaller than 10 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) {
      $db->rollBack();
      jsonResponse(415, 'Only valid JPEG, PNG, or WebP after-photos are accepted.');
    }
    $relativeDirectory = 'uploads/resolutions/' . date('Y/m/d');
    $absoluteDirectory = __DIR__ . '/../../' . $relativeDirectory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
      throw new RuntimeException('Unable to prepare after-photo storage.');
    }
    $storedAfterImage = $relativeDirectory . '/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/../../' . $storedAfterImage)) {
      throw new RuntimeException('Unable to save the after-photo.');
    }
  }

  if ($action === 'verify') {
    $db->prepare(
      'UPDATE assignments
          SET citizen_verified_at = NOW(), citizen_reopen_reason = NULL,
              after_image_path = COALESCE(?, after_image_path)
        WHERE id = ?'
    )->execute([$storedAfterImage, (int) $assignment['id']]);
    $note = 'Citizen verified the completed resolution.';
    $message = 'Resolution verified. Thank you for closing the loop.';
  } else {
    $db->prepare(
      'UPDATE assignments
          SET completed_at = NULL, citizen_verified_at = NULL, citizen_reopen_reason = ?, after_image_path = NULL
        WHERE id = ?'
    )->execute([$reason, (int) $assignment['id']]);
    $db->prepare("UPDATE issues SET status = 'acknowledged', resolved_at = NULL, updated_at = NOW() WHERE id = ?")
      ->execute([(int) $issueId]);
    $note = 'Citizen reopened the issue: ' . $reason;
    $message = 'The issue has been reopened for another review.';
  }

  $db->prepare(
    'INSERT INTO status_history (issue_id, changed_by, old_status, new_status, note, created_at)
     VALUES (?, ?, ?, ?, ?, NOW())'
  )->execute([(int) $issueId, (int) $user['id'], 'resolved', $action === 'verify' ? 'resolved' : 'acknowledged', $note]);
  $db->commit();
  jsonResponse(200, $message, ['issue_id' => (int) $issueId, 'action' => $action]);
} catch (Throwable $exception) {
  if ($db->inTransaction()) {
    $db->rollBack();
  }
  if ($storedAfterImage !== null && is_file(__DIR__ . '/../../' . $storedAfterImage)) {
    unlink(__DIR__ . '/../../' . $storedAfterImage);
  }
  error_log('Resolution verification failed: ' . $exception->getMessage());
  jsonResponse(500, 'The resolution update could not be saved.');
}
