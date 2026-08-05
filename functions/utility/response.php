<?php
  function jsonResponse(int $status, string $message, mixed $data = null): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode([
      'status'  => $status,
      'message' => $message,
      'data'    => $data,
      'timestamp' => time()
    ]);
    exit;
  }
