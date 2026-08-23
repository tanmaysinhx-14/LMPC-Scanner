<?php // Unified Configurator for Project
  if (!defined('CIVICCONNECT_ROOT')) {
    define('CIVICCONNECT_ROOT', __DIR__);
  }

  require_once __DIR__ . '/functions/database/database.php';

  require_once __DIR__ . '/functions/auth/session.php';
  require_once __DIR__ . '/functions/auth/mobile.php';

  require_once __DIR__ . '/functions/location/geohash.php';
  require_once __DIR__ . '/functions/location/geolocation.php';

  require_once __DIR__ . '/functions/utility/response.php';
  require_once __DIR__ . '/functions/utility/stats.php';
  require_once __DIR__ . '/functions/issues/issues.php';
  require_once __DIR__ . '/functions/utility/toast.php';
  require_once __DIR__ . '/functions/utility/utility.php';

  require_once __DIR__ . '/functions/validations/validations.php';

  // Module workers keep page files focused on request orchestration and views.
  foreach ([
    'home/home.php',
    'dashboard/dashboard.php',
    'assignments/assignments.php',
    'changePassword/changePassword.php',
    'login/login.php',
    'logout/logout.php',
    'profile/profile.php',
    'register/register.php',
    'analytics/analytics.php',
    'workManagement/workManagement.php',
    'heatmap/heatmap.php',
    'issueDetail/issueDetail.php',
    'publicFeed/publicFeed.php',
    'report/report.php',
  ] as $workerFile) {
    require_once __DIR__ . '/functions/workers/' . $workerFile;
  }

  function bootstrapWebRoot(): string {
    $projectRoot = realpath(CIVICCONNECT_ROOT);
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));

    if ($projectRoot !== false && $documentRoot !== false) {
      $projectPath = rtrim(str_replace('\\', '/', $projectRoot), '/');
      $documentPath = rtrim(str_replace('\\', '/', $documentRoot), '/');
      $projectPrefix = strtolower($documentPath) . '/';

      if (strtolower($projectPath) === strtolower($documentPath)) {
        return '';
      }

      if (str_starts_with(strtolower($projectPath) . '/', $projectPrefix)) {
        $relativePath = trim(substr($projectPath, strlen($documentPath)), '/');
        return $relativePath === '' ? '' : '/' . $relativePath;
      }
    }

    foreach ([
      (string) ($_SERVER['REQUEST_URI'] ?? ''),
      (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
      (string) ($_SERVER['PHP_SELF'] ?? ''),
    ] as $requestPath) {
      $requestPath = str_replace('\\', '/', (string) (parse_url($requestPath, PHP_URL_PATH) ?: $requestPath));
      foreach (['/pages/', '/api/', '/assets/'] as $routeMarker) {
        $markerPosition = strpos($requestPath, $routeMarker);
        if ($markerPosition !== false) {
          return rtrim(substr($requestPath, 0, $markerPosition), '/');
        }
      }
    }

    return '';
  }

  function bootstrapAccounts(array $options = []): array {
    $urlForRoot = bootstrapWebRoot();

    $urlForApi = $urlForRoot . '/api/';
    $urlForAssets = $urlForRoot . '/assets/';
    $urlForAnalytics = $urlForRoot . '/pages/analytics/';
    $urlForAssignments = $urlForRoot . '/pages/assignments/';
    $urlForChangePassword = $urlForRoot . '/pages/changePassword/';
    $urlForDashboard = $urlForRoot . '/pages/dashboard/';
    $urlForHeatmap = $urlForRoot . '/pages/heatmap/';
    $urlForIssueDetail = $urlForRoot . '/pages/issueDetail/';
    $urlForLogin = $urlForRoot . '/pages/login/';
    $urlForLogout = $urlForRoot . '/pages/logout/';
    $urlForProfile = $urlForRoot . '/pages/profile/';
    $urlForPublicFeed = $urlForRoot . '/pages/publicFeed/';
    $urlForRegister = $urlForRoot . '/pages/register/';
    $urlForReport = $urlForRoot . '/pages/report/';
    $urlForWorkManagement = $urlForRoot . '/pages/workManagement/';

    $db = connectDatabase();

    startAppSession();
    if ($db instanceof PDO) {
      restoreRememberedLogin($db);
      refreshSessionUser($db);
      authenticateMobileBearer($db);
    }

    $requiredRoles = $options['required_roles'] ?? [];

    $requiresLogin = ($options['require_login'] ?? false) || $requiredRoles !== [];

    if ($requiresLogin && !isLoggedIn()) {
      setToast(message: 'You are not logged in. Please log in to access this page.', type: 'danger');
      header('Location: ' . $urlForLogin, true, 303);
      exit;
    }

    if ($requiredRoles !== [] && isLoggedIn()
      && !in_array((string) ($_SESSION['user_role'] ?? ''), $requiredRoles, true)) {
      setToast(message: 'You do not have permission to access this page.', type: 'danger');
      header('Location: ' . $urlForLogin, true, 303);
      exit;
    }

    return [
      'db' => $db,
      'urlForRoot' => $urlForRoot,
      'urlForApi' => $urlForApi,
      'urlForAssets' => $urlForAssets,
      'urlForAnalytics' => $urlForAnalytics,
      'urlForAssignments' => $urlForAssignments,
      'urlForChangePassword' => $urlForChangePassword,
      'urlForDashboard' => $urlForDashboard,
      'urlForHeatmap' => $urlForHeatmap,
      'urlForIssueDetail' => $urlForIssueDetail,
      'urlForLogin' => $urlForLogin,
      'urlForLogout' => $urlForLogout,
      'urlForProfile' => $urlForProfile,
      'urlForPublicFeed' => $urlForPublicFeed,
      'urlForRegister' => $urlForRegister,
      'urlForReport' => $urlForReport,
      'urlForWorkManagement' => $urlForWorkManagement
    ];
  }
