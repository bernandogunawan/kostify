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

    if ($action == 'update_status') {
        $id          = (int)$_POST['maintenance_id'];
        $status      = mysqli_real_escape_string($conn, $_POST['status']);
        $employee_id = $_POST['employee_id'] ? (int)$_POST['employee_id'] : 'NULL';

        // Verify room belongs to admin
        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT m.maintenance_id FROM maintenance m JOIN room r ON m.room_id=r.room_id JOIN building b ON r.building_id=b.building_id WHERE m.maintenance_id=$id AND b.admin_id=$admin_id"));
        if (!$owns) { $error = 'Unauthorized.'; goto done; }

        mysqli_query($conn, "UPDATE maintenance SET status='$status', employee_id=$employee_id WHERE maintenance_id=$id");
        $success = 'Request updated.';

    } elseif ($action == 'delete') {
        $id   = (int)$_POST['maintenance_id'];
        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT m.maintenance_id FROM maintenance m JOIN room r ON m.room_id=r.room_id JOIN building b ON r.building_id=b.building_id WHERE m.maintenance_id=$id AND b.admin_id=$admin_id"));
        if (!$owns) { $error = 'Unauthorized.'; goto done; }
        mysqli_query($conn, "DELETE FROM maintenance WHERE maintenance_id=$id");
        $success = 'Request deleted.';
    }
}
done:

$filter_status = mysqli_real_escape_string($conn, $_GET['status'] ?? '');
$where_status  = $filter_status ? "AND m.status='$filter_status'" : '';

$requests = mysqli_query($conn, "
    SELECT m.*, r.room_number, r.floor,
           b.name AS building_name,
           t.first_name AS t_first, t.last_name AS t_last, t.email AS t_email,
           e.first_name AS e_first, e.last_name AS e_last, e.role AS e_role
    FROM maintenance m
    JOIN room r     ON m.room_id     = r.room_id
    JOIN building b ON r.building_id = b.building_id
    LEFT JOIN booking bk ON bk.room_id = r.room_id AND bk.status='Active'
    LEFT JOIN tenant t   ON bk.tenant_id = t.tenant_id
    LEFT JOIN employee e ON m.employee_id = e.employee_id
    WHERE b.admin_id = $admin_id $where_status
    ORDER BY FIELD(m.status,'Pending','In Progress','Completed') , m.request_date DESC
");

$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total,
           SUM(m.status='Pending')     AS pending,
           SUM(m.status='In Progress') AS in_progress,
           SUM(m.status='Completed')   AS completed
    FROM maintenance m JOIN room r ON m.room_id=r.room_id JOIN building b ON r.building_id=b.building_id
    WHERE b.admin_id=$admin_id
"));

// Employees for assignment dropdown (only admin's buildings employees)
$employees_res = mysqli_query($conn, "SELECT e.employee_id,e.first_name,e.last_name,e.role FROM employee e WHERE e.employer=$admin_id ORDER BY e.first_name");
$employees_arr = [];
while ($emp = mysqli_fetch_assoc($employees_res)) $employees_arr[] = $emp;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>KOSTIFY — Maintenance</title>
    <link href="../css/maintenance.css" rel="stylesheet">
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
        <a href="payments.php"><span class="icon">💳</span> Payments</a>
        <a href="maintenance.php" class="active"><span class="icon">🔧</span> Maintenance</a>
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
        <div><h1>Maintenance</h1><p>Repair requests from your tenants</p></div>
        <div class="topbar-right"><div class="topbar-date">📅 <?= date('D, d M Y') ?></div></div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif ?>

    <div class="stats">
        <div class="stat-card"><div class="stat-icon">📋</div><div><div class="stat-label">Total</div><div class="stat-value"><?= $stats['total'] ?? 0 ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">⏳</div><div><div class="stat-label">Pending</div><div class="stat-value" style="color:#F57F17"><?= $stats['pending'] ?? 0 ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">🔨</div><div><div class="stat-label">In Progress</div><div class="stat-value" style="color:#1565C0"><?= $stats['in_progress'] ?? 0 ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">✅</div><div><div class="stat-label">Completed</div><div class="stat-value" style="color:#2E7D32"><?= $stats['completed'] ?? 0 ?></div></div></div>

    </div>

    <div class="filter-strip">
        <?php $cur=$_GET['status']??''; ?>
        <a href="maintenance.php" class="filter-btn <?= $cur===''?'active':'' ?>">All (<?= $stats['total'] ?? 0 ?>)</a>
        <a href="maintenance.php?status=Pending" class="filter-btn f-pending <?= $cur==='Pending'?'active':'' ?>">⏳ Pending (<?= $stats['pending'] ?? 0 ?>)</a>
        <a href="maintenance.php?status=In Progress" class="filter-btn f-progress <?= $cur==='In Progress'?'active':'' ?>">🔨 In Progress (<?= $stats['in_progress'] ?? 0 ?>)</a>
        <a href="maintenance.php?status=Completed" class="filter-btn f-done <?= $cur==='Completed'?'active':'' ?>">✅ Completed (<?= $stats['completed'] ?? 0 ?>)</a>
    </div>

    <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" id="searchInput" placeholder="Search room or issue…" oninput="filterCards()"></div>

    <div class="requests-grid" id="requestsGrid">
    <?php
    $has=false;
    while ($m=mysqli_fetch_assoc($requests)):
        $has=true;
        $bc='badge-'.strtolower(str_replace(' ','-',$m['status']));
        $card_class = $m['status']=='Pending' ? 'priority-high' : ($m['status']=='In Progress' ? 'priority-medium' : 'priority-low');
    ?>
    <div class="req-card <?= $card_class ?>"
         data-search="<?= strtolower(htmlspecialchars($m['room_number'].' '.$m['issue_description'])) ?>">
        <div class="req-card-head">
            <div>
                <div class="req-room">Room <?= htmlspecialchars($m['room_number']) ?> · Floor <?= $m['floor'] ?></div>
                <div class="req-building">🏢 <?= htmlspecialchars($m['building_name']) ?></div>
            </div>
            <div style="text-align:right">
                <span class="badge <?= $bc ?>"><?= $m['status'] ?></span>
            </div>
        </div>
        <div class="req-body">
            <div class="req-issue">🔧 <?= htmlspecialchars($m['issue_description']) ?></div>
            <div class="req-meta">
                <span>📅 Requested: <strong><?= date('d M Y',strtotime($m['request_date'])) ?></strong></span>
                <?php if ($m['t_first']): ?><span>👤 Tenant: <strong><?= htmlspecialchars($m['t_first'].' '.$m['t_last']) ?></strong></span><?php endif ?>
                <?php if ($m['e_first']): ?><span>👷 Assigned: <strong><?= htmlspecialchars($m['e_first'].' '.$m['e_last']) ?></strong> (<?= htmlspecialchars($m['e_role']) ?>)</span><?php endif ?>
            </div>
        </div>
        <div class="req-foot">
            <button class="btn-sm btn-sm-edit" onclick='openUpdateModal(<?= json_encode(["maintenance_id"=>$m["maintenance_id"],"status"=>$m["status"],"employee_id"=>$m["employee_id"]??""]) ?>,<?= json_encode("Room ".$m["room_number"].": ".$m["issue_description"]) ?>)'>✏️ Update Status</button>
            <button class="btn-sm btn-sm-delete" onclick='openDeleteModal(<?= $m["maintenance_id"] ?>)'>🗑️</button>
        </div>
    </div>
    <?php endwhile ?>
    <?php if(!$has): ?>
    <div class="empty-state" style="grid-column:1/-1">
        <div class="empty-icon">🔧</div>
        <h3>No Maintenance Requests</h3>
        <p>Your tenants haven't submitted any repair requests<?= $filter_status ? ' with this status' : '' ?>.</p>
    </div>
    <?php endif ?>
    </div>
</div>

<!-- UPDATE MODAL -->
<div class="modal-backdrop" id="updateModal"><div class="modal">
    <div class="modal-header"><h3>Update Request</h3><button class="modal-close" onclick="closeModal('updateModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="update_status"><input type="hidden" name="maintenance_id" id="updateId">
        <div class="modal-body">
            <div class="issue-preview-label">Issue</div>
            <div class="issue-preview" id="updateIssuePreview"></div>
            <div class="form-row">
                <div class="form-group"><label>Status</label><select name="status" id="updateStatus" required><option value="Pending">Pending</option><option value="In Progress">In Progress</option><option value="Completed">Completed</option><option value="Cancelled">Cancelled</option></select></div>
                <div class="form-group"><label>Assign Employee</label>
                    <select name="employee_id" id="updateEmployee">
                        <option value="">— Unassigned —</option>
                        <?php foreach($employees_arr as $emp): ?><option value="<?= $emp['employee_id'] ?>"><?= htmlspecialchars($emp['first_name'].' '.$emp['last_name']) ?> (<?= htmlspecialchars($emp['role']) ?>)</option><?php endforeach ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('updateModal')">Cancel</button><button type="submit" class="btn-submit">💾 Save Update</button></div>
    </form>
</div></div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteModal"><div class="modal">
    <div class="modal-header"><h3 style="color:#C62828">Delete Request</h3><button class="modal-close" onclick="closeModal('deleteModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="maintenance_id" id="deleteMainId">
        <div class="modal-body"><div class="delete-warning"><div class="warning-icon">⚠️</div><p>Permanently delete this maintenance request?</p></div></div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn-danger">🗑️ Delete</button></div>
    </form>
</div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.open').forEach(m=>m.classList.remove('open'))});
function openUpdateModal(m, issue){
    document.getElementById('updateId').value=m.maintenance_id;
    document.getElementById('updateStatus').value=m.status;
    document.getElementById('updateEmployee').value=m.employee_id||'';
    document.getElementById('updateIssuePreview').textContent=issue;
    openModal('updateModal');
}
function openDeleteModal(id){document.getElementById('deleteMainId').value=id;openModal('deleteModal')}
function filterCards(){
    const q=document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.req-card').forEach(c=>{c.style.display=!q||c.dataset.search.includes(q)?'':'none'});
}
document.querySelectorAll('.alert').forEach(el=>setTimeout(()=>el.style.display='none',4000));
</script>
</body></html>