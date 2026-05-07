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
        $name    = mysqli_real_escape_string($conn, $_POST['name']);
        $address = mysqli_real_escape_string($conn, $_POST['address']);
        $city    = mysqli_real_escape_string($conn, $_POST['city']);
        $floors  = (int)$_POST['floors'];
        $desc    = mysqli_real_escape_string($conn, $_POST['description']);
        mysqli_query($conn, "INSERT INTO building (admin_id, name, address, city, total_floors, description)
                             VALUES ($admin_id,'$name','$address','$city',$floors,'$desc')");
        $success = 'Building added successfully.';

    } elseif ($action == 'edit') {
        $id      = (int)$_POST['building_id'];
        $name    = mysqli_real_escape_string($conn, $_POST['name']);
        $address = mysqli_real_escape_string($conn, $_POST['address']);
        $city    = mysqli_real_escape_string($conn, $_POST['city']);
        $floors  = (int)$_POST['floors'];
        $desc    = mysqli_real_escape_string($conn, $_POST['description']);
        mysqli_query($conn, "UPDATE building SET name='$name', address='$address', city='$city',
                             total_floors=$floors, description='$desc'
                             WHERE building_id=$id AND admin_id=$admin_id");
        $success = 'Building updated successfully.';

    } elseif ($action == 'delete') {
        $id   = (int)$_POST['building_id'];
        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT building_id FROM building WHERE building_id=$id AND admin_id=$admin_id"));
        if (!$owns) { $error = 'Unauthorized.'; }
        else {
            $rooms = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM room WHERE building_id=$id"))['c'];
            if ($rooms > 0) $error = 'Cannot delete: building has ' . $rooms . ' room(s).';
            else { mysqli_query($conn, "DELETE FROM building WHERE building_id=$id AND admin_id=$admin_id"); $success = 'Building deleted.'; }
        }
    }
}

// ── Fetch MY buildings ──
$buildings = mysqli_query($conn, "
    SELECT b.*, COUNT(r.room_id) AS total_rooms,
           SUM(r.status='Occupied') AS occupied, SUM(r.status='Available') AS available
    FROM building b
    LEFT JOIN room r ON r.building_id = b.building_id
    WHERE b.admin_id = $admin_id
    GROUP BY b.building_id ORDER BY b.building_id DESC
");

$total_buildings = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM building WHERE admin_id=$admin_id"))['c'];
$total_rooms     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM room r JOIN building b ON r.building_id=b.building_id WHERE b.admin_id=$admin_id"))['c'];
$total_cities    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT city) as c FROM building WHERE admin_id=$admin_id"))['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — My Buildings</title>
    <link rel="stylesheet" href="../css/buildings.css">
</head>
<body>
<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>ADMIN PANEL</span></div>
    <nav class="nav">
        <a href="admin_dashboard.php"><span class="icon">🏠</span> Dashboard</a>
        <a href="buildings.php" class="active"><span class="icon">🏢</span> Buildings</a>
        <a href="rooms.php"><span class="icon">🚪</span> Rooms</a>
        <a href="tenants.php"><span class="icon">👥</span> Tenants</a>
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
        <div><h1>My Buildings</h1><p>Properties you own and manage</p></div>
        <div class="topbar-right">
            <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
            <button class="btn-add" onclick="openAddModal()">＋ Add Building</button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif ?>

    <div class="stats">
        <div class="stat-card"><div class="stat-icon">🏢</div><div><div class="stat-label">My Buildings</div><div class="stat-value"><?= $total_buildings ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">🚪</div><div><div class="stat-label">Total Rooms</div><div class="stat-value"><?= $total_rooms ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">📍</div><div><div class="stat-label">Cities</div><div class="stat-value"><?= $total_cities ?></div></div></div>
    </div>

    <div class="toolbar">
        <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" id="searchInput" placeholder="Search buildings…" oninput="filterCards()"></div>
        <select class="filter-select" id="cityFilter" onchange="filterCards()">
            <option value="">All Cities</option>
            <?php $cities = mysqli_query($conn,"SELECT DISTINCT city FROM building WHERE admin_id=$admin_id ORDER BY city"); while($c=mysqli_fetch_assoc($cities)): ?>
                <option value="<?= htmlspecialchars($c['city']) ?>"><?= htmlspecialchars($c['city']) ?></option>
            <?php endwhile ?>
        </select>
    </div>

    <div class="buildings-grid" id="buildingsGrid">
    <?php
    mysqli_data_seek($buildings, 0);
    $has = false;
    while ($b = mysqli_fetch_assoc($buildings)):
        $has = true;
        $pct = $b['total_rooms'] > 0 ? round($b['occupied']/$b['total_rooms']*100) : 0;
    ?>
    <div class="building-card" data-name="<?= strtolower(htmlspecialchars($b['name'])) ?>" data-city="<?= strtolower(htmlspecialchars($b['city'])) ?>">
        <div class="building-card-header">
            <div class="building-name"><?= htmlspecialchars($b['name']) ?></div>
            <div class="building-city"><?= htmlspecialchars($b['city']) ?></div>
        </div>
        <div class="building-card-body">
            <div class="floors-badge">🏗️ <?= $b['total_floors'] ?> Floors</div>
            <div class="building-address">📍 <?= htmlspecialchars($b['address']) ?></div>
            <div class="building-stats">
                <div class="bstat"><div class="bstat-num"><?= $b['total_rooms'] ?></div><div class="bstat-lbl">Total</div></div>
                <div class="bstat"><div class="bstat-num occ"><?= $b['occupied'] ?></div><div class="bstat-lbl">Occupied</div></div>
                <div class="bstat"><div class="bstat-num avail"><?= $b['available'] ?></div><div class="bstat-lbl">Available</div></div>
            </div>
            <div class="occupancy-bar"><div class="occupancy-fill" style="width:<?= $pct ?>%"></div></div>
            <?php if (!empty($b['description'])): ?><div class="building-desc"><?= htmlspecialchars($b['description']) ?></div><?php endif ?>
        </div>
        <div class="building-card-footer">
            <button class="btn-action btn-view" onclick="window.location.href='rooms.php?building_id=<?= $b['building_id'] ?>'">🚪 Rooms</button>
            <button class="btn-action btn-edit" onclick='openEditModal(<?= $b["building_id"] ?>,<?= json_encode($b["name"]) ?>,<?= json_encode($b["address"]) ?>,<?= json_encode($b["city"]) ?>,<?= (int)$b["total_floors"] ?>,<?= json_encode($b["description"]??"") ?>)'>✏️ Edit</button>
            <button class="btn-action btn-delete" onclick='openDeleteModal(<?= $b["building_id"] ?>,<?= json_encode($b["name"]) ?>)'>🗑️ Delete</button>
        </div>
    </div>
    <?php endwhile ?>
    <?php if (!$has): ?><div class="empty-state"><div class="empty-icon">🏢</div><h3>No Buildings Yet</h3><p>Add your first building to get started.</p></div><?php endif ?>
    </div>
</div>

<!-- ADD -->
<div class="modal-backdrop" id="addModal"><div class="modal">
    <div class="modal-header"><h3>Add New Building</h3><button class="modal-close" onclick="closeModal('addModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="add">
        <div class="modal-body">
            <div class="form-group"><label>Building Name</label><input type="text" name="name" placeholder="e.g. Kost Melati" required></div>
            <div class="form-row">
                <div class="form-group"><label>City</label><input type="text" name="city" placeholder="Jakarta" required></div>
                <div class="form-group"><label>Total Floors</label><input type="number" name="floors" min="1" max="99" placeholder="3" required></div>
            </div>
            <div class="form-group"><label>Address</label><input type="text" name="address" placeholder="Jl. Contoh No. 1" required></div>
            <div class="form-group"><label>Description <span style="text-transform:none;letter-spacing:0;font-weight:400">(optional)</span></label><textarea name="description" placeholder="Brief description…"></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn-submit">＋ Add Building</button></div>
    </form>
</div></div>

<!-- EDIT -->
<div class="modal-backdrop" id="editModal"><div class="modal">
    <div class="modal-header"><h3>Edit Building</h3><button class="modal-close" onclick="closeModal('editModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="building_id" id="editId">
        <div class="modal-body">
            <div class="form-group"><label>Building Name</label><input type="text" name="name" id="editName" required></div>
            <div class="form-row">
                <div class="form-group"><label>City</label><input type="text" name="city" id="editCity" required></div>
                <div class="form-group"><label>Total Floors</label><input type="number" name="floors" id="editFloors" min="1" required></div>
            </div>
            <div class="form-group"><label>Address</label><input type="text" name="address" id="editAddress" required></div>
            <div class="form-group"><label>Description</label><textarea name="description" id="editDesc"></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn-submit">💾 Save Changes</button></div>
    </form>
</div></div>

<!-- DELETE -->
<div class="modal-backdrop" id="deleteModal"><div class="modal">
    <div class="modal-header"><h3 style="color:#C62828">Delete Building</h3><button class="modal-close" onclick="closeModal('deleteModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="building_id" id="deleteId">
        <div class="modal-body"><div class="delete-warning"><div class="warning-icon">⚠️</div><p>Delete <strong id="deleteName"></strong>? This cannot be undone.</p></div></div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn-danger">🗑️ Delete</button></div>
    </form>
</div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.open').forEach(m=>m.classList.remove('open'))});
function openAddModal(){openModal('addModal')}
function openEditModal(id,name,address,city,floors,desc){
    document.getElementById('editId').value=id;document.getElementById('editName').value=name;
    document.getElementById('editAddress').value=address;document.getElementById('editCity').value=city;
    document.getElementById('editFloors').value=floors;document.getElementById('editDesc').value=desc;
    openModal('editModal');
}
function openDeleteModal(id,name){document.getElementById('deleteId').value=id;document.getElementById('deleteName').textContent=name;openModal('deleteModal')}
function filterCards(){
    const q=document.getElementById('searchInput').value.toLowerCase();
    const city=document.getElementById('cityFilter').value.toLowerCase();
    document.querySelectorAll('.building-card').forEach(c=>{c.style.display=(!q||c.dataset.name.includes(q))&&(!city||c.dataset.city===city)?'':'none'});
}
document.querySelectorAll('.alert').forEach(el=>setTimeout(()=>el.style.display='none',4000));
</script>
</body></html>