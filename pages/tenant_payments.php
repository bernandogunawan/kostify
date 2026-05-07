<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'tenant') {
    header('Location: ../auth/login.php?mode=tenant'); 
    exit;
}

$tenant_id = $_SESSION['user_id'];

$payments_query = mysqli_query($conn, "
    SELECT p.*, r.room_number, bu.name as building_name
    FROM payment p
    JOIN booking b ON p.booking_id = b.booking_id
    JOIN room r ON b.room_id = r.room_id
    JOIN building bu ON r.building_id = bu.building_id
    WHERE b.tenant_id = $tenant_id
    ORDER BY p.payment_date DESC
");

$total_paid = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT SUM(p.amount) as total
    FROM payment p
    JOIN booking b ON p.booking_id = b.booking_id
    WHERE b.tenant_id = $tenant_id AND p.status = 'Completed'
"))['total'] ?? 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — Payments</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/tenant_payments.css">
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>TENANT PORTAL</span></div>
    <nav class="nav">
        <a href="tenant_dashboard.php">🏠 Dashboard</a>
        <a href="tenant_room.php">🚪 My Room</a>
        <a href="tenant_payments.php" class="active">💳 Payments</a>
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
            <h1>Payment History</h1>
            <p>View all your past and pending payments</p>
        </div>
        <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
    </div>

    <div class="stats" style="grid-template-columns: 1fr;">
        <div class="stat-card" style="max-width: 300px;">
            <div class="stat-icon" style="background: rgba(181,85,106,.1); color: var(--rose);">💳</div>
            <div>
                <div class="stat-label">Total Amount Paid</div>
                <div class="stat-value">Rp <?= number_format($total_paid, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>

    <div class="payments-container">
        <div class="payments-header">
            <h2>All Transactions</h2>
        </div>
        <table class="payments-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference / Room</th>
                    <th>Amount</th>
                    <th>Payment Method</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($payments_query) > 0): ?>
                    <?php while ($p = mysqli_fetch_assoc($payments_query)): ?>
                        <tr>
                            <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                            <td>
                                <div style="font-weight: 500;">Room <?= htmlspecialchars($p['room_number']) ?></div>
                                <div style="font-size: 12px; color: var(--muted);"><?= htmlspecialchars($p['building_name']) ?></div>
                            </td>
                            <td class="payment-amount">Rp <?= number_format($p['amount'], 0, ',', '.') ?></td>
                            <td>
                                <div class="payment-method">
                                    <?php if (strtolower($p['payment_method']) == 'cash'): ?>
                                        💵 Cash
                                    <?php elseif (strtolower($p['payment_method']) == 'transfer'): ?>
                                        🏦 Transfer
                                    <?php else: ?>
                                        💳 <?= htmlspecialchars($p['payment_method']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-<?= strtolower($p['status']) ?>"><?= htmlspecialchars($p['status']) ?></span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px;">
                            <div style="font-size: 40px; margin-bottom: 10px;">🧾</div>
                            <h3 style="color: var(--dark); font-weight: 500;">No payments found</h3>
                            <p style="color: var(--muted); font-size: 13px; margin-top: 4px;">Your payment history will appear here once you make a transaction.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
