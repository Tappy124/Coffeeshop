<?php
include "db.php";

if (!isset($_GET['id'])) {
    die("Product ID not provided.");
}

$id = $_GET['id'];

// Get existing product
$result = $conn->query("SELECT * FROM products WHERE id=$id");
if ($result->num_rows == 0) {
    die("Product not found.");
}
$product = $result->fetch_assoc();

// Update product
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name  = $_POST['name'];
    $category = $_POST['category'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];

    $sql = "UPDATE products 
            SET name='$name', category='$category', price='$price', stock='$stock' 
            WHERE id=$id";

    if ($conn->query($sql)) {
        header("Location: products.php");
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
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="main" style="padding:20px;">
    <h1>Edit Product</h1>
    <form method="POST">
        <label>Product Name</label><br>
        <input type="text" name="name" value="<?= $product['name'] ?>" required><br><br>

        <label>Category</label><br>
        <input type="text" name="category" value="<?= $product['category'] ?>"><br><br>

        <label>Price</label><br>
        <input type="number" step="0.01" name="price" value="<?= $product['price'] ?>" required><br><br>

        <label>Stock</label><br>
        <input type="number" name="stock" value="<?= $product['stock'] ?>" required><br><br>

        <button type="submit" class="btn">Update Product</button>
    </form>
</div>
</body>
</html>
