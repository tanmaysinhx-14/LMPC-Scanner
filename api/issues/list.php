<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') jsonResponse(405, 'GET is required.');
if (!($db instanceof PDO)) jsonResponse(503, 'The database is temporarily unavailable.');

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;
$params = [];
$where = ["i.status <> 'rejected'"];
if (!empty($_GET['ward'])) { $where[] = 'i.ward_id = :ward_id'; $params['ward_id'] = (int) $_GET['ward']; }
if (!empty($_GET['cat'])) { $where[] = 'i.category = :category'; $params['category'] = strtolower(trim((string) $_GET['cat'])); }
if (!empty($_GET['status'])) { $where[] = 'i.status = :status'; $params['status'] = strtolower(trim((string) $_GET['status'])); }
if (!empty($_GET['q'])) { $where[] = '(i.title LIKE :query OR i.description LIKE :query)'; $params['query'] = '%' . substr(trim((string) $_GET['q']), 0, 100) . '%'; }
$whereSql = implode(' AND ', $where);
$countStmt = $db->prepare("SELECT COUNT(*) FROM issues i WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$viewerId = isLoggedIn() ? (int) $_SESSION['user_id'] : 0;
$stmt = $db->prepare(
  "SELECT i.id, i.title, i.description, i.category, i.severity, i.status,
          i.lat, i.lng, i.geohash, i.address, i.upvote_count, i.is_verified,
          i.ai_confidence, i.priority_score, i.created_at, i.updated_at,
          u.name AS reporter_name,
          (SELECT COUNT(*) FROM issue_images ii WHERE ii.issue_id = i.id) AS image_count,
          (SELECT COUNT(*) FROM issue_reports ir WHERE ir.issue_id = i.id) AS report_count,
          (SELECT ii.file_path FROM issue_images ii WHERE ii.issue_id = i.id ORDER BY ii.id ASC LIMIT 1) AS cover_path,
          (SELECT COUNT(*) FROM upvotes uv WHERE uv.issue_id = i.id AND uv.user_id = :viewer_id) AS viewer_upvoted
     FROM issues i LEFT JOIN users u ON u.id = i.user_id
    WHERE {$whereSql}
    ORDER BY i.priority_score DESC, i.created_at DESC LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_INT);
foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$isStaff = in_array((string) ($_SESSION['user_role'] ?? ''), ['worker', 'authority', 'admin'], true);
$items = array_map(static function (array $issue) use ($isStaff): array {
  if (!$isStaff) { $issue['lat'] = round((float) $issue['lat'], 3); $issue['lng'] = round((float) $issue['lng'], 3); }
  if (!empty($issue['cover_path'])) $issue['cover_url'] = '/' . ltrim((string) $issue['cover_path'], '/');
  $issue['image_count'] = (int) $issue['image_count'];
  $issue['report_count'] = (int) $issue['report_count'];
  $issue['viewer_upvoted'] = (bool) $issue['viewer_upvoted'];
  return $issue;
}, $stmt->fetchAll(PDO::FETCH_ASSOC));
jsonResponse(200, 'Issue feed loaded.', ['items' => $items, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)]]);
