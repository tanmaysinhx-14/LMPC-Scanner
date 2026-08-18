<?php
declare(strict_types=1);

function profileLoadAccount(PDO $db, int $userId, array $fallback = []): array
{
  $statement = $db->prepare('SELECT id, name, email, role, ward_id, city, phone FROM users WHERE id = ? LIMIT 1');
  $statement->execute([$userId]);
  return array_merge($fallback, $statement->fetch(PDO::FETCH_ASSOC) ?: []);
}

function profileUpdateAccount(PDO $db, int $userId, array $post): array
{
  $name = trim((string) ($post['name'] ?? ''));
  $city = trim((string) ($post['city'] ?? ''));
  $phone = trim((string) ($post['phone'] ?? ''));
  if ($name === '' || strlen($name) > 120) return ['ok' => false, 'message' => 'Please enter a name between 1 and 120 characters.'];

  $statement = $db->prepare('UPDATE users SET name = ?, city = ?, phone = ? WHERE id = ?');
  $statement->execute([substr($name, 0, 120), substr($city, 0, 120), substr($phone, 0, 30), $userId]);
  return [
    'ok' => true,
    'message' => 'Profile updated successfully.',
    'name' => substr($name, 0, 120),
    'city' => substr($city, 0, 120),
    'phone' => substr($phone, 0, 30),
  ];
}
