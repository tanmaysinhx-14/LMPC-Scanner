<?php
declare(strict_types=1);

function loginFindAccount(PDO $db, string $email, string $role): ?array
{
  $statement = $db->prepare('SELECT id, name, email, password_hash, role, ward_id, city, is_active FROM users WHERE email = ? AND role = ? LIMIT 1');
  $statement->execute([$email, $role]);
  $account = $statement->fetch(PDO::FETCH_ASSOC);
  return $account ?: null;
}

function loginAuthenticate(PDO $db, string $email, string $password, string $role, bool $remember): array
{
  $account = loginFindAccount($db, $email, $role);
  if (!$account || (int) $account['is_active'] !== 1 || !password_verify($password, (string) $account['password_hash'])) {
    recordLoginFailure($db, $email);
    return ['ok' => false, 'message' => 'Invalid email, password, or account role.'];
  }

  clearLoginFailures($db, $email);
  if (password_needs_rehash((string) $account['password_hash'], PASSWORD_DEFAULT)) {
    $rehash = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int) $account['id']]);
  }
  loginUser($db, $account, $remember);
  return ['ok' => true, 'message' => 'Signed in successfully.', 'account' => $account];
}
