<?php
declare(strict_types=1);

function analyticsPageEndpoints(string $apiUrl, string $dashboardUrl, string $logoutUrl): array
{
  return ['analytics' => $apiUrl . 'stats/analytics.php', 'export' => $apiUrl . 'stats/export.php', 'dashboard' => $dashboardUrl, 'logout' => $logoutUrl];
}
