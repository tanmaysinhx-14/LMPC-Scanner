<?php // Bootstrapper + Backend Configuration
  require __DIR__ . '/../../bootstrap.php';

  $bootstrapData = bootstrapAccounts(
    options: [
      'require_login' => true // Page accessible to logged out users only
    ]
  );
  
  extract($bootstrapData);
?>

<?php // Mock Data for Authority Dashboard
  $stats = [
    'total' => 3420,
    'open' => 128,
    'resolved' => 3100
  ];

  $avgResolution = 48.5;

  $recentIssues = [
    ['id' => 1042, 'title' => 'Major Water Pipe Burst on Main Street', 'category' => 'Water Supply', 'priority' => 'critical', 'status' => 'open', 'created_at' => '2026-08-06 09:30:00'],
    ['id' => 1041, 'title' => 'Traffic Lights Malfunction at 5th Ave', 'category' => 'Traffic', 'priority' => 'high', 'status' => 'in_progress', 'created_at' => '2026-08-06 08:15:00'],
    ['id' => 1040, 'title' => 'Pothole near Central Park entrance', 'category' => 'Roads', 'priority' => 'medium', 'status' => 'open', 'created_at' => '2026-08-05 14:20:00'],
    ['id' => 1039, 'title' => 'Street light not working in Zone 4', 'category' => 'Electricity', 'priority' => 'low', 'status' => 'resolved', 'created_at' => '2026-08-04 19:45:00'],
    ['id' => 1038, 'title' => 'Garbage collection delayed by 3 days', 'category' => 'Sanitation', 'priority' => 'high', 'status' => 'open', 'created_at' => '2026-08-03 11:10:00']
  ];

  $departmentStats = [
    ['category' => 'Roads & Infrastructure', 'count' => 1250, 'open_count' => 45, 'in_progress_count' => 80, 'resolved_count' => 1125],
    ['category' => 'Water Supply', 'count' => 980, 'open_count' => 30, 'in_progress_count' => 50, 'resolved_count' => 900],
    ['category' => 'Sanitation', 'count' => 850, 'open_count' => 25, 'in_progress_count' => 40, 'resolved_count' => 785],
    ['category' => 'Electricity', 'count' => 340, 'open_count' => 28, 'in_progress_count' => 30, 'resolved_count' => 282]
  ];

  function getPriorityColor(string $priority)
  {
    switch ($priority) {
      case 'critical':
        return 'danger';
      case 'high':
        return 'warning';
      case 'medium':
        return 'info';
      case 'low':
        return 'success';
      default:
        return 'secondary';
    }
  }
?>

<?php // Header (contains Unified Page Meta-Data and CSS imports)
  require_once '../../components/header.php';
?>

<body class="d-flex vh-100 overflow-hidden bg-light">
  <style>
    #sidebar {
      transition: margin 0.3s ease-in-out;
    }

    #sidebar.collapsed {
      margin-left: -280px;
    }
  </style>
  <div id="sidebar" class="d-flex flex-column flex-shrink-0 p-3 bg-white border-end shadow-sm z-3" style="width: 280px;">
    <a href="dashboard.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto link-dark text-decoration-none gap-2">
      <i class="fas fa-city fs-4 text-primary"></i>
      <span class="fs-4 fw-bold">CivicConnect</span>
    </a>
    <hr>
    <ul class="nav nav-pills flex-column mb-auto gap-1">
      <li class="nav-item">
        <a href="dashboard.php" class="nav-link active d-flex align-items-center gap-3">
          <i class="fas fa-th-large fa-fw"></i> Dashboard
        </a>
      </li>
      <li class="nav-item">
        <a href="issue-management.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-tasks fa-fw"></i> Issue Management
          <span class="badge bg-danger ms-auto rounded-pill"><?php echo $stats['open']; ?></span>
        </a>
      </li>
      <li class="nav-item">
        <a href="analytics.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-chart-bar fa-fw"></i> Analytics
        </a>
      </li>
      <li class="nav-item">
        <a href="department.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-building fa-fw"></i> Department
        </a>
      </li>
      <li class="nav-item">
        <a href="notifications.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-bell fa-fw"></i> Notifications
          <span class="badge bg-danger ms-auto rounded-pill">5</span>
        </a>
      </li>
    </ul>
    <hr>
    <ul class="nav nav-pills flex-column">
      <li class="nav-item">
        <a href="profile.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-user fa-fw"></i> Profile
        </a>
      </li>
      <li class="nav-item">
        <a href="../logout/" class="nav-link text-danger d-flex align-items-center gap-3">
          <i class="fas fa-sign-out-alt fa-fw"></i> Logout
        </a>
      </li>
    </ul>
  </div>

  <div class="d-flex flex-column flex-grow-1 overflow-y-auto w-100">
    <nav class="navbar bg-white border-bottom shadow-sm px-3 py-2 d-flex justify-content-between align-items-center sticky-top">
      <div class="d-flex align-items-center gap-3">
        <button class="btn btn-light" onclick="document.getElementById('sidebar').classList.toggle('collapsed')">
          <i class="fas fa-bars"></i>
        </button>
        <h5 class="mb-0 fw-semibold">Authority Dashboard</h5>
      </div>
      <div class="d-flex align-items-center gap-3">
        <button class="btn btn-light position-relative">
          <i class="fas fa-bell"></i>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65em;">5</span>
        </button>
        <div class="dropdown">
          <a href="#" class="d-flex align-items-center link-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <div class="bg-primary text-white d-flex align-items-center justify-content-center rounded-circle fw-bold" style="width:36px;height:36px;">AO</div>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
            <li>
              <hr class="dropdown-divider">
            </li>
            <li><a class="dropdown-item text-danger" href="../logout/"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </nav>

    <div class="container-fluid p-4">
      <div class="row g-3">
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-muted mb-0 fw-semibold">Total Issues</h6>
                <div class="bg-primary bg-opacity-10 text-primary p-2 rounded"><i class="fas fa-exclamation-triangle fa-lg"></i></div>
              </div>
              <h3 class="mb-2 fw-bold"><?php echo formatNumber($stats['total']); ?></h3>
              <p class="mb-0 text-success small fw-medium"><i class="fas fa-arrow-up"></i> 15% from last month</p>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-muted mb-0 fw-semibold">Open Issues</h6>
                <div class="bg-danger bg-opacity-10 text-danger p-2 rounded"><i class="fas fa-clock fa-lg"></i></div>
              </div>
              <h3 class="mb-2 fw-bold"><?php echo formatNumber($stats['open']); ?></h3>
              <p class="mb-0 text-danger small fw-medium"><i class="fas fa-arrow-up"></i> 5 new today</p>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-muted mb-0 fw-semibold">Resolved</h6>
                <div class="bg-success bg-opacity-10 text-success p-2 rounded"><i class="fas fa-check-circle fa-lg"></i></div>
              </div>
              <h3 class="mb-2 fw-bold"><?php echo formatNumber($stats['resolved']); ?></h3>
              <p class="mb-0 text-success small fw-medium"><i class="fas fa-arrow-up"></i> 92% resolution rate</p>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-muted mb-0 fw-semibold">Avg Resolution Time</h6>
                <div class="bg-info bg-opacity-10 text-info p-2 rounded"><i class="fas fa-clock fa-lg"></i></div>
              </div>
              <h3 class="mb-2 fw-bold"><?php echo round($avgResolution); ?>h</h3>
              <p class="mb-0 text-success small fw-medium"><i class="fas fa-arrow-down"></i> 4% faster</p>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-12 col-lg-5">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
              <h6 class="mb-0 fw-bold">Priority Issues</h6>
              <a href="issue-management.php" class="btn btn-sm btn-outline-primary">Manage All</a>
            </div>
            <div class="list-group list-group-flush">
              <?php foreach ($recentIssues as $issue): ?>
                <?php if ($issue['priority'] == 'critical' || $issue['priority'] == 'high'): ?>
                  <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                    <div class="pe-3">
                      <span class="badge bg-<?php echo getPriorityColor($issue['priority']); ?> me-2">
                        <?php echo ucfirst($issue['priority']); ?>
                      </span>
                      <span class="fw-medium text-dark"><?php echo htmlspecialchars(substr($issue['title'], 0, 40)); ?></span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                      <span class="badge <?php echo $issue['status'] == 'open' ? 'bg-danger bg-opacity-10 text-danger border border-danger-subtle' : ($issue['status'] == 'in_progress' ? 'bg-warning bg-opacity-10 text-warning border border-warning-subtle' : 'bg-success bg-opacity-10 text-success border border-success-subtle'); ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?>
                      </span>
                      <a href="issue-details.php?id=<?php echo $issue['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                    </div>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-7">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-3">
              <h6 class="mb-0 fw-bold">Department Overview</h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover table-borderless align-middle mb-0">
                  <thead class="table-light border-bottom">
                    <tr>
                      <th class="px-4 py-3">Department</th>
                      <th>Total</th>
                      <th>Open</th>
                      <th>In Progress</th>
                      <th>Resolved</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($departmentStats as $dept): ?>
                      <tr>
                        <td class="px-4 py-3 fw-medium"><?php echo htmlspecialchars($dept['category']); ?></td>
                        <td class="fw-bold"><?php echo formatNumber($dept['count']); ?></td>
                        <td><span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle"><?php echo formatNumber($dept['open_count']); ?></span></td>
                        <td><span class="badge bg-warning bg-opacity-10 text-warning border border-warning-subtle"><?php echo formatNumber($dept['in_progress_count']); ?></span></td>
                        <td><span class="badge bg-success bg-opacity-10 text-success border border-success-subtle"><?php echo formatNumber($dept['resolved_count']); ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-4 mb-4">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
          <h6 class="mb-0 fw-bold">Recent Issues</h6>
          <a href="issue-management.php" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-borderless align-middle mb-0">
              <thead class="table-light border-bottom">
                <tr>
                  <th class="px-4 py-3">ID</th>
                  <th>Title</th>
                  <th>Category</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Reported</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentIssues as $issue): ?>
                  <tr>
                    <td class="px-4 py-3 fw-medium text-muted">#<?php echo $issue['id']; ?></td>
                    <td class="fw-medium"><?php echo htmlspecialchars(substr($issue['title'], 0, 40)); ?></td>
                    <td><?php echo htmlspecialchars($issue['category']); ?></td>
                    <td><span class="badge bg-<?php echo getPriorityColor($issue['priority']); ?> bg-opacity-10 text-<?php echo getPriorityColor($issue['priority']); ?> border border-<?php echo getPriorityColor($issue['priority']); ?>-subtle px-2"><?php echo ucfirst($issue['priority']); ?></span></td>
                    <td><span class="badge <?php echo $issue['status'] == 'open' ? 'bg-danger bg-opacity-10 text-danger border border-danger-subtle' : ($issue['status'] == 'in_progress' ? 'bg-warning bg-opacity-10 text-warning border border-warning-subtle' : 'bg-success bg-opacity-10 text-success border border-success-subtle'); ?> px-2"><?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?></span></td>
                    <td class="text-muted small"><?php echo date('M d, Y', strtotime($issue['created_at'])); ?></td>
                    <td><a href="issue-details.php?id=<?php echo $issue['id']; ?>" class="btn btn-sm btn-primary px-3">Manage</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php // Contains JS imports
    require_once '../../components/footer.php';
  ?>
</body>
</html>