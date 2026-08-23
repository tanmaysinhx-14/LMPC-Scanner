<?php 
  function sanitizeInput(mixed $data) {
      $data = trim($data);
      $data = stripslashes($data);
      $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
      return $data;
  }

  function validateEmail(string $email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
  }

  function validatePassword(string $password) {
    $hasMinLength = strlen($password) >= 8;
    $hasNumber = preg_match('/\d/', $password);
    $hasSymbol = preg_match('/[^A-Za-z0-9]/', $password);
    
    return $hasMinLength && $hasNumber && $hasSymbol;
  }
?>