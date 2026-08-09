<?php
declare(strict_types=1);

function issueCategoryLabel(?string $category): string
{
  $label = str_replace('_', ' ', strtolower(trim((string) $category)));
  return ucwords($label === '' ? 'unknown' : $label);
}

function issueStatusLabel(?string $status): string
{
  return ucwords(str_replace('_', ' ', strtolower(trim((string) $status ?: 'pending'))));
}

function issueStatusClass(?string $status): string
{
  return match (strtolower((string) $status)) {
    'resolved' => 'success',
    'in_progress', 'acknowledged' => 'warning',
    'rejected' => 'secondary',
    default => 'danger',
  };
}

function normalizedDepartment(?string $department): string
{
  $department = strtolower(trim((string) $department));
  $allowed = ['sanitation', 'drainage', 'public_works', 'electricity', 'municipal'];
  return in_array($department, $allowed, true) ? $department : 'municipal';
}

function departmentForCategory(?string $category): string
{
  return match (strtolower(trim((string) $category))) {
    'garbage' => 'sanitation',
    'waterlogging', 'open_drain' => 'drainage',
    'streetlight' => 'electricity',
    'pothole', 'road_damage', 'graffiti' => 'public_works',
    default => 'municipal',
  };
}

function civicPriorityFormula(): string
{
  return '(severity x 2) + ln(report_count + 1) + ln(upvote_count + 1) + recency_decay, with a 15% recurrence multiplier';
}

function calculatePriorityScore(int $severity, int $reportCount, int $upvoteCount, ?string $createdAt = null, bool $recurring = false): float
{
  $createdTimestamp = $createdAt ? strtotime($createdAt) : time();
  $ageHours = max(0.0, (time() - ($createdTimestamp ?: time())) / 3600);
  $recencyDecay = 5.0 * exp(-$ageHours / (24.0 * 7.0));
  $score = ($severity * 2.0) + log($reportCount + 1.0) + log($upvoteCount + 1.0) + $recencyDecay;
  return round($score * ($recurring ? 1.15 : 1.0), 4);
}

function issueImageUrl(?string $path): string
{
  if (!$path) {
    return '';
  }

  return '/' . ltrim(str_replace('\\', '/', $path), '/');
}

/**
 * Return the canonical, grouped issue posts used by the public feed.
 * Individual citizen reports remain in issue_reports, while one canonical
 * issue is displayed as one post with its image/report counters.
 */
function fetchIssueFeed(PDO $db, array $options = []): array
{
  $page = max(1, (int) ($options['page'] ?? 1));
  $limit = min(50, max(1, (int) ($options['limit'] ?? 20)));
  $offset = ($page - 1) * $limit;
  $bindings = [];
  $where = ["i.status <> 'rejected'"];

  if (isset($options['ward']) && $options['ward'] !== null && $options['ward'] !== '') {
    $where[] = 'i.ward_id = :ward_id';
    $bindings['ward_id'] = (int) $options['ward'];
  }
  if (!empty($options['category'])) {
    $where[] = 'i.category = :category';
    $bindings['category'] = strtolower(trim((string) $options['category']));
  }
  if (!empty($options['status'])) {
    $where[] = 'i.status = :issue_status';
    $bindings['issue_status'] = strtolower(trim((string) $options['status']));
  }
  if (!empty($options['query'])) {
    $query = '%' . substr(trim((string) $options['query']), 0, 100) . '%';
    $where[] = '(i.title LIKE :query_title OR i.description LIKE :query_description OR i.address LIKE :query_address)';
    $bindings['query_title'] = $query;
    $bindings['query_description'] = $query;
    $bindings['query_address'] = $query;
  }

  $whereSql = implode(' AND ', $where);
  $count = $db->prepare("SELECT COUNT(*) FROM issues i WHERE {$whereSql}");
  foreach ($bindings as $key => $value) {
    $count->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $count->execute();
  $total = (int) $count->fetchColumn();

  $viewerId = max(0, (int) ($options['viewer_id'] ?? 0));
  $sort = strtolower((string) ($options['sort'] ?? 'hot'));
  $orderBy = match ($sort) {
    'new' => 'i.created_at DESC, i.id DESC',
    'top' => 'i.upvote_count DESC, i.created_at DESC',
    'rising' => 'i.updated_at DESC, i.upvote_count DESC',
    default => 'i.priority_score DESC, i.created_at DESC, i.id DESC',
  };

  $stmt = $db->prepare(
    "SELECT i.id, i.title, i.description, i.category, i.department, i.severity, i.status,
            i.lat, i.lng, i.address, i.upvote_count, i.is_verified,
            i.ai_confidence, i.priority_score, i.is_recurring, i.recurrence_of, i.created_at, i.updated_at,
            u.name AS reporter_name,
            (SELECT worker.name
               FROM assignments assignment
               INNER JOIN users worker ON worker.id = assignment.worker_id
              WHERE assignment.issue_id = i.id
              ORDER BY assignment.completed_at IS NULL DESC, assignment.assigned_at DESC, assignment.id DESC
              LIMIT 1) AS assigned_worker_name,
            (SELECT administrator.name
               FROM assignments assignment
               LEFT JOIN users administrator ON administrator.id = assignment.assigned_by
              WHERE assignment.issue_id = i.id
              ORDER BY assignment.completed_at IS NULL DESC, assignment.assigned_at DESC, assignment.id DESC
              LIMIT 1) AS assigned_by_name,
            (SELECT assignment.completed_at
               FROM assignments assignment
              WHERE assignment.issue_id = i.id
              ORDER BY assignment.completed_at IS NULL DESC, assignment.assigned_at DESC, assignment.id DESC
              LIMIT 1) AS assignment_completed_at,
            (SELECT assignment.citizen_verified_at
               FROM assignments assignment
              WHERE assignment.issue_id = i.id
              ORDER BY assignment.completed_at IS NULL DESC, assignment.assigned_at DESC, assignment.id DESC
              LIMIT 1) AS citizen_verified_at,
            (SELECT COUNT(*) FROM issue_images ii WHERE ii.issue_id = i.id) AS image_count,
            (SELECT COUNT(*) FROM issue_reports ir WHERE ir.issue_id = i.id) AS report_count,
            (SELECT COUNT(*) FROM status_history sh WHERE sh.issue_id = i.id) AS update_count,
            (SELECT ii.file_path FROM issue_images ii
              WHERE ii.issue_id = i.id ORDER BY ii.id ASC LIMIT 1) AS cover_path,
            (SELECT COUNT(*) FROM upvotes uv
              WHERE uv.issue_id = i.id AND uv.user_id = :viewer_id) AS viewer_upvoted
       FROM issues i
       LEFT JOIN users u ON u.id = i.user_id
      WHERE {$whereSql}
      ORDER BY {$orderBy}
      LIMIT :limit OFFSET :offset"
  );
  $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_INT);
  foreach ($bindings as $key => $value) {
    $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();

  $items = array_map(static function (array $issue): array {
    $issue['id'] = (int) $issue['id'];
    $issue['severity'] = (int) $issue['severity'];
    $issue['upvote_count'] = (int) $issue['upvote_count'];
    $issue['image_count'] = (int) $issue['image_count'];
    $issue['report_count'] = (int) $issue['report_count'];
    $issue['update_count'] = (int) $issue['update_count'];
    $issue['viewer_upvoted'] = (bool) $issue['viewer_upvoted'];
    $issue['is_recurring'] = (bool) $issue['is_recurring'];
    $issue['citizen_verified'] = !empty($issue['citizen_verified_at']);
    $issue['cover_url'] = issueImageUrl($issue['cover_path'] ?? null);
    return $issue;
  }, $stmt->fetchAll(PDO::FETCH_ASSOC));

  return [
    'items' => $items,
    'pagination' => [
      'page' => $page,
      'limit' => $limit,
      'total' => $total,
      'pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
    ],
  ];
}

function fetchCitizenDashboardData(PDO $db, int $userId): array
{
  $stats = [
    'total_reports' => 0,
    'resolved' => 0,
    'active' => 0,
    'upvotes' => 0,
  ];

  $statsStmt = $db->prepare(
    "SELECT
        (SELECT COUNT(*) FROM issue_reports WHERE reporter_id = ?) AS total_reports,
        (SELECT COUNT(DISTINCT i.id)
           FROM issues i INNER JOIN issue_reports ir ON ir.issue_id = i.id
          WHERE ir.reporter_id = ? AND i.status = 'resolved') AS resolved,
        (SELECT COUNT(DISTINCT i.id)
           FROM issues i INNER JOIN issue_reports ir ON ir.issue_id = i.id
          WHERE ir.reporter_id = ? AND i.status IN ('pending', 'acknowledged', 'in_progress')) AS active,
        (SELECT COALESCE(SUM(i.upvote_count), 0)
           FROM issues i
          WHERE EXISTS (SELECT 1 FROM issue_reports ir WHERE ir.issue_id = i.id AND ir.reporter_id = ?)) AS upvotes"
  );
  $statsStmt->execute([$userId, $userId, $userId, $userId]);
  $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $stats['total_reports'] = (int) ($statsRow['total_reports'] ?? 0);
  $stats['resolved'] = (int) ($statsRow['resolved'] ?? 0);
  $stats['active'] = (int) ($statsRow['active'] ?? 0);
  $stats['upvotes'] = (int) ($statsRow['upvotes'] ?? 0);

  $activityStmt = $db->prepare(
    "SELECT i.id, i.title, i.category, i.department, i.status, i.address, i.upvote_count,
             i.created_at, i.updated_at,
             (SELECT worker.name
                FROM assignments assignment
                INNER JOIN users worker ON worker.id = assignment.worker_id
               WHERE assignment.issue_id = i.id
               ORDER BY assignment.completed_at IS NULL DESC, assignment.assigned_at DESC, assignment.id DESC
               LIMIT 1) AS assigned_worker_name,
             (SELECT administrator.name
                FROM assignments assignment
                LEFT JOIN users administrator ON administrator.id = assignment.assigned_by
               WHERE assignment.issue_id = i.id
               ORDER BY assignment.completed_at IS NULL DESC, assignment.assigned_at DESC, assignment.id DESC
               LIMIT 1) AS assigned_by_name,
             (SELECT assignment.assigned_at
                FROM assignments assignment
               WHERE assignment.issue_id = i.id
               ORDER BY assignment.completed_at IS NULL DESC, assignment.assigned_at DESC, assignment.id DESC
               LIMIT 1) AS assigned_at,
             (SELECT assignment.completed_at
                FROM assignments assignment
               WHERE assignment.issue_id = i.id
               ORDER BY assignment.completed_at IS NULL DESC, assignment.assigned_at DESC, assignment.id DESC
               LIMIT 1) AS assignment_completed_at,
             (SELECT assignment.citizen_verified_at
                FROM assignments assignment
               WHERE assignment.issue_id = i.id
               ORDER BY assignment.completed_at IS NULL DESC, assignment.assigned_at DESC, assignment.id DESC
               LIMIT 1) AS citizen_verified_at,
             MAX(ir.created_at) AS last_reported_at,
             COUNT(ir.id) AS citizen_report_count,
             (SELECT COUNT(*) FROM issue_images ii WHERE ii.issue_id = i.id) AS image_count,
             (SELECT ii.file_path FROM issue_images ii
               WHERE ii.issue_id = i.id ORDER BY ii.id ASC LIMIT 1) AS cover_path,
             (SELECT COUNT(*) FROM status_history sh WHERE sh.issue_id = i.id) AS update_count
       FROM issues i
       INNER JOIN issue_reports ir ON ir.issue_id = i.id AND ir.reporter_id = ?
      GROUP BY i.id, i.title, i.category, i.department, i.status, i.address, i.upvote_count, i.created_at, i.updated_at
      ORDER BY last_reported_at DESC, i.id DESC
      LIMIT 6"
  );
  $activityStmt->execute([$userId]);
  $activity = array_map(static function (array $row): array {
    $row['id'] = (int) $row['id'];
    $row['upvote_count'] = (int) $row['upvote_count'];
    $row['citizen_report_count'] = (int) $row['citizen_report_count'];
    $row['image_count'] = (int) $row['image_count'];
    $row['update_count'] = (int) $row['update_count'];
    $row['citizen_verified'] = !empty($row['citizen_verified_at']);
    $row['cover_url'] = issueImageUrl($row['cover_path'] ?? null);
    return $row;
  }, $activityStmt->fetchAll(PDO::FETCH_ASSOC));

  $reportTotal = max(0, $stats['total_reports']);
  $rankStmt = $db->prepare(
    "SELECT COUNT(*) + 1
       FROM (
         SELECT reporter_id
           FROM issue_reports
          GROUP BY reporter_id
         HAVING COUNT(*) > ?
       ) ranked"
  );
  $rankStmt->execute([$reportTotal]);
  $rank = max(1, (int) $rankStmt->fetchColumn());

  return [
    'stats' => $stats,
    'rank' => $rank,
    'activity' => $activity,
  ];
}
