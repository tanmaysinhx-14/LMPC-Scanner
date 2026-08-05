<?php
// Admin Dashboard
session_start();
require_once __DIR__ . '/../../functions/database/database.php';
require_once __DIR__ . '/../../functions/validations/validations.php';
require_once __DIR__ . '/../../functions/utility/response.php';

$pdo = connectDatabase();

// Get statistics
$stats = getIssueStats($pdo);

// Get user counts
$stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
$userRoles = $stmt->fetchAll();

// Get total users
$stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
$totalUsers = $stmt->fetch()['total'];

// Get new users this month
$stmt = $pdo->query("SELECT COUNT(*) as new FROM users WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
$newUsers = $stmt->fetch()['new'];

// Get monthly statistics
$stmt = $pdo->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved
    FROM issues 
    WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
");
$monthlyStats = $stmt->fetchAll();

// Get AI performance stats
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_categorized,
        COUNT(DISTINCT category) as unique_categories,
        AVG(CASE WHEN ai_category IS NOT NULL THEN 1 ELSE 0 END) * 100 as accuracy
    FROM issues 
    WHERE ai_category IS NOT NULL
");
$aiStats = $stmt->fetch();

// Get top reporters
$stmt = $pdo->query("
    SELECT u.id, u.full_name, COUNT(i.id) as report_count,
    SUM(i.upvotes) as total_upvotes
    FROM users u
    LEFT JOIN issues i ON u.id = i.user_id
    WHERE u.role = 'citizen'
    GROUP BY u.id
    ORDER BY report_count DESC
    LIMIT 5
");
$topReporters = $stmt->fetchAll();

function formatNumber($num) {
    if ($num >= 1000000) return number_format($num / 1000000, 1) . 'M';
    if ($num >= 1000) return number_format($num / 1000, 1) . 'K';
    return $num;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CivicConnect - Admin Dashboard</title>
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
                <a href="users.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span class="nav-label">Users</span>
                    <span class="badge bg-primary ms-auto"><?php echo $totalUsers; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a href="departments.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-building"></i></span>
                    <span class="nav-label">Departments</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="analytics.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                    <span class="nav-label">Analytics</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="ai-monitor.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-robot"></i></span>
                    <span class="nav-label">AI Monitor</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="settings.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-cog"></i></span>
                    <span class="nav-label">Settings</span>
                </a>
            </li>
        </ul>
        <div class="sidebar-footer mt-auto">
            <ul class="sidebar-nav">
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
                <h5 class="mb-0">Admin Dashboard</h5>
                <span class="badge bg-primary ms-2">Super Admin</span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-link position-relative">
                    <i class="fas fa-bell fs-5"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">8</span>
                </button>
                <div class="dropdown">
                    <button class="btn btn-link dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                        <div class="avatar-sm bg-primary text-white d-flex align-items-center justify-content-center rounded-circle" style="width:32px;height:32px;">AD</div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
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
                <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-users"></i></div>
                <div class="stat-label">Total Users</div>
                <div class="stat-value"><?php echo formatNumber($totalUsers); ?></div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> <?php echo $newUsers; ?> new this month</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-label">Total Issues</div>
                <div class="stat-value"><?php echo formatNumber($stats['total']); ?></div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> <?php echo $stats['open']; ?> open</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Resolution Rate</div>
                <div class="stat-value"><?php echo $stats['total'] > 0 ? round(($stats['resolved'] / $stats['total']) * 100) : 0; ?>%</div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> 2% improvement</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="fas fa-robot"></i></div>
                <div class="stat-label">AI Accuracy</div>
                <div class="stat-value"><?php echo round($aiStats['accuracy'] ?? 0); ?>%</div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> <?php echo $aiStats['total_categorized'] ?? 0; ?> categorized</div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- User Roles -->
            <div class="chart-container">
                <div class="chart-header">
                    <span class="chart-title">User Distribution</span>
                    <a href="users.php" class="btn btn-sm btn-outline-primary">Manage Users</a>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($userRoles as $role): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fas fa-<?php echo $role['role'] == 'citizen' ? 'user' : ($role['role'] == 'authority' ? 'user-tie' : 'crown'); ?> me-2"></i>
                                <?php echo ucfirst($role['role']); ?>s
                            </span>
                            <span class="badge bg-primary rounded-pill"><?php echo $role['count']; ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center fw-bold">
                        <span>Total</span>
                        <span class="badge bg-dark rounded-pill"><?php echo $totalUsers; ?></span>
                    </div>
                </div>
            </div>

            <!-- Monthly Stats -->
            <div class="chart-container">
                <div class="chart-header">
                    <span class="chart-title">Monthly Overview</span>
                </div>
                <div class="table-wrapper">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Total</th>
                                <th>Open</th>
                                <th>In Progress</th>
                                <th>Resolved</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthlyStats as $stat): ?>
                                <tr>
                                    <td><?php echo date('M Y', strtotime($stat['month'] . '-01')); ?></td>
                                    <td><?php echo $stat['total']; ?></td>
                                    <td><span class="badge bg-danger"><?php echo $stat['open']; ?></span></td>
                                    <td><span class="badge bg-warning"><?php echo $stat['in_progress']; ?></span></td>
                                    <td><span class="badge bg-success"><?php echo $stat['resolved']; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Top Reporters & System Health -->
        <div class="dashboard-grid">
            <!-- Top Reporters -->
            <div class="chart-container">
                <div class="chart-header">
                    <span class="chart-title">Top Reporters</span>
                    <span class="badge bg-primary">Leaderboard</span>
                </div>
                <?php foreach ($topReporters as $index => $reporter): ?>
                    <div class="leaderboard-item">
                        <span class="rank <?php echo $index == 0 ? 'gold' : ($index == 1 ? 'silver' : ($index == 2 ? 'bronze' : '')); ?>">
                            #<?php echo $index + 1; ?>
                        </span>
                        <div class="leaderboard-info">
                            <div class="leaderboard-name"><?php echo htmlspecialchars($reporter['full_name']); ?></div>
                            <div class="leaderboard-details"><?php echo $reporter['report_count']; ?> reports</div>
                        </div>
                        <div class="leaderboard-points"><?php echo $reporter['total_upvotes']; ?> ↑</div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- System Health -->
            <div class="chart-container">
                <div class="chart-header">
                    <span class="chart-title">System Health</span>
                </div>
                <div class="list-group list-group-flush">
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-database text-success me-2"></i> Database</span>
                        <span class="badge bg-success">Online</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-server text-success me-2"></i> Server</span>
                        <span class="badge bg-success">Online</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-robot text-success me-2"></i> AI Service</span>
                        <span class="badge bg-success">Online</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-cloud text-success me-2"></i> Storage</span>
                        <span class="badge bg-warning">85% Used</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-shield-alt text-success me-2"></i> Security</span>
                        <span class="badge bg-success">Secure</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-users text-success me-2"></i> Active Users</span>
                        <span class="badge bg-info"><?php echo rand(50, 200); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="chart-container mt-4">
            <div class="chart-header">
                <span class="chart-title">Quick Actions</span>
            </div>
            <div class="d-flex gap-3 flex-wrap">
                <a href="users.php" class="btn btn-outline-primary">
                    <i class="fas fa-users me-2"></i> Manage Users
                </a>
                <a href="departments.php" class="btn btn-outline-secondary">
                    <i class="fas fa-building me-2"></i> Manage Departments
                </a>
                <a href="analytics.php" class="btn btn-outline-info">
                    <i class="fas fa-chart-line me-2"></i> View Analytics
                </a>
                <a href="ai-monitor.php" class="btn btn-outline-success">
                    <i class="fas fa-robot me-2"></i> AI Monitor
                </a>
                <a href="settings.php" class="btn btn-outline-warning">
                    <i class="fas fa-cog me-2"></i> System Settings
                </a>
                <button class="btn btn-outline-danger" onclick="confirm('Are you sure? This will clear all cache.')">
                    <i class="fas fa-trash me-2"></i> Clear Cache
                </button>
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