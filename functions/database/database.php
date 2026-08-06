<?php
  function connectDatabase(): PDO|null
  {
    // Replace with your actual database credentials
    $host = 'localhost';
    $dbname = 'civicconnect';
    $username = 'root';
    $password = '';

    try {
      $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
      $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
      return $pdo;
    } 
    catch (PDOException $e) {
      return null;
    }
  }
