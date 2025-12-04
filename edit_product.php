<?php
session_start();

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- Authentication Check (allow both roles to edit) ---
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

include "includes/db.php";
$categories = ['Basic Ingredient', 'Cups and Lids', 'Other Supplies', 'Powders', 'Syrup', 'Sinkers'];


if (!isset($_GET['id'])) {
    die("Product ID not provided.");
}

$id = $_GET['id'];
$product = null;
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->bind_param("s", $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $product = $result->fetch_assoc();
} else {
    die("Product not found.");
}
$stmt->close();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name  = $_POST['name'];
    $category = $_POST['category'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    // Note: We don't regenerate the ID on edit to maintain its unique identity.

    $stmt = $conn->prepare("UPDATE products SET name = ?, category = ?, price = ?, stock = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ssdis", $name, $category, $price, $stock, $id);
        $stmt->execute();
        $stmt->close();
        header("Location: inventory_products.php");
        exit;
    } else {
        echo "Error: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Product</title>
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="main">
    <h1>Edit Product</h1>
    <form method="POST">
        <label>Product Name</label><br>
        <input type="text" name="name" value="<?= $product['name'] ?>" required><br><br>

        <label>Category</label><br>
        <select name="category" required>
            <option value="" disabled>Select a category</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= ($product['category'] == $cat) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat) ?>
                </option>
            <?php endforeach; ?>
        </select><br><br>

        <label>Price</label><br>
        <input type="number" step="0.01" name="price" value="<?= $product['price'] ?>" required><br><br>

        <label>Stock</label><br>
        <input type="number" name="stock" value="<?= $product['stock'] ?>" required><br><br>

        <button type="submit" class="btn">Update Product</button>
    </form>
</div>
</body>
</html>
