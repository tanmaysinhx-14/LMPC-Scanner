<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../functions/ai/service.php';
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

function storeUploadedIssueImage(array $file): array
{
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
    jsonResponse(400, 'An issue image is required.');
  }
  if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 10 * 1024 * 1024) {
    jsonResponse(413, 'The image must be smaller than 10 MB.');
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($file['tmp_name']);
  $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) {
    jsonResponse(415, 'Only valid JPEG, PNG, or WebP images are accepted.');
  }

  $relativeDirectory = 'uploads/' . date('Y/m/d');
  $absoluteDirectory = __DIR__ . '/../../' . $relativeDirectory;
  if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
    jsonResponse(500, 'Unable to prepare image storage.');
  }

  $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
  $relativePath = $relativeDirectory . '/' . $filename;
  $absolutePath = __DIR__ . '/../../' . $relativePath;
  if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
    jsonResponse(500, 'Unable to save the image.');
  }

  return [
    'absolute_path' => $absolutePath,
    'relative_path' => $relativePath,
    'mime_type' => $mime,
    'file_size' => (int) $file['size'],
    'original_name' => substr(basename((string) ($file['name'] ?? 'issue')), 0, 255),
    'sha256' => hash_file('sha256', $absolutePath),
  ];
}

$latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
  jsonResponse(422, 'A valid GPS latitude and longitude are required.');
}

$description = substr(trim((string) ($_POST['issueDescription'] ?? '')), 0, 5000);
$address = substr(trim((string) ($_POST['issueLocation'] ?? '')), 0, 500);
$submittedCategory = normalizedCategory($_POST['issueCategory'] ?? 'other');
$gpsAccuracy = filter_var($_POST['gps_accuracy'] ?? null, FILTER_VALIDATE_FLOAT);
$gpsAccuracy = $gpsAccuracy === false ? null : max(0.0, min(10000.0, (float) $gpsAccuracy));

try {
  $image = storeUploadedIssueImage($_FILES['issueImage'] ?? $_FILES['image'] ?? []);
  $ai = callAIService($image['relative_path']);
  $category = $ai['category'] === 'unknown' ? $submittedCategory : $ai['category'];
  $severity = (int) $ai['severity'];
  $geohash = encodeGeohash((float) $latitude, (float) $longitude, 7);
  $rawAI = json_encode($ai['raw'] ?? $ai, JSON_UNESCAPED_SLASHES);

  $db->beginTransaction();
  $duplicate = $db->prepare(
    "SELECT id FROM issues
      WHERE geohash LIKE :geohash_prefix AND category = :category
        AND status NOT IN ('resolved', 'rejected')
        AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
      ORDER BY created_at ASC LIMIT 1 FOR UPDATE"
  );
  $duplicate->execute(['geohash_prefix' => substr($geohash, 0, 6) . '%', 'category' => $category]);
  $existing = $duplicate->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    $issueId = (int) $existing['id'];
    $db->prepare(
      'INSERT INTO issue_reports
        (issue_id, reporter_id, submitted_category, description, lat, lng, geohash, gps_accuracy, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    )->execute([$issueId, $user['id'], $submittedCategory, $description, $latitude, $longitude, $geohash, $gpsAccuracy]);
    $reportId = (int) $db->lastInsertId();
    $db->prepare(
      'INSERT INTO issue_images
        (issue_id, report_id, file_path, original_name, mime_type, file_size, sha256, ai_raw_output, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    )->execute([$issueId, $reportId, $image['relative_path'], $image['original_name'], $image['mime_type'], $image['file_size'], $image['sha256'], $rawAI]);
    $imageId = (int) $db->lastInsertId();
    $db->prepare(
      'INSERT INTO issue_ai_analyses
        (issue_id, report_id, image_id, category, severity, confidence, is_manipulated, model_version, raw_output, analyzed_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    )->execute([$issueId, $reportId, $imageId, $category, $severity, $ai['confidence'], (int) $ai['is_manipulated'], $ai['model_version'], $rawAI]);
    $db->prepare(
      'UPDATE issues SET upvote_count = upvote_count + 1,
          severity = GREATEST(severity, ?), priority_score = (GREATEST(severity, ?) * 20) + upvote_count
       WHERE id = ?'
    )->execute([$severity, $severity, $issueId]);
    $db->commit();
    $message = 'Your report was grouped with an existing nearby issue.';
    setToast($message, 'success');
    jsonResponse(201, $message, ['issue_id' => $issueId, 'grouped' => true, 'category' => $category, 'ai' => $ai]);
  }

  $title = ucfirst(str_replace('_', ' ', $category)) . ' reported nearby';
  $insertIssue = $db->prepare(
    'INSERT INTO issues
      (user_id, title, description, category, severity, status, lat, lng, geohash, address, ward_id,
       upvote_count, is_verified, ai_confidence, is_manipulated, priority_score, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, NOW(), NOW())'
  );
  $insertIssue->execute([$user['id'], $title, $description, $category, $severity, 'pending', $latitude, $longitude, $geohash, $address, $user['ward_id'], $ai['confidence'], (int) $ai['is_manipulated'], ($severity * 20) + ($ai['confidence'] * 10)]);
  $issueId = (int) $db->lastInsertId();
  $db->prepare(
    'INSERT INTO issue_reports
      (issue_id, reporter_id, submitted_category, description, lat, lng, geohash, gps_accuracy, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
  )->execute([$issueId, $user['id'], $submittedCategory, $description, $latitude, $longitude, $geohash, $gpsAccuracy]);
  $reportId = (int) $db->lastInsertId();
  $db->prepare(
    'INSERT INTO status_history (issue_id, changed_by, old_status, new_status, note, created_at)
     VALUES (?, ?, NULL, ?, ?, NOW())'
  )->execute([$issueId, $user['id'], 'pending', 'Report submitted']);
  $db->prepare(
    'INSERT INTO issue_images
      (issue_id, report_id, file_path, original_name, mime_type, file_size, sha256, ai_raw_output, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
  )->execute([$issueId, $reportId, $image['relative_path'], $image['original_name'], $image['mime_type'], $image['file_size'], $image['sha256'], $rawAI]);
  $imageId = (int) $db->lastInsertId();
  $db->prepare(
    'INSERT INTO issue_ai_analyses
      (issue_id, report_id, image_id, category, severity, confidence, is_manipulated, model_version, raw_output, analyzed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
  )->execute([$issueId, $reportId, $imageId, $category, $severity, $ai['confidence'], (int) $ai['is_manipulated'], $ai['model_version'], $rawAI]);

  $db->commit();
  $message = 'Report submitted and added to the community feed.';
  setToast($message, 'success');
  jsonResponse(201, $message, ['issue_id' => $issueId, 'grouped' => false, 'category' => $category, 'ai' => $ai]);
} catch (Throwable $exception) {
  if ($db->inTransaction()) $db->rollBack();
  if (isset($image['absolute_path']) && is_file($image['absolute_path'])) unlink($image['absolute_path']);
  error_log('Issue submission failed: ' . $exception->getMessage());
  if ($exception instanceof AIServiceException) {
    jsonResponse(503, $exception->getMessage());
  }
  jsonResponse(500, 'The report could not be saved. Please try again.');
}
