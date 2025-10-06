<?php
$host = "localhost";
$user = "root";   // default XAMPP MySQL user
$pass = "";       // default password in XAMPP
$db   = "coffee_shop"; // make sure this database exists in phpMyAdmin

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>
