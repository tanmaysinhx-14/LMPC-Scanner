<?php
// Database connection functions

function connectDatabase() {
    // Replace with your actual database credentials
    $host = 'localhost';
    $dbname = 'civicconnect';
    $username = 'root';
    $password = '';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

function getIssueStats($pdo) {
    try {
        // Get total issues
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM issues");
        $total = $stmt->fetch()['total'];
        
        // Get open issues
        $stmt = $pdo->query("SELECT COUNT(*) as open FROM issues WHERE status = 'open'");
        $open = $stmt->fetch()['open'];
        
        // Get in progress issues
        $stmt = $pdo->query("SELECT COUNT(*) as in_progress FROM issues WHERE status = 'in_progress'");
        $inProgress = $stmt->fetch()['in_progress'];
        
        // Get resolved issues
        $stmt = $pdo->query("SELECT COUNT(*) as resolved FROM issues WHERE status = 'resolved'");
        $resolved = $stmt->fetch()['resolved'];
        
        // Get total issues reported (last 30 days)
        $stmt = $pdo->query("SELECT COUNT(*) as reported FROM issues WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $reported = $stmt->fetch()['reported'];
        
        // Get satisfaction rate (average rating from resolved issues)
        $stmt = $pdo->query("SELECT AVG(rating) as avg_rating FROM issues WHERE status = 'resolved' AND rating IS NOT NULL");
        $avgRating = $stmt->fetch()['avg_rating'];
        $satisfaction = $avgRating ? round(($avgRating / 5) * 100) : 0;
        
        return [
            'total' => $total ?? 0,
            'open' => $open ?? 0,
            'in_progress' => $inProgress ?? 0,
            'resolved' => $resolved ?? 0,
            'reported' => $reported ?? 0,
            'satisfaction' => $satisfaction ?? 0
        ];
    } catch (PDOException $e) {
        // Return default values if tables don't exist yet
        return [
            'total' => 0,
            'open' => 0,
            'in_progress' => 0,
            'resolved' => 0,
            'reported' => 0,
            'satisfaction' => 0
        ];
    }
}

// Function to get user stats
function getUserStats($pdo, $userId) {
    try {
        // Get total reports by user
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM issues WHERE user_id = ?");
        $stmt->execute([$userId]);
        $total = $stmt->fetch()['total'];
        
        // Get resolved reports by user
        $stmt = $pdo->prepare("SELECT COUNT(*) as resolved FROM issues WHERE user_id = ? AND status = 'resolved'");
        $stmt->execute([$userId]);
        $resolved = $stmt->fetch()['resolved'];
        
        // Get pending reports by user
        $stmt = $pdo->prepare("SELECT COUNT(*) as pending FROM issues WHERE user_id = ? AND status IN ('open', 'in_progress')");
        $stmt->execute([$userId]);
        $pending = $stmt->fetch()['pending'];
        
        // Get total upvotes received by user's issues
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(upvotes), 0) as upvotes FROM issues WHERE user_id = ?");
        $stmt->execute([$userId]);
        $upvotes = $stmt->fetch()['upvotes'];
        
        return [
            'total' => $total ?? 0,
            'resolved' => $resolved ?? 0,
            'pending' => $pending ?? 0,
            'upvotes' => $upvotes ?? 0
        ];
    } catch (PDOException $e) {
        return [
            'total' => 0,
            'resolved' => 0,
            'pending' => 0,
            'upvotes' => 0
        ];
    }
}

// Function to sanitize input
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}
?>