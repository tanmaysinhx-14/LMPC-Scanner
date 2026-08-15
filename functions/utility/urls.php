<?php
declare(strict_types=1);

/**
 * Central browser route map.
 *
 * Maintained feature pages live below /pages/<route>/index.php, so pages no
 * longer need to guess how many ../ segments are required for a link.
 */
function civicApplicationBaseUrl(): string
{
  $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
  foreach (['/pages/', '/api/', '/assets/'] as $marker) {
    $position = strpos($script, $marker);
    if ($position !== false) {
      return rtrim(substr($script, 0, $position), '/');
    }
  }

  $directory = str_replace('\\', '/', dirname($script));
  return $directory === '/' || $directory === '.' ? '' : rtrim($directory, '/');
}

function civicUrl(string $path = '', array $query = []): string
{
  $url = civicApplicationBaseUrl() . '/' . ltrim($path, '/');
  if ($query !== []) {
    $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }
  return $url === '/' ? '/' : $url;
}

function civicRouteMap(): array
{
  return [
    'home' => '',
    'dashboard' => 'pages/dashboard/',
    'login' => 'pages/login/',
    'logout' => 'pages/logout/',
    'register' => 'pages/register/',
    'report' => 'pages/report/',
    'feed' => 'pages/publicFeed/',
    'pulse' => 'pages/heatmap/',
    'profile' => 'pages/profile/',
    'change_password' => 'pages/changePassword/',
    'work_management' => 'pages/workManagement/',
    'analytics' => 'pages/analytics/',
    'assignments' => 'pages/assignments/',
    'issue_detail' => 'pages/issueDetail/',
  ];
}

function civicRoute(string $name, array $query = []): string
{
  $routes = civicRouteMap();
  return civicUrl($routes[$name] ?? $name, $query);
}

function civicApi(string $path, array $query = []): string
{
  return civicUrl('api/' . ltrim($path, '/'), $query);
}

function civicAsset(string $path): string
{
  return civicUrl('assets/' . ltrim($path, '/'));
}

function civicDashboardForRole(?string $role = null): string
{
  return civicRoute('dashboard');
}
