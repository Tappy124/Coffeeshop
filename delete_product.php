<?php
session_start();

// --- Authentication Check (allow both roles to delete) ---
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include "includes/db.php";

if (!isset($_GET['id'])) {
    die("Product ID not provided.");
}

$id = $_GET['id'];
$type = $_GET['type'] ?? 'inventory'; // Default to 'inventory' if not specified

$table_to_delete_from = 'products'; // Default table
$redirect_page = 'inventory_products.php';
if ($type === 'menu') {
    $table_to_delete_from = 'menu_product';
    $redirect_page = 'products_menu.php';
}

if ($stmt = $conn->prepare("DELETE FROM $table_to_delete_from WHERE " . ($type === 'menu' ? 'product_id' : 'id') . " = ?")) {
    $stmt->bind_param("s", $id);
    if ($stmt->execute()) {
        $stmt->close();
        echo "<script>
            localStorage.setItem('productMessage', 'Product Deleted Successfully!');
            window.location.href='$redirect_page';
        </script>";
        exit;
    } else {
        echo "Error deleting record: " . $stmt->error;
    }
}
?>
