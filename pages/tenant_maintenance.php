<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'tenant') {
    header('Location: ../auth/login.php?mode=tenant'); 
    exit;
}

$tenant_id = $_SESSION['user_id'];

$maint_query = mysqli_query($conn, "
    SELECT DISTINCT m.*, e.first_name as emp_first, e.last_name as emp_last, e.role as employee_role,
           r.room_number, bu.name as building_name
    FROM maintenance m
    JOIN room r ON m.room_id = r.room_id
    JOIN building bu ON r.building_id = bu.building_id
    JOIN booking b ON b.room_id = m.room_id
    LEFT JOIN employee e ON m.employee_id = e.employee_id
    WHERE b.tenant_id = $tenant_id 
      AND m.request_date >= b.start_date
    ORDER BY m.request_date DESC
");

$active_issues = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(DISTINCT m.maintenance_id) as total
    FROM maintenance m
    JOIN booking b ON b.room_id = m.room_id
    WHERE b.tenant_id = $tenant_id 
      AND m.request_date >= b.start_date
      AND m.status != 'Completed'
"))['total'] ?? 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — Maintenance</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/tenant_maintenance.css">
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>TENANT PORTAL</span></div>
    <nav class="nav">
        <a href="tenant_dashboard.php">🏠 Dashboard</a>
        <a href="tenant_room.php">🚪 My Room</a>
        <a href="tenant_payments.php">💳 Payments</a>
        <a href="tenant_maintenance.php" class="active">🔧 Maintenance</a>
        <a href="tenant_profile.php">👤 My Profile</a>
    </nav>
    <div class="sidebar-bottom">
        <div class="user-info">
            <div class="user-avatar">
                <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
            </div>
            <div>
                <div class="user-name"><?= htmlspecialchars($_SESSION['user_name']) ?></div>
                <div class="user-role">Tenant</div>
            </div>
        </div>
        <a href="../auth/logout.php" class="logout-btn">🚪 Logout</a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div>
            <h1>Maintenance History</h1>
            <p>Track repairs and issues in your rooms</p>
        </div>
        <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
    </div>

    <div class="stats" style="grid-template-columns: 1fr;">
        <div class="stat-card" style="max-width: 300px;">
            <div class="stat-icon" style="background: rgba(181,85,106,.1); color: var(--rose);">🔧</div>
            <div>
                <div class="stat-label">Pending Issues</div>
                <div class="stat-value"><?= $active_issues ?></div>
            </div>
        </div>
    </div>

    <div class="maintenance-container">
        <div class="maintenance-header">
            <h2>Maintenance Records</h2>
        </div>
        <table class="maintenance-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Room / Location</th>
                    <th>Issue Description</th>
                    <th>Handled By</th>
                    <th>Repair Cost</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($maint_query) > 0): ?>
                    <?php while ($m = mysqli_fetch_assoc($maint_query)): ?>
                        <tr>
                            <td style="white-space: nowrap;"><?= date('d M Y', strtotime($m['request_date'])) ?></td>
                            <td style="white-space: nowrap;">
                                <div style="font-weight: 500;">Room <?= htmlspecialchars($m['room_number']) ?></div>
                                <div style="font-size: 12px; color: var(--muted);"><?= htmlspecialchars($m['building_name']) ?></div>
                            </td>
                            <td>
                                <div class="issue-desc"><?= nl2br(htmlspecialchars($m['issue_description'])) ?></div>
                            </td>
                            <td>
                                <?php if ($m['emp_first']): ?>
                                    <div class="emp-details">
                                        <?= htmlspecialchars($m['emp_first'] . ' ' . $m['emp_last']) ?>
                                        <div class="emp-role"><?= htmlspecialchars($m['employee_role']) ?></div>
                                    </div>
                                <?php else: ?>
                                    <span style="color:var(--muted); font-size:13px;">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="cost-amount">Rp <?= number_format($m['repair_cost'], 0, ',', '.') ?></td>
                            <td>
                                <span class="badge badge-<?= strtolower($m['status']) ?>"><?= htmlspecialchars($m['status']) ?></span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px;">
                            <div style="font-size: 40px; margin-bottom: 10px;">✨</div>
                            <h3 style="color: var(--dark); font-weight: 500;">Everything is working fine!</h3>
                            <p style="color: var(--muted); font-size: 13px; margin-top: 4px;">No maintenance requests have been made for your rooms.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
