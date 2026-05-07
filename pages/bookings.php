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
        $tenant_id  = (int)$_POST['tenant_id'];
        $room_id    = (int)$_POST['room_id'];
        $start_date = mysqli_real_escape_string($conn, $_POST['start_date']);
        $end_date   = mysqli_real_escape_string($conn, $_POST['end_date']);
        $deposit    = (float)$_POST['deposit_amount'];
        $status     = mysqli_real_escape_string($conn, $_POST['status']);

        // Verify room belongs to admin
        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT r.room_id FROM room r JOIN building b ON r.building_id=b.building_id WHERE r.room_id=$room_id AND b.admin_id=$admin_id"));
        if (!$owns) { $error = 'Unauthorized room.'; goto done; }

        $conflict = mysqli_fetch_assoc(mysqli_query($conn, "SELECT booking_id FROM booking WHERE room_id=$room_id AND status NOT IN ('Cancelled','Completed') AND ('$start_date'<=end_date AND '$end_date'>=start_date)"));
        if ($conflict) { $error = 'Room already booked during that period.'; goto done; }

        mysqli_query($conn, "INSERT INTO booking (tenant_id,room_id,booking_date,start_date,end_date,deposit_amount,status) VALUES ($tenant_id,$room_id,CURDATE(),'$start_date','$end_date',$deposit,'$status')");
        if (in_array($status, ['Active','Confirmed'])) mysqli_query($conn, "UPDATE room SET status='Occupied' WHERE room_id=$room_id");
        $success = 'Booking created.';

    } elseif ($action == 'edit') {
        $id         = (int)$_POST['booking_id'];
        $tenant_id  = (int)$_POST['tenant_id'];
        $room_id    = (int)$_POST['room_id'];
        $start_date = mysqli_real_escape_string($conn, $_POST['start_date']);
        $end_date   = mysqli_real_escape_string($conn, $_POST['end_date']);
        $deposit    = (float)$_POST['deposit_amount'];
        $status     = mysqli_real_escape_string($conn, $_POST['status']);
        $old_status = mysqli_real_escape_string($conn, $_POST['old_status']);

        // Verify booking belongs to admin
        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT b.booking_id FROM booking b JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id WHERE b.booking_id=$id AND bu.admin_id=$admin_id"));
        if (!$owns) { $error = 'Unauthorized.'; goto done; }

        mysqli_query($conn, "UPDATE booking SET tenant_id=$tenant_id,room_id=$room_id,start_date='$start_date',end_date='$end_date',deposit_amount=$deposit,status='$status' WHERE booking_id=$id");
        if ($status == 'Active') mysqli_query($conn, "UPDATE room SET status='Occupied' WHERE room_id=$room_id");
        elseif (in_array($status, ['Cancelled','Completed'])) {
            $other = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM booking WHERE room_id=$room_id AND status='Active' AND booking_id!=$id"))['c'];
            if (!$other) mysqli_query($conn, "UPDATE room SET status='Available' WHERE room_id=$room_id");
        }
        $success = 'Booking updated.';

    } elseif ($action == 'delete') {
        $id   = (int)$_POST['booking_id'];
        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT b.booking_id FROM booking b JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id WHERE b.booking_id=$id AND bu.admin_id=$admin_id"));
        if (!$owns) { $error = 'Unauthorized.'; goto done; }
        $pmts = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM payment WHERE booking_id=$id"))['c'];
        if ($pmts > 0) { $error = 'Cannot delete: booking has '.$pmts.' payment(s).'; goto done; }
        mysqli_query($conn, "DELETE FROM booking WHERE booking_id=$id");
        $success = 'Booking deleted.';
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
           SUM(b.status='Pending')   as pending,
           SUM(b.status='Completed') as completed,
           SUM(b.status='Cancelled') as cancelled
    FROM booking b JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id
    WHERE bu.admin_id=$admin_id
"));

// Tenants dropdown — any tenant in the system (admin is creating a booking for them)
$tenants_res = mysqli_query($conn, "SELECT tenant_id,first_name,last_name FROM tenant ORDER BY first_name");
$tenants_arr = [];
while ($t = mysqli_fetch_assoc($tenants_res)) $tenants_arr[] = $t;

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
            <button class="btn-add" onclick="openAddModal()">＋ New Booking</button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif ?>

    <div class="status-strip">
        <?php
        $cur = $_GET['status'] ?? '';
        foreach ([['','All',$stats['total'],''],['Active','Active',$stats['active'],'s-active'],['Confirmed','Confirmed',$stats['confirmed'],'s-confirmed'],['Pending','Pending',$stats['pending'],'s-pending'],['Completed','Completed',$stats['completed'],'s-completed'],['Cancelled','Cancelled',$stats['cancelled'],'s-cancelled']] as [$sv,$sl,$sn,$sc]):
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
                <thead><tr><th>ID</th><th>Tenant</th><th>Room</th><th>Period</th><th>Deposit</th><th>Price/Mo</th><th>Status</th><th>Actions</th></tr></thead>
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
                    <td><div class="actions">
                        <button class="btn-sm btn-sm-edit" onclick='openEditModal(<?= json_encode(["booking_id"=>$b["booking_id"],"tenant_id"=>$b["tenant_id"],"room_id"=>$b["room_id"],"start_date"=>$b["start_date"],"end_date"=>$b["end_date"],"deposit_amount"=>$b["deposit_amount"],"status"=>$b["status"]]) ?>)'>✏️ Edit</button>
                        <button class="btn-sm btn-sm-delete" onclick='openDeleteModal(<?= $b["booking_id"] ?>,<?= json_encode($b["first_name"]." ".$b["last_name"]) ?>)'>🗑️</button>
                    </div></td>
                </tr>
                <?php endwhile ?>
                <?php if (!$has_rows): ?><tr class="empty-row"><td colspan="8">📋 No bookings found for your properties.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer"><span id="rowCount">Showing all bookings</span></div>
    </div>
</div>

<!-- ADD -->
<div class="modal-backdrop" id="addModal"><div class="modal">
    <div class="modal-header"><h3>New Booking</h3><button class="modal-close" onclick="closeModal('addModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="add">
        <div class="modal-body">
            <div class="form-group"><label>Tenant</label><select name="tenant_id" required><option value="">— Select Tenant —</option><?php foreach($tenants_arr as $t): ?><option value="<?= $t['tenant_id'] ?>"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></option><?php endforeach ?></select></div>
            <div class="form-group"><label>Room (Your Properties)</label><select name="room_id" required><option value="">— Select Room —</option><?php foreach($rooms_arr as $r): ?><option value="<?= $r['room_id'] ?>"><?= htmlspecialchars($r['building_name']) ?> — Room <?= htmlspecialchars($r['room_number']) ?> (<?= $r['room_type'] ?>)</option><?php endforeach ?></select></div>
            <div class="form-row">
                <div class="form-group"><label>Start Date</label><input type="date" name="start_date" required></div>
                <div class="form-group"><label>End Date</label><input type="date" name="end_date" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Deposit (Rp)</label><input type="number" name="deposit_amount" value="0" min="0"></div>
                <div class="form-group"><label>Status</label><select name="status" required><option value="Pending">Pending</option><option value="Confirmed">Confirmed</option><option value="Active">Active</option></select></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn-submit">＋ Create Booking</button></div>
    </form>
</div></div>

<!-- EDIT -->
<div class="modal-backdrop" id="editModal"><div class="modal">
    <div class="modal-header"><h3>Edit Booking</h3><button class="modal-close" onclick="closeModal('editModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="booking_id" id="editBookingId"><input type="hidden" name="old_status" id="editOldStatus">
        <div class="modal-body">
            <div class="form-group"><label>Tenant</label><select name="tenant_id" id="editTenantId" required><?php foreach($tenants_arr as $t): ?><option value="<?= $t['tenant_id'] ?>"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></option><?php endforeach ?></select></div>
            <div class="form-group"><label>Room</label><select name="room_id" id="editRoomId" required><?php foreach($rooms_arr as $r): ?><option value="<?= $r['room_id'] ?>"><?= htmlspecialchars($r['building_name']) ?> — Room <?= htmlspecialchars($r['room_number']) ?> (<?= $r['room_type'] ?>)</option><?php endforeach ?></select></div>
            <div class="form-row">
                <div class="form-group"><label>Start Date</label><input type="date" name="start_date" id="editStartDate" required></div>
                <div class="form-group"><label>End Date</label><input type="date" name="end_date" id="editEndDate" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Deposit (Rp)</label><input type="number" name="deposit_amount" id="editDeposit" min="0"></div>
                <div class="form-group"><label>Status</label><select name="status" id="editStatus" required><option value="Pending">Pending</option><option value="Confirmed">Confirmed</option><option value="Active">Active</option><option value="Completed">Completed</option><option value="Cancelled">Cancelled</option></select></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn-submit">💾 Save Changes</button></div>
    </form>
</div></div>

<!-- DELETE -->
<div class="modal-backdrop" id="deleteModal"><div class="modal">
    <div class="modal-header"><h3 style="color:#C62828">Delete Booking</h3><button class="modal-close" onclick="closeModal('deleteModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="booking_id" id="deleteBookingId">
        <div class="modal-body"><div class="delete-warning"><div class="warning-icon">⚠️</div><p>Delete booking for <strong id="deleteBookingTenant"></strong>?<br>Bookings with payments cannot be deleted.</p></div></div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn-danger">🗑️ Delete</button></div>
    </form>
</div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.open').forEach(m=>m.classList.remove('open'))});
function openAddModal(){openModal('addModal')}
function openEditModal(b){document.getElementById('editBookingId').value=b.booking_id;document.getElementById('editTenantId').value=b.tenant_id;document.getElementById('editRoomId').value=b.room_id;document.getElementById('editStartDate').value=b.start_date;document.getElementById('editEndDate').value=b.end_date;document.getElementById('editDeposit').value=b.deposit_amount;document.getElementById('editStatus').value=b.status;document.getElementById('editOldStatus').value=b.status;openModal('editModal')}
function openDeleteModal(id,name){document.getElementById('deleteBookingId').value=id;document.getElementById('deleteBookingTenant').textContent=name;openModal('deleteModal')}
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