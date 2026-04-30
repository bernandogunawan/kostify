<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Location: ../auth/login.php?mode=admin'); exit;
}

$success = '';
$error   = '';

// ── Handle POST ──
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'add') {
        $tenant_id     = (int)$_POST['tenant_id'];
        $room_id       = (int)$_POST['room_id'];
        $start_date    = mysqli_real_escape_string($conn, $_POST['start_date']);
        $end_date      = mysqli_real_escape_string($conn, $_POST['end_date']);
        $deposit       = (float)$_POST['deposit_amount'];
        $status        = mysqli_real_escape_string($conn, $_POST['status']);
        $booking_date  = date('Y-m-d');

        // Check for conflicting active booking for this room
        $conflict = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT booking_id FROM booking
             WHERE room_id=$room_id AND status NOT IN ('Cancelled','Completed')
             AND ('$start_date' <= end_date AND '$end_date' >= start_date)"
        ));
        if ($conflict) {
            $error = 'Room is already booked during that period.';
        } else {
            mysqli_query($conn, "INSERT INTO booking (tenant_id, room_id, booking_date, start_date, end_date, deposit_amount, status)
                                 VALUES ($tenant_id,$room_id,'$booking_date','$start_date','$end_date',$deposit,'$status')");
            // Update room status if Active or Confirmed
            if (in_array($status, ['Active','Confirmed'])) {
                mysqli_query($conn, "UPDATE room SET status='Occupied' WHERE room_id=$room_id");
            }
            $success = 'Booking created successfully.';
        }

    } elseif ($action == 'edit') {
        $id         = (int)$_POST['booking_id'];
        $tenant_id  = (int)$_POST['tenant_id'];
        $room_id    = (int)$_POST['room_id'];
        $start_date = mysqli_real_escape_string($conn, $_POST['start_date']);
        $end_date   = mysqli_real_escape_string($conn, $_POST['end_date']);
        $deposit    = (float)$_POST['deposit_amount'];
        $old_status = mysqli_real_escape_string($conn, $_POST['old_status']);
        $status     = mysqli_real_escape_string($conn, $_POST['status']);

        mysqli_query($conn, "UPDATE booking SET tenant_id=$tenant_id, room_id=$room_id,
                             start_date='$start_date', end_date='$end_date',
                             deposit_amount=$deposit, status='$status'
                             WHERE booking_id=$id");

        // Sync room status
        if ($status == 'Active') {
            mysqli_query($conn, "UPDATE room SET status='Occupied' WHERE room_id=$room_id");
        } elseif (in_array($status, ['Cancelled','Completed'])) {
            // Only free the room if no other active booking
            $other = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) as c FROM booking WHERE room_id=$room_id AND status='Active' AND booking_id!=$id"
            ))['c'];
            if (!$other) mysqli_query($conn, "UPDATE room SET status='Available' WHERE room_id=$room_id");
        }
        $success = 'Booking updated.';

    } elseif ($action == 'delete') {
        $id = (int)$_POST['booking_id'];
        $payments = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM payment WHERE booking_id=$id"))['c'];
        if ($payments > 0) {
            $error = 'Cannot delete: booking has ' . $payments . ' payment record(s).';
        } else {
            mysqli_query($conn, "DELETE FROM booking WHERE booking_id=$id");
            $success = 'Booking deleted.';
        }
    }
}

// ── Filters ──
$filter_status = mysqli_real_escape_string($conn, $_GET['status'] ?? '');
$where = $filter_status ? "WHERE b.status='$filter_status'" : '';

// ── Fetch bookings ──
$bookings = mysqli_query($conn, "
    SELECT b.*,
           t.first_name, t.last_name, t.email,
           r.room_number, r.room_type, r.price_per_month,
           bu.name as building_name
    FROM booking b
    JOIN tenant   t  ON b.tenant_id  = t.tenant_id
    JOIN room     r  ON b.room_id    = r.room_id
    JOIN building bu ON r.building_id = bu.building_id
    $where
    ORDER BY b.booking_date DESC
");

// ── Stats ──
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*) as total,
        SUM(status='Active')    as active,
        SUM(status='Confirmed') as confirmed,
        SUM(status='Pending')   as pending,
        SUM(status='Cancelled') as cancelled,
        SUM(status='Completed') as completed
    FROM booking
"));

// ── Dropdown data ──
$tenants_res   = mysqli_query($conn, "SELECT tenant_id, first_name, last_name FROM tenant ORDER BY first_name");
$tenants_arr   = [];
while ($t = mysqli_fetch_assoc($tenants_res)) $tenants_arr[] = $t;

$rooms_res = mysqli_query($conn, "
    SELECT r.room_id, r.room_number, r.room_type, r.floor, b.name as building_name
    FROM room r JOIN building b ON r.building_id = b.building_id
    ORDER BY b.name, r.room_number
");
$rooms_arr = [];
while ($r = mysqli_fetch_assoc($rooms_res)) $rooms_arr[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — Bookings</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --dark: #1C1410; --rose: #B5556A; --rose-dark: #8E3F52;
            --bg: #F0EBE1; --muted: #8C7B70; --border: #DDD5C8;
            --sidebar-w: 220px;
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); display: flex; min-height: 100vh; }

        .sidebar { width: var(--sidebar-w); background: var(--dark); display: flex; flex-direction: column; padding: 32px 0; position: fixed; top: 0; left: 0; height: 100vh; overflow-y: auto; }
        .sidebar-logo { font-family: 'Playfair Display', serif; font-size: 20px; color: #fff; letter-spacing: 4px; padding: 0 24px 32px; border-bottom: 1px solid rgba(255,255,255,.08); }
        .sidebar-logo span { display: block; font-family: 'DM Sans', sans-serif; font-size: 10px; letter-spacing: 3px; color: var(--muted); margin-top: 2px; }
        .nav { margin-top: 24px; flex: 1; }
        .nav a { display: flex; align-items: center; gap: 12px; padding: 12px 24px; color: var(--muted); text-decoration: none; font-size: 14px; font-weight: 500; transition: all .2s; border-left: 3px solid transparent; }
        .nav a:hover, .nav a.active { background: rgba(181,85,106,.12); color: #fff; border-left-color: var(--rose); }
        .nav a .icon { font-size: 16px; width: 20px; }
        .sidebar-bottom { padding: 24px; border-top: 1px solid rgba(255,255,255,.08); }
        .user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
        .user-avatar { width: 36px; height: 36px; background: var(--rose); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; font-size: 14px; flex-shrink: 0; }
        .user-name { font-size: 13px; color: #fff; font-weight: 500; }
        .user-role { font-size: 11px; color: var(--muted); }
        .logout-btn { display: block; text-align: center; padding: 9px; background: rgba(181,85,106,.15); color: var(--rose); text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 500; transition: background .2s; }
        .logout-btn:hover { background: var(--rose); color: #fff; }

        .main { margin-left: var(--sidebar-w); flex: 1; padding: 36px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; }
        .topbar h1 { font-family: 'Playfair Display', serif; font-size: 26px; color: var(--dark); }
        .topbar p { font-size: 13px; color: var(--muted); margin-top: 2px; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .topbar-date { font-size: 13px; color: var(--muted); background: #fff; padding: 8px 16px; border-radius: 8px; border: 1px solid var(--border); }
        .btn-add { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--rose); color: #fff; border: none; border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 600; cursor: pointer; transition: background .2s; }
        .btn-add:hover { background: var(--rose-dark); }

        .alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: #E8F5E9; color: #2E7D32; border: 1px solid #C8E6C9; }
        .alert-error   { background: #FEE8ED; color: #8E3F52; border: 1px solid #F5C6D0; }

        /* STATUS STRIP */
        .status-strip { display: flex; gap: 10px; margin-bottom: 28px; flex-wrap: wrap; }
        .strip-card { flex: 1; min-width: 100px; background: #fff; border-radius: 12px; padding: 16px 18px; border: 1px solid var(--border); cursor: pointer; transition: all .2s; text-decoration: none; display: block; }
        .strip-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); transform: translateY(-1px); }
        .strip-card.active-filter { border-color: var(--rose); box-shadow: 0 0 0 3px rgba(181,85,106,.15); }
        .strip-num   { font-size: 28px; font-weight: 700; color: var(--dark); }
        .strip-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); margin-top: 2px; }
        .strip-num.s-active    { color: #1565C0; }
        .strip-num.s-confirmed { color: #6A1B9A; }
        .strip-num.s-pending   { color: #F57F17; }
        .strip-num.s-completed { color: #2E7D32; }
        .strip-num.s-cancelled { color: #C62828; }

        .toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .search-wrap { position: relative; flex: 1; max-width: 300px; }
        .search-wrap input { width: 100%; padding: 10px 14px 10px 38px; border: 1.5px solid var(--border); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 14px; background: #fff; color: var(--dark); outline: none; transition: border-color .2s; }
        .search-wrap input:focus { border-color: var(--rose); }
        .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 14px; }
        .filter-select { padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 14px; background: #fff; color: var(--dark); outline: none; cursor: pointer; }

        .table-card { background: #fff; border-radius: 14px; border: 1px solid var(--border); overflow: hidden; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #FDFAF6; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); padding: 12px 18px; text-align: left; white-space: nowrap; }
        td { font-size: 13px; color: var(--dark); padding: 14px 18px; border-top: 1px solid var(--border); vertical-align: middle; }
        tbody tr:hover { background: #FDFAF6; }
        .table-footer { display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-top: 1px solid var(--border); font-size: 13px; color: var(--muted); }

        .booking-id { font-family: 'DM Sans', sans-serif; font-weight: 700; color: var(--rose); font-size: 13px; }
        .tenant-name { font-weight: 600; }
        .tenant-email { font-size: 11px; color: var(--muted); }
        .room-info { font-weight: 600; }
        .room-building { font-size: 11px; color: var(--muted); }
        .date-range { font-size: 12px; }
        .date-range .sep { color: var(--muted); }

        /* BADGES */
        .badge { padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .badge-active    { background: #E3F2FD; color: #1565C0; }
        .badge-confirmed { background: #EDE7F6; color: #6A1B9A; }
        .badge-pending   { background: #FFF8E1; color: #F57F17; }
        .badge-completed { background: #E8F5E9; color: #2E7D32; }
        .badge-cancelled { background: #FFEBEE; color: #C62828; }

        .actions { display: flex; gap: 6px; }
        .btn-sm { padding: 6px 12px; border: none; border-radius: 7px; font-family: 'DM Sans', sans-serif; font-size: 12px; font-weight: 600; cursor: pointer; transition: all .2s; }
        .btn-sm-edit   { background: rgba(181,85,106,.1); color: var(--rose); }
        .btn-sm-edit:hover { background: var(--rose); color: #fff; }
        .btn-sm-delete { background: rgba(198,40,40,.08); color: #C62828; }
        .btn-sm-delete:hover { background: #C62828; color: #fff; }

        .modal-backdrop { position: fixed; inset: 0; background: rgba(28,20,16,.55); backdrop-filter: blur(4px); z-index: 100; display: none; align-items: center; justify-content: center; }
        .modal-backdrop.open { display: flex; }
        .modal { background: #fff; border-radius: 18px; width: 100%; max-width: 540px; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 80px rgba(0,0,0,.2); animation: slideUp .25s ease; }
        @keyframes slideUp { from { opacity:0; transform:translateY(24px); } to { opacity:1; transform:translateY(0); } }
        .modal-header { padding: 24px 28px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--dark); }
        .modal-close { width: 32px; height: 32px; background: #F0EBE1; border: none; border-radius: 50%; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .modal-close:hover { background: var(--border); }
        .modal-body { padding: 24px 28px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 11px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
        .form-group input, .form-group select { width: 100%; padding: 11px 14px; border: 1.5px solid var(--border); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 14px; color: var(--dark); background: #FDFAF6; outline: none; transition: border-color .2s; }
        .form-group input:focus, .form-group select:focus { border-color: var(--rose); box-shadow: 0 0 0 3px rgba(181,85,106,.1); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .modal-footer { padding: 16px 28px 24px; display: flex; gap: 10px; justify-content: flex-end; }
        .btn-cancel { padding: 10px 22px; background: #F0EBE1; border: 1.5px solid var(--border); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 500; color: var(--muted); cursor: pointer; }
        .btn-cancel:hover { background: var(--border); color: var(--dark); }
        .btn-submit { padding: 10px 24px; background: var(--rose); border: none; border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 600; color: #fff; cursor: pointer; }
        .btn-submit:hover { background: var(--rose-dark); }
        .btn-danger { padding: 10px 24px; background: #C62828; border: none; border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 600; color: #fff; cursor: pointer; }
        .btn-danger:hover { background: #8B1A1A; }
        .delete-warning { text-align: center; padding: 8px 0 16px; }
        .delete-warning .warning-icon { font-size: 48px; margin-bottom: 12px; }
        .delete-warning p { font-size: 14px; color: var(--muted); line-height: 1.6; }
        .delete-warning strong { color: var(--dark); }

        /* Duration chip */
        .duration-chip { display: inline-block; font-size: 11px; font-weight: 600; background: rgba(28,20,16,.06); color: var(--muted); padding: 2px 8px; border-radius: 5px; margin-top: 3px; }

        .empty-row td { text-align: center; padding: 48px; color: var(--muted); font-size: 14px; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>ADMIN PANEL</span></div>
    <nav class="nav">
        <a href="admin_dashboard.php"><span class="icon">🏠</span> Dashboard</a>
        <a href="buildings.php"><span class="icon">🏢</span> Buildings</a>
        <a href="rooms.php"><span class="icon">🚪</span> Rooms</a>
        <a href="tenants.php"><span class="icon">👥</span> Tenants</a>
        <a href="bookings.php" class="active"><span class="icon">📋</span> Bookings</a>
        <a href="#"><span class="icon">💳</span> Payments</a>
        <a href="#"><span class="icon">🔧</span> Maintenance</a>
        <a href="#"><span class="icon">👨‍💼</span> Employees</a>
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
            <h1>Bookings</h1>
            <p>Manage all room bookings and reservations</p>
        </div>
        <div class="topbar-right">
            <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
            <button class="btn-add" onclick="openAddModal()">＋ New Booking</button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif ?>

    <!-- Status Strip -->
    <div class="status-strip">
        <?php
        $current_filter = $_GET['status'] ?? '';
        $strip_items = [
            ['label' => 'All',       'status' => '',          'num' => $stats['total'],     'cls' => ''],
            ['label' => 'Active',    'status' => 'Active',    'num' => $stats['active'],    'cls' => 's-active'],
            ['label' => 'Confirmed', 'status' => 'Confirmed', 'num' => $stats['confirmed'], 'cls' => 's-confirmed'],
            ['label' => 'Pending',   'status' => 'Pending',   'num' => $stats['pending'],   'cls' => 's-pending'],
            ['label' => 'Completed', 'status' => 'Completed', 'num' => $stats['completed'], 'cls' => 's-completed'],
            ['label' => 'Cancelled', 'status' => 'Cancelled', 'num' => $stats['cancelled'], 'cls' => 's-cancelled'],
        ];
        foreach ($strip_items as $si):
            $is_active = ($current_filter === $si['status']);
        ?>
        <a href="bookings.php<?= $si['status'] ? '?status='.$si['status'] : '' ?>"
           class="strip-card <?= $is_active ? 'active-filter' : '' ?>">
            <div class="strip-num <?= $si['cls'] ?>"><?= $si['num'] ?></div>
            <div class="strip-label"><?= $si['label'] ?></div>
        </a>
        <?php endforeach ?>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="search-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" id="searchInput" placeholder="Search tenant or room…" oninput="filterTable()">
        </div>
        <select class="filter-select" id="buildingFilter" onchange="filterTable()">
            <option value="">All Buildings</option>
            <?php foreach ($rooms_arr as $r):
                $bld = $r['building_name'];
            ?>
                <option value="<?= htmlspecialchars($bld) ?>"><?= htmlspecialchars($bld) ?></option>
            <?php endforeach ?>
        </select>
    </div>

    <div class="table-card">
        <div class="table-wrap">
            <table id="bookingTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tenant</th>
                        <th>Room</th>
                        <th>Period</th>
                        <th>Deposit</th>
                        <th>Price / Mo</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $has_rows = false;
                while ($b = mysqli_fetch_assoc($bookings)):
                    $has_rows  = true;
                    $badge_cls = 'badge-' . strtolower($b['status']);
                    // Duration
                    $d1  = new DateTime($b['start_date']);
                    $d2  = new DateTime($b['end_date']);
                    $dur = $d1->diff($d2)->days . 'd';
                ?>
                <tr data-search="<?= strtolower($b['first_name'].' '.$b['last_name'].' '.$b['room_number']) ?>"
                    data-building="<?= strtolower(htmlspecialchars($b['building_name'])) ?>">
                    <td><span class="booking-id">#<?= $b['booking_id'] ?></span></td>
                    <td>
                        <div class="tenant-name"><?= htmlspecialchars($b['first_name'].' '.$b['last_name']) ?></div>
                        <div class="tenant-email"><?= htmlspecialchars($b['email']) ?></div>
                    </td>
                    <td>
                        <div class="room-info">Room <?= htmlspecialchars($b['room_number']) ?> <span style="font-weight:400;color:var(--muted)">(<?= $b['room_type'] ?>)</span></div>
                        <div class="room-building"><?= htmlspecialchars($b['building_name']) ?></div>
                    </td>
                    <td>
                        <div class="date-range">
                            <?= date('d M Y', strtotime($b['start_date'])) ?>
                            <span class="sep"> → </span>
                            <?= date('d M Y', strtotime($b['end_date'])) ?>
                        </div>
                        <span class="duration-chip"><?= $dur ?></span>
                    </td>
                    <td>Rp <?= number_format($b['deposit_amount'], 0, ',', '.') ?></td>
                    <td>Rp <?= number_format($b['price_per_month'], 0, ',', '.') ?></td>
                    <td><span class="badge <?= $badge_cls ?>"><?= $b['status'] ?></span></td>
                    <td>
                        <div class="actions">
                            <button class="btn-sm btn-sm-edit" onclick='openEditModal(<?= json_encode([
                                "booking_id"     => $b["booking_id"],
                                "tenant_id"      => $b["tenant_id"],
                                "room_id"        => $b["room_id"],
                                "start_date"     => $b["start_date"],
                                "end_date"       => $b["end_date"],
                                "deposit_amount" => $b["deposit_amount"],
                                "status"         => $b["status"],
                            ]) ?>)'>✏️ Edit</button>
                            <button class="btn-sm btn-sm-delete" onclick='openDeleteModal(<?= $b["booking_id"] ?>, <?= json_encode($b["first_name"]." ".$b["last_name"]) ?>)'>🗑️</button>
                        </div>
                    </td>
                </tr>
                <?php endwhile ?>
                <?php if (!$has_rows): ?>
                    <tr class="empty-row"><td colspan="8">📋 No bookings found.</td></tr>
                <?php endif ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <span id="rowCount">Showing all bookings</span>
        </div>
    </div>
</div>

<!-- ADD MODAL -->
<div class="modal-backdrop" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h3>New Booking</h3>
            <button class="modal-close" onclick="closeModal('addModal')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label>Tenant</label>
                    <select name="tenant_id" required>
                        <option value="">— Select Tenant —</option>
                        <?php foreach ($tenants_arr as $t): ?>
                            <option value="<?= $t['tenant_id'] ?>"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Room</label>
                    <select name="room_id" required>
                        <option value="">— Select Room —</option>
                        <?php foreach ($rooms_arr as $r): ?>
                            <option value="<?= $r['room_id'] ?>"><?= htmlspecialchars($r['building_name']) ?> — Room <?= htmlspecialchars($r['room_number']) ?> (<?= $r['room_type'] ?>, Fl.<?= $r['floor'] ?>)</option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Start Date</label><input type="date" name="start_date" required></div>
                    <div class="form-group"><label>End Date</label><input type="date" name="end_date" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Deposit (Rp)</label>
                        <input type="number" name="deposit_amount" placeholder="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" required>
                            <option value="Pending">Pending</option>
                            <option value="Confirmed">Confirmed</option>
                            <option value="Active">Active</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn-submit">＋ Create Booking</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-backdrop" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Edit Booking</h3>
            <button class="modal-close" onclick="closeModal('editModal')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="booking_id" id="editBookingId">
            <input type="hidden" name="old_status" id="editOldStatus">
            <div class="modal-body">
                <div class="form-group">
                    <label>Tenant</label>
                    <select name="tenant_id" id="editTenantId" required>
                        <?php foreach ($tenants_arr as $t): ?>
                            <option value="<?= $t['tenant_id'] ?>"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Room</label>
                    <select name="room_id" id="editRoomId" required>
                        <?php foreach ($rooms_arr as $r): ?>
                            <option value="<?= $r['room_id'] ?>"><?= htmlspecialchars($r['building_name']) ?> — Room <?= htmlspecialchars($r['room_number']) ?> (<?= $r['room_type'] ?>)</option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Start Date</label><input type="date" name="start_date" id="editStartDate" required></div>
                    <div class="form-group"><label>End Date</label><input type="date" name="end_date" id="editEndDate" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Deposit (Rp)</label>
                        <input type="number" name="deposit_amount" id="editDeposit" min="0">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="editStatus" required>
                            <option value="Pending">Pending</option>
                            <option value="Confirmed">Confirmed</option>
                            <option value="Active">Active</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-submit">💾 Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal">
        <div class="modal-header"><h3 style="color:#C62828">Delete Booking</h3><button class="modal-close" onclick="closeModal('deleteModal')">✕</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="booking_id" id="deleteBookingId">
            <div class="modal-body">
                <div class="delete-warning">
                    <div class="warning-icon">⚠️</div>
                    <p>Delete booking for <strong id="deleteBookingTenant"></strong>?<br>Bookings with payments cannot be deleted.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn-danger">🗑️ Delete Booking</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-backdrop').forEach(bd => bd.addEventListener('click', e => { if(e.target===bd) bd.classList.remove('open'); }));
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.modal-backdrop.open').forEach(m=>m.classList.remove('open')); });

function openAddModal() { openModal('addModal'); }

function openEditModal(b) {
    document.getElementById('editBookingId').value  = b.booking_id;
    document.getElementById('editTenantId').value   = b.tenant_id;
    document.getElementById('editRoomId').value     = b.room_id;
    document.getElementById('editStartDate').value  = b.start_date;
    document.getElementById('editEndDate').value    = b.end_date;
    document.getElementById('editDeposit').value    = b.deposit_amount;
    document.getElementById('editStatus').value     = b.status;
    document.getElementById('editOldStatus').value  = b.status;
    openModal('editModal');
}

function openDeleteModal(id, name) {
    document.getElementById('deleteBookingId').value          = id;
    document.getElementById('deleteBookingTenant').textContent = name;
    openModal('deleteModal');
}

function filterTable() {
    const q   = document.getElementById('searchInput').value.toLowerCase();
    const bld = document.getElementById('buildingFilter').value.toLowerCase();
    const rows = document.querySelectorAll('#bookingTable tbody tr:not(.empty-row)');
    let shown = 0;
    rows.forEach(row => {
        const match = (!q || row.dataset.search.includes(q))
                   && (!bld || row.dataset.building === bld);
        row.style.display = match ? '' : 'none';
        if (match) shown++;
    });
    document.getElementById('rowCount').textContent = `Showing ${shown} booking(s)`;
}

document.querySelectorAll('.alert').forEach(el => setTimeout(() => el.style.display='none', 4000));
filterTable();
</script>
</body>
</html>