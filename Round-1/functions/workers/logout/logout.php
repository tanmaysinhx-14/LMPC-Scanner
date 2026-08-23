<?php
declare(strict_types=1);

function logoutCurrentAccount(?PDO $db): void
{
  logoutUser($db);
}
