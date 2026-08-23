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

function civicPriorityConfig(): array
{
  return [
    'same_location_radius_m' => 150.0,
    'nearby_radius_m' => 1000.0,
    'lookback_days' => 30,
    'critical_threshold' => 75.0,
    'high_threshold' => 55.0,
    'medium_threshold' => 30.0,
  ];
}

function civicPriorityFormula(): string
{
  return 'severity x 10 + same-location reports x 14 + nearby same-category reports x 4 + nearby different-category reports x 1.5 + ln(upvotes + 1) x 2.5 + recency (0-10) + recurrence bonus; capped at 100';
}

function priorityBand(float $score): string
{
  $config = civicPriorityConfig();
  if ($score >= $config['critical_threshold']) return 'critical';
  if ($score >= $config['high_threshold']) return 'high';
  if ($score >= $config['medium_threshold']) return 'medium';
  return 'low';
}

function priorityReason(array $signals): string
{
  if ((int) ($signals['same_location_report_count'] ?? 0) > 0) return 'Same problem reported at this location';
  if ((int) ($signals['nearby_similar_reports'] ?? 0) > 0) return 'Same category is recurring nearby';
  if ((int) ($signals['nearby_different_reports'] ?? 0) > 0) return 'Different issues are clustering nearby';
  return 'Isolated report';
}

/**
 * Calculate transparent, database-backed community signals for one issue.
 * The current canonical issue is excluded from nearby counts so its own
 * report_count remains visible separately. Same-location duplicates inside
 * that canonical issue are still returned as a scoring signal.
 */
function fetchIssueCommunityStats(PDO $db, int $issueId, float $latitude, float $longitude, string $category, int $reportCount = 1): array
{
  $config = civicPriorityConfig();
  $category = strtolower(trim($category));
  $sameRadius = (float) $config['same_location_radius_m'];
  $nearbyRadius = (float) $config['nearby_radius_m'];
  $lookbackDays = (int) $config['lookback_days'];
  $distanceSql = '(6371000 * 2 * ASIN(SQRT(POWER(SIN(RADIANS(ir2.lat - :latitude) / 2), 2) + COS(RADIANS(:latitude_cos)) * COS(RADIANS(ir2.lat)) * POWER(SIN(RADIANS(ir2.lng - :longitude) / 2), 2))))';

  $related = $db->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN related.category = :same_category_a AND related.distance_m <= :same_radius_a THEN 1 ELSE 0 END), 0) AS same_location_other_reports,
        COALESCE(SUM(CASE WHEN related.category = :same_category_b AND related.distance_m <= :nearby_radius_a THEN 1 ELSE 0 END), 0) AS nearby_similar_reports,
        COALESCE(SUM(CASE WHEN related.category <> :same_category_c AND related.distance_m <= :nearby_radius_b THEN 1 ELSE 0 END), 0) AS nearby_different_reports,
        COUNT(DISTINCT CASE WHEN related.category = :same_category_d AND related.distance_m <= :nearby_radius_c THEN related.issue_id END) AS nearby_similar_issues,
        COUNT(DISTINCT CASE WHEN related.category <> :same_category_e AND related.distance_m <= :nearby_radius_d THEN related.issue_id END) AS nearby_different_issues
       FROM (
         SELECT i2.id AS issue_id, i2.category, {$distanceSql} AS distance_m
           FROM issue_reports ir2
           INNER JOIN issues i2 ON i2.id = ir2.issue_id
          WHERE i2.status <> 'rejected'
            AND i2.id <> :current_issue_id
            AND ir2.created_at >= DATE_SUB(NOW(), INTERVAL {$lookbackDays} DAY)
       ) AS related"
  );
  $related->execute([
    'latitude' => $latitude,
    'latitude_cos' => $latitude,
    'longitude' => $longitude,
    'current_issue_id' => $issueId,
    'same_category_a' => $category,
    'same_category_b' => $category,
    'same_category_c' => $category,
    'same_category_d' => $category,
    'same_category_e' => $category,
    'same_radius_a' => $sameRadius,
    'nearby_radius_a' => $nearbyRadius,
    'nearby_radius_b' => $nearbyRadius,
    'nearby_radius_c' => $nearbyRadius,
    'nearby_radius_d' => $nearbyRadius,
  ]);
  $relatedRow = $related->fetch(PDO::FETCH_ASSOC) ?: [];

  $city = $db->prepare(
    "SELECT COUNT(ir.id) AS city_similar_reports, COUNT(DISTINCT i.id) AS city_similar_issues
       FROM issue_reports ir
       INNER JOIN issues i ON i.id = ir.issue_id
      WHERE i.status <> 'rejected'
        AND i.category = ?
        AND ir.created_at >= DATE_SUB(NOW(), INTERVAL {$lookbackDays} DAY)"
  );
  $city->execute([$category]);
  $cityRow = $city->fetch(PDO::FETCH_ASSOC) ?: [];

  $sameLocationReports = max(0, $reportCount - 1) + (int) ($relatedRow['same_location_other_reports'] ?? 0);
  return [
    'same_location_report_count' => $sameLocationReports,
    'nearby_similar_reports' => (int) ($relatedRow['nearby_similar_reports'] ?? 0),
    'nearby_different_reports' => (int) ($relatedRow['nearby_different_reports'] ?? 0),
    'nearby_similar_issues' => (int) ($relatedRow['nearby_similar_issues'] ?? 0),
    'nearby_different_issues' => (int) ($relatedRow['nearby_different_issues'] ?? 0),
    'city_similar_reports' => (int) ($cityRow['city_similar_reports'] ?? 0),
    'city_similar_issues' => (int) ($cityRow['city_similar_issues'] ?? 0),
    'same_location_radius_m' => $sameRadius,
    'nearby_radius_m' => $nearbyRadius,
  ];
}

function calculatePriorityAssessment(
  PDO $db,
  int $issueId,
  float $latitude,
  float $longitude,
  string $category,
  int $severity,
  int $reportCount,
  int $upvoteCount,
  ?string $createdAt = null,
  bool $recurring = false
): array {
  $signals = fetchIssueCommunityStats($db, $issueId, $latitude, $longitude, $category, $reportCount);
  $createdTimestamp = $createdAt ? strtotime($createdAt) : time();
  $ageHours = max(0.0, (time() - ($createdTimestamp ?: time())) / 3600);
  $recency = 10.0 * exp(-$ageHours / (24.0 * 30.0));
  $sameLocation = min(35.0, (int) $signals['same_location_report_count'] * 14.0);
  $nearbySimilar = min(18.0, (int) $signals['nearby_similar_reports'] * 4.0);
  $nearbyDifferent = min(8.0, (int) $signals['nearby_different_reports'] * 1.5);
  $upvotes = min(8.0, log($upvoteCount + 1.0) * 2.5);
  $recurrenceBonus = $recurring ? 5.0 : 0.0;
  $score = min(100.0, max(0.0, ($severity * 10.0) + $sameLocation + $nearbySimilar + $nearbyDifferent + $upvotes + $recency + $recurrenceBonus));
  $band = priorityBand($score);
  $reason = priorityReason($signals);
  $assessment = array_merge($signals, [
    'score' => round($score, 4),
    'band' => $band,
    'reason' => $reason,
    'priority_band' => $band,
    'priority_reason' => $reason,
    'severity_factor' => round($severity * 10.0, 2),
    'same_location_factor' => round($sameLocation, 2),
    'nearby_similar_factor' => round($nearbySimilar, 2),
    'nearby_different_factor' => round($nearbyDifferent, 2),
    'upvote_factor' => round($upvotes, 2),
    'recency_factor' => round($recency, 2),
    'recurrence_bonus' => round($recurrenceBonus, 2),
  ]);
  return $assessment;
}

function recalculateStoredIssuePriority(PDO $db, int $issueId): array
{
  $issueStmt = $db->prepare('SELECT id, lat, lng, category, severity, upvote_count, created_at, is_recurring FROM issues WHERE id = ? FOR UPDATE');
  $issueStmt->execute([$issueId]);
  $issue = $issueStmt->fetch(PDO::FETCH_ASSOC);
  if (!$issue) throw new RuntimeException('Issue not found while recalculating priority.');
  $reportStmt = $db->prepare('SELECT COUNT(*) FROM issue_reports WHERE issue_id = ?');
  $reportStmt->execute([$issueId]);
  $assessment = calculatePriorityAssessment(
    $db,
    $issueId,
    (float) $issue['lat'],
    (float) $issue['lng'],
    (string) $issue['category'],
    (int) $issue['severity'],
    max(1, (int) $reportStmt->fetchColumn()),
    (int) $issue['upvote_count'],
    (string) $issue['created_at'],
    !empty($issue['is_recurring'])
  );
  $db->prepare('UPDATE issues SET priority_score = ?, updated_at = updated_at WHERE id = ?')->execute([$assessment['score'], $issueId]);
  return $assessment;
}

function issueImageUrl(?string $path): string
{
  if (!$path) {
    return '';
  }

  return '/' . ltrim(str_replace('\\', '/', $path), '/');
}

function enrichIssueWithCommunitySignals(PDO $db, array $issue): array
{
  $assessment = calculatePriorityAssessment(
    $db,
    (int) ($issue['id'] ?? 0),
    (float) ($issue['lat'] ?? 0),
    (float) ($issue['lng'] ?? 0),
    (string) ($issue['category'] ?? 'other'),
    (int) ($issue['severity'] ?? 1),
    max(1, (int) ($issue['report_count'] ?? 1)),
    (int) ($issue['upvote_count'] ?? 0),
    (string) ($issue['created_at'] ?? ''),
    !empty($issue['is_recurring'])
  );
  $storedScore = (float) ($issue['priority_score'] ?? 0);
  // Repair legacy stored scores the first time they are read, but do not
  // write on every five-second mobile refresh for tiny recency changes.
  if ((int) ($issue['id'] ?? 0) > 0 && abs($storedScore - (float) $assessment['score']) > 0.25) {
    $db->prepare('UPDATE issues SET priority_score = ? WHERE id = ?')->execute([$assessment['score'], (int) $issue['id']]);
  }
  $issue = array_merge($issue, $assessment);
  $issue['priority_score'] = (float) $assessment['score'];
  return $issue;
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
  // The hot score is recalculated from live report/upvote/location evidence
  // below, so SQL pagination based on the stored score can return the wrong
  // page before enrichment. Fetch the complete filtered set for this one
  // sort, then paginate after the live scores have been calculated.
  $prioritySort = $sort === 'hot';
  $orderBy = match ($sort) {
    'new' => 'i.created_at DESC, i.id DESC',
    'top' => 'i.upvote_count DESC, i.created_at DESC',
    'rising' => 'i.updated_at DESC, i.upvote_count DESC',
    default => 'i.priority_score DESC, i.created_at DESC, i.id DESC',
  };
  $paginationSql = $prioritySort ? '' : 'LIMIT :limit OFFSET :offset';

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
      {$paginationSql}"
  );
  $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_INT);
  foreach ($bindings as $key => $value) {
    $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  if (!$prioritySort) {
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  }
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
  $items = array_map(static fn (array $issue): array => enrichIssueWithCommunitySignals($db, $issue), $items);

  if ($prioritySort) {
    usort($items, static function (array $left, array $right): int {
      $scoreComparison = (float) ($right['priority_score'] ?? 0) <=> (float) ($left['priority_score'] ?? 0);
      if ($scoreComparison !== 0) return $scoreComparison;

      foreach (['same_location_report_count', 'nearby_similar_reports', 'report_count', 'upvote_count', 'severity'] as $field) {
        $comparison = (int) ($right[$field] ?? 0) <=> (int) ($left[$field] ?? 0);
        if ($comparison !== 0) return $comparison;
      }

      $dateComparison = strtotime((string) ($right['created_at'] ?? '')) <=> strtotime((string) ($left['created_at'] ?? ''));
      return $dateComparison !== 0 ? $dateComparison : ((int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0));
    });
    $items = array_slice($items, $offset, $limit);
  }

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
    "SELECT i.id, i.title, i.category, i.department, i.status, i.address, i.lat, i.lng,
             i.upvote_count, i.priority_score, i.created_at, i.updated_at,
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
      GROUP BY i.id, i.title, i.category, i.department, i.status, i.address, i.lat, i.lng, i.upvote_count, i.priority_score, i.created_at, i.updated_at
      ORDER BY last_reported_at DESC, i.id DESC
      LIMIT 6"
  );
  $activityStmt->execute([$userId]);
  $activity = array_map(static function (array $row): array {
    $row['id'] = (int) $row['id'];
    $row['upvote_count'] = (int) $row['upvote_count'];
    $row['citizen_report_count'] = (int) $row['citizen_report_count'];
    $row['report_count'] = $row['citizen_report_count'];
    $row['image_count'] = (int) $row['image_count'];
    $row['update_count'] = (int) $row['update_count'];
    $row['citizen_verified'] = !empty($row['citizen_verified_at']);
    $row['cover_url'] = issueImageUrl($row['cover_path'] ?? null);
    return $row;
  }, $activityStmt->fetchAll(PDO::FETCH_ASSOC));
  $activity = array_map(static fn (array $issue): array => enrichIssueWithCommunitySignals($db, $issue), $activity);

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
