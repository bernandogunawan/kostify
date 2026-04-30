<?php
session_start();
require_once '../config/database.php';

$error = '';
$mode  = $_GET['mode'] ?? 'tenant';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $mode  = $_POST['mode'];
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $pass  = $_POST['password'];

    if ($mode == 'admin') {
        $result = mysqli_query($conn, "SELECT * FROM admin WHERE email = '$email'");
        $row    = mysqli_fetch_assoc($result);
        if ($row && (password_verify($pass, $row['password_hash']) || $pass == $row['password_hash'])) {
            $_SESSION['user_id']   = $row['admin_id'];
            $_SESSION['user_name'] = $row['username'];
            $_SESSION['role']      = 'admin';
            header('Location: ../pages/admin_dashboard.php'); exit;
        } else {
            $error = 'Invalid email or password';
        }
    } else {
        $result = mysqli_query($conn, "SELECT * FROM tenant WHERE email = '$email'");
        $row    = mysqli_fetch_assoc($result);
        if ($row && (password_verify($pass, $row['password_hash']) || $pass == $row['password_hash'])) {
            $_SESSION['user_id']   = $row['tenant_id'];
            $_SESSION['user_name'] = $row['first_name'] . ' ' . $row['last_name'];
            $_SESSION['role']      = 'tenant';
            header('Location: ../pages/tenant_dashboard.php'); exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>KOSTIFY — Login</title>
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
            <a href="login.php?mode=<?= $mode ?>"    class="tab-btn active">Login</a>
            <a href="register.php?mode=<?= $mode ?>" class="tab-btn">Register</a>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">⚠️ <?= $error ?></div>
        <?php endif ?>

        <h2>Welcome <span>Back</span></h2>
        <p class="sub"><?= $mode == 'admin' ? 'Log in to your admin account' : 'Log in to your account' ?></p>

        <form method="POST" action="?mode=<?= $mode ?>">
            <input type="hidden" name="mode" value="<?= $mode ?>">

            <label>Email</label>
            <input type="email" name="email" placeholder="email@example.com"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

            <label>Password</label>
            <input type="password" name="password" placeholder="Enter your password">

            <div class="row-between">
                <label><input type="checkbox"> Remember me</label>
                <a href="#">Forgot password?</a>
            </div>

            <button type="submit">Login Now</button>
        </form>

        <p class="alt">Don't have an account? <a href="register.php?mode=<?= $mode ?>">Register</a></p>

    </div>
</div>

</body>
</html>