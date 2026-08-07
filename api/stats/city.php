<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') jsonResponse(405, 'GET is required.');
if (!($db instanceof PDO)) jsonResponse(503, 'The database is temporarily unavailable.');
$stats = $db->query("SELECT COUNT(*) AS total, COALESCE(SUM(status IN ('pending', 'acknowledged', 'in_progress')), 0) AS open_count, COALESCE(SUM(status = 'resolved' AND DATE(resolved_at) = CURRENT_DATE), 0) AS resolved_today, COALESCE(SUM(status = 'resolved'), 0) AS resolved_count, COALESCE(AVG(CASE WHEN status = 'resolved' AND resolved_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) END), 0) AS avg_resolution_hours FROM issues WHERE status <> 'rejected'")->fetch(PDO::FETCH_ASSOC) ?: [];
$categoryStmt = $db->query("SELECT category, COUNT(*) AS report_count FROM issues WHERE status <> 'rejected' GROUP BY category ORDER BY report_count DESC");
jsonResponse(200, 'City statistics loaded.', ['kpis' => ['total' => (int) ($stats['total'] ?? 0), 'open' => (int) ($stats['open_count'] ?? 0), 'resolved_today' => (int) ($stats['resolved_today'] ?? 0), 'resolved' => (int) ($stats['resolved_count'] ?? 0), 'avg_resolution_hours' => round((float) ($stats['avg_resolution_hours'] ?? 0), 1)], 'categories' => $categoryStmt->fetchAll(PDO::FETCH_ASSOC)]);
