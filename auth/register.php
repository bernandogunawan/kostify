<?php
session_start();
require_once '../config/database.php';

$error = '';
$mode  = $_GET['mode'] ?? 'tenant';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $mode    = $_POST['mode'];
    $first   = mysqli_real_escape_string($conn, $_POST['first_name']);
    $last    = mysqli_real_escape_string($conn, $_POST['last_name']);
    $email   = mysqli_real_escape_string($conn, $_POST['email']);
    $phone   = mysqli_real_escape_string($conn, $_POST['phone']);
    $pass    = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if ($pass != $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($mode == 'admin' && $_POST['access_code'] != ADMIN_SECRET_CODE) {
        $error = 'Invalid admin access code.';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);

        if ($mode == 'admin') {
            $check = mysqli_query($conn, "SELECT admin_id FROM admin WHERE email='$email'");
            if (mysqli_num_rows($check) > 0) {
                $error = 'Email already registered.';
            } else {
                $name = $first . ' ' . $last;
                mysqli_query($conn, "INSERT INTO admin (username, password_hash, email) VALUES ('$name','$hash','$email')");
                header('Location: login.php?mode=admin'); exit;
            }
        } else {
            $id_card = mysqli_real_escape_string($conn, $_POST['id_card_number'] ?? '');
            $dob     = mysqli_real_escape_string($conn, $_POST['date_of_birth'] ?? '');

            $check = mysqli_query($conn, "SELECT tenant_id FROM tenant WHERE email='$email'");
            if (mysqli_num_rows($check) > 0) {
                $error = 'Email already registered.';
            } else {
                mysqli_query($conn, "INSERT INTO tenant (first_name, last_name, email, phone, id_card_number, date_of_birth, password_hash)
                    VALUES ('$first','$last','$email','$phone','$id_card','$dob','$hash')");
                header('Location: login.php?mode=tenant'); exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>KOSTIFY — Register</title>
    <link rel="stylesheet" href="../css/auth.css">
</head>
<body>

<div class="left">
    <div class="logo-name">KOSTIFY</div>
    <div class="logo-sub">Kost Management</div>

    <div>
        <h1>Manage Your Kost<br><em>More Easily</em></h1>
        <p>A digital platform for tenants and Kost managers.</p>
        <div class="mode-switch">
            <a href="?mode=admin"  class="ms-btn <?= $mode=='admin'  ? 'active':'' ?>">🛡️ Admin</a>
            <a href="?mode=tenant" class="ms-btn <?= $mode=='tenant' ? 'active':'' ?>">🏠 Tenant</a>
        </div>
    </div>

    <div class="testimonial">
        <p>"KOSTIFY really helps me manage rooms and payments with ease."</p>
        <small>— Shanny, Kostify Tenant</small>
    </div>
</div>

<div class="right">
    <div class="card">

        <div class="tab-bar">
            <a href="login.php?mode=<?= $mode ?>"    class="tab-btn">Login</a>
            <a href="register.php?mode=<?= $mode ?>" class="tab-btn active">Register</a>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">⚠️ <?= $error ?></div>
        <?php endif ?>

        <h2>Create <span>Account</span></h2>
        <p class="sub">Join the KOSTIFY platform</p>

        <form method="POST" action="?mode=<?= $mode ?>">
            <input type="hidden" name="mode" value="<?= $mode ?>">

            <div class="row2">
                <div>
                    <label>First Name</label>
                    <input type="text" name="first_name" placeholder="First Name"
                        value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                </div>
                <div>
                    <label>Last Name</label>
                    <input type="text" name="last_name" placeholder="Last Name"
                        value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                </div>
            </div>

            <label>Email</label>
            <input type="email" name="email" placeholder="email@example.com"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

            <label>Phone Number</label>
            <input type="text" name="phone" placeholder="+62 812-3456-7890"
                value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">

            <?php if ($mode == 'tenant'): ?>
            <div class="row2">
                <div>
                    <label>ID Card Number</label>
                    <input type="text" name="id_card_number" placeholder="e.g. 3171XXXXXXXXXX"
                        value="<?= htmlspecialchars($_POST['id_card_number'] ?? '') ?>">
                </div>
                <div>
                    <label>Date of Birth</label>
                    <input type="text" name="date_of_birth" 
                        placeholder="YYYY-MM-DD (e.g. 1995-05-20)"
                        value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>">
                </div>
            </div>
            <?php endif ?>

            <?php if ($mode == 'admin'): ?>
            <label>🔐 Admin Access Code</label>
            <input type="password" name="access_code" placeholder="Enter admin access code">
            <small class="hint">Contact your system administrator for the code.</small>
            <?php endif ?>

            <label>Password</label>
            <input type="password" name="password" placeholder="Minimum 8 characters">

            <label>Confirm Password</label>
            <input type="password" name="confirm_password" placeholder="Repeat password">

            <label class="check"><input type="checkbox" required> I agree to the <a href="#">Terms & Conditions</a></label>

            <button type="submit">Create Account</button>
        </form>

        <p class="alt">Already have an account? <a href="login.php?mode=<?= $mode ?>">Login</a></p>

    </div>
</div>

</body>
</html>