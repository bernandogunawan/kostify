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
    <link href="../css/tenants.css" rel="stylesheet">
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