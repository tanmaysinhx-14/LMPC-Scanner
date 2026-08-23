<?php
declare(strict_types=1);

function issueDetailPageEndpoints(string $apiUrl): array
{
  return ['detail' => $apiUrl . 'issues/detail.php', 'upvote' => $apiUrl . 'issues/upvote.php'];
}
