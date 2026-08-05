<?php
  function redirect(string $url, int $delaySeconds = 0): void
  {
    $safeUrl = filter_var($url, FILTER_SANITIZE_URL);
    $delayMs = $delaySeconds * 1000;

    echo '<script type="text/javascript">';
    echo 'setTimeout(function() { window.location.href = ' . json_encode($safeUrl) . '; }, ' . $delayMs . ');';
    echo '</script>';
  }
