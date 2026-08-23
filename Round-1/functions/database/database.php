<?php
  function connectDatabase(): PDO|null {
    // Environment variables are the deployment contract. The ignored local
    // file keeps the existing LAN demo working without committing credentials.
    $local = __DIR__ . '/database.local.php';
    $localConfig = is_file($local) ? require $local : [];
    $localConfig = is_array($localConfig) ? $localConfig : [];
    $read = static fn (string $key, mixed $fallback = null): mixed => getenv($key) !== false
      ? getenv($key)
      : ($localConfig[$key] ?? $fallback);

    $host     = (string) $read('CIVICCONNECT_DB_HOST', '127.0.0.1');
    $port     = (int) $read('CIVICCONNECT_DB_PORT', 3306);
    $dbname   = (string) $read('CIVICCONNECT_DB_NAME', 'civicconnect');
    $username = (string) $read('CIVICCONNECT_DB_USER', '');
    $password = (string) $read('CIVICCONNECT_DB_PASSWORD', '');

    if ($username === '' || $password === '') return null;

    try {
      $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]);
      return $pdo;
    }

    catch (PDOException) {
      return null;
    }
  }
