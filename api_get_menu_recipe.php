<?php
session_start();
header('Content-Type: application/json');

// Security check: Ensure an admin is logged in and a product ID is provided.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin' || !isset($_GET['product_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or missing product ID.']);
    exit;
}

include "includes/db.php";

$product_id = $_GET['product_id'];
$response = ['success' => false, 'recipe' => []];

$stmt = $conn->prepare("SELECT size, ingredient_product_id, amount_used, unit FROM product_recipes WHERE drink_product_id = ? ORDER BY size, id");
$stmt->bind_param("s", $product_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $recipe = [];
    while ($row = $result->fetch_assoc()) {
        // Group ingredients by size for easy processing in JavaScript
        $recipe[$row['size']][] = [
            'ingredient_id' => $row['ingredient_product_id'],
            'amount' => $row['amount_used'],
            'unit' => $row['unit']
        ];
    }
    $response['success'] = true;
    $response['recipe'] = $recipe;
}

$stmt->close();
$conn->close();

echo json_encode($response);
?>