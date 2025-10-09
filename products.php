<?php
include "includes/db.php";


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $name     = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $price    = (float)($_POST['price'] ?? 0);
    $stock    = (int)($_POST['stock'] ?? 0);

    $stmt = $conn->prepare("INSERT INTO products (name, category, price, stock) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssdi", $name, $category, $price, $stock);
        $stmt->execute();
        $stmt->close();
        header("Location: products.php");
        exit;
    } else {
        $error = "Prepare failed: " . $conn->error;
    }
}


$result = $conn->query("SELECT * FROM products ORDER BY id DESC");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Product Management</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
    
</head>
<body>
<div class="container">

  
    <aside class="sidebar">
        <div class="logo">
            <img src="images/logo.png" alt="Logo" style="max-width:120px; display:block; margin:20px auto 8px;">
        </div>
        <h2>Dashboard</h2>
        <ul>
            <li><a href="dashboard2.php">Dashboard</a></li>
            <li><a href="products.php" class="active">Inventory</a></li>
            <li><a href="customers.php">Customers</a></li>
            <li><a href="#">Waste</a></li>
            <li><a href="sales.php">Sales</a></li>
            <li><a href="staff_admin.php">Staff</a></li>
            <li><a href="#">Reports</a></li>
        </ul>
    </aside>

 
    <main class="main">
        <header>
            <div class="top-row" style="width:100%;">
                <h1>Products</h1>
                <button class="btn" id="openAddProduct">+ Add Product</button>
            </div>
        </header>

        <section class="box">
            <?php if (!empty($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

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
                <?php if ($result && $result->num_rows): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['id']) ?></td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= htmlspecialchars($row['category']) ?></td>
                            <td>₱<?= number_format($row['price'], 2) ?></td>
                            <td><?= htmlspecialchars($row['stock']) ?></td>
                            <td>
                                <a href="edit_product.php?id=<?= $row['id'] ?>" class="action-btn edit-btn">Edit</a>
                                <a href="delete_product.php?id=<?= $row['id'] ?>" class="action-btn delete-btn" onclick="return confirm('Delete this product?')">Delete</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center;padding:20px;">No products found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>


<div class="modal" id="addProductModal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="addProductTitle">
        <span class="close" id="closeModal">&times;</span>
        <h2 id="addProductTitle">Add New Product</h2>
        <form method="POST" action="products.php">
            <input type="hidden" name="add_product" value="1">
            <label>Product Name</label>
            <input type="text" name="name" required>

            <label>Category</label>
            <input type="text" name="category">

            <label>Price</label>
            <input type="number" step="0.01" name="price" required>

            <label>Stock</label>
            <input type="number" name="stock" required>

            <button type="submit">Save Product</button>
        </form>
    </div>
</div>

<script>
   
    const openBtn = document.getElementById('openAddProduct');
    const modal = document.getElementById('addProductModal');
    const closeBtn = document.getElementById('closeModal');

    openBtn.addEventListener('click', () => {
        modal.style.display = 'block';
        modal.setAttribute('aria-hidden','false');
    });
    closeBtn.addEventListener('click', () => {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden','true');
    });
    window.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden','true');
        }
    });
    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden','true');
        }
    });
</script>
</body>
</html>
