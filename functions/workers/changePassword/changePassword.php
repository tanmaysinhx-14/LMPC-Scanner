<?php
declare(strict_types=1);

function changePasswordRecord(PDO $db, int $userId, string $current, string $new, string $confirm): array
{
  $statement = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
  $statement->execute([$userId]);
  $hash = (string) $statement->fetchColumn();
  if (!password_verify($current, $hash)) return ['ok' => false, 'message' => 'Your current password is incorrect.'];
  if (!validatePassword($new)) return ['ok' => false, 'message' => 'Use at least 8 characters, one number, and one symbol.'];
  if (!hash_equals($new, $confirm)) return ['ok' => false, 'message' => 'The new passwords do not match.'];
  $updated = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
  return $updated
    ? ['ok' => true, 'message' => 'Password changed successfully.']
    : ['ok' => false, 'message' => 'The password could not be updated.'];
}
