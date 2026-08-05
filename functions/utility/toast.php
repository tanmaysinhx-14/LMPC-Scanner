<?php
session_start();
function setToast(string $message, string $type = 'info', int $duration = 4000): void
{
  $_SESSION['toast'] = compact('message', 'type', 'duration');
}
function consumeToast(): ?array
{
  $toast = $_SESSION['toast'] ?? null;
  unset($_SESSION['toast']);
  return $toast;
}
