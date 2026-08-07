<?php
declare(strict_types=1);

$issueId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$location = '../citizen/public-feed.php';
if ($issueId !== false && $issueId !== null && $issueId > 0) {
  $location .= '?issue=' . (int) $issueId;
}

header('Location: ' . $location, true, 303);
exit;
