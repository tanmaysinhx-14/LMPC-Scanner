<?php
declare(strict_types=1);

function fetchHeatmapPageData(?PDO $db, string $apiUrl): array
{
  return ['endpoint' => $apiUrl, 'cityStats' => getIssueStats($db)];
}
