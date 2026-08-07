<?php
  function connectDatabase(): PDO|null
  {
    // Use environment variables outside local development; do not commit
    // production credentials to the repository.
    $host = getenv('CIVIC_DB_HOST') ?: '127.0.0.1';
    $dbname = getenv('CIVIC_DB_NAME') ?: 'civicconnect';
    $username = getenv('CIVIC_DB_USER') ?: 'root';
    $password = getenv('CIVIC_DB_PASSWORD') ?: '';

    try {
      $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]);
      return $pdo;
    } 
    catch (PDOException $e) {
      return null;
    }
  }
