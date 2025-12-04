<?php
session_start();
header('Content-Type: application/json');

// Basic security checks
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin' || !isset($_GET['supplier_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include "includes/db.php";

$supplier_id = (int)$_GET['supplier_id'];
$response = ['success' => false, 'products' => [], 'products_details' => []];

$stmt = $conn->prepare("
    SELECT p.id, p.name, sp.default_quantity
    FROM supplier_products sp 
    JOIN products p ON sp.product_id = p.id 
    WHERE sp.supplier_id = ?
");
$stmt->bind_param("i", $supplier_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $products = $result->fetch_all(MYSQLI_ASSOC);
    $response['success'] = true;
    $response['products'] = array_column($products, 'id'); // Array of just IDs
    $response['products_details'] = $products; // Array of full product details
}

$stmt->close();
$conn->close();

echo json_encode($response);
