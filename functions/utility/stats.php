<?php
function getIssueStats(?PDO $db)
{
  if (!$db) {
    return ['total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0, 'reported' => 0, 'satisfaction' => 0];
  }

  try {
    $stmt = $db->query("SELECT COUNT(*) as total FROM issues");
    $total = $stmt->fetch()['total'];
    $stmt = $db->query("SELECT COUNT(*) as open FROM issues WHERE status IN ('pending', 'acknowledged', 'in_progress')");
    $open = $stmt->fetch()['open'];
    $stmt = $db->query("SELECT COUNT(*) as in_progress FROM issues WHERE status = 'in_progress'");
    $inProgress = $stmt->fetch()['in_progress'];
    $stmt = $db->query("SELECT COUNT(*) as resolved FROM issues WHERE status = 'resolved'");
    $resolved = $stmt->fetch()['resolved'];
    $stmt = $db->query("SELECT COUNT(*) as reported FROM issues WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $reported = $stmt->fetch()['reported'];

    return [
      'total' => $total ?? 0,
      'open' => $open ?? 0,
      'in_progress' => $inProgress ?? 0,
      'resolved' => $resolved ?? 0,
      'reported' => $reported ?? 0,
      'satisfaction' => 0
    ];
  } catch (PDOException $e) {
    return ['total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0, 'reported' => 0, 'satisfaction' => 0];
  }
}

function getUserStats(?PDO $db, int $userId)
{
  if (!$db) {
    return ['total' => 0, 'resolved' => 0, 'pending' => 0, 'upvotes' => 0];
  }

  try {
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM issues WHERE user_id = ?");
    $stmt->execute([$userId]);
    $total = $stmt->fetch()['total'];
    $stmt = $db->prepare("SELECT COUNT(*) as resolved FROM issues WHERE user_id = ? AND status = 'resolved'");
    $stmt->execute([$userId]);
    $resolved = $stmt->fetch()['resolved'];
    $stmt = $db->prepare("SELECT COUNT(*) as pending FROM issues WHERE user_id = ? AND status IN ('pending', 'acknowledged', 'in_progress')");
    $stmt->execute([$userId]);
    $pending = $stmt->fetch()['pending'];
    $stmt = $db->prepare("SELECT COALESCE(SUM(upvote_count), 0) as upvotes FROM issues WHERE user_id = ?");
    $stmt->execute([$userId]);
    $upvotes = $stmt->fetch()['upvotes'];

    return ['total' => $total ?? 0, 'resolved' => $resolved ?? 0, 'pending' => $pending ?? 0, 'upvotes' => $upvotes ?? 0];
  } catch (PDOException $e) {
    return ['total' => 0, 'resolved' => 0, 'pending' => 0, 'upvotes' => 0];
  }
}

function formatNumber(int $num): string
{
  if ($num >= 1000000) return number_format($num / 1000000, 1) . 'M';
  if ($num >= 1000) return number_format($num / 1000, 1) . 'K';
  return (string) $num;
}
