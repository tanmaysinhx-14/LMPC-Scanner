<?php

// functions/geohash.php
function encodeGeohash(float $lat, float $lng, int $precision = 7): string
{
  // Standard geohash encoding — precision 7 ≈ ±76m cell
  // Use a library: composer require ezimuel/geohash  OR implement encode()
  return \Geohash\Geohash::encode($lat, $lng, $precision);
}

// In submit.php, before inserting new issue:
function detectAndHandleDuplicate(PDO $db, float $lat, float $lng, string $category, int $userId): ?int
{
  $geohash = encodeGeohash($lat, $lng, 7);
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
  $stmt->execute([substr($geohash, 0, 6) . '%', $category, $userId]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    // Increment upvote on parent issue instead of creating new one
    $db->prepare("UPDATE issues SET upvote_count = upvote_count + 1 WHERE id = ?")
      ->execute([$existing['id']]);
    return $existing['id']; // Return parent ID → tell citizen "reinforced existing complaint"
  }

  return null; // No duplicate, proceed with normal insert
}
