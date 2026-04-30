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
        .user-name{font-size:13px;color:#fff;font-weight:500}
        .user-role{font-size:11px;color:var(--muted)}
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
        .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:28px}
        .stat-card{background:#fff;border-radius:14px;padding:22px;border:1px solid var(--border);display:flex;align-items:center;gap:16px}
        .stat-icon{font-size:28px;width:52px;height:52px;background:#FEF0F3;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .stat-label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
        .stat-value{font-size:26px;font-weight:700;color:var(--dark);margin-top:2px}
        .toolbar{display:flex;align-items:center;gap:12px;margin-bottom:20px}
        .search-wrap{position:relative;flex:1;max-width:320px}
        .search-wrap input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;background:#fff;color:var(--dark);outline:none;transition:border-color .2s}
        .search-wrap input:focus{border-color:var(--rose)}
        .search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:14px}
        .filter-select{padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;background:#fff;color:var(--dark);outline:none;cursor:pointer}
        .buildings-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:20px}
        .building-card{background:#fff;border-radius:16px;border:1px solid var(--border);overflow:hidden;transition:box-shadow .2s,transform .2s}
        .building-card:hover{box-shadow:0 8px 32px rgba(28,20,16,.10);transform:translateY(-2px)}
        .building-card-header{background:var(--dark);padding:22px 24px;position:relative;overflow:hidden}
        .building-card-header::before{content:'';position:absolute;top:-40px;right:-40px;width:140px;height:140px;background:radial-gradient(circle,rgba(181,85,106,.35) 0%,transparent 70%);border-radius:50%}
        .building-card-header::after{content:'🏢';position:absolute;right:22px;bottom:12px;font-size:42px;opacity:.15}
        .building-name{font-family:'Playfair Display',serif;font-size:18px;color:#fff;margin-bottom:4px;position:relative}
        .building-city{font-size:12px;color:rgba(255,255,255,.5);letter-spacing:1px;text-transform:uppercase;position:relative}
        .building-card-body{padding:20px 24px}
        .building-address{font-size:13px;color:var(--muted);margin-bottom:16px}
        .floors-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(181,85,106,.1);color:var(--rose);padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;margin-bottom:12px}
        .building-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}
        .bstat{text-align:center;padding:12px 8px;background:var(--bg);border-radius:10px}
        .bstat-num{font-size:20px;font-weight:700;color:var(--dark)}
        .bstat-lbl{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-top:2px}
        .bstat-num.occ{color:var(--rose)}.bstat-num.avail{color:#2E7D32}
        .occupancy-bar{height:6px;background:#EDE8DF;border-radius:999px;overflow:hidden;margin-bottom:18px}
        .occupancy-fill{height:100%;background:linear-gradient(90deg,var(--rose-dark),var(--rose));border-radius:999px}
        .building-desc{font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:18px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
        .building-card-footer{display:flex;gap:8px;padding:0 24px 20px}
        .btn-action{flex:1;padding:9px;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s}
        .btn-edit{background:rgba(181,85,106,.1);color:var(--rose)}.btn-edit:hover{background:var(--rose);color:#fff}
        .btn-delete{background:rgba(200,50,50,.08);color:#C62828}.btn-delete:hover{background:#C62828;color:#fff}
        .btn-view{background:rgba(28,20,16,.06);color:var(--dark)}.btn-view:hover{background:var(--dark);color:#fff}
        .modal-backdrop{position:fixed;inset:0;background:rgba(28,20,16,.55);backdrop-filter:blur(4px);z-index:100;display:none;align-items:center;justify-content:center}
        .modal-backdrop.open{display:flex}
        .modal{background:#fff;border-radius:18px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.2);animation:slideUp .25s ease}
        @keyframes slideUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
        .modal-header{padding:24px 28px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
        .modal-header h3{font-family:'Playfair Display',serif;font-size:20px;color:var(--dark)}
        .modal-close{width:32px;height:32px;background:var(--bg);border:none;border-radius:50%;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center}
        .modal-close:hover{background:var(--border)}
        .modal-body{padding:24px 28px}
        .form-group{margin-bottom:18px}
        .form-group label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
        .form-group input,.form-group textarea{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--dark);background:#FDFAF6;outline:none;transition:border-color .2s}
        .form-group input:focus,.form-group textarea:focus{border-color:var(--rose);box-shadow:0 0 0 3px rgba(181,85,106,.1)}
        .form-group textarea{resize:vertical;min-height:80px}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .modal-footer{padding:16px 28px 24px;display:flex;gap:10px;justify-content:flex-end}
        .btn-cancel{padding:10px 22px;background:var(--bg);border:1.5px solid var(--border);border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;color:var(--muted);cursor:pointer}
        .btn-cancel:hover{background:var(--border);color:var(--dark)}
        .btn-submit{padding:10px 24px;background:var(--rose);border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;color:#fff;cursor:pointer}
        .btn-submit:hover{background:var(--rose-dark)}
        .btn-danger{padding:10px 24px;background:#C62828;border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;color:#fff;cursor:pointer}
        .btn-danger:hover{background:#8B1A1A}
        .delete-warning{text-align:center;padding:8px 0 16px}
        .delete-warning .warning-icon{font-size:48px;margin-bottom:12px}
        .delete-warning p{font-size:14px;color:var(--muted);line-height:1.6}
        .delete-warning strong{color:var(--dark)}
        .empty-state{grid-column:1/-1;text-align:center;padding:60px 24px;background:#fff;border-radius:16px;border:1px solid var(--border);color:var(--muted)}
        .empty-state .empty-icon{font-size:56px;margin-bottom:16px}
        .empty-state h3{font-size:18px;color:var(--dark);margin-bottom:8px}
    </style>
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