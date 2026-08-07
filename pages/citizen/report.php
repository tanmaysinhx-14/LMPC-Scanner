<?php
declare(strict_types=1);

// Compatibility route for older links. The maintained report experience is
// pages/report/index.php, which owns the complete form and submission flow.
require __DIR__ . '/../../bootstrap.php';
bootstrapAccounts(['required_roles' => ['citizen']]);
header('Location: ../report/');
exit;
