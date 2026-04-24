<?php
define('ADMIN_SECRET_CODE', 'apayah');

$host     = "localhost";
$user     = "root";
$password = "";
$db       = "kostify_db";

$conn = mysqli_connect($host, $user, $password, $db);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>