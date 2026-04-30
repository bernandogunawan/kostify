<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Location: ../auth/login.php?mode=admin'); exit;
}

$admin_id = (int)$_SESSION['user_id'];
$success  = '';
$error    = '';

// ── Helper: verify a building belongs to this admin ──
function adminOwnsBuilding($conn, $building_id, $admin_id) {
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT building_id FROM building WHERE building_id=$building_id AND admin_id=$admin_id"));
    return (bool)$r;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'add') {
        $building_id = (int)$_POST['building_id'];
        if (!adminOwnsBuilding($conn, $building_id, $admin_id)) { $error = 'Unauthorized building.'; goto done; }

        $room_number = mysqli_real_escape_string($conn, $_POST['room_number']);
        $room_type   = mysqli_real_escape_string($conn, $_POST['room_type']);
        $floor       = (int)$_POST['floor'];
        $price       = (float)$_POST['price_per_month'];
        $status      = mysqli_real_escape_string($conn, $_POST['status']);
        $facilities  = mysqli_real_escape_string($conn, $_POST['facilities'] ?? '');
        $size        = mysqli_real_escape_string($conn, $_POST['size'] ?? '');

        $dup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT room_id FROM room WHERE building_id=$building_id AND room_number='$room_number'"));
        if ($dup) { $error = 'Room number already exists in this building.'; goto done; }

        mysqli_query($conn, "INSERT INTO room (building_id,room_number,room_type,floor,price_per_month,status,facilities,size)
                             VALUES ($building_id,'$room_number','$room_type',$floor,$price,'$status','$facilities','$size')");
        $success = 'Room added successfully.';

    } elseif ($action == 'edit') {
        $id          = (int)$_POST['room_id'];
        $building_id = (int)$_POST['building_id'];
        if (!adminOwnsBuilding($conn, $building_id, $admin_id)) { $error = 'Unauthorized building.'; goto done; }

        $room_number = mysqli_real_escape_string($conn, $_POST['room_number']);
        $room_type   = mysqli_real_escape_string($conn, $_POST['room_type']);
        $floor       = (int)$_POST['floor'];
        $price       = (float)$_POST['price_per_month'];
        $status      = mysqli_real_escape_string($conn, $_POST['status']);
        $facilities  = mysqli_real_escape_string($conn, $_POST['facilities'] ?? '');
        $size        = mysqli_real_escape_string($conn, $_POST['size'] ?? '');

        mysqli_query($conn, "UPDATE room SET building_id=$building_id,room_number='$room_number',
                             room_type='$room_type',floor=$floor,price_per_month=$price,
                             status='$status',facilities='$facilities',size='$size'
                             WHERE room_id=$id");
        $success = 'Room updated.';

    } elseif ($action == 'delete') {
        $id = (int)$_POST['room_id'];
        // Verify ownership through building
        $room_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT r.room_id FROM room r JOIN building b ON r.building_id=b.building_id WHERE r.room_id=$id AND b.admin_id=$admin_id"));
        if (!$room_row) { $error = 'Unauthorized.'; goto done; }

        $used = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM booking WHERE room_id=$id"))['c'];
        if ($used > 0) { $error = 'Cannot delete: room has ' . $used . ' booking(s).'; goto done; }

        mysqli_query($conn, "DELETE FROM room WHERE room_id=$id");
        $success = 'Room deleted.';
    }
}
done:

// ── Filters ──
$filter_building = isset($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
// Validate filter_building belongs to this admin
if ($filter_building && !adminOwnsBuilding($conn, $filter_building, $admin_id)) $filter_building = 0;

$filter_status = mysqli_real_escape_string($conn, $_GET['status'] ?? '');
$filter_type   = mysqli_real_escape_string($conn, $_GET['type']   ?? '');

$where_parts = ["b.admin_id = $admin_id"];
if ($filter_building) $where_parts[] = "r.building_id=$filter_building";
if ($filter_status)   $where_parts[] = "r.status='$filter_status'";
if ($filter_type)     $where_parts[] = "r.room_type='$filter_type'";
$where = 'WHERE ' . implode(' AND ', $where_parts);

$rooms = mysqli_query($conn, "
    SELECT r.*, b.name AS building_name, b.city, b.building_id AS bid
    FROM room r
    JOIN building b ON r.building_id = b.building_id
    $where
    ORDER BY b.name, r.floor, r.room_number
");

// ── Stats (only my rooms) ──
$total_rooms = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM room r JOIN building b ON r.building_id=b.building_id WHERE b.admin_id=$admin_id"))['c'];
$occupied    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM room r JOIN building b ON r.building_id=b.building_id WHERE b.admin_id=$admin_id AND r.status='Occupied'"))['c'];
$available   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM room r JOIN building b ON r.building_id=b.building_id WHERE b.admin_id=$admin_id AND r.status='Available'"))['c'];
$total_rev   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(r.price_per_month),0) as s FROM room r JOIN building b ON r.building_id=b.building_id WHERE b.admin_id=$admin_id AND r.status='Occupied'"))['s'];

// ── My buildings for dropdowns ──
$buildings_res = mysqli_query($conn, "SELECT building_id, name, city FROM building WHERE admin_id=$admin_id ORDER BY name");
$buildings_arr = [];
while ($bld = mysqli_fetch_assoc($buildings_res)) $buildings_arr[] = $bld;

// ── Distinct types from my rooms ──
$types_res = mysqli_query($conn, "SELECT DISTINCT r.room_type FROM room r JOIN building b ON r.building_id=b.building_id WHERE b.admin_id=$admin_id ORDER BY r.room_type");
$types = [];
while ($t = mysqli_fetch_assoc($types_res)) $types[] = $t['room_type'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — Rooms</title>
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
        .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
        .stat-card{background:#fff;border-radius:14px;padding:22px;border:1px solid var(--border);display:flex;align-items:center;gap:16px}
        .stat-icon{font-size:26px;width:50px;height:50px;background:#FEF0F3;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .stat-label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
        .stat-value{font-size:22px;font-weight:700;color:var(--dark);margin-top:2px}
        .toolbar{display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap}
        .search-wrap{position:relative;flex:1;min-width:200px;max-width:280px}
        .search-wrap input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;background:#fff;color:var(--dark);outline:none;transition:border-color .2s}
        .search-wrap input:focus{border-color:var(--rose)}
        .search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:14px}
        .filter-select{padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;background:#fff;color:var(--dark);outline:none;cursor:pointer}
        .table-card{background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden}
        .table-wrap{overflow-x:auto}
        table{width:100%;border-collapse:collapse}
        thead{background:#FDFAF6}
        th{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);padding:12px 20px;text-align:left;white-space:nowrap}
        td{font-size:13px;color:var(--dark);padding:14px 20px;border-top:1px solid var(--border);vertical-align:middle}
        tbody tr:hover{background:#FDFAF6}
        .table-footer{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-top:1px solid var(--border);font-size:13px;color:var(--muted)}
        .room-num{font-weight:700;font-size:15px}
        .building-tag{font-size:11px;color:var(--muted);margin-top:2px}
        .type-chip{display:inline-block;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;background:rgba(181,85,106,.1);color:var(--rose)}
        .badge{padding:4px 12px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
        .badge-available{background:#E8F5E9;color:#2E7D32}
        .badge-occupied{background:#FEE8ED;color:#8E3F52}
        .badge-maintenance{background:#FFF8E1;color:#F57F17}
        .badge-reserved{background:#E3F2FD;color:#1565C0}
        .actions{display:flex;gap:6px}
        .btn-sm{padding:6px 12px;border:none;border-radius:7px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;white-space:nowrap}
        .btn-sm-edit{background:rgba(181,85,106,.1);color:var(--rose)}.btn-sm-edit:hover{background:var(--rose);color:#fff}
        .btn-sm-delete{background:rgba(198,40,40,.08);color:#C62828}.btn-sm-delete:hover{background:#C62828;color:#fff}
        .modal-backdrop{position:fixed;inset:0;background:rgba(28,20,16,.55);backdrop-filter:blur(4px);z-index:100;display:none;align-items:center;justify-content:center}
        .modal-backdrop.open{display:flex}
        .modal{background:#fff;border-radius:18px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.2);animation:slideUp .25s ease}
        @keyframes slideUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
        .modal-header{padding:24px 28px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
        .modal-header h3{font-family:'Playfair Display',serif;font-size:20px;color:var(--dark)}
        .modal-close{width:32px;height:32px;background:#F0EBE1;border:none;border-radius:50%;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center}
        .modal-close:hover{background:var(--border)}
        .modal-body{padding:24px 28px}
        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
        .form-group input,.form-group select{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--dark);background:#FDFAF6;outline:none;transition:border-color .2s}
        .form-group input:focus,.form-group select:focus{border-color:var(--rose);box-shadow:0 0 0 3px rgba(181,85,106,.1)}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
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
        <a href="rooms.php" class="active"><span class="icon">🚪</span> Rooms</a>
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
        <div><h1>My Rooms</h1><p>Rooms across your properties</p></div>
        <div class="topbar-right">
            <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
            <?php if ($buildings_arr): ?>
                <button class="btn-add" onclick="openAddModal()">＋ Add Room</button>
            <?php endif ?>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif ?>

    <?php if (!$buildings_arr): ?>
        <div style="text-align:center;padding:60px;background:#fff;border-radius:16px;border:1px solid var(--border);color:var(--muted)">
            <div style="font-size:48px;margin-bottom:12px">🏢</div>
            <h3 style="color:var(--dark);margin-bottom:8px">No Buildings Yet</h3>
            <p>Add a building first before creating rooms. <a href="buildings.php" style="color:var(--rose)">Go to Buildings →</a></p>
        </div>
    <?php else: ?>

    <div class="stats">
        <div class="stat-card"><div class="stat-icon">🚪</div><div><div class="stat-label">Total Rooms</div><div class="stat-value"><?= $total_rooms ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">✅</div><div><div class="stat-label">Available</div><div class="stat-value"><?= $available ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">🔴</div><div><div class="stat-label">Occupied</div><div class="stat-value"><?= $occupied ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">💰</div><div><div class="stat-label">Monthly Revenue</div><div class="stat-value" style="font-size:16px">Rp <?= number_format($total_rev,0,',','.') ?></div></div></div>
    </div>

    <div class="toolbar">
        <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" id="searchInput" placeholder="Search room number…" oninput="filterTable()"></div>
        <select class="filter-select" id="buildingFilter" onchange="filterTable()">
            <option value="">All My Buildings</option>
            <?php foreach ($buildings_arr as $bld): ?>
                <option value="<?= htmlspecialchars($bld['name']) ?>" <?= $filter_building==$bld['building_id']?'selected':'' ?>><?= htmlspecialchars($bld['name']) ?></option>
            <?php endforeach ?>
        </select>
        <select class="filter-select" id="typeFilter" onchange="filterTable()">
            <option value="">All Types</option>
            <?php foreach ($types as $t): ?><option value="<?= htmlspecialchars($t) ?>" <?= $filter_type==$t?'selected':'' ?>><?= htmlspecialchars($t) ?></option><?php endforeach ?>
        </select>
        <select class="filter-select" id="statusFilter" onchange="filterTable()">
            <option value="">All Status</option>
            <option value="Available" <?= $filter_status=='Available'?'selected':'' ?>>Available</option>
            <option value="Occupied" <?= $filter_status=='Occupied'?'selected':'' ?>>Occupied</option>
            <option value="Maintenance" <?= $filter_status=='Maintenance'?'selected':'' ?>>Maintenance</option>
            <option value="Reserved" <?= $filter_status=='Reserved'?'selected':'' ?>>Reserved</option>
        </select>
    </div>

    <div class="table-card">
        <div class="table-wrap">
            <table id="roomTable">
                <thead><tr><th>#</th><th>Room</th><th>Building</th><th>Type</th><th>Floor</th><th>Size</th><th>Price / Month</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php $i=1; $has_rows=false; while ($r=mysqli_fetch_assoc($rooms)): $has_rows=true; $badge='badge-'.strtolower(str_replace(' ','',$r['status'])); ?>
                <tr data-room="<?= strtolower($r['room_number']) ?>"
                    data-building="<?= htmlspecialchars($r['building_name']) ?>"
                    data-type="<?= htmlspecialchars($r['room_type']) ?>"
                    data-status="<?= htmlspecialchars($r['status']) ?>">
                    <td style="color:var(--muted)"><?= $i++ ?></td>
                    <td><div class="room-num"><?= htmlspecialchars($r['room_number']) ?></div></td>
                    <td><div><?= htmlspecialchars($r['building_name']) ?></div><div class="building-tag"><?= htmlspecialchars($r['city']) ?></div></td>
                    <td><span class="type-chip"><?= htmlspecialchars($r['room_type']) ?></span></td>
                    <td>Floor <?= $r['floor'] ?></td>
                    <td><?= $r['size'] ? htmlspecialchars($r['size']) : '—' ?></td>
                    <td style="font-weight:600">Rp <?= number_format($r['price_per_month'],0,',','.') ?></td>
                    <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td>
                        <div class="actions">
                            <button class="btn-sm btn-sm-edit" onclick='openEditModal(<?= json_encode(["room_id"=>$r["room_id"],"building_id"=>$r["building_id"],"room_number"=>$r["room_number"],"room_type"=>$r["room_type"],"floor"=>$r["floor"],"price_per_month"=>$r["price_per_month"],"status"=>$r["status"],"size"=>$r["size"]??"","facilities"=>$r["facilities"]??""]) ?>)'>✏️ Edit</button>
                            <button class="btn-sm btn-sm-delete" onclick='openDeleteModal(<?= $r["room_id"] ?>,<?= json_encode($r["room_number"]) ?>)'>🗑️</button>
                        </div>
                    </td>
                </tr>
                <?php endwhile ?>
                <?php if (!$has_rows): ?><tr class="empty-row"><td colspan="9">🚪 No rooms found. Add your first room!</td></tr><?php endif ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer"><span id="rowCount">Showing all rooms</span></div>
    </div>
    <?php endif ?>
</div>

<!-- ADD MODAL -->
<div class="modal-backdrop" id="addModal"><div class="modal">
    <div class="modal-header"><h3>Add New Room</h3><button class="modal-close" onclick="closeModal('addModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="add">
        <div class="modal-body">
            <div class="form-group"><label>Building</label>
                <select name="building_id" required>
                    <option value="">— Select Building —</option>
                    <?php foreach ($buildings_arr as $bld): ?><option value="<?= $bld['building_id'] ?>"><?= htmlspecialchars($bld['name']) ?> (<?= htmlspecialchars($bld['city']) ?>)</option><?php endforeach ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Room Number</label><input type="text" name="room_number" placeholder="e.g. 101" required></div>
                <div class="form-group"><label>Floor</label><input type="number" name="floor" min="1" placeholder="1" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Room Type</label>
                    <select name="room_type" required>
                        <option value="">— Select —</option>
                        <option value="Single">Single</option><option value="Double">Double</option>
                        <option value="Studio">Studio</option><option value="Suite">Suite</option><option value="Deluxe">Deluxe</option>
                    </select>
                </div>
                <div class="form-group"><label>Size (m²)</label><input type="text" name="size" placeholder="e.g. 12m²"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Price / Month (Rp)</label><input type="number" name="price_per_month" placeholder="1500000" required></div>
                <div class="form-group"><label>Status</label>
                    <select name="status" required>
                        <option value="Available">Available</option><option value="Occupied">Occupied</option>
                        <option value="Maintenance">Maintenance</option><option value="Reserved">Reserved</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Facilities <span style="text-transform:none;letter-spacing:0;font-weight:400">(optional)</span></label><input type="text" name="facilities" placeholder="e.g. AC, WiFi, Kamar Mandi Dalam"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn-submit">＋ Add Room</button></div>
    </form>
</div></div>

<!-- EDIT MODAL -->
<div class="modal-backdrop" id="editModal"><div class="modal">
    <div class="modal-header"><h3>Edit Room</h3><button class="modal-close" onclick="closeModal('editModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="room_id" id="editRoomId">
        <div class="modal-body">
            <div class="form-group"><label>Building</label>
                <select name="building_id" id="editBuildingId" required>
                    <?php foreach ($buildings_arr as $bld): ?><option value="<?= $bld['building_id'] ?>"><?= htmlspecialchars($bld['name']) ?> (<?= htmlspecialchars($bld['city']) ?>)</option><?php endforeach ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Room Number</label><input type="text" name="room_number" id="editRoomNumber" required></div>
                <div class="form-group"><label>Floor</label><input type="number" name="floor" id="editFloor" min="1" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Room Type</label>
                    <select name="room_type" id="editRoomType" required>
                        <option value="Single">Single</option><option value="Double">Double</option>
                        <option value="Studio">Studio</option><option value="Suite">Suite</option><option value="Deluxe">Deluxe</option>
                    </select>
                </div>
                <div class="form-group"><label>Size (m²)</label><input type="text" name="size" id="editSize"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Price / Month (Rp)</label><input type="number" name="price_per_month" id="editPrice" required></div>
                <div class="form-group"><label>Status</label>
                    <select name="status" id="editStatus" required>
                        <option value="Available">Available</option><option value="Occupied">Occupied</option>
                        <option value="Maintenance">Maintenance</option><option value="Reserved">Reserved</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Facilities</label><input type="text" name="facilities" id="editFacilities"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn-submit">💾 Save Changes</button></div>
    </form>
</div></div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteModal"><div class="modal">
    <div class="modal-header"><h3 style="color:#C62828">Delete Room</h3><button class="modal-close" onclick="closeModal('deleteModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="room_id" id="deleteRoomId">
        <div class="modal-body"><div class="delete-warning"><div class="warning-icon">⚠️</div><p>Delete Room <strong id="deleteRoomNum"></strong>? This cannot be undone.</p></div></div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn-danger">🗑️ Delete Room</button></div>
    </form>
</div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.open').forEach(m=>m.classList.remove('open'))});
function openAddModal(){openModal('addModal')}
function openEditModal(r){
    document.getElementById('editRoomId').value=r.room_id;
    document.getElementById('editBuildingId').value=r.building_id;
    document.getElementById('editRoomNumber').value=r.room_number;
    document.getElementById('editFloor').value=r.floor;
    document.getElementById('editRoomType').value=r.room_type;
    document.getElementById('editSize').value=r.size;
    document.getElementById('editPrice').value=r.price_per_month;
    document.getElementById('editStatus').value=r.status;
    document.getElementById('editFacilities').value=r.facilities;
    openModal('editModal');
}
function openDeleteModal(id,num){document.getElementById('deleteRoomId').value=id;document.getElementById('deleteRoomNum').textContent=num;openModal('deleteModal')}
function filterTable(){
    const q=document.getElementById('searchInput').value.toLowerCase();
    const bld=document.getElementById('buildingFilter').value.toLowerCase();
    const type=document.getElementById('typeFilter').value.toLowerCase();
    const status=document.getElementById('statusFilter').value.toLowerCase();
    const rows=document.querySelectorAll('#roomTable tbody tr:not(.empty-row)');
    let shown=0;
    rows.forEach(row=>{
        const match=(!q||row.dataset.room.includes(q))&&(!bld||row.dataset.building.toLowerCase()===bld)&&(!type||row.dataset.type.toLowerCase()===type)&&(!status||row.dataset.status.toLowerCase()===status);
        row.style.display=match?'':'none';if(match)shown++;
    });
    document.getElementById('rowCount').textContent=`Showing ${shown} room(s)`;
}
document.querySelectorAll('.alert').forEach(el=>setTimeout(()=>el.style.display='none',4000));
filterTable();
</script>
</body></html>