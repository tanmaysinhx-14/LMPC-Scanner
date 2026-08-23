<?php
declare(strict_types=1);

function normalizedCategory(?string $category): string
{
  $category = strtolower(trim((string) $category));
  $aliases = [
    'pothole detection' => 'pothole', 'potholes' => 'pothole',
    'garbage dump' => 'garbage', 'open drainage' => 'open_drain',
    'drainage' => 'open_drain', 'street light' => 'streetlight',
    'broken streetlight' => 'streetlight', 'fallen tree' => 'fallen_tree',
    'road damage' => 'road_damage',
  ];
  $category = $aliases[$category] ?? $category;
  $allowed = ['pothole', 'garbage', 'streetlight', 'waterlogging', 'road_damage', 'encroachment', 'graffiti', 'open_drain', 'fallen_tree', 'other', 'unknown'];
  return in_array($category, $allowed, true) ? $category : 'other';
}

class AIServiceException extends RuntimeException
{
}

function callAIService(
  string $relativePath,
  ?string $submittedCategory = null,
  ?float $latitude = null,
  ?float $longitude = null,
  bool $includePreview = false
): array
{
  // Keep the model on the local machine by default, but allow a deployed PHP
  // host to reach it through an authenticated/private tunnel when required.
  $endpoint = rtrim((string) (getenv('CIVICCONNECT_AI_URL') ?: 'http://127.0.0.1:8000'), '/') . '/analyze';
  $aiToken = trim((string) (getenv('CIVICCONNECT_AI_TOKEN') ?: ''));
  $payloadData = ['filepath' => $relativePath];
  if ($includePreview) {
    $payloadData['include_preview'] = true;
  }
  if ($submittedCategory !== null && $submittedCategory !== '') {
    $payloadData['submitted_category'] = normalizedCategory($submittedCategory);
  }
  if ($latitude !== null && $longitude !== null) {
    $payloadData['latitude'] = $latitude;
    $payloadData['longitude'] = $longitude;
  }
  $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES);
  if ($payload === false) {
    throw new AIServiceException('AI analysis request could not be prepared.');
  }

  $response = false;
  $status = 0;
  $transportError = '';

  if (function_exists('curl_init')) {
    $ch = curl_init($endpoint);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($aiToken !== '') $headers[] = 'Authorization: Bearer ' . $aiToken;
    curl_setopt_array($ch, [
      CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $transportError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
    $headerLines = "Content-Type: application/json\r\nAccept: application/json\r\n";
    if ($aiToken !== '') $headerLines .= 'Authorization: Bearer ' . $aiToken . "\r\n";
    $httpHeaders = [
      'method' => 'POST',
      'header' => $headerLines,
      'content' => $payload,
      'timeout' => 15,
      'ignore_errors' => true,
    ];
    $context = stream_context_create(['http' => $httpHeaders]);
    $response = @file_get_contents($endpoint, false, $context);
    foreach ($http_response_header ?? [] as $header) {
      if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
        $status = (int) $matches[1];
      }
    }
    if ($response === false) {
      $transportError = 'PHP could not connect to the AI service';
    }
  } else {
    throw new AIServiceException('AI analysis is unavailable because PHP has neither cURL nor allow_url_fopen enabled.');
  }

  if ($response === false) {
    throw new AIServiceException('AI analysis request failed' . ($transportError !== '' ? ': ' . $transportError : '.'));
  }

  $decoded = is_string($response) ? json_decode($response, true) : null;
  if ($status !== 200 || !is_array($decoded)) {
    throw new AIServiceException('AI analysis service returned an invalid response.');
  }
  if (($decoded['success'] ?? false) !== true) {
    $error = trim((string) ($decoded['error'] ?? 'The AI model could not analyze this image.'));
    throw new AIServiceException('AI analysis failed: ' . $error);
  }
  if (!isset($decoded['category'], $decoded['confidence'], $decoded['severity'])) {
    throw new AIServiceException('AI analysis response is missing required fields.');
  }

  $detectedCategory = normalizedCategory($decoded['category']);
  $department = normalizedDepartment($decoded['department'] ?? departmentForCategory($detectedCategory));
  $detections = [];
  foreach (($decoded['detections'] ?? []) as $detection) {
    if (!is_array($detection)) {
      continue;
    }
    $bbox = $detection['bbox'] ?? [];
    if (!is_array($bbox) || count($bbox) !== 4) {
      continue;
    }
    $detections[] = [
      'class' => substr(trim((string) ($detection['class'] ?? 'unknown')), 0, 100),
      'category' => normalizedCategory($detection['category'] ?? $detection['class'] ?? 'unknown'),
      'confidence' => max(0.0, min(1.0, (float) ($detection['confidence'] ?? 0))),
      'bbox' => array_map(static fn($value): float => round((float) $value, 2), array_values($bbox)),
    ];
  }

  // Annotated previews are for the immediate UI response only. The final
  // report stores the structured detection data without duplicating the
  // base64 image in issue_images.ai_raw_output or issue_ai_analyses.raw_output.
  $raw = $decoded;
  unset($raw['annotated_image'], $raw['preview_width'], $raw['preview_height']);
  $annotatedImage = $decoded['annotated_image'] ?? null;
  if (!is_string($annotatedImage) || !preg_match('/^data:image\/(?:jpeg|png|webp);base64,/', $annotatedImage) || strlen($annotatedImage) > 16 * 1024 * 1024) {
    $annotatedImage = null;
  }

  $result = [
    'category' => $detectedCategory,
    'severity' => max(1, min(5, (int) $decoded['severity'])),
    'confidence' => max(0.0, min(1.0, (float) $decoded['confidence'])),
    'is_manipulated' => !empty($decoded['is_manipulated']),
    'department' => $department,
    'low_confidence' => !empty($decoded['low_confidence']) || (float) $decoded['confidence'] < 0.45,
    'manipulation' => is_array($decoded['manipulation'] ?? null) ? $decoded['manipulation'] : [],
    'model_version' => (string) ($decoded['model_version'] ?? 'civicconnect-ai-service'),
    'detections' => $detections,
    'detection_count' => count($detections),
    'bbox' => is_array($decoded['bbox'] ?? null) ? array_map(static fn($value): float => round((float) $value, 2), array_values($decoded['bbox'])) : [],
    'image_width' => max(0, (int) ($decoded['image_width'] ?? 0)),
    'image_height' => max(0, (int) ($decoded['image_height'] ?? 0)),
    'raw' => $raw,
  ];

  if ($includePreview) {
    $result['annotated_image'] = $annotatedImage;
    $result['preview_width'] = max(0, (int) ($decoded['preview_width'] ?? 0));
    $result['preview_height'] = max(0, (int) ($decoded['preview_height'] ?? 0));
  }

  return $result;
}
