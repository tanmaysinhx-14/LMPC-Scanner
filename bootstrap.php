<?php // Unified Configurator for Project
  require_once __DIR__ . '/functions/database/database.php';

  require_once __DIR__ . '/functions/auth/session.php';

  require_once __DIR__ . '/functions/location/geohash.php';
  require_once __DIR__ . '/functions/location/geolocation.php';

  require_once __DIR__ . '/functions/utility/response.php';
  require_once __DIR__ . '/functions/utility/stats.php';
  require_once __DIR__ . '/functions/issues/issues.php';
  require_once __DIR__ . '/functions/utility/toast.php';
  require_once __DIR__ . '/functions/utility/utility.php';

  require_once __DIR__ . '/functions/validations/validations.php';

  function bootstrapAccounts(array $options = []): array {
    $db = connectDatabase();

    startAppSession();
    if ($db instanceof PDO) {
      restoreRememberedLogin($db);
      refreshSessionUser($db);
    }

    $requiredRoles = $options['required_roles'] ?? [];

    $requiresLogin = ($options['require_login'] ?? false) || $requiredRoles !== [];

    if ($requiresLogin && !isLoggedIn()) {
      setToast(message: 'You are not logged in. Please log in to access this page.', type: 'danger');
      redirect('../login/', 0);
      exit;
    }

    if ($requiredRoles !== [] && isLoggedIn()
      && !in_array((string) ($_SESSION['user_role'] ?? ''), $requiredRoles, true)) {
      setToast(message: 'You do not have permission to access this page.', type: 'danger');
      redirect('../login/', 0);
      exit;
    }

    return [
      'db' => $db
    ];
  }
