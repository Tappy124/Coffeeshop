<?php
include "db.php";
$result = $conn->query("SELECT * FROM products");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Product Management</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">


        <!-- Sidebar -->
        <aside class="sidebar">
                <div class="logo">
        <img src="images/logo.png" alt="Logo">
    </div>
            <h2>Dashboard</h2>
            <ul>
                <li><a href="dashboard2.php">Dashboard</a></li>
                <li><a href="products.php" class="active">Inventory</a></li>
                <li><a href="customers.php">Customers</a></li>
                <li><a href="#">Waste</a></li>
                <li><a href="#">Sales</a></li>
                <li><a href="staff_admin.php">Staff</a></li>
                <li><a href="#">Reports</a></li>
            </ul>
        </aside>

    <!-- Main -->
    <main class="main">
        <header>
            <h1>Products</h1>
            <a href="add_products.php" class="btn">+ Add Product</a>
        </header>

        <section class="box">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= $row['name'] ?></td>
                        <td><?= $row['category'] ?></td>
                        <td>₱<?= number_format($row['price'], 2) ?></td>
                        <td><?= $row['stock'] ?></td>
                        <td>
                            <a href="edit_product.php?id=<?= $row['id'] ?>">Edit</a> | 
                            <a href="delete_product.php?id=<?= $row['id'] ?>" onclick="return confirm('Delete this product?')">Delete</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>
</body>
</html>
