<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
bootstrapAccounts(['required_roles' => ['citizen', 'worker', 'admin']]);

header('Location: ../account/change-password.php', true, 303);
exit;
