<?php
declare(strict_types=1);

function fetchLandingPageData(?PDO $db): array
{
  $stats = getIssueStats($db);
  $total = (int) ($stats['total'] ?? 0);
  $resolved = (int) ($stats['resolved'] ?? 0);
  return [
    'stats' => $stats,
    'total' => $total,
    'resolved' => $resolved,
    'resolutionRate' => $total > 0 ? (int) round(($resolved / $total) * 100) : 0,
  ];
}
