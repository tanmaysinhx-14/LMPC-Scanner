<?php
// Authority Dashboard
session_start();
require_once __DIR__ . '/../../functions/database/database.php';
require_once __DIR__ . '/../../functions/validations/validations.php';
require_once __DIR__ . '/../../functions/utility/response.php';

$pdo = connectDatabase();

// Get statistics
$stats = getIssueStats($pdo);

// Get recent issues
$stmt = $pdo->query("SELECT * FROM issues ORDER BY 
    CASE priority 
        WHEN 'critical' THEN 1 
        WHEN 'high' THEN 2 
        WHEN 'medium' THEN 3 
        WHEN 'low' THEN 4 
    END, created_at DESC LIMIT 10");
$recentIssues = $stmt->fetchAll();

// Get department stats
$stmt = $pdo->query("
    SELECT category, COUNT(*) as count, 
    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_count
    FROM issues 
    GROUP BY category 
    ORDER BY count DESC
");
$departmentStats = $stmt->fetchAll();

// Get average resolution time
$stmt = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours 
    FROM issues 
    WHERE status = 'resolved' AND resolved_at IS NOT NULL
");
$avgResolution = $stmt->fetch()['avg_hours'] ?? 0;

function formatNumber($num) {
    if ($num >= 1000000) return number_format($num / 1000000, 1) . 'M';
    if ($num >= 1000) return number_format($num / 1000, 1) . 'K';
    return $num;
}

function getPriorityColor($priority) {
    switch ($priority) {
        case 'critical': return 'danger';
        case 'high': return 'warning';
        case 'medium': return 'info';
        case 'low': return 'success';
        default: return 'secondary';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CivicConnect - Authority Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>

    <!-- ===== SIDEBAR ===== -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-icon"><i class="fas fa-city"></i></span>
            <span>CivicConnect</span>
        </div>
        <ul class="sidebar-nav">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link active">
                    <span class="nav-icon"><i class="fas fa-th-large"></i></span>
                    <span class="nav-label">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="issue-management.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-tasks"></i></span>
                    <span class="nav-label">Issue Management</span>
                    <span class="badge bg-danger ms-auto"><?php echo $stats['open']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a href="analytics.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-label">Analytics</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="department.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-building"></i></span>
                    <span class="nav-label">Department</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="notifications.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-bell"></i></span>
                    <span class="nav-label">Notifications</span>
                    <span class="badge bg-danger ms-auto">5</span>
                </a>
            </li>
        </ul>
        <div class="sidebar-footer mt-auto">
            <ul class="sidebar-nav">
                <li class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-user"></i></span>
                        <span class="nav-label">Profile</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../login/index.php?logout=true" class="nav-link text-danger">
                        <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                        <span class="nav-label">Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- ===== MAIN CONTENT ===== -->
    <div class="main-content">
        <!-- Navbar -->
        <nav class="navbar-top d-flex justify-content-between align-items-center p-3 bg-white border-bottom">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-link" onclick="toggleSidebar()">
                    <i class="fas fa-bars fs-4"></i>
                </button>
                <h5 class="mb-0">Authority Dashboard</h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-link position-relative">
                    <i class="fas fa-bell fs-5"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">5</span>
                </button>
                <div class="dropdown">
                    <button class="btn btn-link dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                        <div class="avatar-sm bg-primary text-white d-flex align-items-center justify-content-center rounded-circle" style="width:32px;height:32px;">AO</div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../login/index.php?logout=true"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Stats Grid -->
        <div class="stats-grid mt-4">
            <div class="stat-card">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-label">Total Issues</div>
                <div class="stat-value"><?php echo formatNumber($stats['total']); ?></div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> 15% from last month</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Open Issues</div>
                <div class="stat-value"><?php echo $stats['open']; ?></div>
                <div class="stat-change negative"><i class="fas fa-arrow-up"></i> 5 new today</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Resolved</div>
                <div class="stat-value"><?php echo formatNumber($stats['resolved']); ?></div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> 92% resolution rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Avg Resolution Time</div>
                <div class="stat-value"><?php echo round($avgResolution); ?>h</div>
                <div class="stat-change positive"><i class="fas fa-arrow-down"></i> 4% faster</div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Priority Issues -->
            <div class="chart-container">
                <div class="chart-header">
                    <span class="chart-title">Priority Issues</span>
                    <a href="issue-management.php" class="btn btn-sm btn-outline-primary">Manage All</a>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($recentIssues as $issue): ?>
                        <?php if ($issue['priority'] == 'critical' || $issue['priority'] == 'high'): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="badge bg-<?php echo getPriorityColor($issue['priority']); ?> me-2">
                                        <?php echo ucfirst($issue['priority']); ?>
                                    </span>
                                    <?php echo htmlspecialchars(substr($issue['title'], 0, 40)); ?>
                                </div>
                                <div>
                                    <span class="badge <?php echo $issue['status'] == 'open' ? 'bg-danger' : ($issue['status'] == 'in_progress' ? 'bg-warning' : 'bg-success'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?>
                                    </span>
                                    <a href="issue-details.php?id=<?php echo $issue['id']; ?>" class="btn btn-sm btn-outline-primary ms-2">View</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Department Stats -->
            <div class="chart-container">
                <div class="chart-header">
                    <span class="chart-title">Department Overview</span>
                </div>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Total</th>
                                <th>Open</th>
                                <th>In Progress</th>
                                <th>Resolved</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($departmentStats as $dept): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($dept['category']); ?></td>
                                    <td><?php echo $dept['count']; ?></td>
                                    <td><span class="badge bg-danger"><?php echo $dept['open_count']; ?></span></td>
                                    <td><span class="badge bg-warning"><?php echo $dept['in_progress_count']; ?></span></td>
                                    <td><span class="badge bg-success"><?php echo $dept['resolved_count']; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Issues -->
        <div class="chart-container mt-4">
            <div class="chart-header">
                <span class="chart-title">Recent Issues</span>
                <a href="issue-management.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
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
                                <td>#<?php echo $issue['id']; ?></td>
                                <td><?php echo htmlspecialchars(substr($issue['title'], 0, 30)); ?></td>
                                <td><?php echo htmlspecialchars($issue['category']); ?></td>
                                <td><span class="badge bg-<?php echo getPriorityColor($issue['priority']); ?>"><?php echo ucfirst($issue['priority']); ?></span></td>
                                <td><span class="badge <?php echo $issue['status'] == 'open' ? 'bg-danger' : ($issue['status'] == 'in_progress' ? 'bg-warning' : 'bg-success'); ?>"><?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($issue['created_at'])); ?></td>
                                <td><a href="issue-details.php?id=<?php echo $issue['id']; ?>" class="btn btn-sm btn-primary">Manage</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }
    </script>
</body>
</html>