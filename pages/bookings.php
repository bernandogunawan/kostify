<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Location: ../auth/login.php?mode=admin'); exit;
}

$admin_id = (int)$_SESSION['user_id'];
$success  = '';
$error    = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['add', 'edit', 'delete'], true)) {
        $error = 'Booking create/update/delete is disabled for admin.';
    }
}

$bookings = mysqli_query($conn, "
    SELECT b.*, t.first_name, t.last_name, t.email,
           r.room_number, r.room_type, r.price_per_month,
           bu.name AS building_name, bu.building_id
    FROM booking b
    JOIN room r      ON b.room_id    = r.room_id
    JOIN building bu ON r.building_id = bu.building_id
    JOIN tenant t    ON b.tenant_id  = t.tenant_id
    WHERE bu.admin_id = $admin_id AND b.status IN ('Active','Completed')
    ORDER BY b.booking_date DESC
");

$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(b.status='Active')    AS active_count,
        SUM(b.status='Completed') AS completed_count
    FROM booking b JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id
    WHERE bu.admin_id=$admin_id AND b.status IN ('Active','Completed')
"));

$buildings_filter = [];
$bf = mysqli_query($conn, "SELECT building_id, name FROM building WHERE admin_id=$admin_id ORDER BY name");
while ($row = mysqli_fetch_assoc($bf)) {
    $buildings_filter[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>KOSTIFY — Bookings</title>
    <link rel="stylesheet" href="../css/bookings.css">
    <style>
        .status-strip { display: flex; gap: 16px; margin-bottom: 24px; }
        .s-active    { color: var(--rose); }
        .s-completed { color: #2E7D32; }

        /* ── Status filter buttons in toolbar ── */
        .status-btns { display: flex; gap: 8px; }

        .status-btn {
            padding: 9px 18px;
            border-radius: 9px;
            border: 1.5px solid var(--border);
            background: #fff;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            transition: all .2s;
        }

        .status-btn--active.active-filter {
            background: #E3F2FD;
            border-color: #1565C0;
            color: #1565C0;
        }

        .status-btn--completed.active-filter {
            background: #E8F5E9;
            border-color: #2E7D32;
            color: #2E7D32;
        }

        .status-btn:hover { border-color: var(--rose); color: var(--rose); }
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
        <a href="payments.php"><span class="icon">💳</span> Payments</a>
        <a href="maintenance.php"><span class="icon">🔧</span> Maintenance</a>
        <a href="employees.php"><span class="icon">👨‍💼</span> Employees</a>
    </nav>
    <div class="sidebar-bottom">
        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'],0,1)) ?></div>
            <div><div class="user-name"><?= htmlspecialchars($_SESSION['user_name']) ?></div><div class="user-role">Administrator</div></div>
        </div>
        <a href="../auth/logout.php" class="logout-btn">🚪 Logout</a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div><h1>Bookings</h1><p>Active &amp; completed leases on your properties</p></div>
        <div class="topbar-right">
            <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif ?>

    <div class="status-strip">
        <div class="strip-card strip-card--static">
            <div class="strip-num s-active"><?= (int)($stats['active_count'] ?? 0) ?></div>
            <div class="strip-label">Active bookings</div>
        </div>
        <div class="strip-card strip-card--static">
            <div class="strip-num s-completed"><?= (int)($stats['completed_count'] ?? 0) ?></div>
            <div class="strip-label">Completed bookings</div>
        </div>
    </div>

    <div class="toolbar">
        <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" id="searchInput" placeholder="Search tenant or room…" oninput="filterTable()"></div>
        <select class="filter-select" id="buildingFilter" onchange="filterTable()">
            <option value="">All Buildings</option>
            <?php foreach ($buildings_filter as $bfrow): ?>
                <option value="<?= (int)$bfrow['building_id'] ?>"><?= htmlspecialchars($bfrow['name']) ?></option>
            <?php endforeach ?>
        </select>
        <div class="status-btns">
            <button class="status-btn status-btn--active" id="btn-active" onclick="setStatusFilter('active', this)">🔵 Active</button>
            <button class="status-btn status-btn--completed" id="btn-completed" onclick="setStatusFilter('completed', this)">✅ Completed</button>
        </div>
    </div>

    <div class="table-card">
        <div class="table-wrap">
            <table id="bookingTable">
                <thead><tr><th>ID</th><th>Tenant</th><th>Room</th><th>Period</th><th>Price/Mo</th><th>Status</th></tr></thead>
                <tbody>
                <?php $has_rows=false; while ($b=mysqli_fetch_assoc($bookings)): $has_rows=true;
                    $d1=new DateTime($b['start_date']); $d2=new DateTime($b['end_date']); $dur=$d1->diff($d2)->days.'d';
                    $bc='badge-'.strtolower($b['status']);
                ?>
                <tr data-search="<?= strtolower($b['first_name'].' '.$b['last_name'].' '.$b['room_number']) ?>" data-building-id="<?= (int)$b['building_id'] ?>" data-status="<?= strtolower($b['status']) ?>">
                    <td><span class="booking-id">#<?= $b['booking_id'] ?></span></td>
                    <td><div style="font-weight:600"><?= htmlspecialchars($b['first_name'].' '.$b['last_name']) ?></div><div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($b['email']) ?></div></td>
                    <td><div style="font-weight:600">Room <?= htmlspecialchars($b['room_number']) ?> <span style="color:var(--muted);font-weight:400">(<?= $b['room_type'] ?>)</span></div><div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($b['building_name']) ?></div></td>
                    <td><div style="font-size:12px"><?= date('d M Y',strtotime($b['start_date'])) ?> → <?= date('d M Y',strtotime($b['end_date'])) ?></div><span class="duration-chip"><?= $dur ?></span></td>
                    <td>Rp <?= number_format($b['price_per_month'],0,',','.') ?></td>
                    <td><span class="badge <?= $bc ?>"><?= $b['status'] ?></span></td>
                </tr>
                <?php endwhile ?>
                <?php if (!$has_rows): ?><tr class="empty-row"><td colspan="6">📋 No bookings found.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer"><span id="rowCount">Showing all bookings</span></div>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.open').forEach(m=>m.classList.remove('open'))});

let activeStatusFilter = ''; // default to all

function setStatusFilter(status, el) {
    activeStatusFilter = status;
    document.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active-filter'));
    el.classList.add('active-filter');
    filterTable();
}

function filterTable(){
    const q   = document.getElementById('searchInput').value.toLowerCase();
    const bld = document.getElementById('buildingFilter').value;
    const rows = document.querySelectorAll('#bookingTable tbody tr:not(.empty-row)');
    let shown = 0;
    rows.forEach(row => {
        const matchSearch   = !q   || row.dataset.search.includes(q);
        const matchBuilding = !bld || row.dataset.buildingId === bld;
        const matchStatus   = !activeStatusFilter || row.dataset.status === activeStatusFilter;
        const visible = matchSearch && matchBuilding && matchStatus;
        row.style.display = visible ? '' : 'none';
        if (visible) shown++;
    });
    document.getElementById('rowCount').textContent = `Showing ${shown} booking(s)`;
}
document.querySelectorAll('.alert').forEach(el=>setTimeout(()=>el.style.display='none',4000));
filterTable();
</script>
</body></html>