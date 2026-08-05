<?php 
  function connectDatabase() {
    $dhost = 'localhost';
    $dbname = 'civicconnect';
    $hname = 'root';
    $password = '';
    $timeout = 5; // Connection timeout in seconds

    try {
      $pdo = new PDO("mysql:host=$dhost;dbname=$dbname", $hname, $password, [PDO::ATTR_TIMEOUT => $timeout]);
      $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); // Enable persistent connection
      return $pdo;
    } 
    catch (PDOException $e) {
      echo "Connection failed: " . $e->getMessage();
    }
  }
?>