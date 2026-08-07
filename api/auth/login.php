<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
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
$requestedRole = strtolower(trim((string) ($body['role'] ?? '')));
$rememberMe = filter_var($body['remember_me'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (isLoginRateLimited($db, $email)) {
  jsonResponse(429, 'Too many sign-in attempts. Please try again later.');
}

$stmt = $db->prepare('SELECT id, name, email, password_hash, role, ward_id, city, is_active FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, $user['password_hash'])
  || ($requestedRole !== '' && $requestedRole !== $user['role'])) {
  recordLoginFailure($db, $email);
  jsonResponse(401, 'Invalid email, password, or account role.');
}

clearLoginFailures($db, $email);
loginUser($db, $user, $rememberMe);
jsonResponse(200, 'Login successful.', ['user' => sessionUser(), 'csrf_token' => csrfToken()]);
