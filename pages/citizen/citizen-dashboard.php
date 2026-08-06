<?php // Bootstrapper + Backend Integration
  require __DIR__ . '/../../bootstrap.php';
?>

<?php // Mock Data for Citizen Dashboard
$userStats = [
  'total' => 24,
  'resolved' => 18,
  'upvotes' => 450
];

$rank = 4; // Top 5 contributor

$recentActivity = [
  [
    'title' => 'Pothole fixed on Elm Street',
    'status' => 'resolved',
    'comment_count' => 3,
    'history_count' => 2,
    'created_at' => '2026-08-05 14:30:00'
  ],
  [
    'title' => 'Broken street light on 5th Ave',
    'status' => 'in_progress',
    'comment_count' => 8,
    'history_count' => 3,
    'created_at' => '2026-08-03 09:15:00'
  ],
  [
    'title' => 'Missed garbage collection',
    'status' => 'open',
    'comment_count' => 0,
    'history_count' => 1,
    'created_at' => '2026-08-01 11:20:00'
  ]
];

$communityIssues = [
  [
    'id' => 1045,
    'title' => 'Major Water Pipe Burst',
    'status' => 'open',
    'address' => 'Downtown Main St.',
    'created_at' => '2026-08-06 08:00:00',
    'upvotes' => 156
  ],
  [
    'id' => 1043,
    'title' => 'Traffic light malfunction',
    'status' => 'in_progress',
    'address' => 'King St & 4th Ave',
    'created_at' => '2026-08-05 16:45:00',
    'upvotes' => 89
  ],
  [
    'id' => 1021,
    'title' => 'Fallen tree blocking road',
    'status' => 'resolved',
    'address' => 'Park View Estates',
    'created_at' => '2026-08-02 07:15:00',
    'upvotes' => 245
  ],
  [
    'id' => 1018,
    'title' => 'Graffiti on public library',
    'status' => 'open',
    'address' => 'Central Library',
    'created_at' => '2026-08-01 14:20:00',
    'upvotes' => 42
  ]
];
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
        <a href="report-issue.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-plus-circle fa-fw"></i> Report Issue
        </a>
      </li>
      <li class="nav-item">
        <a href="my-reports.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-list fa-fw"></i> My Reports
        </a>
      </li>
      <li class="nav-item">
        <a href="public-feed.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-globe fa-fw"></i> Public Feed
        </a>
      </li>
      <li class="nav-item">
        <a href="notifications.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-bell fa-fw"></i> Notifications
          <span class="badge bg-danger ms-auto rounded-pill">3</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="leaderboard.php" class="nav-link link-dark d-flex align-items-center gap-3">
          <i class="fas fa-trophy fa-fw"></i> Leaderboard
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
        <a href="../login/index.php?logout=true" class="nav-link text-danger d-flex align-items-center gap-3">
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
        <h5 class="mb-0 fw-semibold">Citizen Dashboard</h5>
      </div>
      <div class="d-flex align-items-center gap-3">
        <button class="btn btn-light position-relative">
          <i class="fas fa-bell"></i>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65em;">3</span>
        </button>
        <div class="dropdown">
          <a href="#" class="d-flex align-items-center link-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <div class="bg-primary text-white d-flex align-items-center justify-content-center rounded-circle fw-bold" style="width:36px;height:36px;">
              TS
            </div>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
            <li>
              <hr class="dropdown-divider">
            </li>
            <li><a class="dropdown-item text-danger" href="../login/index.php?logout=true"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
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
                <h6 class="text-muted mb-0 fw-semibold">Total Reports</h6>
                <div class="bg-primary bg-opacity-10 text-primary p-2 rounded"><i class="fas fa-file-alt fa-lg"></i></div>
              </div>
              <h3 class="mb-2 fw-bold"><?php echo formatNumber($userStats['total']); ?></h3>
              <p class="mb-0 text-success small fw-medium"><i class="fas fa-arrow-up"></i> 12% from last month</p>
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
              <h3 class="mb-2 fw-bold"><?php echo formatNumber($userStats['resolved']); ?></h3>
              <p class="mb-0 text-success small fw-medium"><i class="fas fa-arrow-up"></i> 8% from last month</p>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-muted mb-0 fw-semibold">Total Upvotes</h6>
                <div class="bg-warning bg-opacity-10 text-warning p-2 rounded"><i class="fas fa-thumbs-up fa-lg"></i></div>
              </div>
              <h3 class="mb-2 fw-bold"><?php echo formatNumber($userStats['upvotes']); ?></h3>
              <p class="mb-0 text-success small fw-medium"><i class="fas fa-arrow-up"></i> 5% from last month</p>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-muted mb-0 fw-semibold">Your Rank</h6>
                <div class="bg-info bg-opacity-10 text-info p-2 rounded"><i class="fas fa-trophy fa-lg"></i></div>
              </div>
              <h3 class="mb-2 fw-bold">#<?php echo $rank; ?></h3>
              <p class="mb-0 <?php echo $rank <= 5 ? 'text-success' : 'text-muted'; ?> small fw-medium">
                <i class="fas fa-<?php echo $rank <= 5 ? 'arrow-up' : 'minus'; ?>"></i>
                <?php echo $rank <= 5 ? 'Top 5 Contributor' : 'Keep going!'; ?>
              </p>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-12 col-lg-8">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
              <h6 class="mb-0 fw-bold">Recent Activity</h6>
              <a href="my-reports.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body">
              <?php if (empty($recentActivity)): ?>
                <p class="text-muted text-center py-4">No recent activity</p>
              <?php else: ?>
                <div class="position-relative ms-2 mt-2">
                  <?php foreach ($recentActivity as $activity): ?>
                    <div class="position-relative ps-4 pb-4 border-start border-2 border-primary-subtle last-child-no-border">
                      <span class="position-absolute top-0 start-0 translate-middle p-2 bg-primary border border-white border-2 rounded-circle"></span>
                      <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="small text-muted fw-medium"><?php echo date('M d, Y', strtotime($activity['created_at'])); ?></div>
                        <span class="badge <?php echo $activity['status'] == 'resolved' ? 'bg-success bg-opacity-10 text-success border border-success-subtle' : ($activity['status'] == 'in_progress' ? 'bg-warning bg-opacity-10 text-warning border border-warning-subtle' : 'bg-danger bg-opacity-10 text-danger border border-danger-subtle'); ?>">
                          <?php echo ucfirst(str_replace('_', ' ', $activity['status'])); ?>
                        </span>
                      </div>
                      <div class="card border border-light shadow-sm bg-body-tertiary">
                        <div class="card-body p-3">
                          <h6 class="fw-bold mb-2"><?php echo htmlspecialchars($activity['title']); ?></h6>
                          <div class="text-muted small d-flex gap-3">
                            <span><i class="fas fa-comment me-1"></i> <?php echo $activity['comment_count']; ?> comments</span>
                            <span><i class="fas fa-history me-1"></i> <?php echo $activity['history_count']; ?> updates</span>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <style>
                .last-child-no-border:last-child {
                  border-color: transparent !important;
                }
              </style>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-4">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-3">
              <h6 class="mb-0 fw-bold">Quick Actions</h6>
            </div>
            <div class="card-body d-flex flex-column gap-3 justify-content-center">
              <a href="report-issue.php" class="btn btn-primary btn-lg py-3 d-flex align-items-center justify-content-center gap-2">
                <i class="fas fa-plus-circle"></i> Report New Issue
              </a>
              <a href="my-reports.php" class="btn btn-outline-primary btn-lg py-3 d-flex align-items-center justify-content-center gap-2">
                <i class="fas fa-list"></i> View My Reports
              </a>
              <a href="public-feed.php" class="btn btn-outline-secondary btn-lg py-3 d-flex align-items-center justify-content-center gap-2">
                <i class="fas fa-globe"></i> Browse Public Feed
              </a>
              <a href="leaderboard.php" class="btn btn-outline-warning text-dark btn-lg py-3 d-flex align-items-center justify-content-center gap-2">
                <i class="fas fa-trophy"></i> View Leaderboard
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-4 mb-4">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
          <h6 class="mb-0 fw-bold">Community Issues Near You</h6>
          <a href="public-feed.php" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="card-body">
          <div class="row g-4">
            <?php if (empty($communityIssues)): ?>
              <div class="col-12 text-center py-4">
                <p class="text-muted">No community issues to display</p>
              </div>
            <?php else: ?>
              <?php foreach ($communityIssues as $issue): ?>
                <div class="col-12 col-md-6 col-xl-3">
                  <div class="card border border-light shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                      <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="fw-bold mb-0 me-2 text-truncate"><?php echo htmlspecialchars($issue['title']); ?></h6>
                        <span class="badge <?php echo $issue['status'] == 'resolved' ? 'bg-success' : ($issue['status'] == 'in_progress' ? 'bg-warning text-dark' : 'bg-danger'); ?>">
                          <?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?>
                        </span>
                      </div>
                      <div class="text-muted small mb-3 flex-grow-1">
                        <div class="mb-1"><i class="fas fa-map-pin me-2 text-primary"></i><?php echo htmlspecialchars($issue['address']); ?></div>
                        <div><i class="far fa-clock me-2 text-primary"></i><?php echo date('M d, Y', strtotime($issue['created_at'])); ?></div>
                      </div>
                      <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-auto">
                        <span class="text-success fw-bold">
                          <i class="fas fa-thumbs-up me-1"></i> <?php echo formatNumber($issue['upvotes']); ?>
                        </span>
                        <a href="issue-details.php?id=<?php echo $issue['id']; ?>" class="btn btn-sm btn-outline-primary px-3">View</a>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
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