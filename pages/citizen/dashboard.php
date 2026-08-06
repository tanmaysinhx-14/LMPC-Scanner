<?php
// Citizen Dashboard
session_start();
require_once __DIR__ . '/../../functions/database/database.php';
require_once __DIR__ . '/../../functions/validations/validations.php';
require_once __DIR__ . '/../../functions/utility/response.php';

$pdo = connectDatabase();

// Get user data (assuming user is logged in)
$userId = $_SESSION['user_id'] ?? 1; // Default to 1 for demo

// Get statistics
$stats = getIssueStats($pdo);

// Get user's stats
$userStats = getUserStats($pdo, $userId);

// Get user's reported issues
$stmt = $pdo->prepare("SELECT * FROM issues WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$userId]);
$userIssues = $stmt->fetchAll();

// Get user's rank in leaderboard
$stmt = $pdo->query("
    SELECT id, full_name, 
    (SELECT COUNT(*) FROM issues WHERE user_id = users.id) as report_count,
    (SELECT COALESCE(SUM(upvotes), 0) FROM issues WHERE user_id = users.id) as total_upvotes
    FROM users WHERE role = 'citizen' 
    ORDER BY total_upvotes DESC, report_count DESC
");
$leaderboard = $stmt->fetchAll();

$rank = 1;
foreach ($leaderboard as $index => $user) {
    if ($user['id'] == $userId) {
        $rank = $index + 1;
        break;
    }
}

// Get recent activity
$stmt = $pdo->prepare("
    SELECT i.*, 
    (SELECT COUNT(*) FROM comments WHERE issue_id = i.id) as comment_count,
    (SELECT COUNT(*) FROM issue_history WHERE issue_id = i.id) as history_count
    FROM issues i 
    WHERE i.user_id = ? 
    ORDER BY i.updated_at DESC LIMIT 10
");
$stmt->execute([$userId]);
$recentActivity = $stmt->fetchAll();

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
    <title>CivicConnect - Citizen Dashboard</title>
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
                <a href="report-issue.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-plus-circle"></i></span>
                    <span class="nav-label">Report Issue</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="my-reports.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-list"></i></span>
                    <span class="nav-label">My Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="public-feed.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-globe"></i></span>
                    <span class="nav-label">Public Feed</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="notifications.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-bell"></i></span>
                    <span class="nav-label">Notifications</span>
                    <span class="badge bg-danger ms-auto">3</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="leaderboard.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-trophy"></i></span>
                    <span class="nav-label">Leaderboard</span>
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
                <h5 class="mb-0">Citizen Dashboard</h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-link position-relative">
                    <i class="fas fa-bell fs-5"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">3</span>
                </button>
                <div class="dropdown">
                    <button class="btn btn-link dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                        <div class="avatar-sm bg-primary text-white d-flex align-items-center justify-content-center rounded-circle" style="width:32px;height:32px;">
                            <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'John Doe', 0, 2)); ?>
                        </div>
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
                <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-file-alt"></i></div>
                <div class="stat-label">Total Reports</div>
                <div class="stat-value"><?php echo $userStats['total']; ?></div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> 12% from last month</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Resolved</div>
                <div class="stat-value"><?php echo $userStats['resolved']; ?></div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> 8% from last month</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-thumbs-up"></i></div>
                <div class="stat-label">Total Upvotes</div>
                <div class="stat-value"><?php echo formatNumber($userStats['upvotes']); ?></div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> 5% from last month</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="fas fa-trophy"></i></div>
                <div class="stat-label">Your Rank</div>
                <div class="stat-value">#<?php echo $rank; ?></div>
                <div class="stat-change <?php echo $rank <= 5 ? 'positive' : 'neutral'; ?>">
                    <i class="fas fa-<?php echo $rank <= 5 ? 'arrow-up' : 'minus'; ?>"></i> 
                    <?php echo $rank <= 5 ? 'Top 5 Contributor' : 'Keep going!'; ?>
                </div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Recent Activity -->
            <div class="chart-container">
                <div class="chart-header">
                    <span class="chart-title">Recent Activity</span>
                    <a href="my-reports.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="timeline">
                    <?php if (empty($recentActivity)): ?>
                        <p class="text-muted text-center py-4">No recent activity</p>
                    <?php else: ?>
                        <?php foreach ($recentActivity as $activity): ?>
                            <div class="timeline-item">
                                <div class="timeline-time"><?php echo date('M d, Y', strtotime($activity['created_at'])); ?></div>
                                <div class="timeline-content">
                                    <strong><?php echo htmlspecialchars($activity['title']); ?></strong>
                                    <span class="badge <?php echo $activity['status'] == 'resolved' ? 'bg-success' : ($activity['status'] == 'in_progress' ? 'bg-warning' : 'bg-danger'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $activity['status'])); ?>
                                    </span>
                                    <div class="text-muted small">
                                        <i class="fas fa-comment me-1"></i> <?php echo $activity['comment_count']; ?> comments
                                        <i class="fas fa-history ms-2 me-1"></i> <?php echo $activity['history_count']; ?> updates
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="chart-container">
                <div class="chart-header">
                    <span class="chart-title">Quick Actions</span>
                </div>
                <div class="d-grid gap-3">
                    <a href="report-issue.php" class="btn btn-primary btn-lg py-3">
                        <i class="fas fa-plus-circle me-2"></i> Report New Issue
                    </a>
                    <a href="my-reports.php" class="btn btn-outline-primary btn-lg py-3">
                        <i class="fas fa-list me-2"></i> View My Reports
                    </a>
                    <a href="public-feed.php" class="btn btn-outline-secondary btn-lg py-3">
                        <i class="fas fa-globe me-2"></i> Browse Public Feed
                    </a>
                    <a href="leaderboard.php" class="btn btn-outline-warning btn-lg py-3">
                        <i class="fas fa-trophy me-2"></i> View Leaderboard
                    </a>
                </div>
            </div>
        </div>

        <!-- Community Issues -->
        <div class="chart-container">
            <div class="chart-header">
                <span class="chart-title">Community Issues Near You</span>
                <a href="public-feed.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="row g-3">
                <?php
                $stmt = $pdo->query("SELECT * FROM issues ORDER BY upvotes DESC, created_at DESC LIMIT 4");
                $communityIssues = $stmt->fetchAll();
                ?>
                <?php if (empty($communityIssues)): ?>
                    <div class="col-12 text-center py-4">
                        <p class="text-muted">No community issues to display</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($communityIssues as $issue): ?>
                        <div class="col-md-3">
                            <div class="issue-card">
                                <div class="issue-header">
                                    <span class="issue-title"><?php echo htmlspecialchars($issue['title']); ?></span>
                                    <span class="badge <?php echo $issue['status'] == 'resolved' ? 'bg-success' : ($issue['status'] == 'in_progress' ? 'bg-warning' : 'bg-danger'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?>
                                    </span>
                                </div>
                                <div class="issue-meta">
                                    <span class="issue-location"><i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($issue['address'] ?? 'Unknown location'); ?></span>
                                    <span><i class="far fa-clock"></i> <?php echo date('M d', strtotime($issue['created_at'])); ?></span>
                                </div>
                                <div class="issue-footer">
                                    <span class="issue-votes">
                                        <i class="fas fa-thumbs-up"></i> <?php echo $issue['upvotes']; ?>
                                    </span>
                                    <a href="issue-details.php?id=<?php echo $issue['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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