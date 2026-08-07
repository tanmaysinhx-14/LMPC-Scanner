<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

// The old authority route is retained as a compatibility link for existing
// bookmarks. Municipal operations now belong to the administrator role.
bootstrapAccounts(['required_roles' => ['admin']]);
header('Location: ../admin/work-management.php', true, 303);
exit;
