<?php
declare(strict_types=1);

function getAdminCount(PDO $db): int
{
  return (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

function checkUserRecord(PDO $db, string $email): bool
{
  $statement = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
  $statement->execute(['email' => $email]);
  return (bool) $statement->fetchColumn();
}

function insertAccountDetails(PDO $db, array $account): bool
{
  $statement = $db->prepare(
    'INSERT INTO users (name, email, password_hash, role, department, ward_id, city, phone)
     VALUES (:name, :email, :password_hash, :role, :department, :ward_id, :city, :phone)'
  );
  return $statement->execute([
    'name' => $account['name'],
    'email' => $account['email'],
    'password_hash' => $account['password_hash'],
    'role' => $account['role'],
    'department' => $account['department'],
    'ward_id' => $account['ward_id'],
    'city' => $account['city'],
    'phone' => $account['phone'],
  ]);
}

function registerAccount(PDO $db, array $post, ?array $viewer = null): array
{
  $name = sanitizeInput($post['fullName'] ?? '');
  $email = strtolower(trim((string) ($post['email'] ?? '')));
  $phone = sanitizeInput($post['phone'] ?? '');
  $city = sanitizeInput($post['city'] ?? '');
  $wardId = !empty($post['ward_id']) ? (int) $post['ward_id'] : null;
  $password = (string) ($post['password'] ?? '');
  $confirmPassword = (string) ($post['confirmPassword'] ?? '');
  $role = strtolower(trim((string) ($post['userRole'] ?? '')));
  $department = $role === 'worker' ? normalizedDepartment($post['department'] ?? null) : null;
  $staffCode = trim((string) ($post['staffCode'] ?? ''));
  $adminCount = getAdminCount($db);
  $viewerRole = (string) ($viewer['role'] ?? '');
  $registrationKey = trim((string) (getenv('CIVIC_STAFF_REGISTRATION_KEY') ?: ''));
  $staffRole = in_array($role, ['worker', 'admin'], true);
  $staffAllowed = $viewerRole === 'admin'
    || ($role === 'admin' && $adminCount === 0)
    || ($registrationKey !== '' && $staffCode !== '' && hash_equals($registrationKey, $staffCode));

  if (!isset($post['termsAccepted'])) return ['ok' => false, 'message' => 'Agree to Terms and Conditions.'];
  if ($name === '' || !validateEmail($email) || !validatePassword($password)) return ['ok' => false, 'message' => 'Enter a valid name, email, and strong password.'];
  if (!in_array($role, ['citizen', 'worker', 'admin'], true)) return ['ok' => false, 'message' => 'Choose a valid CivicConnect role.'];
  if ($staffRole && !$staffAllowed) return ['ok' => false, 'message' => 'Worker and admin accounts can only be created by an admin, the first platform admin, or a valid staff registration key.'];
  if ($password !== $confirmPassword) return ['ok' => false, 'message' => 'Passwords entered do not match.'];
  if (checkUserRecord($db, $email)) return ['ok' => false, 'message' => 'Account exists with this email.'];

  try {
    $inserted = insertAccountDetails($db, [
      'name' => $name,
      'email' => $email,
      'password_hash' => password_hash($password, PASSWORD_DEFAULT),
      'role' => $role,
      'department' => $department,
      'ward_id' => $wardId,
      'city' => $city,
      'phone' => $phone,
    ]);
    return $inserted
      ? ['ok' => true, 'message' => ucfirst($role) . ' account created successfully. You can sign in now.']
      : ['ok' => false, 'message' => 'Database error occurred. Please try again later.'];
  } catch (PDOException $exception) {
    error_log('Registration insert failed: ' . $exception->getMessage());
    return ['ok' => false, 'message' => 'Database error occurred. Please try again later.'];
  }
}
