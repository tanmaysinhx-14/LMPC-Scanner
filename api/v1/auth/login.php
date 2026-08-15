<?php
declare(strict_types=1);

require __DIR__ . '/../../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  jsonResponse(405, 'POST is required.');
}
if (!($db instanceof PDO)) {
  jsonResponse(503, 'The database is temporarily unavailable.');
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : $_POST;
$email = strtolower(trim((string) ($body['email'] ?? '')));
$password = (string) ($body['password'] ?? '');
$requestedRole = strtolower(trim((string) ($body['role'] ?? 'citizen')));

if ($email === '' || $password === '' || !in_array($requestedRole, ['citizen', 'worker', 'admin'], true)) {
  jsonResponse(422, 'Email, password, and a valid role are required.');
}
if (isLoginRateLimited($db, $email)) {
  jsonResponse(429, 'Too many sign-in attempts. Please try again later.');
}

$stmt = $db->prepare('SELECT id, name, email, password_hash, role, ward_id, city, is_active FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, (string) $user['password_hash']) || $requestedRole !== $user['role']) {
  recordLoginFailure($db, $email);
  jsonResponse(401, 'Invalid email, password, or account role.');
}

clearLoginFailures($db, $email);
try {
  $token = createMobileSession($db, $user);
} catch (Throwable $exception) {
  error_log('Mobile token creation failed: ' . $exception->getMessage());
  jsonResponse(503, 'Native sign-in is not ready on this server. Apply the mobile access-token migration first.');
}

jsonResponse(200, 'Mobile login successful.', [
  ...$token,
  'user' => [
    'id' => (int) $user['id'],
    'name' => (string) $user['name'],
    'email' => (string) $user['email'],
    'role' => (string) $user['role'],
    'ward_id' => $user['ward_id'] === null ? null : (int) $user['ward_id'],
    'city' => $user['city'],
  ],
]);
