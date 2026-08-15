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

requireAuthentication($db instanceof PDO ? $db : null, ['citizen']);
requireCsrfToken(json: true);

$file = $_FILES['issueImage'] ?? $_FILES['image'] ?? [];
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

$temporaryPath = null;
$analysis = null;
$errorStatus = null;
$errorMessage = null;

try {
  $relativeDirectory = 'uploads/.ai-preview';
  $absoluteDirectory = __DIR__ . '/../../' . $relativeDirectory;
  if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
    throw new RuntimeException('Unable to prepare temporary AI analysis storage.');
  }

  $filename = 'preview-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
  $relativePath = $relativeDirectory . '/' . $filename;
  $temporaryPath = __DIR__ . '/../../' . $relativePath;
  if (!move_uploaded_file($file['tmp_name'], $temporaryPath)) {
    throw new RuntimeException('Unable to prepare the image for AI analysis.');
  }

  $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
  $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
  $validLatitude = $latitude !== false && $latitude !== null && $latitude >= -90 && $latitude <= 90;
  $validLongitude = $longitude !== false && $longitude !== null && $longitude >= -180 && $longitude <= 180;
  $analysis = callAIService(
    $relativePath,
    normalizedCategory($_POST['issueCategory'] ?? null),
    $validLatitude && $validLongitude ? (float) $latitude : null,
    $validLatitude && $validLongitude ? (float) $longitude : null,
    true
  );
} catch (AIServiceException $exception) {
  $errorStatus = 503;
  $errorMessage = $exception->getMessage();
} catch (Throwable $exception) {
  $errorStatus = 500;
  $errorMessage = 'The image could not be analyzed. Please try again.';
  error_log('AI preview failed: ' . $exception->getMessage());
} finally {
  if (is_string($temporaryPath) && is_file($temporaryPath)) {
    unlink($temporaryPath);
  }
}

if ($errorStatus !== null) {
  jsonResponse($errorStatus, $errorMessage ?? 'AI analysis failed.');
}

jsonResponse(200, 'AI analysis complete.', ['ai' => $analysis]);
