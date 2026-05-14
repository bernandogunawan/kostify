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

$__photo_col = @mysqli_query($conn, "SHOW COLUMNS FROM `room` LIKE 'photo_path'");
if ($__photo_col && mysqli_num_rows($__photo_col) === 0) {
    @mysqli_query($conn, "ALTER TABLE `room` ADD COLUMN `photo_path` VARCHAR(500) NULL DEFAULT NULL");
}
if ($__photo_col) {
    mysqli_free_result($__photo_col);
}
?>