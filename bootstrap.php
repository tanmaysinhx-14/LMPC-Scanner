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
  $db = connectDatabase();
?>