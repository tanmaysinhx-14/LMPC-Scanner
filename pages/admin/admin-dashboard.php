<?php // Bootstrapper + Backend Configuration
  require __DIR__ . '/../../bootstrap.php';

  $bootstrapData = bootstrapAccounts(
    options: [
      'require_login' => true // Page accessible to logged out users only
    ]
  );
  
  extract($bootstrapData);
?>

<?php // Mock Data for Admin Dashboard
  $totalUsers = 15420;
  $newUsers = 342;
  
  $stats = [
      'total' => 8432,
      'open' => 142,
      'resolved' => 7900
  ];
  
  $aiStats = [
      'accuracy' => 96,
      'total_categorized' => 8120
  ];

  $userRoles = [
      ['role' => 'citizen', 'count' => 14200],
      ['role' => 'authority', 'count' => 1150],
      ['role' => 'admin', 'count' => 70]
  ];

  $monthlyStats = [
      ['month' => '2023-08', 'total' => 850, 'open' => 45, 'in_progress' => 120, 'resolved' => 685],
      ['month' => '2023-09', 'total' => 920, 'open' => 60, 'in_progress' => 150, 'resolved' => 710],
      ['month' => '2023-10', 'total' => 1050, 'open' => 130, 'in_progress' => 200, 'resolved' => 720],
      ['month' => '2023-11', 'total' => 1120, 'open' => 142, 'in_progress' => 180, 'resolved' => 798]
  ];

  $topReporters = [
      ['full_name' => 'Rajesh Kumar', 'report_count' => 145, 'total_upvotes' => 890],
      ['full_name' => 'Priya Sharma', 'report_count' => 112, 'total_upvotes' => 750],
      ['full_name' => 'Amit Patel', 'report_count' => 98, 'total_upvotes' => 620],
      ['full_name' => 'Sneha Reddy', 'report_count' => 85, 'total_upvotes' => 410],
      ['full_name' => 'Vikram Singh', 'report_count' => 76, 'total_upvotes' => 380],
  ];
?>

<?php // Header (contains Unified Page Meta-Data and CSS imports)
  require_once '../../components/header.php';
?>

<body class="d-flex vh-100 overflow-hidden bg-light">
  <style>
    #sidebar { transition: margin 0.3s ease-in-out; }
    #sidebar.collapsed { margin-left: -280px; }
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
        <a href="users.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-users fa-fw"></i> Users
          <span class="badge bg-primary ms-auto rounded-pill"><?php echo $totalUsers; ?></span>
        </a>
      </li>
      <li class="nav-item">
        <a href="departments.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-building fa-fw"></i> Departments
        </a>
      </li>
      <li class="nav-item">
        <a href="analytics.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-chart-line fa-fw"></i> Analytics
        </a>
      </li>
      <li class="nav-item">
        <a href="ai-monitor.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-robot fa-fw"></i> AI Monitor
        </a>
      </li>
      <li class="nav-item">
        <a href="settings.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-cog fa-fw"></i> Settings
        </a>
      </li>
    </ul>
    <hr>
    <ul class="nav nav-pills flex-column">
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
        <h5 class="mb-0 fw-semibold">Admin Dashboard</h5>
        <span class="badge bg-primary">Super Admin</span>
      </div>
      <div class="d-flex align-items-center gap-3">
        <button class="btn btn-light position-relative">
          <i class="fas fa-bell"></i>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65em;">8</span>
        </button>
        <div class="dropdown">
          <a href="#" class="d-flex align-items-center link-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <div class="bg-primary text-white d-flex align-items-center justify-content-center rounded-circle fw-bold" style="width:36px;height:36px;">AD</div>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
            <li><hr class="dropdown-divider"></li>
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
                <h6 class="text-muted mb-0 fw-semibold">Total Users</h6>
                <div class="bg-primary bg-opacity-10 text-primary p-2 rounded"><i class="fas fa-users fa-lg"></i></div>
              </div>
              <h3 class="mb-2 fw-bold"><?php echo formatNumber($totalUsers); ?></h3>
              <p class="mb-0 text-success small fw-medium"><i class="fas fa-arrow-up"></i> <?php echo $newUsers; ?> new this month</p>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-muted mb-0 fw-semibold">Total Issues</h6>
                <div class="bg-danger bg-opacity-10 text-danger p-2 rounded"><i class="fas fa-exclamation-triangle fa-lg"></i></div>
              </div>
              <h3 class="mb-2 fw-bold"><?php echo formatNumber($stats['total']); ?></h3>
              <p class="mb-0 text-danger small fw-medium"><i class="fas fa-arrow-up"></i> <?php echo $stats['open']; ?> open</p>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-muted mb-0 fw-semibold">Resolution Rate</h6>
                <div class="bg-success bg-opacity-10 text-success p-2 rounded"><i class="fas fa-check-circle fa-lg"></i></div>
              </div>
              <h3 class="mb-2 fw-bold"><?php echo $stats['total'] > 0 ? round(($stats['resolved'] / $stats['total']) * 100) : 0; ?>%</h3>
              <p class="mb-0 text-success small fw-medium"><i class="fas fa-arrow-up"></i> 2% improvement</p>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-muted mb-0 fw-semibold">AI Accuracy</h6>
                <div class="bg-info bg-opacity-10 text-info p-2 rounded"><i class="fas fa-robot fa-lg"></i></div>
              </div>
              <h3 class="mb-2 fw-bold"><?php echo round($aiStats['accuracy'] ?? 0); ?>%</h3>
              <p class="mb-0 text-success small fw-medium"><i class="fas fa-arrow-up"></i> <?php echo $aiStats['total_categorized'] ?? 0; ?> categorized</p>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-12 col-lg-4">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
              <h6 class="mb-0 fw-bold">User Distribution</h6>
              <a href="users.php" class="btn btn-sm btn-outline-primary">Manage</a>
            </div>
            <div class="list-group list-group-flush">
              <?php foreach ($userRoles as $role): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                  <span class="fw-medium text-capitalize">
                    <i class="fas fa-<?php echo $role['role'] == 'citizen' ? 'user' : ($role['role'] == 'authority' ? 'user-tie' : 'crown'); ?> text-secondary me-2 width-20 text-center"></i>
                    <?php echo $role['role']; ?>s
                  </span>
                  <span class="badge bg-primary rounded-pill"><?php echo $role['count']; ?></span>
                </div>
              <?php endforeach; ?>
              <div class="list-group-item d-flex justify-content-between align-items-center py-3 fw-bold bg-light">
                <span>Total</span>
                <span class="badge bg-dark rounded-pill"><?php echo $totalUsers; ?></span>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-8">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-3">
              <h6 class="mb-0 fw-bold">Monthly Overview</h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover table-borderless align-middle mb-0">
                  <thead class="table-light border-bottom">
                    <tr>
                      <th class="px-4 py-3">Month</th>
                      <th>Total</th>
                      <th>Open</th>
                      <th>In Progress</th>
                      <th>Resolved</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($monthlyStats as $stat): ?>
                      <tr>
                        <td class="px-4 py-3 fw-medium"><?php echo date('M Y', strtotime($stat['month'] . '-01')); ?></td>
                        <td class="fw-bold"><?php echo $stat['total']; ?></td>
                        <td><span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle"><?php echo $stat['open']; ?></span></td>
                        <td><span class="badge bg-warning bg-opacity-10 text-warning border border-warning-subtle"><?php echo $stat['in_progress']; ?></span></td>
                        <td><span class="badge bg-success bg-opacity-10 text-success border border-success-subtle"><?php echo $stat['resolved']; ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-12 col-lg-6">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
              <h6 class="mb-0 fw-bold">Top Reporters</h6>
              <span class="badge bg-primary">Leaderboard</span>
            </div>
            <div class="list-group list-group-flush">
              <?php foreach ($topReporters as $index => $reporter): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                  <div class="d-flex align-items-center gap-3">
                    <span class="fw-bold fs-5 <?php echo $index == 0 ? 'text-warning' : ($index == 1 ? 'text-secondary' : ($index == 2 ? 'text-danger' : 'text-muted')); ?>" style="width: 24px;">
                      #<?php echo $index + 1; ?>
                    </span>
                    <div>
                      <div class="fw-bold"><?php echo htmlspecialchars($reporter['full_name']); ?></div>
                      <div class="small text-muted"><?php echo $reporter['report_count']; ?> reports</div>
                    </div>
                  </div>
                  <span class="badge bg-success rounded-pill px-3 py-2"><?php echo $reporter['total_upvotes']; ?> <i class="fas fa-arrow-up ms-1"></i></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-6">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-3">
              <h6 class="mb-0 fw-bold">System Health</h6>
            </div>
            <div class="list-group list-group-flush">
              <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                <span class="fw-medium"><i class="fas fa-database text-success me-3"></i>Database</span>
                <span class="badge bg-success">Online</span>
              </div>
              <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                <span class="fw-medium"><i class="fas fa-server text-success me-3"></i>Server</span>
                <span class="badge bg-success">Online</span>
              </div>
              <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                <span class="fw-medium"><i class="fas fa-robot text-success me-3"></i>AI Service</span>
                <span class="badge bg-success">Online</span>
              </div>
              <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                <span class="fw-medium"><i class="fas fa-cloud text-warning me-3"></i>Storage</span>
                <span class="badge bg-warning text-dark">85% Used</span>
              </div>
              <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                <span class="fw-medium"><i class="fas fa-shield-alt text-success me-3"></i>Security</span>
                <span class="badge bg-success">Secure</span>
              </div>
              <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                <span class="fw-medium"><i class="fas fa-users text-info me-3"></i>Active Users</span>
                <span class="badge bg-info text-dark"><?php echo rand(50, 200); ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-4 mb-4">
        <div class="card-header bg-white border-bottom py-3">
          <h6 class="mb-0 fw-bold">Quick Actions</h6>
        </div>
        <div class="card-body">
          <div class="d-flex gap-2 flex-wrap">
            <a href="users.php" class="btn btn-outline-primary"><i class="fas fa-users me-2"></i>Manage Users</a>
            <a href="departments.php" class="btn btn-outline-secondary"><i class="fas fa-building me-2"></i>Departments</a>
            <a href="analytics.php" class="btn btn-outline-info"><i class="fas fa-chart-line me-2"></i>Analytics</a>
            <a href="ai-monitor.php" class="btn btn-outline-success"><i class="fas fa-robot me-2"></i>AI Monitor</a>
            <a href="settings.php" class="btn btn-outline-warning text-dark"><i class="fas fa-cog me-2"></i>Settings</a>
            <button class="btn btn-outline-danger" onclick="confirm('Are you sure? This will clear all cache.')"><i class="fas fa-trash me-2"></i>Clear Cache</button>
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