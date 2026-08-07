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

function callAIService(string $relativePath): array
{
  $endpoint = 'http://127.0.0.1:8000/analyze';
  $payload = json_encode(['filepath' => $relativePath], JSON_UNESCAPED_SLASHES);
  if ($payload === false) {
    throw new AIServiceException('AI analysis request could not be prepared.');
  }

  $response = false;
  $status = 0;
  $transportError = '';

  if (function_exists('curl_init')) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
      CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
      CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $transportError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
    $httpHeaders = [
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
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
  if ($detectedCategory === 'unknown') {
    throw new AIServiceException('AI analysis returned an unsupported category.');
  }

  return [
    'category' => $detectedCategory,
    'severity' => max(1, min(5, (int) $decoded['severity'])),
    'confidence' => max(0.0, min(1.0, (float) $decoded['confidence'])),
    'is_manipulated' => !empty($decoded['is_manipulated']),
    'model_version' => 'civicconnect-ai-service',
    'raw' => $decoded,
  ];
}
