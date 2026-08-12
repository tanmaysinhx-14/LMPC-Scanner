<?php
  function connectDatabase(): PDO|null {
    // Use environment variables outside local development; do not commit
    // production credentials to the repository.
    $host     = 'civic-connect.c5w64g80ekuc.ap-south-1.rds.amazonaws.com'; 
    $port     = 3306; 
    $dbname   = 'civicconnect'; 
    $username = 'admin';
    $password = '0hmeiS1GSX4ZPtNWvsSV';

    try {
      $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
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