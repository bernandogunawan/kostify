<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Location: ../auth/login.php?mode=admin'); exit;
}

$total_rooms     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM room"))['c'];
$occupied_rooms  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM room WHERE status='Occupied'"))['c'];
$total_tenants   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tenant"))['c'];
$total_buildings = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM building"))['c'];
$total_revenue   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(amount) as s FROM payment WHERE status='Completed'"))['s'] ?? 0;
$pending_maint   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM maintenance WHERE status='Pending'"))['c'];

$bookings = mysqli_query($conn, "
    SELECT b.booking_id, b.start_date, b.end_date, b.status,
           t.first_name, t.last_name,
           r.room_number, r.room_type
    FROM booking b
    JOIN tenant t ON b.tenant_id = t.tenant_id
    JOIN room   r ON b.room_id   = r.room_id
    ORDER BY b.booking_date DESC LIMIT 5
");

$payments = mysqli_query($conn, "
    SELECT p.amount, p.payment_date, p.payment_method, p.status,
           t.first_name, t.last_name
    FROM payment p
    JOIN booking b ON p.booking_id = b.booking_id
    JOIN tenant  t ON b.tenant_id  = t.tenant_id
    ORDER BY p.payment_date DESC LIMIT 5
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — Admin Dashboard</title>
    <link rel="stylesheet" href="../css/dashboard.css">
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>ADMIN PANEL</span></div>
    <nav class="nav">
        <a href="admin_dashboard.php" class="active"><span class="icon">🏠</span> Dashboard</a>
        <a href="buildings.php"><span class="icon">🏢</span> Buildings</a>
        <a href="rooms.php"><span class="icon">🚪</span> Rooms</a>
        <a href="tenants.php"><span class="icon">👥</span> Tenants</a>
        <a href="bookings.php"><span class="icon">📋</span> Bookings</a>
        <a href="payments.php"><span class="icon">💳</span> Payments</a>
        <a href="maintenance.php"><span class="icon">🔧</span> Maintenance</a>
        <a href="employees.php"><span class="icon">👨‍💼</span> Employees</a>
    </nav>
    <div class="sidebar-bottom">
        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= htmlspecialchars($_SESSION['user_name']) ?></div>
                <div class="user-role">Administrator</div>
            </div>
        </div>
        <a href="../auth/logout.php" class="logout-btn">🚪 Logout</a>
    </div>
</div>

<div class="main">

    <div class="topbar">
        <div>
            <h1>Dashboard</h1>
            <p>Welcome back, <?= htmlspecialchars($_SESSION['user_name']) ?>!</p>
        </div>
        <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-icon">🏢</div>
            <div>
                <div class="stat-label">Buildings</div>
                <div class="stat-value"><?= $total_buildings ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🚪</div>
            <div>
                <div class="stat-label">Total Rooms</div>
                <div class="stat-value"><?= $total_rooms ?></div>
                <div class="stat-sub"><?= $occupied_rooms ?> occupied</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div>
                <div class="stat-label">Tenants</div>
                <div class="stat-value"><?= $total_tenants ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div>
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value">Rp <?= number_format($total_revenue, 0, ',', '.') ?></div> 
                <div class="stat-sub">Completed payments</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🔧</div>
            <div>
                <div class="stat-label">Maintenance</div>
                <div class="stat-value"><?= $pending_maint ?></div>
                <div class="stat-sub">Pending requests</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div>
                <div class="stat-label">Occupancy Rate</div>
                <div class="stat-value"><?= $total_rooms > 0 ? round($occupied_rooms / $total_rooms * 100) : 0 ?>%</div>
                <div class="stat-sub">Of total rooms</div>
            </div>
        </div>
    </div>

    <div class="tables">

        <div class="table-card">
            <div class="table-head">
                <h3>Recent Bookings</h3>
                <a href="bookings.php">View all →</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Room</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($b = mysqli_fetch_assoc($bookings)): ?>
                    <tr>
                        <td><?= $b['first_name'] . ' ' . $b['last_name'] ?></td>
                        <td><?= $b['room_number'] ?> (<?= $b['room_type'] ?>)</td>
                        <td><span class="badge badge-<?= strtolower($b['status']) ?>"><?= $b['status'] ?></span></td>
                    </tr>
                <?php endwhile ?>
                </tbody>
            </table>
        </div>

        <div class="table-card">
            <div class="table-head">
                <h3>Recent Payments</h3>
                <a href="payments.php">View all →</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($p = mysqli_fetch_assoc($payments)): ?>
                    <tr>
                        <td><?= $p['first_name'] . ' ' . $p['last_name'] ?></td>
                        <td>Rp <?= number_format($p['amount'], 0, ',', '.') ?></td>
                        <td><span class="badge badge-<?= strtolower($p['status']) ?>"><?= $p['status'] ?></span></td>
                    </tr>
                <?php endwhile ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

</body>
</html>