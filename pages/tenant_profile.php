<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'tenant') {
    header('Location: ../auth/login.php?mode=tenant'); 
    exit;
}

$tenant_id = $_SESSION['user_id'];

$tenant_query = mysqli_query($conn, "
    SELECT * FROM tenant WHERE tenant_id = $tenant_id
");
$tenant = mysqli_fetch_assoc($tenant_query);

$booking_stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) as total_bookings, 
           SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_bookings
    FROM booking 
    WHERE tenant_id = $tenant_id
"));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — My Profile</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/tenant_profile.css">
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>TENANT PORTAL</span></div>
    <nav class="nav">
        <a href="tenant_dashboard.php">🏠 Dashboard</a>
        <a href="tenant_room.php">🚪 My Room</a>
        <a href="tenant_payments.php">💳 Payments</a>
        <a href="tenant_maintenance.php">🔧 Maintenance</a>
        <a href="tenant_profile.php" class="active">👤 My Profile</a>
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
            <h1>My Profile</h1>
            <p>View your personal information</p>
        </div>
        <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
    </div>

    <div class="profile-container">
        <!-- LEFT: Avatar & Stats -->
        <div class="profile-card">
            <div class="profile-avatar-large">
                <?= strtoupper(substr($tenant['first_name'], 0, 1) . substr($tenant['last_name'], 0, 1)) ?>
            </div>
            <div class="profile-name">
                <?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?>
            </div>
            <div class="profile-role">Tenant</div>
            
            <div class="profile-stat" style="margin-top:20px;">
                <span>Total Bookings</span>
                <span><?= $booking_stats['total_bookings'] ?></span>
            </div>
            <div class="profile-stat">
                <span>Active Rooms</span>
                <span><?= $booking_stats['active_bookings'] ?></span>
            </div>
            <div class="profile-stat" style="border-bottom: 1px solid var(--border);">
                <span>Member Since</span>
                <span><?= date('M Y', strtotime($tenant['created_at'] ?? 'now')) ?></span>
            </div>
        </div>

        <!-- RIGHT: Details -->
        <div class="settings-card">
            <div class="settings-header">
                <h2>Personal Details</h2>
            </div>
            <div class="settings-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name</label>
                        <div class="form-value"><?= htmlspecialchars($tenant['first_name']) ?></div>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <div class="form-value"><?= htmlspecialchars($tenant['last_name']) ?></div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <div class="form-value"><?= htmlspecialchars($tenant['email']) ?></div>
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <div class="form-value"><?= htmlspecialchars($tenant['phone'] ?: '—') ?></div>
                </div>

                <div style="margin-top: 40px; padding-top: 24px; border-top: 1px solid var(--border);">
                    <p style="font-size: 13px; color: var(--muted); line-height: 1.5;">
                        ℹ️ To update your personal information or change your password, please contact your building administrator.
                    </p>
                </div>
            </div>
        </div>
    </div>

</div>

</body>
</html>
