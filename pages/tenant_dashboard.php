<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'tenant') {
    header('Location: ../auth/login.php?mode=tenant'); 
    exit;
}

$tenant_id = $_SESSION['user_id'];

$booking = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT b.*, r.room_number, r.room_type, r.floor, r.price_per_month,
           bu.name as building_name, bu.address, bu.city
    FROM booking b
    JOIN room r      ON b.room_id      = r.room_id
    JOIN building bu ON r.building_id  = bu.building_id
    WHERE b.tenant_id = $tenant_id
    ORDER BY b.booking_date DESC LIMIT 1
"));

$payments = mysqli_query($conn, "
    SELECT p.*
    FROM payment p
    JOIN booking b ON p.booking_id = b.booking_id
    WHERE b.tenant_id = $tenant_id
    ORDER BY p.payment_date DESC
");

$maint = mysqli_query($conn, "
    SELECT m.*, e.first_name as emp_first, e.last_name as emp_last, e.role as employee_role
    FROM maintenance m
    LEFT JOIN employee e ON m.employee_id = e.employee_id
    WHERE m.room_id = " . ($booking['room_id'] ?? 0) . "
    ORDER BY m.request_date DESC LIMIT 5
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — My Dashboard</title>
    <link rel="stylesheet" href="../css/tenant_dashboard.css">
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>TENANT PORTAL</span></div>
    <nav class="nav">
        <a href="tenant_dashboard.php" class="active">🏠 Dashboard</a>
        <a href="tenant_room.php">🚪 My Room</a>
        <a href="tenant_payments.php">💳 Payments</a>
        <a href="tenant_maintenance.php">🔧 Maintenance</a>
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
            <h1>My Dashboard</h1>
            <p>Welcome back, <?= htmlspecialchars($_SESSION['user_name']) ?>!</p>
        </div>
        <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
    </div>

    <!-- ROOM CARD -->
    <div class="room-card">
        <div>
            <h2>
                Room <?= $booking['room_number'] ?? '-' ?> — 
                <?= $booking['room_type'] ?? 'No Room Yet' ?>
            </h2>

            <p>
                🏢 <?= htmlspecialchars($booking['building_name'] ?? '-') ?>, 
                <?= htmlspecialchars($booking['city'] ?? '-') ?>
            </p>

            <p>
                📍 <?= htmlspecialchars($booking['address'] ?? '-') ?> · 
                Floor <?= $booking['floor'] ?? '-' ?>
            </p>

            <p style="margin-top:12px">
                <span class="badge badge-<?= strtolower($booking['status'] ?? 'pending') ?>">
                    <?= $booking['status'] ?? 'No Booking' ?>
                </span>
            </p>
        </div>

        <div style="text-align:right">
            <div class="room-price">
                Rp <?= isset($booking['price_per_month']) 
                    ? number_format($booking['price_per_month'], 0, ',', '.') 
                    : '0' ?>
                <span>/mo</span>
            </div>

            <p style="margin-top:8px; font-size:13px; color:rgba(255,255,255,.6)">
                📅 <?= $booking['start_date'] ?? '-' ?> → <?= $booking['end_date'] ?? '-' ?>
            </p>
        </div>
    </div>

    <div class="grid2">

        <!-- BOOKING DETAILS -->
        <div class="card">
            <div class="card-head"><h3>Booking Details</h3></div>

            <div class="info-row">
                <span>Booking ID</span>
                <span>#<?= $booking['booking_id'] ?? '-' ?></span>
            </div>

            <div class="info-row">
                <span>Room Type</span>
                <span><?= $booking['room_type'] ?? '-' ?></span>
            </div>

            <div class="info-row">
                <span>Floor</span>
                <span><?= $booking['floor'] ?? '-' ?></span>
            </div>

            <div class="info-row">
                <span>Start Date</span>
                <span><?= $booking['start_date'] ?? '-' ?></span>
            </div>

            <div class="info-row">
                <span>End Date</span>
                <span><?= $booking['end_date'] ?? '-' ?></span>
            </div>

            <div class="info-row">
                <span>Deposit</span>
                <span>
                    Rp <?= isset($booking['deposit_amount']) 
                        ? number_format($booking['deposit_amount'], 0, ',', '.') 
                        : '0' ?>
                </span>
            </div>

            <div class="info-row">
                <span>Status</span>
                <span><?= $booking['status'] ?? '-' ?></span>
            </div>
        </div>

        <!-- PAYMENTS -->
        <div class="card">
            <div class="card-head"><h3>Payment History</h3></div>

            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php 
                if (mysqli_num_rows($payments) > 0):
                    while ($p = mysqli_fetch_assoc($payments)): ?>
                    <tr>
                        <td><?= $p['payment_date'] ?></td>
                        <td>Rp <?= number_format($p['amount'], 0, ',', '.') ?></td>
                        <td><?= $p['payment_method'] ?></td>
                        <td>
                            <span class="badge badge-<?= strtolower($p['status']) ?>">
                                <?= $p['status'] ?>
                            </span>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr>
                        <td colspan="4" style="text-align:center;">No payment data</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- MAINTENANCE -->
    <div class="card" style="margin-top:20px">
        <div class="card-head"><h3>Maintenance History</h3></div>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Issue</th>
                    <th>Handled By</th>
                    <th>Cost</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
            <?php 
            if (mysqli_num_rows($maint) > 0):
                while ($m = mysqli_fetch_assoc($maint)): ?>
                <tr>
                    <td><?= $m['request_date'] ?></td>
                    <td><?= htmlspecialchars($m['issue_description']) ?></td>
                    <td>
                        <?= ($m['emp_first'] ?? '-') . ' ' . ($m['emp_last'] ?? '') ?>
                        <br><small><?= $m['employee_role'] ?? '-' ?></small>
                    </td>
                    <td>Rp <?= number_format($m['repair_cost'], 0, ',', '.') ?></td>
                    <td>
                        <span class="badge badge-<?= strtolower($m['status']) ?>">
                            <?= $m['status'] ?>
                        </span>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr>
                    <td colspan="5" style="text-align:center;">No maintenance history</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>