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
        $role  = mysqli_real_escape_string($conn, $_POST['role']);
        $hire_date = mysqli_real_escape_string($conn, $_POST['hire_date']);

        if ($first === '' || $last === '' || $email === '' || $role === '' || $hire_date === '') {
            $error = 'All fields are required.';
        } elseif (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
        } else {
            $dup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT employee_id FROM employee WHERE email='$email'"));
            if ($dup) { $error = 'Email already registered.'; goto done; }
            mysqli_query($conn, "INSERT INTO employee (first_name,last_name,email,role,hire_date,employer) VALUES ('$first','$last','$email','$role','$hire_date',$admin_id)");
            $success = 'Employee added successfully.';
        }

    } elseif ($action == 'edit') {
        $id    = (int)$_POST['employee_id'];
        $first = mysqli_real_escape_string($conn, $_POST['first_name']);
        $last  = mysqli_real_escape_string($conn, $_POST['last_name']);
        $role  = mysqli_real_escape_string($conn, $_POST['role']);

        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT employee_id FROM employee WHERE employee_id=$id AND employer=$admin_id"));
        if (!$owns) { $error = 'Unauthorized.'; goto done; }

        mysqli_query($conn, "UPDATE employee SET first_name='$first',last_name='$last',role='$role' WHERE employee_id=$id AND employer=$admin_id");
        $success = 'Employee updated.';

    } elseif ($action == 'delete') {
        $id   = (int)$_POST['employee_id'];
        $owns = mysqli_fetch_assoc(mysqli_query($conn, "SELECT employee_id FROM employee WHERE employee_id=$id AND employer=$admin_id"));
        if (!$owns) { $error = 'Unauthorized.'; goto done; }

        $used = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM maintenance WHERE employee_id=$id"))['c'];
        if ($used > 0) { $error = 'Cannot delete: employee is assigned to '.$used.' maintenance request(s).'; }
        else {
            mysqli_query($conn, "DELETE FROM employee WHERE employee_id=$id AND employer=$admin_id");
            $success = 'Employee deleted.';
        }
    }
}
done:

$employees = mysqli_query($conn, "SELECT * FROM employee WHERE employer=$admin_id ORDER BY employee_id DESC");

$total_employees = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM employee WHERE employer=$admin_id"))['c'];
$active_assignments = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(DISTINCT m.employee_id) as c
    FROM maintenance m
    JOIN employee e ON m.employee_id = e.employee_id
    WHERE e.employer=$admin_id AND m.status IN ('Pending','In Progress')
"))['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — Employees</title>
    <link href="../css/tenants.css" rel="stylesheet">
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
        <a href="maintenance.php"><span class="icon">🔧</span> Maintenance</a>
        <a href="employees.php" class="active"><span class="icon">👨‍💼</span> Employees</a>
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
        <div><h1>My Employees</h1><p>Staff assigned to your properties</p></div>
        <div class="topbar-right">
            <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
            <button class="btn-add" onclick="openAddModal()">＋ Add Employee</button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif ?>

    <div class="stats">
        <div class="stat-card"><div class="stat-icon">👨‍💼</div><div><div class="stat-label">Total Employees</div><div class="stat-value"><?= $total_employees ?></div></div></div>
        <div class="stat-card"><div class="stat-icon">🔧</div><div><div class="stat-label">Currently Assigned</div><div class="stat-value"><?= $active_assignments ?></div></div></div>
    </div>

    <div class="toolbar">
        <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" id="searchInput" placeholder="Search by name or role…" oninput="filterTable()"></div>
    </div>

    <div class="table-card">
        <div class="table-wrap">
            <table id="employeeTable">
                <thead><tr><th>#</th><th>Name</th><th>Role</th><th>Actions</th></tr></thead>
                <tbody>
                <?php
                $colors=['#B5556A','#5E6DA0','#4A9B7F','#C68642','#7B5EA7','#3A7CB8'];
                $i=1; $has_rows=false;
                while ($e=mysqli_fetch_assoc($employees)):
                    $has_rows=true;
                    $initials=strtoupper(substr($e['first_name'],0,1).substr($e['last_name'],0,1));
                    $color=$colors[$e['employee_id']%count($colors)];
                ?>
                <tr data-search="<?= strtolower($e['first_name'].' '.$e['last_name'].' '.$e['role']) ?>">
                    <td style="color:var(--muted)"><?= $i++ ?></td>
                    <td><div class="t-info">
                        <div class="t-avatar" style="background:<?= $color ?>"><?= $initials ?></div>
                        <div><div class="t-name"><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></div></div>
                    </div></td>
                    <td><span class="badge badge-active"><?= htmlspecialchars($e['role']) ?></span></td>
                    <td><div class="actions">
                        <button class="btn-sm btn-sm-edit" onclick='openEditModal(<?= json_encode(["employee_id"=>$e["employee_id"],"first_name"=>$e["first_name"],"last_name"=>$e["last_name"],"role"=>$e["role"]]) ?>)'>✏️ Edit</button>
                        <button class="btn-sm btn-sm-delete" onclick='openDeleteModal(<?= $e["employee_id"] ?>,<?= json_encode($e["first_name"]." ".$e["last_name"]) ?>)'>🗑️</button>
                    </div></td>
                </tr>
                <?php endwhile ?>
                <?php if (!$has_rows): ?><tr class="empty-row"><td colspan="4">👨‍💼 No employees yet. Add your first employee.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer"><span id="rowCount"><?= $total_employees ?> employee(s)</span></div>
    </div>
</div>

<!-- ADD MODAL -->
<div class="modal-backdrop" id="addModal"><div class="modal">
    <div class="modal-header"><h3>Add New Employee</h3><button class="modal-close" onclick="closeModal('addModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="add">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label>First Name</label><input type="text" name="first_name" placeholder="John" required></div>
                <div class="form-group"><label>Last Name</label><input type="text" name="last_name" placeholder="Doe" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="employee@example.com" required></div>
                <div class="form-group"><label>Hire Date</label><input type="date" name="hire_date" value="<?= date('Y-m-d') ?>" required></div>
            </div>
            <div class="form-group"><label>Role</label><input type="text" name="role" placeholder="Technician / Cleaner / Security" required></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn-submit">＋ Add Employee</button></div>
    </form>
</div></div>

<!-- EDIT MODAL -->
<div class="modal-backdrop" id="editModal"><div class="modal">
    <div class="modal-header"><h3>Edit Employee</h3><button class="modal-close" onclick="closeModal('editModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="employee_id" id="editEmployeeId">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label>First Name</label><input type="text" name="first_name" id="editFirst" required></div>
                <div class="form-group"><label>Last Name</label><input type="text" name="last_name" id="editLast" required></div>
            </div>
            <div class="form-group"><label>Role</label><input type="text" name="role" id="editRole" required></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn-submit">💾 Save Changes</button></div>
    </form>
</div></div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteModal"><div class="modal">
    <div class="modal-header"><h3 style="color:#C62828">Delete Employee</h3><button class="modal-close" onclick="closeModal('deleteModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="employee_id" id="deleteEmployeeId">
        <div class="modal-body"><div class="delete-warning"><div class="warning-icon">⚠️</div><p>Delete <strong id="deleteEmployeeName"></strong>? This cannot be undone.</p></div></div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn-danger">🗑️ Delete</button></div>
    </form>
</div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.open').forEach(m=>m.classList.remove('open'))});
function openAddModal(){openModal('addModal')}
function openEditModal(e){document.getElementById('editEmployeeId').value=e.employee_id;document.getElementById('editFirst').value=e.first_name;document.getElementById('editLast').value=e.last_name;document.getElementById('editRole').value=e.role;openModal('editModal')}
function openDeleteModal(id,name){document.getElementById('deleteEmployeeId').value=id;document.getElementById('deleteEmployeeName').textContent=name;openModal('deleteModal')}
function filterTable(){
    const q=document.getElementById('searchInput').value.toLowerCase();
    const rows=document.querySelectorAll('#employeeTable tbody tr:not(.empty-row)');
    let shown=0;
    rows.forEach(row=>{const match=!q||row.dataset.search.includes(q);row.style.display=match?'':'none';if(match)shown++;});
    document.getElementById('rowCount').textContent=`Showing ${shown} employee(s)`;
}
document.querySelectorAll('.alert').forEach(el=>setTimeout(()=>el.style.display='none',4000));
</script>
</body></html>
