<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'tenant') {
    header('Location: ../auth/login.php?mode=tenant');
    exit;
}

$tenant_id = (int)$_SESSION['user_id'];
$error       = '';
$show_payment = false;
$vd          = null;

function tb_compute_end(string $startYmd, int $months): ?string {
    $sd = DateTime::createFromFormat('Y-m-d', $startYmd);
    if (!$sd) {
        return null;
    }
    $ed = clone $sd;
    $ed->modify('+' . $months . ' months');
    $ed->modify('-1 day');
    return $ed->format('Y-m-d');
}

function tb_parse_start(string $s): ?DateTime {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $d : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize'])) {
    $room_id = (int)($_POST['room_id'] ?? 0);
    $months  = (int)($_POST['duration_months'] ?? 0);
    $start   = trim($_POST['start_date'] ?? '');
    $pm      = trim($_POST['payment_method'] ?? '');
    $paidOk  = isset($_POST['paid_ack']) && $_POST['paid_ack'] === 'yes';

    if (!$paidOk) {
        $error = 'Please confirm that you have completed the payment.';
    } elseif (!in_array($months, [6, 12], true)) {
        $error = 'Invalid booking length.';
    } elseif (!in_array($pm, ['Bank Transfer', 'E-Wallet'], true)) {
        $error = 'Please choose a payment method.';
    } else {
        $sd = tb_parse_start($start);
        $today = new DateTime('today');
        if (!$sd || $sd < $today) {
            $error = 'Invalid or past start date.';
        } else {
            $end = tb_compute_end($start, $months);
            if (!$end) {
                $error = 'Could not calculate end date.';
            } else {
                $room_id_esc = (int)$room_id;
                mysqli_begin_transaction($conn);
                try {
                    $room = mysqli_fetch_assoc(mysqli_query($conn, "SELECT room_id, price_per_month, status FROM room WHERE room_id=$room_id_esc"));
                    if (!$room || $room['status'] !== 'Available') {
                        throw new Exception('This room is no longer available.');
                    }
                    $ppm       = (float)$room['price_per_month'];
                    $total     = $ppm * $months;
                    $start_esc = mysqli_real_escape_string($conn, $start);
                    $end_esc   = mysqli_real_escape_string($conn, $end);
                    $pm_esc    = mysqli_real_escape_string($conn, $pm);

                    $ok = mysqli_query($conn, "
                        INSERT INTO booking (room_id, tenant_id, booking_date, start_date, end_date, deposit_amount, status)
                        VALUES ($room_id_esc, $tenant_id, CURDATE(), '$start_esc', '$end_esc', 0, 'Active')
                    ");
                    if (!$ok) {
                        throw new Exception('Could not create booking.');
                    }
                    $bid = (int)mysqli_insert_id($conn);

                    $ok2 = mysqli_query($conn, "UPDATE room SET status='Occupied' WHERE room_id=$room_id_esc AND status='Available'");
                    if (!$ok2 || mysqli_affected_rows($conn) !== 1) {
                        throw new Exception('Could not update room status.');
                    }

                    $ok3 = mysqli_query($conn, "
                        INSERT INTO payment (booking_id, amount, payment_date, payment_method, status)
                        VALUES ($bid, $total, CURDATE(), '$pm_esc', 'Completed')
                    ");
                    if (!$ok3) {
                        throw new Exception('Could not save payment.');
                    }

                    mysqli_commit($conn);
                    header('Location: tenant_dashboard.php?booked=1');
                    exit;
                } catch (Exception $ex) {
                    mysqli_rollback($conn);
                    $error = $ex->getMessage();
                }
            }
        }
    }

    if ($error !== '') {
        $room_id_r = (int)($_POST['room_id'] ?? 0);
        $months_r  = (int)($_POST['duration_months'] ?? 6);
        $start_r   = trim($_POST['start_date'] ?? '');
        $room_r    = mysqli_fetch_assoc(mysqli_query($conn, "
            SELECT r.*, bu.name AS building_name, bu.city, bu.address
            FROM room r JOIN building bu ON r.building_id = bu.building_id
            WHERE r.room_id = $room_id_r
        "));
        if ($room_r && in_array($months_r, [6, 12], true) && ($end_r = tb_compute_end($start_r, $months_r))) {
            $ppm_r   = (float)$room_r['price_per_month'];
            $total_r = $ppm_r * $months_r;
            $show_payment = true;
            $vd = [
                'room_id'          => $room_id_r,
                'duration_months'  => $months_r,
                'start_date'       => $start_r,
                'end_date'         => $end_r,
                'total'            => $total_r,
                'price_per_month'  => $ppm_r,
                'building_line'    => $room_r['building_name'] . ' (' . $room_r['city'] . ')',
                'room_label'       => 'Room ' . $room_r['room_number'] . ' — ' . $room_r['room_type'],
                'address'          => $room_r['address'],
            ];
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_step']) && $_POST['book_step'] === '1') {
    $room_id = (int)($_POST['room_id'] ?? 0);
    $months  = (int)($_POST['duration_months'] ?? 0);
    $start   = trim($_POST['start_date'] ?? '');

    if ($room_id <= 0 || !in_array($months, [6, 12], true)) {
        $error = 'Invalid booking request.';
    } else {
        $sd = tb_parse_start($start);
        $today = new DateTime('today');
        if (!$sd || $sd < $today) {
            $error = 'Please choose a valid start date (today or later).';
        } else {
            $room = mysqli_fetch_assoc(mysqli_query($conn, "
                SELECT r.*, bu.name AS building_name, bu.city, bu.address
                FROM room r JOIN building bu ON r.building_id = bu.building_id
                WHERE r.room_id = $room_id
            "));
            if (!$room || $room['status'] !== 'Available') {
                $error = 'This room is not available for booking.';
            } else {
                $end = tb_compute_end($start, $months);
                if (!$end) {
                    $error = 'Could not calculate end date.';
                } else {
                    $ppm   = (float)$room['price_per_month'];
                    $total = $ppm * $months;
                    $show_payment = true;
                    $vd = [
                        'room_id'          => $room_id,
                        'duration_months'  => $months,
                        'start_date'       => $start,
                        'end_date'         => $end,
                        'total'            => $total,
                        'price_per_month'  => $ppm,
                        'building_line'    => $room['building_name'] . ' (' . $room['city'] . ')',
                        'room_label'       => 'Room ' . $room['room_number'] . ' — ' . $room['room_type'],
                        'address'          => $room['address'],
                    ];
                }
            }
        }
    }
}

if (!$show_payment && $error === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: tenant_dashboard.php');
    exit;
}

$css_v = (int)@filemtime(__DIR__ . '/../css/tenant_booking.css');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — Pay for booking</title>
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
            <h1>Payment</h1>
            <p>Confirm how you paid for your booking</p>
        </div>
        <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
    </div>

    <?php if ($error && !$show_payment): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <p><a class="tb-back" href="tenant_dashboard.php">← Back to dashboard</a></p>
    <?php elseif ($show_payment && $vd): ?>
        <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <div class="tb-card">
            <div class="tb-card-header">
                <h2>Payment</h2>
                <a class="tb-back" href="tenant_booking.php?room_id=<?= (int)$vd['room_id'] ?>">← Edit booking</a>
            </div>
            <div class="tb-card-body">
                <div class="tb-form-group">
                    <label>Building</label>
                    <div class="tb-readonly"><?= htmlspecialchars($vd['building_line']) ?></div>
                </div>
                <div class="tb-form-group">
                    <label>Address</label>
                    <div class="tb-readonly"><?= htmlspecialchars($vd['address']) ?></div>
                </div>
                <div class="tb-form-group">
                    <label>Room</label>
                    <div class="tb-readonly"><?= htmlspecialchars($vd['room_label']) ?></div>
                </div>
                <div class="tb-form-row">
                    <div class="tb-form-group">
                        <label>Start date</label>
                        <div class="tb-readonly"><?= htmlspecialchars(date('d M Y', strtotime($vd['start_date']))) ?></div>
                    </div>
                    <div class="tb-form-group">
                        <label>End date</label>
                        <div class="tb-readonly"><?= htmlspecialchars(date('d M Y', strtotime($vd['end_date']))) ?></div>
                    </div>
                </div>
                <div class="tb-form-group">
                    <label>Amount due</label>
                    <div class="tb-readonly" style="font-weight:700;color:var(--rose-dark)">Rp <?= number_format($vd['total'], 0, ',', '.') ?></div>
                </div>
            </div>

            <form method="post" action="tenant_booking_pay.php" id="payForm">
                <input type="hidden" name="finalize" value="1">
                <input type="hidden" name="room_id" value="<?= (int)$vd['room_id'] ?>">
                <input type="hidden" name="duration_months" value="<?= (int)$vd['duration_months'] ?>">
                <input type="hidden" name="start_date" value="<?= htmlspecialchars($vd['start_date']) ?>">

                <div class="tb-card-body" style="padding-top: 0;">
                    <div class="tb-form-group">
                        <label for="payment_method">Payment method</label>
                        <select class="tb-select" name="payment_method" id="payment_method" required>
                            <option value="" disabled selected>Select method…</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="E-Wallet">E-Wallet</option>
                        </select>
                    </div>

                    <div class="tb-pay-row">
                        <input type="checkbox" name="paid_ack" id="paid_ack" value="yes">
                        <label for="paid_ack">I have completed the payment (bank transfer or e-wallet to the property).</label>
                    </div>
                    <p class="tb-hint">Your booking and payment will be recorded for the property manager. This cannot be undone from here.</p>
                </div>
                <div class="tb-footer">
                    <button type="submit" class="tb-btn-primary" id="doneBtn" disabled>Done</button>
                    <a href="tenant_dashboard.php" class="tb-btn-secondary" style="text-align:center;text-decoration:none;display:block">Cancel</a>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="alert-error">Something went wrong.</div>
        <p><a class="tb-back" href="tenant_dashboard.php">← Back to dashboard</a></p>
    <?php endif; ?>
</div>

<?php if ($show_payment && $vd): ?>
<script>
(function () {
    var cb = document.getElementById('paid_ack');
    var pm = document.getElementById('payment_method');
    var btn = document.getElementById('doneBtn');
    function sync() {
        var ok = cb.checked && pm.value;
        btn.disabled = !ok;
    }
    cb.addEventListener('change', sync);
    pm.addEventListener('change', sync);
    sync();
})();
</script>
<?php endif; ?>
</body>
</html>
