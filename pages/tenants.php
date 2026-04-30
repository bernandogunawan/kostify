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
        $first = mysqli_real_escape_string($conn, $_POST['first_name']);
        $last  = mysqli_real_escape_string($conn, $_POST['last_name']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $pass  = $_POST['password'];

        $dup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT tenant_id FROM tenant WHERE email='$email'"));
        if ($dup) { $error = 'Email already registered.'; }
        elseif (strlen($pass) < 8) { $error = 'Password must be at least 8 characters.'; }
        else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            mysqli_query($conn, "INSERT INTO tenant (first_name,last_name,email,phone,password) VALUES ('$first','$last','$email','$phone','$hash')");
            $success = 'Tenant added successfully.';
        }

    } elseif ($action == 'edit') {
        $id    = (int)$_POST['tenant_id'];
        // Verify this tenant belongs to admin's buildings
        $owns  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT t.tenant_id FROM tenant t JOIN booking b ON b.tenant_id=t.tenant_id JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id WHERE t.tenant_id=$id AND bu.admin_id=$admin_id LIMIT 1"));
        if (!$owns) { $error = 'Unauthorized.'; goto done; }

        $first = mysqli_real_escape_string($conn, $_POST['first_name']);
        $last  = mysqli_real_escape_string($conn, $_POST['last_name']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);

        $dup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT tenant_id FROM tenant WHERE email='$email' AND tenant_id!=$id"));
        if ($dup) { $error = 'Email already in use.'; goto done; }

        $sql = "UPDATE tenant SET first_name='$first',last_name='$last',email='$email',phone='$phone'";
        if (!empty($_POST['password'])) {
            if (strlen($_POST['password']) < 8) { $error = 'Password must be at least 8 characters.'; goto done; }
            $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $sql .= ",password='$hash'";
        }
        mysqli_query($conn, "$sql WHERE tenant_id=$id");
        $success = 'Tenant updated.';

    } elseif ($action == 'delete') {
        $id   = (int)$_POST['tenant_id'];
        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT t.tenant_id FROM tenant t JOIN booking b ON b.tenant_id=t.tenant_id JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id WHERE t.tenant_id=$id AND bu.admin_id=$admin_id LIMIT 1"));
        if (!$owns) { $error = 'Unauthorized.'; goto done; }

        $bookings = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM booking WHERE tenant_id=$id"))['c'];
        if ($bookings > 0) { $error = 'Cannot delete: tenant has ' . $bookings . ' booking(s).'; }
        else { mysqli_query($conn, "DELETE FROM tenant WHERE tenant_id=$id"); $success = 'Tenant deleted.'; }
    }
}
done:

// ── Fetch only tenants who have a booking in my buildings ──
$tenants = mysqli_query($conn, "
    SELECT DISTINCT t.*,
           COUNT(DISTINCT b.booking_id)     AS total_bookings,
           MAX(b.booking_date)              AS last_booking,
           (SELECT r2.room_number
            FROM booking b2
            JOIN room r2 ON b2.room_id = r2.room_id
            JOIN building bu2 ON r2.building_id = bu2.building_id
            WHERE b2.tenant_id = t.tenant_id
              AND b2.status = 'Active'
              AND bu2.admin_id = $admin_id
            LIMIT 1)                         AS current_room
    FROM tenant t
    JOIN booking b   ON b.tenant_id  = t.tenant_id
    JOIN room r      ON b.room_id    = r.room_id
    JOIN building bu ON r.building_id = bu.building_id
    WHERE bu.admin_id = $admin_id
    GROUP BY t.tenant_id
    ORDER BY last_booking DESC
");

$total_tenants  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT t.tenant_id) as c FROM tenant t JOIN booking b ON b.tenant_id=t.tenant_id JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id WHERE bu.admin_id=$admin_id"))['c'];
$active_tenants = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT t.tenant_id) as c FROM tenant t JOIN booking b ON b.tenant_id=t.tenant_id JOIN room r ON b.room_id=r.room_id JOIN building bu ON r.building_id=bu.building_id WHERE bu.admin_id=$admin_id AND b.status='Active'"))['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — Tenants</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        :root{--dark:#1C1410;--rose:#B5556A;--rose-dark:#8E3F52;--bg:#F0EBE1;--muted:#8C7B70;--border:#DDD5C8;--sidebar-w:220px}
        body{font-family:'DM Sans',sans-serif;background:var(--bg);display:flex;min-height:100vh}
        .sidebar{width:var(--sidebar-w);background:var(--dark);display:flex;flex-direction:column;padding:32px 0;position:fixed;top:0;left:0;height:100vh;overflow-y:auto}
        .sidebar-logo{font-family:'Playfair Display',serif;font-size:20px;color:#fff;letter-spacing:4px;padding:0 24px 32px;border-bottom:1px solid rgba(255,255,255,.08)}
        .sidebar-logo span{display:block;font-family:'DM Sans',sans-serif;font-size:10px;letter-spacing:3px;color:var(--muted);margin-top:2px}
        .nav{margin-top:24px;flex:1}
        .nav a{display:flex;align-items:center;gap:12px;padding:12px 24px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:500;transition:all .2s;border-left:3px solid transparent}
        .nav a:hover,.nav a.active{background:rgba(181,85,106,.12);color:#fff;border-left-color:var(--rose)}
        .nav a .icon{font-size:16px;width:20px}
        .sidebar-bottom{padding:24px;border-top:1px solid rgba(255,255,255,.08)}
        .user-info{display:flex;align-items:center;gap:10px;margin-bottom:16px}
        .user-avatar{width:36px;height:36px;background:var(--rose);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:14px;flex-shrink:0}
        .user-name{font-size:13px;color:#fff;font-weight:500}.user-role{font-size:11px;color:var(--muted)}
        .logout-btn{display:block;text-align:center;padding:9px;background:rgba(181,85,106,.15);color:var(--rose);text-decoration:none;border-radius:8px;font-size:13px;font-weight:500;transition:background .2s}
        .logout-btn:hover{background:var(--rose);color:#fff}
        .main{margin-left:var(--sidebar-w);flex:1;padding:36px}
        .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px}
        .topbar h1{font-family:'Playfair Display',serif;font-size:26px;color:var(--dark)}
        .topbar p{font-size:13px;color:var(--muted);margin-top:2px}
        .topbar-right{display:flex;align-items:center;gap:12px}
        .topbar-date{font-size:13px;color:var(--muted);background:#fff;padding:8px 16px;border-radius:8px;border:1px solid var(--border)}
        .btn-add{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:var(--rose);color:#fff;border:none;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s}
        .btn-add:hover{background:var(--rose-dark)}
        .alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
        .alert-success{background:#E8F5E9;color:#2E7D32;border:1px solid #C8E6C9}
        .alert-error{background:#FEE8ED;color:#8E3F52;border:1px solid #F5C6D0}
        .stats{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:28px}
        .stat-card{background:#fff;border-radius:14px;padding:22px;border:1px solid var(--border);display:flex;align-items:center;gap:16px}
        .stat-icon{font-size:26px;width:50px;height:50px;background:#FEF0F3;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .stat-label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
        .stat-value{font-size:26px;font-weight:700;color:var(--dark);margin-top:2px}
        .toolbar{display:flex;align-items:center;gap:10px;margin-bottom:20px}
        .search-wrap{position:relative;flex:1;max-width:340px}
        .search-wrap input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;background:#fff;color:var(--dark);outline:none;transition:border-color .2s}
        .search-wrap input:focus{border-color:var(--rose)}
        .search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:14px}
        .table-card{background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden}
        .table-wrap{overflow-x:auto}
        table{width:100%;border-collapse:collapse}
        thead{background:#FDFAF6}
        th{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);padding:12px 20px;text-align:left;white-space:nowrap}
        td{font-size:13px;color:var(--dark);padding:14px 20px;border-top:1px solid var(--border);vertical-align:middle}
        tbody tr:hover{background:#FDFAF6}
        .table-footer{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-top:1px solid var(--border);font-size:13px;color:var(--muted)}
        .t-info{display:flex;align-items:center;gap:12px}
        .t-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#fff;flex-shrink:0}
        .t-name{font-weight:600;font-size:14px}
        .t-email{font-size:12px;color:var(--muted);margin-top:1px}
        .badge{padding:4px 12px;border-radius:999px;font-size:11px;font-weight:600}
        .badge-active{background:#E8F5E9;color:#2E7D32}
        .badge-no-room{background:#F5F5F5;color:#777}
        .actions{display:flex;gap:6px}
        .btn-sm{padding:6px 12px;border:none;border-radius:7px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s}
        .btn-sm-edit{background:rgba(181,85,106,.1);color:var(--rose)}.btn-sm-edit:hover{background:var(--rose);color:#fff}
        .btn-sm-delete{background:rgba(198,40,40,.08);color:#C62828}.btn-sm-delete:hover{background:#C62828;color:#fff}
        .modal-backdrop{position:fixed;inset:0;background:rgba(28,20,16,.55);backdrop-filter:blur(4px);z-index:100;display:none;align-items:center;justify-content:center}
        .modal-backdrop.open{display:flex}
        .modal{background:#fff;border-radius:18px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.2);animation:slideUp .25s ease}
        @keyframes slideUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
        .modal-header{padding:24px 28px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
        .modal-header h3{font-family:'Playfair Display',serif;font-size:20px;color:var(--dark)}
        .modal-close{width:32px;height:32px;background:#F0EBE1;border:none;border-radius:50%;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center}
        .modal-close:hover{background:var(--border)}
        .modal-body{padding:24px 28px}
        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
        .form-group input{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--dark);background:#FDFAF6;outline:none;transition:border-color .2s}
        .form-group input:focus{border-color:var(--rose);box-shadow:0 0 0 3px rgba(181,85,106,.1)}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .form-hint{font-size:11px;color:var(--muted);margin-top:4px}
        .modal-footer{padding:16px 28px 24px;display:flex;gap:10px;justify-content:flex-end}
        .btn-cancel{padding:10px 22px;background:#F0EBE1;border:1.5px solid var(--border);border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;color:var(--muted);cursor:pointer}
        .btn-cancel:hover{background:var(--border);color:var(--dark)}
        .btn-submit{padding:10px 24px;background:var(--rose);border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;color:#fff;cursor:pointer}
        .btn-submit:hover{background:var(--rose-dark)}
        .btn-danger{padding:10px 24px;background:#C62828;border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;color:#fff;cursor:pointer}
        .btn-danger:hover{background:#8B1A1A}
        .delete-warning{text-align:center;padding:8px 0 16px}
        .delete-warning .warning-icon{font-size:48px;margin-bottom:12px}
        .delete-warning p{font-size:14px;color:var(--muted);line-height:1.6}
        .delete-warning strong{color:var(--dark)}
        .empty-row td{text-align:center;padding:48px;color:var(--muted);font-size:14px}
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>ADMIN PANEL</span></div>
    <nav class="nav">
        <a href="admin_dashboard.php"><span class="icon">🏠</span> Dashboard</a>
        <a href="buildings.php"><span class="icon">🏢</span> Buildings</a>
        <a href="rooms.php"><span class="icon">🚪</span> Rooms</a>
        <a href="tenants.php" class="active"><span class="icon">👥</span> Tenants</a>
        <a href="bookings.php"><span class="icon">📋</span> Bookings</a>
        <a href="#"><span class="icon">💳</span> Payments</a>
        <a href="#"><span class="icon">🔧</span> Maintenance</a>
        <a href="#"><span class="icon">👨‍💼</span> Employees</a>
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
        <div><h1>My Tenants</h1><p>People currently renting your rooms</p></div>
        <div class="topbar-right">
            <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
            <button class="btn-add" onclick="openAddModal()">＋ Add Tenant</button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif ?>

    <div class="stats">
        <div class="stat-card"><div class="stat-icon">👥</div><div><div class="stat-label">Total Tenants</div><div class="stat-value"><?= $total_tenants ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">🏠</div><div><div class="stat-label">Currently Active</div><div class="stat-value"><?= $active_tenants ?></div></div></div>
    </div>

    <div class="toolbar">
        <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" id="searchInput" placeholder="Search by name or email…" oninput="filterTable()"></div>
    </div>

    <div class="table-card">
        <div class="table-wrap">
            <table id="tenantTable">
                <thead><tr><th>#</th><th>Tenant</th><th>Phone</th><th>Active Room</th><th>Bookings</th><th>Last Booking</th><th>Actions</th></tr></thead>
                <tbody>
                <?php
                $colors=['#B5556A','#5E6DA0','#4A9B7F','#C68642','#7B5EA7','#3A7CB8'];
                $i=1; $has_rows=false;
                while ($t=mysqli_fetch_assoc($tenants)):
                    $has_rows=true;
                    $initials=strtoupper(substr($t['first_name'],0,1).substr($t['last_name'],0,1));
                    $color=$colors[$t['tenant_id']%count($colors)];
                ?>
                <tr data-search="<?= strtolower($t['first_name'].' '.$t['last_name'].' '.$t['email']) ?>">
                    <td style="color:var(--muted)"><?= $i++ ?></td>
                    <td><div class="t-info">
                        <div class="t-avatar" style="background:<?= $color ?>"><?= $initials ?></div>
                        <div><div class="t-name"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></div><div class="t-email"><?= htmlspecialchars($t['email']) ?></div></div>
                    </div></td>
                    <td><?= htmlspecialchars($t['phone']?:'—') ?></td>
                    <td><?php if ($t['current_room']): ?><span class="badge badge-active">Room <?= htmlspecialchars($t['current_room']) ?></span><?php else: ?><span class="badge badge-no-room">No Active Room</span><?php endif ?></td>
                    <td style="text-align:center"><?= $t['total_bookings'] ?></td>
                    <td><?= $t['last_booking'] ? date('d M Y',strtotime($t['last_booking'])) : '—' ?></td>
                    <td><div class="actions">
                        <button class="btn-sm btn-sm-edit" onclick='openEditModal(<?= json_encode(["tenant_id"=>$t["tenant_id"],"first_name"=>$t["first_name"],"last_name"=>$t["last_name"],"email"=>$t["email"],"phone"=>$t["phone"]]) ?>)'>✏️ Edit</button>
                        <button class="btn-sm btn-sm-delete" onclick='openDeleteModal(<?= $t["tenant_id"] ?>,<?= json_encode($t["first_name"]." ".$t["last_name"]) ?>)'>🗑️</button>
                    </div></td>
                </tr>
                <?php endwhile ?>
                <?php if (!$has_rows): ?><tr class="empty-row"><td colspan="7">👥 No tenants yet. Tenants appear here once they have a booking in your buildings.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer"><span id="rowCount"><?= $total_tenants ?> tenant(s)</span></div>
    </div>
</div>

<!-- ADD MODAL -->
<div class="modal-backdrop" id="addModal"><div class="modal">
    <div class="modal-header"><h3>Add New Tenant</h3><button class="modal-close" onclick="closeModal('addModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="add">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label>First Name</label><input type="text" name="first_name" placeholder="John" required></div>
                <div class="form-group"><label>Last Name</label><input type="text" name="last_name" placeholder="Doe" required></div>
            </div>
            <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="john@example.com" required></div>
            <div class="form-group"><label>Phone</label><input type="text" name="phone" placeholder="+62 812-3456-7890"></div>
            <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="Minimum 8 characters" required><div class="form-hint">Tenant uses this to log in to the portal.</div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn-submit">＋ Add Tenant</button></div>
    </form>
</div></div>

<!-- EDIT MODAL -->
<div class="modal-backdrop" id="editModal"><div class="modal">
    <div class="modal-header"><h3>Edit Tenant</h3><button class="modal-close" onclick="closeModal('editModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="tenant_id" id="editTenantId">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label>First Name</label><input type="text" name="first_name" id="editFirst" required></div>
                <div class="form-group"><label>Last Name</label><input type="text" name="last_name" id="editLast" required></div>
            </div>
            <div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail" required></div>
            <div class="form-group"><label>Phone</label><input type="text" name="phone" id="editPhone"></div>
            <div class="form-group"><label>New Password <span style="text-transform:none;letter-spacing:0;font-weight:400">(leave blank to keep current)</span></label><input type="password" name="password" placeholder="Leave blank to keep current"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn-submit">💾 Save Changes</button></div>
    </form>
</div></div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteModal"><div class="modal">
    <div class="modal-header"><h3 style="color:#C62828">Delete Tenant</h3><button class="modal-close" onclick="closeModal('deleteModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="tenant_id" id="deleteTenantId">
        <div class="modal-body"><div class="delete-warning"><div class="warning-icon">⚠️</div><p>Delete <strong id="deleteTenantName"></strong>? This cannot be undone.</p></div></div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn-danger">🗑️ Delete</button></div>
    </form>
</div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.open').forEach(m=>m.classList.remove('open'))});
function openAddModal(){openModal('addModal')}
function openEditModal(t){document.getElementById('editTenantId').value=t.tenant_id;document.getElementById('editFirst').value=t.first_name;document.getElementById('editLast').value=t.last_name;document.getElementById('editEmail').value=t.email;document.getElementById('editPhone').value=t.phone;openModal('editModal')}
function openDeleteModal(id,name){document.getElementById('deleteTenantId').value=id;document.getElementById('deleteTenantName').textContent=name;openModal('deleteModal')}
function filterTable(){
    const q=document.getElementById('searchInput').value.toLowerCase();
    const rows=document.querySelectorAll('#tenantTable tbody tr:not(.empty-row)');
    let shown=0;
    rows.forEach(row=>{const match=!q||row.dataset.search.includes(q);row.style.display=match?'':'none';if(match)shown++;});
    document.getElementById('rowCount').textContent=`Showing ${shown} tenant(s)`;
}
document.querySelectorAll('.alert').forEach(el=>setTimeout(()=>el.style.display='none',4000));
</script>
</body></html>