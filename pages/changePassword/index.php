<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts(['required_roles' => ['citizen', 'worker', 'admin']]);
extract($bootstrapData);

$viewer = sessionUser() ?? [];
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  requireCsrfToken();
  $current = (string) ($_POST['current_password'] ?? '');
  $new = (string) ($_POST['new_password'] ?? '');
  $confirm = (string) ($_POST['confirm_password'] ?? '');
  if (!$db instanceof PDO) $error = 'The database is temporarily unavailable.';
  else {
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $viewer['id']]);
    $hash = (string) $stmt->fetchColumn();
    if (!password_verify($current, $hash)) $error = 'Your current password is incorrect.';
    elseif (!validatePassword($new)) $error = 'Use at least 8 characters, one number, and one symbol.';
    elseif (!hash_equals($new, $confirm)) $error = 'The new passwords do not match.';
    else {
      $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int) $viewer['id']]);
      setToast('Password changed successfully.', 'success');
      header('Location: ' . civicRoute('profile'), true, 303);
      exit;
    }
  }
}
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<?php require_once CIVICCONNECT_ROOT . '/components/header.php'; ?>
<body class="app-body">
  <main class="container py-4 py-lg-5"><div class="d-flex align-items-center justify-content-between gap-3 mb-4"><a href="<?= $e(civicRoute('profile')) ?>" class="text-decoration-none fw-bold"><i class="fas fa-arrow-left me-2"></i>Back to profile</a><span class="small text-muted"><?= $e($viewer['email'] ?? '') ?></span></div><div class="row justify-content-center"><div class="col-12 col-md-7 col-lg-5"><section class="data-card"><div class="data-card-header"><div><span class="eyebrow">Security</span><h1>Change password</h1></div><i class="fas fa-shield-halved text-primary"></i></div><div class="p-4"><?php if ($error): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $e(csrfToken()) ?>"><div class="mb-3"><label class="form-label fw-semibold" for="currentPassword">Current password</label><input id="currentPassword" name="current_password" type="password" class="form-control" autocomplete="current-password" required></div><div class="mb-3"><label class="form-label fw-semibold" for="newPassword">New password</label><input id="newPassword" name="new_password" type="password" class="form-control" minlength="8" autocomplete="new-password" required><div class="form-text">At least 8 characters, including a number and a symbol.</div></div><div class="mb-3"><label class="form-label fw-semibold" for="confirmPassword">Confirm new password</label><input id="confirmPassword" name="confirm_password" type="password" class="form-control" minlength="8" autocomplete="new-password" required></div><button class="btn btn-primary w-100 mt-2" type="submit">Update password</button></form></div></section></div></div></main>
  <?php require_once CIVICCONNECT_ROOT . '/components/footer.php'; ?>
</body>
</html>
