<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') jsonResponse(405, 'GET is required.');
if (!($db instanceof PDO)) jsonResponse(503, 'The database is temporarily unavailable.');
$params = [];
$where = ["i.status <> 'rejected'"];
if (!empty($_GET['ward'])) { $where[] = 'i.ward_id = :ward_id'; $params['ward_id'] = (int) $_GET['ward']; }
if (!empty($_GET['cat'])) { $where[] = 'i.category = :category'; $params['category'] = strtolower(trim((string) $_GET['cat'])); }
$stmt = $db->prepare(
  'SELECT i.geohash, AVG(i.lat) AS lat, AVG(i.lng) AS lng,
          COUNT(ir.id) AS report_count, MAX(i.severity) AS severity,
          MAX(i.upvote_count) AS upvotes
     FROM issues i
     LEFT JOIN issue_reports ir ON ir.issue_id = i.id
    WHERE ' . implode(' AND ', $where) . '
    GROUP BY i.geohash ORDER BY report_count DESC'
);
$stmt->execute($params);
$points = [];
$isStaff = in_array((string) ($_SESSION['user_role'] ?? ''), ['worker', 'authority', 'admin'], true);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $point) {
  // Legacy issues may not have a child report row yet; still show them as a
  // single low-density point.
  $count = max(1, (int) $point['report_count']);
  $severity = (int) $point['severity'];
  $precision = $isStaff ? 6 : 3;
  $points[] = ['lat' => round((float) $point['lat'], $precision), 'lng' => round((float) $point['lng'], $precision), 'count' => $count, 'severity' => $severity, 'upvotes' => (int) $point['upvotes'], 'band' => $count >= 6 ? 'red' : ($count >= 3 ? 'yellow' : 'green'), 'weight' => round(min(1.0, ($count / 10) * ($severity / 5)), 3)];
}
jsonResponse(200, 'Heatmap data loaded.', ['points' => $points]);
