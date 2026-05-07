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

    if ($action == 'add') {
        $booking_id     = (int)$_POST['booking_id'];
        $amount         = (float)$_POST['amount'];
        $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
        $payment_date   = mysqli_real_escape_string($conn, $_POST['payment_date']);
        $status         = mysqli_real_escape_string($conn, $_POST['status']);
        $notes          = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

        // Verify booking belongs to admin
        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT b.booking_id FROM booking b JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id WHERE b.booking_id=$booking_id AND bu.admin_id=$admin_id"));
        if (!$owns) { $error = 'Unauthorized booking.'; goto done; }

        mysqli_query($conn, "INSERT INTO payment (booking_id,amount,payment_method,payment_date,status,notes) VALUES ($booking_id,$amount,'$payment_method','$payment_date','$status','$notes')");
        $success = 'Payment recorded.';

    } elseif ($action == 'edit') {
        $id             = (int)$_POST['payment_id'];
        $amount         = (float)$_POST['amount'];
        $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
        $payment_date   = mysqli_real_escape_string($conn, $_POST['payment_date']);
        $status         = mysqli_real_escape_string($conn, $_POST['status']);
        $notes          = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

        // Verify via booking chain
        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p.payment_id FROM payment p JOIN booking b ON p.booking_id=b.booking_id JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id WHERE p.payment_id=$id AND bu.admin_id=$admin_id"));
        if (!$owns) { $error = 'Unauthorized.'; goto done; }

        mysqli_query($conn, "UPDATE payment SET amount=$amount,payment_method='$payment_method',payment_date='$payment_date',status='$status',notes='$notes' WHERE payment_id=$id");
        $success = 'Payment updated.';

    } elseif ($action == 'delete') {
        $id   = (int)$_POST['payment_id'];
        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p.payment_id FROM payment p JOIN booking b ON p.booking_id=b.booking_id JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id WHERE p.payment_id=$id AND bu.admin_id=$admin_id"));
        if (!$owns) { $error = 'Unauthorized.'; goto done; }
        mysqli_query($conn, "DELETE FROM payment WHERE payment_id=$id");
        $success = 'Payment deleted.';
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

// ── Active bookings with NO payment (unpaid tenants) ──
$unpaid = mysqli_query($conn, "
    SELECT b.booking_id, b.start_date, b.end_date, r.price_per_month,
           t.first_name, t.last_name, t.email, t.phone,
           r.room_number, bu.name AS building_name,
           COALESCE(SUM(p.amount),0) AS paid_total,
           DATEDIFF(CURDATE(), b.start_date) AS days_since_start
    FROM booking b
    JOIN room r     ON b.room_id    = r.room_id
    JOIN building bu ON r.building_id = bu.building_id
    JOIN tenant t   ON b.tenant_id  = t.tenant_id
    LEFT JOIN payment p ON p.booking_id = b.booking_id AND p.status = 'Completed'
    WHERE bu.admin_id = $admin_id AND b.status = 'Active'
    GROUP BY b.booking_id
    HAVING paid_total = 0 OR paid_total < r.price_per_month
    ORDER BY days_since_start DESC
");

// ── Stats ──
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(CASE WHEN p.status='Completed' THEN p.amount ELSE 0 END),0) AS total_received,
           COALESCE(SUM(CASE WHEN p.status='Pending'   THEN p.amount ELSE 0 END),0) AS total_pending,
           COUNT(CASE WHEN p.status='Completed' THEN 1 END)                           AS count_paid,
           COUNT(CASE WHEN p.status='Pending'   THEN 1 END)                           AS count_pending
    FROM payment p JOIN booking b ON p.booking_id=b.booking_id JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id
    WHERE bu.admin_id=$admin_id
"));

$unpaid_count = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(DISTINCT b.booking_id) as c
    FROM booking b JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id
    LEFT JOIN payment p ON p.booking_id=b.booking_id AND p.status='Completed'
    WHERE bu.admin_id=$admin_id AND b.status='Active'
    GROUP BY b.booking_id HAVING COALESCE(SUM(p.amount),0)=0
"))['c'] ?? 0;

// ── Bookings dropdown (only admin's) ──
$bookings_res = mysqli_query($conn, "SELECT b.booking_id,t.first_name,t.last_name,r.room_number,bu.name AS building_name FROM booking b JOIN tenant t ON b.tenant_id=t.tenant_id JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id WHERE bu.admin_id=$admin_id AND b.status IN ('Active','Confirmed') ORDER BY b.booking_id DESC");
$bookings_arr = [];
while ($bk = mysqli_fetch_assoc($bookings_res)) $bookings_arr[] = $bk;
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
            <button class="btn-add" onclick="openAddModal()">＋ Record Payment</button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif ?>

    <div class="stats">
        <div class="stat-card"><div class="stat-icon">💰</div><div><div class="stat-label">Total Received</div><div class="stat-value" style="font-size:16px">Rp <?= number_format($stats['total_received'],0,',','.') ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">⏳</div><div><div class="stat-label">Pending</div><div class="stat-value" style="font-size:16px">Rp <?= number_format($stats['total_pending'],0,',','.') ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">✅</div><div><div class="stat-label">Paid Transactions</div><div class="stat-value"><?= $stats['count_paid'] ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">🚨</div><div><div class="stat-label">Unpaid Tenants</div><div class="stat-value" style="color:var(--rose)"><?= $unpaid_count ?></div></div></div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('unpaid',this)">🚨 Unpaid Tenants</button>
        <button class="tab-btn" onclick="switchTab('history',this)">📜 Payment History</button>
    </div>

    <!-- UNPAID TENANTS TAB -->
    <div id="tab-unpaid" class="tab-panel active">
        <div class="unpaid-banner">
            <div class="unpaid-banner-head">
                <span>⚠️</span>
                <h3>Tenants With Outstanding Balance</h3>
                <span class="count"><?php mysqli_data_seek($unpaid,0); $uc=mysqli_num_rows($unpaid); echo $uc; ?> tenant(s)</span>
            </div>
            <div class="unpaid-table">
                <table>
                    <thead><tr><th>Tenant</th><th>Room</th><th>Building</th><th>Monthly Rent</th><th>Paid So Far</th><th>Outstanding</th><th>Days Active</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php
                    mysqli_data_seek($unpaid,0);
                    $has=false;
                    while ($u=mysqli_fetch_assoc($unpaid)):
                        $has=true;
                        $outstanding=$u['price_per_month']-$u['paid_total'];
                        $days=(int)$u['days_since_start'];
                    ?>
                    <tr>
                        <td><div style="font-weight:600"><?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?></div><div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($u['email']) ?></div></td>
                        <td>Room <?= htmlspecialchars($u['room_number']) ?></td>
                        <td><?= htmlspecialchars($u['building_name']) ?></td>
                        <td>Rp <?= number_format($u['price_per_month'],0,',','.') ?></td>
                        <td style="color:#2E7D32;font-weight:600">Rp <?= number_format($u['paid_total'],0,',','.') ?></td>
                        <td style="color:#C62828;font-weight:700">Rp <?= number_format($outstanding,0,',','.') ?></td>
                        <td><?= $days > 30 ? '<span class="overdue-chip">⚠️ '.$days.'d overdue</span>' : '<span class="days-chip">'.$days.'d</span>' ?></td>
                        <td><button class="btn-sm btn-sm-record" onclick='openAddModalForBooking(<?= $u["booking_id"] ?>,<?= json_encode($u["first_name"]." ".$u["last_name"]) ?>,<?= $u["price_per_month"] ?>)'>💳 Record</button></td>
                    </tr>
                    <?php endwhile ?>
                    <?php if(!$has): ?><tr class="empty-row"><td colspan="8">✅ All active tenants have paid their rent!</td></tr><?php endif ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PAYMENT HISTORY TAB -->
    <div id="tab-history" class="tab-panel">
        <div class="toolbar">
            <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" id="searchInput" placeholder="Search tenant or room…" oninput="filterPayments()"></div>
            <select class="filter-select" id="statusFilter" onchange="filterPayments()">
                <option value="">All Status</option>
                <option value="Completed" <?= $filter_status=='Completed'?'selected':'' ?>>Completed</option>
                <option value="Pending"   <?= $filter_status=='Pending'?'selected':'' ?>>Pending</option>
                <option value="Failed"    <?= $filter_status=='Failed'?'selected':'' ?>>Failed</option>
                <option value="Refunded"  <?= $filter_status=='Refunded'?'selected':'' ?>>Refunded</option>
            </select>
        </div>
        <div class="table-card">
            <div class="table-wrap">
                <table id="paymentTable">
                    <thead><tr><th>#</th><th>Tenant</th><th>Room</th><th>Date</th><th>Amount</th><th>Method</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead>
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
                        <td style="color:var(--muted);font-size:12px"><?= htmlspecialchars($p['notes']?:'—') ?></td>
                        <td><div class="actions">
                            <button class="btn-sm btn-sm-edit" onclick='openEditModal(<?= json_encode(["payment_id"=>$p["payment_id"],"booking_id"=>$p["booking_id"],"amount"=>$p["amount"],"payment_method"=>$p["payment_method"],"payment_date"=>$p["payment_date"],"status"=>$p["status"],"notes"=>$p["notes"]??""]) ?>)'>✏️</button>
                            <button class="btn-sm btn-sm-delete" onclick='openDeleteModal(<?= $p["payment_id"] ?>)'>🗑️</button>
                        </div></td>
                    </tr>
                    <?php endwhile ?>
                    <?php if(!$has_rows): ?><tr class="empty-row"><td colspan="9">💳 No payment records yet.</td></tr><?php endif ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer"><span id="rowCount">Showing all payments</span></div>
        </div>
    </div>
</div>

<!-- ADD MODAL -->
<div class="modal-backdrop" id="addModal"><div class="modal">
    <div class="modal-header"><h3>Record Payment</h3><button class="modal-close" onclick="closeModal('addModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="add">
        <div class="modal-body">
            <div class="form-group"><label>Booking</label><select name="booking_id" id="addBookingId" required><option value="">— Select Booking —</option><?php foreach($bookings_arr as $bk): ?><option value="<?= $bk['booking_id'] ?>">#<?= $bk['booking_id'] ?> — <?= htmlspecialchars($bk['first_name'].' '.$bk['last_name']) ?> (Room <?= htmlspecialchars($bk['room_number']) ?>, <?= htmlspecialchars($bk['building_name']) ?>)</option><?php endforeach ?></select></div>
            <div class="form-row">
                <div class="form-group"><label>Amount (Rp)</label><input type="number" name="amount" id="addAmount" min="0" required></div>
                <div class="form-group"><label>Payment Date</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Method</label><select name="payment_method" required><option value="Cash">Cash</option><option value="Transfer">Transfer</option><option value="QRIS">QRIS</option><option value="Debit">Debit</option><option value="Credit">Credit</option></select></div>
                <div class="form-group"><label>Status</label><select name="status" required><option value="Completed">Completed</option><option value="Pending">Pending</option><option value="Failed">Failed</option></select></div>
            </div>
            <div class="form-group"><label>Notes <span style="text-transform:none;letter-spacing:0;font-weight:400">(optional)</span></label><textarea name="notes" placeholder="e.g. Rent for June 2026…"></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn-submit">💾 Save Payment</button></div>
    </form>
</div></div>

<!-- EDIT MODAL -->
<div class="modal-backdrop" id="editModal"><div class="modal">
    <div class="modal-header"><h3>Edit Payment</h3><button class="modal-close" onclick="closeModal('editModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="payment_id" id="editPaymentId">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label>Amount (Rp)</label><input type="number" name="amount" id="editAmount" min="0" required></div>
                <div class="form-group"><label>Payment Date</label><input type="date" name="payment_date" id="editPaymentDate" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Method</label><select name="payment_method" id="editPaymentMethod"><option value="Cash">Cash</option><option value="Transfer">Transfer</option><option value="QRIS">QRIS</option><option value="Debit">Debit</option><option value="Credit">Credit</option></select></div>
                <div class="form-group"><label>Status</label><select name="status" id="editPaymentStatus"><option value="Completed">Completed</option><option value="Pending">Pending</option><option value="Failed">Failed</option><option value="Refunded">Refunded</option></select></div>
            </div>
            <div class="form-group"><label>Notes</label><textarea name="notes" id="editNotes"></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn-submit">💾 Save Changes</button></div>
    </form>
</div></div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteModal"><div class="modal">
    <div class="modal-header"><h3 style="color:#C62828">Delete Payment</h3><button class="modal-close" onclick="closeModal('deleteModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="payment_id" id="deletePaymentId">
        <div class="modal-body"><div class="delete-warning"><div class="warning-icon">⚠️</div><p>Permanently delete this payment record?</p></div></div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn-danger">🗑️ Delete</button></div>
    </form>
</div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.open').forEach(m=>m.classList.remove('open'))});
function openAddModal(){openModal('addModal')}
function openAddModalForBooking(bookingId, name, amount){
    document.getElementById('addBookingId').value=bookingId;
    document.getElementById('addAmount').value=amount;
    openModal('addModal');
}
function openEditModal(p){document.getElementById('editPaymentId').value=p.payment_id;document.getElementById('editAmount').value=p.amount;document.getElementById('editPaymentDate').value=p.payment_date;document.getElementById('editPaymentMethod').value=p.payment_method;document.getElementById('editPaymentStatus').value=p.status;document.getElementById('editNotes').value=p.notes;openModal('editModal')}
function openDeleteModal(id){document.getElementById('deletePaymentId').value=id;openModal('deleteModal')}
function switchTab(name, btn){
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('tab-'+name).classList.add('active');
    btn.classList.add('active');
}
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