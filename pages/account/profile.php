<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts(['required_roles' => ['citizen', 'worker', 'admin']]);
extract($bootstrapData);

$viewer = sessionUser() ?? [];
$profile = $viewer;
$profile['phone'] = '';
$error = '';

if ($db instanceof PDO) {
  $stmt = $db->prepare('SELECT id, name, email, role, ward_id, city, phone FROM users WHERE id = ? LIMIT 1');
  $stmt->execute([(int) $viewer['id']]);
  $profile = array_merge($profile, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  requireCsrfToken();
  $name = trim((string) ($_POST['name'] ?? ''));
  $city = trim((string) ($_POST['city'] ?? ''));
  $phone = trim((string) ($_POST['phone'] ?? ''));
  if ($name === '' || strlen($name) > 120) {
    $error = 'Please enter a name between 1 and 120 characters.';
  } elseif (!$db instanceof PDO) {
    $error = 'The database is temporarily unavailable.';
  } else {
    $update = $db->prepare('UPDATE users SET name = ?, city = ?, phone = ? WHERE id = ?');
    $update->execute([substr($name, 0, 120), substr($city, 0, 120), substr($phone, 0, 30), (int) $viewer['id']]);
    $_SESSION['user_name'] = substr($name, 0, 120);
    $_SESSION['user_city'] = substr($city, 0, 120);
    setToast('Profile updated successfully.', 'success');
    header('Location: profile.php');
    exit;
  }
  $profile['name'] = $name;
  $profile['city'] = $city;
  $profile['phone'] = $phone;
}

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$dashboardPath = match ((string) ($viewer['role'] ?? 'citizen')) {
  'admin' => '../admin/admin-dashboard.php',
  'worker' => '../worker/assignments.php',
  default => '../citizen/citizen-dashboard.php',
};
?>
<?php require_once __DIR__ . '/../../components/header.php'; ?>
<body class="app-body">
  <main class="container py-4 py-lg-5">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-4"><a href="<?= $e($dashboardPath) ?>" class="text-decoration-none fw-bold"><i class="fas fa-arrow-left me-2"></i>Back to dashboard</a><a href="change-password.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-lock me-1"></i>Change password</a></div>
    <div class="row justify-content-center"><div class="col-12 col-lg-8"><section class="data-card"><div class="data-card-header"><div><span class="eyebrow">Account</span><h1>Profile settings</h1></div><span class="pill pill-category text-capitalize"><?= $e($profile['role'] ?? 'user') ?></span></div><div class="p-4"><?php if ($error): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $e(csrfToken()) ?>"><div class="row g-3"><div class="col-12"><label class="form-label fw-semibold" for="profileName">Name</label><input id="profileName" name="name" class="form-control" maxlength="120" required value="<?= $e($profile['name'] ?? '') ?>"></div><div class="col-12 col-md-6"><label class="form-label fw-semibold" for="profileEmail">Email</label><input id="profileEmail" class="form-control" value="<?= $e($profile['email'] ?? '') ?>" readonly></div><div class="col-12 col-md-6"><label class="form-label fw-semibold" for="profileRole">Role</label><input id="profileRole" class="form-control text-capitalize" value="<?= $e($profile['role'] ?? '') ?>" readonly></div><div class="col-12 col-md-6"><label class="form-label fw-semibold" for="profileCity">City</label><input id="profileCity" name="city" class="form-control" maxlength="120" value="<?= $e($profile['city'] ?? '') ?>"></div><div class="col-12 col-md-6"><label class="form-label fw-semibold" for="profilePhone">Phone</label><input id="profilePhone" name="phone" class="form-control" maxlength="30" value="<?= $e($profile['phone'] ?? '') ?>"></div></div><button class="btn btn-primary mt-4" type="submit">Save changes</button></form></div></section></div></div>
  </main>
  <?php require_once __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
