<?php // Unified Configurator for Project
  require_once __DIR__ . '/functions/database/database.php';

  require_once __DIR__ . '/functions/auth/session.php';

  require_once __DIR__ . '/functions/location/geohash.php';
  require_once __DIR__ . '/functions/location/geolocation.php';

  require_once __DIR__ . '/functions/utility/response.php';
  require_once __DIR__ . '/functions/utility/stats.php';
  require_once __DIR__ . '/functions/utility/toast.php';
  require_once __DIR__ . '/functions/utility/utility.php';

  require_once __DIR__ . '/functions/validations/validations.php';
?>

<?php 
  function bootstrapAccounts(array $options = []): array {
    $db = connectDatabase();

    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }

    $requiredRoles = $options['required_roles'] ?? [];

    $requiresLogin = ($options['require_login'] ?? false) || $requiredRoles !== [];

    if ($requiresLogin && (($_SESSION['logged_in'] ?? false) !== true)) {
      setToast(message: 'You are not logged in. Please log in to access this page.', type: 'danger');
      redirect('../login/', 0);
    }

    return [
      'db' => $db
    ];
  }
?>