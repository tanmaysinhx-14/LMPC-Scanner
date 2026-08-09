<?php

// functions/geohash.php
function encodeGeohash(float $lat, float $lng, int $precision = 7): string
{
  // Standard geohash encoding — precision 7 ≈ ±76m cell
  // Use a library: composer require ezimuel/geohash  OR implement encode()
  $lat = max(-90.0, min(90.0, $lat));
  $lng = max(-180.0, min(180.0, $lng));
  $precision = max(1, min(12, $precision));
  $alphabet = '0123456789bcdefghjkmnpqrstuvwxyz';
  $latRange = [-90.0, 90.0];
  $lngRange = [-180.0, 180.0];
  $hash = '';
  $bit = 0;
  $charIndex = 0;
  $evenBit = true;

  while (strlen($hash) < $precision) {
    $range = $evenBit ? $lngRange : $latRange;
    $value = $evenBit ? $lng : $lat;
    $mid = ($range[0] + $range[1]) / 2;
    $charIndex = ($charIndex << 1) | ($value >= $mid ? 1 : 0);

    if ($value >= $mid) {
      $range[0] = $mid;
    } else {
      $range[1] = $mid;
    }
    if ($evenBit) {
      $lngRange = $range;
    } else {
      $latRange = $range;
    }

    if (++$bit === 5) {
      $hash .= $alphabet[$charIndex];
      $bit = 0;
      $charIndex = 0;
    }
    $evenBit = !$evenBit;
  }

  return $hash;
}

function geohashPrecisionForCategory(?string $category): int
{
  $category = strtolower(trim((string) $category));
  $defaults = [
    'pothole' => 7, 'road_damage' => 7, 'streetlight' => 7, 'fallen_tree' => 7,
    'waterlogging' => 6, 'open_drain' => 6, 'garbage' => 7, 'encroachment' => 7,
    'graffiti' => 7, 'other' => 7,
  ];
  $envKey = 'CIVIC_GEOHASH_PRECISION_' . strtoupper(str_replace('-', '_', $category));
  $configured = getenv($envKey);
  if ($configured !== false && ctype_digit((string) $configured)) {
    return max(5, min(8, (int) $configured));
  }
  return $defaults[$category] ?? 7;
}

function issueGeohash(float $lat, float $lng, ?string $category): string
{
  return encodeGeohash($lat, $lng, geohashPrecisionForCategory($category));
}

// In submit.php, before inserting new issue:
function detectAndHandleDuplicate(PDO $db, float $lat, float $lng, string $category, int $userId): ?int
{
  $geohash = issueGeohash($lat, $lng, $category);
  $prefixes = [
    substr($geohash, 0, 7),  // exact cell
    substr($geohash, 0, 6),  // parent cell (±600m)
  ];

  $placeholders = implode(',', array_fill(0, count($prefixes), '?'));
  $stmt = $db->prepare("
        SELECT id, upvote_count FROM issues 
        WHERE geohash LIKE ? 
          AND category = ? 
          AND status NOT IN ('resolved','rejected')
          AND created_at > DATE_SUB(NOW(), INTERVAL 6 HOUR)
          AND user_id != ?
        LIMIT 1
    ");
  $stmt->execute([substr($geohash, 0, geohashPrecisionForCategory($category)) . '%', $category, $userId]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    // Increment upvote on parent issue instead of creating new one
    $db->prepare("UPDATE issues SET upvote_count = upvote_count + 1 WHERE id = ?")
      ->execute([$existing['id']]);
    return $existing['id']; // Return parent ID → tell citizen "reinforced existing complaint"
  }

  return null; // No duplicate, proceed with normal insert
}
