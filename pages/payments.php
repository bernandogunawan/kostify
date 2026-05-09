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
        $error = 'Payment create/update/delete is disabled for admin.';
    }
}
done:

$filter_status = mysqli_real_escape_string($conn, $_GET['status'] ?? '');
$where_status  = $filter_status ? "AND p.status='$filter_status'" : '';

// ── All payments for admin's buildings ──
$payments = mysqli_query($conn, "
    SELECT p.*, b.booking_id, b.start_date, b.end_date, b.status AS booking_status,
           t.first_name, t.last_name, t.email,
           r.room_number, r.room_type, bu.name AS building_name
    FROM payment p
    JOIN booking b  ON p.booking_id = b.booking_id
    JOIN room r     ON b.room_id    = r.room_id
    JOIN building bu ON r.building_id = bu.building_id
    JOIN tenant t   ON b.tenant_id  = t.tenant_id
    WHERE bu.admin_id = $admin_id $where_status
    ORDER BY p.payment_date DESC
");

// ── Stats ──
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(CASE WHEN p.status='Completed' THEN p.amount ELSE 0 END),0) AS total_received,
           COUNT(CASE WHEN p.status='Completed' THEN 1 END)                           AS count_paid
    FROM payment p JOIN booking b ON p.booking_id=b.booking_id JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id
    WHERE bu.admin_id=$admin_id
"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>KOSTIFY — Payments</title>
    <link href="../css/payments.css" rel="stylesheet">
</head>
<body>
<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>ADMIN PANEL</span></div>
    <nav class="nav">
        <a href="admin_dashboard.php"><span class="icon">🏠</span> Dashboard</a>
        <a href="buildings.php"><span class="icon">🏢</span> Buildings</a>
        <a href="rooms.php"><span class="icon">🚪</span> Rooms</a>
        <a href="tenants.php"><span class="icon">👥</span> Tenants</a>
        <a href="bookings.php"><span class="icon">📋</span> Bookings</a>
        <a href="payments.php" class="active"><span class="icon">💳</span> Payments</a>
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
        <div><h1>Payments</h1><p>Track rent collection and outstanding balances</p></div>
        <div class="topbar-right">
            <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif ?>

    <div class="stats">
        <div class="stat-card"><div class="stat-icon">💰</div><div><div class="stat-label">Total Received</div><div class="stat-value" style="font-size:16px">Rp <?= number_format($stats['total_received'],0,',','.') ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">✅</div><div><div class="stat-label">Paid Transactions</div><div class="stat-value"><?= $stats['count_paid'] ?></div></div></div>
    </div>

    <!-- PAYMENT HISTORY -->
    <div id="tab-history" class="tab-panel active">
        <div class="toolbar">
            <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" id="searchInput" placeholder="Search tenant or room…" oninput="filterPayments()"></div>
            <select class="filter-select" id="statusFilter" onchange="filterPayments()">
                <option value="">All Status</option>
                <option value="Completed" <?= $filter_status=='Completed'?'selected':'' ?>>Completed</option>
                <option value="Failed"    <?= $filter_status=='Failed'?'selected':'' ?>>Failed</option>
                <option value="Refunded"  <?= $filter_status=='Refunded'?'selected':'' ?>>Refunded</option>
            </select>
        </div>
        <div class="table-card">
            <div class="table-wrap">
                <table id="paymentTable">
                    <thead><tr><th>#</th><th>Tenant</th><th>Room</th><th>Date</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php $i=1; $has_rows=false; mysqli_data_seek($payments,0); while ($p=mysqli_fetch_assoc($payments)): $has_rows=true; $bc='badge-'.strtolower($p['status']); ?>
                    <tr data-search="<?= strtolower($p['first_name'].' '.$p['last_name'].' '.$p['room_number']) ?>" data-status="<?= strtolower($p['status']) ?>">
                        <td style="color:var(--muted)"><?= $i++ ?></td>
                        <td><div style="font-weight:600"><?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?></div><div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($p['building_name']) ?></div></td>
                        <td>Room <?= htmlspecialchars($p['room_number']) ?></td>
                        <td><?= date('d M Y',strtotime($p['payment_date'])) ?></td>
                        <td style="font-weight:700">Rp <?= number_format($p['amount'],0,',','.') ?></td>
                        <td><?= htmlspecialchars($p['payment_method']) ?></td>
                        <td><span class="badge <?= $bc ?>"><?= $p['status'] ?></span></td>
                    </tr>
                    <?php endwhile ?>
                    <?php if(!$has_rows): ?><tr class="empty-row"><td colspan="7">💳 No payment records yet.</td></tr><?php endif ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer"><span id="rowCount">Showing all payments</span></div>
        </div>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.open').forEach(m=>m.classList.remove('open'))});
function filterPayments(){
    const q=document.getElementById('searchInput').value.toLowerCase();
    const s=document.getElementById('statusFilter').value.toLowerCase();
    const rows=document.querySelectorAll('#paymentTable tbody tr:not(.empty-row)');
    let shown=0;
    rows.forEach(row=>{const match=(!q||row.dataset.search.includes(q))&&(!s||row.dataset.status===s);row.style.display=match?'':'none';if(match)shown++});
    document.getElementById('rowCount').textContent=`Showing ${shown} payment(s)`;
}
document.querySelectorAll('.alert').forEach(el=>setTimeout(()=>el.style.display='none',4000));
</script>
</body></html>