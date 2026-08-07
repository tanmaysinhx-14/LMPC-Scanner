<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') jsonResponse(405, 'GET is required.');
if (!($db instanceof PDO)) jsonResponse(503, 'The database is temporarily unavailable.');
$params = [];
$where = ["status <> 'rejected'"];
if (!empty($_GET['ward'])) { $where[] = 'ward_id = :ward_id'; $params['ward_id'] = (int) $_GET['ward']; }
if (!empty($_GET['cat'])) { $where[] = 'category = :category'; $params['category'] = strtolower(trim((string) $_GET['cat'])); }
$stmt = $db->prepare('SELECT geohash, AVG(lat) AS lat, AVG(lng) AS lng, COUNT(*) AS report_count, MAX(severity) AS severity, SUM(upvote_count) AS upvotes FROM issues WHERE ' . implode(' AND ', $where) . ' GROUP BY geohash ORDER BY report_count DESC');
$stmt->execute($params);
$points = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $point) {
  $count = (int) $point['report_count'];
  $severity = (int) $point['severity'];
  $points[] = ['lat' => round((float) $point['lat'], 6), 'lng' => round((float) $point['lng'], 6), 'count' => $count, 'severity' => $severity, 'upvotes' => (int) $point['upvotes'], 'band' => $count >= 6 ? 'red' : ($count >= 3 ? 'yellow' : 'green'), 'weight' => round(min(1.0, ($count / 10) * ($severity / 5)), 3)];
}
jsonResponse(200, 'Heatmap data loaded.', ['points' => $points]);
