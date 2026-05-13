<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'tenant') {
    header('Location: ../auth/login.php?mode=tenant'); 
    exit;
}

$tenant_id = $_SESSION['user_id'];

/* ── Handle profile update ── */
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $email      = trim($_POST['email']       ?? '');
    $phone      = trim($_POST['phone']       ?? '');

    if ($first_name === '' || $last_name === '' || $email === '') {
        $error_msg = 'First name, last name, and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Please enter a valid email address.';
    } else {
        $fn = mysqli_real_escape_string($conn, $first_name);
        $ln = mysqli_real_escape_string($conn, $last_name);
        $em = mysqli_real_escape_string($conn, $email);
        $ph = mysqli_real_escape_string($conn, $phone);

        $update = mysqli_query($conn,
            "UPDATE tenant SET first_name='$fn', last_name='$ln', email='$em', phone='$ph'
             WHERE tenant_id=$tenant_id"
        );

        if ($update) {
            // Update session name
            $_SESSION['user_name'] = $first_name . ' ' . $last_name;
            $success_msg = 'Profile updated successfully!';
        } else {
            $error_msg = 'Something went wrong. Please try again.';
        }
    }
}

$tenant_query = mysqli_query($conn,
    "SELECT * FROM tenant WHERE tenant_id = $tenant_id"
);
$tenant = mysqli_fetch_assoc($tenant_query);

$booking_stats = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total_bookings, 
           SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_bookings
     FROM booking 
     WHERE tenant_id = $tenant_id"
));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOSTIFY — My Profile</title>
    <link rel="stylesheet" href="../css/tenant_profile.css">
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">KOSTIFY <span>TENANT PORTAL</span></div>
    <nav class="nav">
        <a href="tenant_dashboard.php">🏠 Dashboard</a>
        <a href="tenant_room.php">🚪 My Room</a>
        <a href="tenant_payments.php">💳 Payments</a>
        <a href="tenant_maintenance.php">🔧 Maintenance</a>
        <a href="tenant_profile.php" class="active">👤 My Profile</a>
    </nav>
    <div class="sidebar-bottom">
        <div class="user-info">
            <div class="user-avatar">
                <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
            </div>
            <div>
                <div class="user-name"><?= htmlspecialchars($_SESSION['user_name']) ?></div>
                <div class="user-role">Tenant</div>
            </div>
        </div>
        <a href="../auth/logout.php" class="logout-btn">🚪 Logout</a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div>
            <h1>My Profile</h1>
            <p>View and manage your personal information</p>
        </div>
        <div class="topbar-date">📅 <?= date('D, d M Y') ?></div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error">⚠️ <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <div class="profile-container">
        <!-- LEFT: Avatar & Stats -->
        <div class="profile-card">
            <div class="profile-avatar-large">
                <?= strtoupper(substr($tenant['first_name'], 0, 1) . substr($tenant['last_name'], 0, 1)) ?>
            </div>
            <div class="profile-name">
                <?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?>
            </div>
            <div class="profile-role">Tenant</div>
            
            <div class="profile-stat" style="margin-top:20px;">
                <span>Total Bookings</span>
                <span><?= $booking_stats['total_bookings'] ?></span>
            </div>
            <div class="profile-stat">
                <span>Active Rooms</span>
                <span><?= $booking_stats['active_bookings'] ?></span>
            </div>
            <div class="profile-stat" style="border-bottom: 1px solid var(--border);">
                <span>Member Since</span>
                <span><?= date('M Y', strtotime($tenant['created_at'] ?? 'now')) ?></span>
            </div>

            <button id="editProfileBtn" class="edit-profile-btn" onclick="openEditModal()">✏️ Edit Profile</button>
        </div>

        <!-- RIGHT: Details -->
        <div class="settings-card">
            <div class="settings-header">
                <h2>Personal Details</h2>
            </div>
            <div class="settings-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name</label>
                        <div class="form-value"><?= htmlspecialchars($tenant['first_name']) ?></div>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <div class="form-value"><?= htmlspecialchars($tenant['last_name']) ?></div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <div class="form-value"><?= htmlspecialchars($tenant['email']) ?></div>
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <div class="form-value"><?= htmlspecialchars($tenant['phone'] ?: '—') ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- EDIT PROFILE MODAL -->
<div id="editModal" class="modal-backdrop" onclick="if(event.target===this)closeEditModal()">
    <div class="modal">
        <div class="modal-header">
            <h3>Edit Profile</h3>
            <button class="modal-close" onclick="closeEditModal()">✕</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name <span style="color:var(--rose)">*</span></label>
                        <input type="text" id="first_name" name="first_name"
                               value="<?= htmlspecialchars($tenant['first_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name <span style="color:var(--rose)">*</span></label>
                        <input type="text" id="last_name" name="last_name"
                               value="<?= htmlspecialchars($tenant['last_name']) ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="email">Email Address <span style="color:var(--rose)">*</span></label>
                    <input type="email" id="email" name="email"
                           value="<?= htmlspecialchars($tenant['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone"
                           value="<?= htmlspecialchars($tenant['phone']) ?>"
                           placeholder="e.g. 08123456789">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" name="update_profile" class="btn-submit">💾 Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal()  { document.getElementById('editModal').classList.add('open'); document.body.style.overflow='hidden'; }
function closeEditModal() { document.getElementById('editModal').classList.remove('open'); document.body.style.overflow=''; }
document.addEventListener('keydown', e => { if(e.key==='Escape') closeEditModal(); });
document.querySelectorAll('.alert').forEach(el => setTimeout(() => el.style.display='none', 4000));
<?php if ($error_msg): ?>openEditModal();<?php endif; ?>
</script>

</body>
</html>
