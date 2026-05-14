<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'tenant') {
    header('Location: ../auth/login.php?mode=tenant');
    exit;
}

$tenant_id = (int)$_SESSION['user_id'];
$room_id   = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$error     = '';

$room = null;
if ($room_id > 0) {
    $room = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT r.*, bu.name AS building_name, bu.city, bu.address
        FROM room r
        JOIN building bu ON r.building_id = bu.building_id
        WHERE r.room_id = $room_id
    "));
}

if (!$room || $room['status'] !== 'Available') {
    $error = $room ? 'This room is not available for booking.' : 'Room not found.';
}

$css_v = (int)@filemtime(__DIR__ . '/../css/tenant_booking.css');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — Make a booking</title>
    <link rel="stylesheet" href="../css/tenant_booking.css?v=<?= $css_v ?>">
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>TENANT PORTAL</span></div>
    <nav class="nav">
        <a href="tenant_dashboard.php">🏠 Dashboard</a>
        <a href="tenant_room.php">🚪 My Room</a>
        <a href="tenant_payments.php">💳 Payments</a>
        <a href="tenant_maintenance.php">🔧 Maintenance</a>
        <a href="tenant_profile.php">👤 My Profile</a>
    </nav>
    <div class="sidebar-bottom">
        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?></div>
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
            <h1>Make a booking</h1>
            <p>Choose how long you want to stay and when it starts</p>
        </div>
        <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
    </div>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <p><a class="tb-back" href="tenant_dashboard.php">← Back to dashboard</a></p>
    <?php else: ?>
        <div class="tb-card">
            <div class="tb-card-header">
                <h2>Make a booking</h2>
                <a class="tb-back" href="tenant_dashboard.php">Cancel</a>
            </div>
            <form class="tb-card-body" method="post" action="tenant_booking_pay.php" id="bookingForm">
                <input type="hidden" name="book_step" value="1">
                <input type="hidden" name="room_id" value="<?= (int)$room['room_id'] ?>">

                <div class="tb-form-group">
                    <label>Building</label>
                    <div class="tb-readonly"><?= htmlspecialchars($room['building_name'] . ' (' . $room['city'] . ')') ?></div>
                </div>
                <div class="tb-form-group">
                    <label>Room</label>
                    <div class="tb-readonly">Room <?= htmlspecialchars($room['room_number']) ?> — <?= htmlspecialchars($room['room_type']) ?></div>
                </div>

                <div class="tb-form-group">
                    <label>Price / month (Rp)</label>
                    <div class="tb-readonly" id="priceMonthly">Rp <?= number_format((float)$room['price_per_month'], 0, ',', '.') ?></div>
                </div>

                <div class="tb-form-group">
                    <label for="duration_months">Booking length</label>
                    <select class="tb-select" name="duration_months" id="duration_months" required>
                        <option value="6">6 months</option>
                        <option value="12">1 year (12 months)</option>
                    </select>
                    <p class="tb-hint">Total rent is calculated from the monthly rate × the length you choose.</p>
                </div>

                <div class="tb-total-box">
                    <div class="tb-label">Total for this stay</div>
                    <div class="tb-total-amount" id="totalDisplay">Rp 0</div>
                </div>

                <div class="tb-form-row" style="margin-top: 20px;">
                    <div class="tb-form-group">
                        <label for="start_date">Start date</label>
                        <input class="tb-input" type="date" name="start_date" id="start_date" required
                               min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="tb-form-group">
                        <label for="end_date">End date</label>
                        <input class="tb-input" type="date" id="end_date" readonly style="opacity:.95" aria-label="End date (calculated)">
                    </div>
                </div>
                <p class="tb-hint">The end date updates automatically from your start date and booking length.</p>
            </form>
            <div class="tb-footer">
                <button type="submit" form="bookingForm" class="tb-btn-primary">Confirm and pay</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!$error): ?>
<script>
(function () {
    var monthly = <?= json_encode((float)$room['price_per_month']) ?>;
    var dur = document.getElementById('duration_months');
    var start = document.getElementById('start_date');
    var end = document.getElementById('end_date');
    var totalEl = document.getElementById('totalDisplay');

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function ymd(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    function addMonthsClamp(y, m, d, addM) {
        var dt = new Date(y, m - 1, d);
        dt.setMonth(dt.getMonth() + addM);
        dt.setDate(dt.getDate() - 1);
        return dt;
    }

    function recalc() {
        var months = parseInt(dur.value, 10) || 0;
        var t = monthly * months;
        totalEl.textContent = 'Rp ' + t.toLocaleString('id-ID', { maximumFractionDigits: 0 });

        if (!start.value) {
            end.value = '';
            return;
        }
        var p = start.value.split('-').map(Number);
        if (p.length !== 3) return;
        var ed = addMonthsClamp(p[0], p[1], p[2], months);
        end.value = ymd(ed);
    }

    dur.addEventListener('change', recalc);
    start.addEventListener('change', recalc);
    recalc();
})();
</script>
<?php endif; ?>
</body>
</html>
