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
done:

$filter_status = mysqli_real_escape_string($conn, $_GET['status'] ?? '');
$where_status  = $filter_status ? "AND b.status='$filter_status'" : '';

$bookings = mysqli_query($conn, "
    SELECT b.*, t.first_name, t.last_name, t.email,
           r.room_number, r.room_type, r.price_per_month,
           bu.name AS building_name
    FROM booking b
    JOIN room r      ON b.room_id    = r.room_id
    JOIN building bu ON r.building_id = bu.building_id
    JOIN tenant t    ON b.tenant_id  = t.tenant_id
    WHERE bu.admin_id = $admin_id $where_status
    ORDER BY b.booking_date DESC
");

$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) as total,
           SUM(b.status='Active')    as active,
           SUM(b.status='Confirmed') as confirmed,
           SUM(b.status='Completed') as completed
    FROM booking b JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id
    WHERE bu.admin_id=$admin_id
"));

// Only rooms from admin's buildings
$rooms_res = mysqli_query($conn, "SELECT r.room_id,r.room_number,r.room_type,r.floor,b.name AS building_name FROM room r JOIN building b ON r.building_id=b.building_id WHERE b.admin_id=$admin_id ORDER BY b.name,r.room_number");
$rooms_arr = [];
while ($r = mysqli_fetch_assoc($rooms_res)) $rooms_arr[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>KOSTIFY — Bookings</title>
    <link rel="stylesheet" href="../css/bookings.css">
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
        <div><h1>Bookings</h1><p>All reservations in your properties</p></div>
        <div class="topbar-right">
            <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif ?>

    <div class="status-strip">
        <?php
        $cur = $_GET['status'] ?? '';
        foreach ([['','All',$stats['total'],''],['Active','Active',$stats['active'],'s-active'],['Confirmed','Confirmed',$stats['confirmed'],'s-confirmed'],['Completed','Completed',$stats['completed'],'s-completed']] as [$sv,$sl,$sn,$sc]):
        ?>
        <a href="bookings.php<?= $sv?'?status='.$sv:'' ?>" class="strip-card <?= $cur===$sv?'active-filter':'' ?>">
            <div class="strip-num <?= $sc ?>"><?= $sn ?></div>
            <div class="strip-label"><?= $sl ?></div>
        </a>
        <?php endforeach ?>
    </div>

    <div class="toolbar">
        <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" id="searchInput" placeholder="Search tenant or room…" oninput="filterTable()"></div>
        <select class="filter-select" id="buildingFilter" onchange="filterTable()">
            <option value="">All Buildings</option>
            <?php foreach ($rooms_arr as $r): ?><option value="<?= htmlspecialchars($r['building_name']) ?>"><?= htmlspecialchars($r['building_name']) ?></option><?php endforeach ?>
        </select>
    </div>

    <div class="table-card">
        <div class="table-wrap">
            <table id="bookingTable">
                <thead><tr><th>ID</th><th>Tenant</th><th>Room</th><th>Period</th><th>Deposit</th><th>Price/Mo</th><th>Status</th></tr></thead>
                <tbody>
                <?php $has_rows=false; while ($b=mysqli_fetch_assoc($bookings)): $has_rows=true;
                    $d1=new DateTime($b['start_date']); $d2=new DateTime($b['end_date']); $dur=$d1->diff($d2)->days.'d';
                    $bc='badge-'.strtolower($b['status']);
                ?>
                <tr data-search="<?= strtolower($b['first_name'].' '.$b['last_name'].' '.$b['room_number']) ?>" data-building="<?= strtolower(htmlspecialchars($b['building_name'])) ?>">
                    <td><span class="booking-id">#<?= $b['booking_id'] ?></span></td>
                    <td><div style="font-weight:600"><?= htmlspecialchars($b['first_name'].' '.$b['last_name']) ?></div><div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($b['email']) ?></div></td>
                    <td><div style="font-weight:600">Room <?= htmlspecialchars($b['room_number']) ?> <span style="color:var(--muted);font-weight:400">(<?= $b['room_type'] ?>)</span></div><div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($b['building_name']) ?></div></td>
                    <td><div style="font-size:12px"><?= date('d M Y',strtotime($b['start_date'])) ?> → <?= date('d M Y',strtotime($b['end_date'])) ?></div><span class="duration-chip"><?= $dur ?></span></td>
                    <td>Rp <?= number_format($b['deposit_amount'],0,',','.') ?></td>
                    <td>Rp <?= number_format($b['price_per_month'],0,',','.') ?></td>
                    <td><span class="badge <?= $bc ?>"><?= $b['status'] ?></span></td>
                </tr>
                <?php endwhile ?>
                <?php if (!$has_rows): ?><tr class="empty-row"><td colspan="7">📋 No bookings found for your properties.</td></tr><?php endif ?>
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
function filterTable(){
    const q=document.getElementById('searchInput').value.toLowerCase();
    const bld=document.getElementById('buildingFilter').value.toLowerCase();
    const rows=document.querySelectorAll('#bookingTable tbody tr:not(.empty-row)');
    let shown=0;
    rows.forEach(row=>{const match=(!q||row.dataset.search.includes(q))&&(!bld||row.dataset.building===bld);row.style.display=match?'':'none';if(match)shown++});
    document.getElementById('rowCount').textContent=`Showing ${shown} booking(s)`;
}
document.querySelectorAll('.alert').forEach(el=>setTimeout(()=>el.style.display='none',4000));
filterTable();
</script>
</body></html>