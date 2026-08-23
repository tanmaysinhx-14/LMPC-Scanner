<?php
declare(strict_types=1);

function reportPageEndpoints(string $apiUrl, string $assetUrl, string $dashboardUrl): array
{
  return ['analyze' => $apiUrl . 'issues/analyze.php', 'submit' => $apiUrl . 'issues/submit.php', 'serviceWorker' => $assetUrl . 'js/sw.js', 'dashboard' => $dashboardUrl];
}
