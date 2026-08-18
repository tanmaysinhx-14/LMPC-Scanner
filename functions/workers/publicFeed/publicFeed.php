<?php
declare(strict_types=1);

function fetchPublicFeedPageData(?PDO $db, ?array $viewer, array $filters): array
{
  $page = max(1, (int) ($filters['page'] ?? 1));
  $data = ['feed' => ['items' => [], 'pagination' => ['page' => $page, 'limit' => 20, 'total' => 0, 'pages' => 0]], 'cityStats' => ['total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0]];
  if (!$db instanceof PDO) return $data;
  try {
    $data['feed'] = fetchIssueFeed($db, [
      'page' => $page, 'limit' => 20, 'sort' => $filters['sort'] ?? 'hot',
      'category' => $filters['category'] ?? '', 'status' => $filters['status'] ?? '',
      'query' => $filters['query'] ?? '', 'viewer_id' => $viewer['id'] ?? 0,
    ]);
    $data['cityStats'] = getIssueStats($db);
  } catch (Throwable $exception) {
    error_log('Public feed page data failed: ' . $exception->getMessage());
  }
  return $data;
}
