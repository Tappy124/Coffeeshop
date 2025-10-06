<?php
include "db.php";

if (!isset($_GET['id'])) {
    die("Product ID not provided.");
}

$id = $_GET['id'];

// Delete product
$sql = "DELETE FROM products WHERE id=$id";

if ($conn->query($sql)) {
    header("Location: products.php");
    exit;
} else {
    echo "Error: " . $conn->error;
}
?>
