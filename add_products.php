<?php
include "db.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name  = $_POST['name'];
    $category = $_POST['category'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];

    $sql = "INSERT INTO products (name, category, price, stock) 
            VALUES ('$name', '$category', '$price', '$stock')";
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
    <title>Add Product</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="main" style="padding:20px;">
    <h1>Add New Product</h1>
    <form method="POST">
        <label>Product Name</label><br>~
        <input type="text" name="name" required><br><br>

        <label>Category</label><br>
        <input type="text" name="category"><br><br>

        <label>Price</label><br>
        <input type="number" step="0.01" name="price" required><br><br>

        <label>Stock</label><br>
        <input type="number" name="stock" required><br><br>

        <button type="submit" class="btn">Save Product</button>
    </form>
</div>
</body>
</html>
