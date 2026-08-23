<?php
  function setToast(string $message, string $type, int $duration = 7000): void {
    $toastMessage = $message;

    $_SESSION['toasts'][] = ['message' => $toastMessage, 'type' => $type, 'duration' => $duration,];
  }