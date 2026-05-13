<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'tenant') {
    header('Location: ../auth/login.php?mode=tenant'); 
    exit;
}

$tenant_id = $_SESSION['user_id'];

$bookings_query = mysqli_query($conn, "
    SELECT b.*, r.room_number, r.room_type, r.floor, r.price_per_month,
           bu.name as building_name, bu.address, bu.city
    FROM booking b
    JOIN room r      ON b.room_id      = r.room_id
    JOIN building bu ON r.building_id  = bu.building_id
    WHERE b.tenant_id = $tenant_id
    ORDER BY b.booking_date DESC
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — My Room</title>
    <link rel="stylesheet" href="../css/tenant_room.css">
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>TENANT PORTAL</span></div>
    <nav class="nav">
        <a href="tenant_dashboard.php">🏠 Dashboard</a>
        <a href="tenant_room.php" class="active">🚪 My Room</a>
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
            <h1>My Room</h1>
            <p>Details about your current and past rooms</p>
        </div>
        <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
    </div>

    <div class="my-room-container">
        <?php if (mysqli_num_rows($bookings_query) > 0): ?>
            <?php while ($b = mysqli_fetch_assoc($bookings_query)): ?>
                <div class="booking-section">
                    <div class="booking-header">
                        <div>
                            <h2>Room <?= htmlspecialchars($b['room_number']) ?> — <?= htmlspecialchars($b['room_type']) ?></h2>
                            <p>🏢 <?= htmlspecialchars($b['building_name']) ?>, <?= htmlspecialchars($b['city']) ?></p>
                        </div>
                        <div style="text-align:right">
                            <span class="badge badge-<?= strtolower($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span>
                            <p style="margin-top:8px">ID: #<?= $b['booking_id'] ?></p>
                        </div>
                    </div>
                    
                    <div class="booking-details-grid">
                        <div class="booking-col">
                            <div class="detail-item">
                                <div class="detail-label">Location Address</div>
                                <div class="detail-value">📍 <?= htmlspecialchars($b['address']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Floor</div>
                                <div class="detail-value">Level <?= htmlspecialchars($b['floor']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Booking Date</div>
                                <div class="detail-value">📅 <?= date('d M Y', strtotime($b['booking_date'])) ?></div>
                            </div>
                        </div>
                        
                        <div class="booking-col">
                            <div class="detail-item">
                                <div class="detail-label">Lease Period</div>
                                <div class="detail-value">
                                    <?= date('d M Y', strtotime($b['start_date'])) ?> → <?= date('d M Y', strtotime($b['end_date'])) ?>
                                </div>
                            </div>
                            
                            <div class="price-box">
                                <div class="detail-label">Monthly Rent</div>
                                <div class="detail-value">Rp <?= number_format($b['price_per_month'], 0, ',', '.') ?><span>/mo</span></div>
                            </div>

                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="no-booking">
                <h3>You don't have any room bookings yet.</h3>
                <p style="margin-top:10px;">Once you book a room, details will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
