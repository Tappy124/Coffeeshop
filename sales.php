<?php

include "includes/db.php";


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sale'])) {
    $product = $_POST['product'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $total_amount = (float)($_POST['total_amount'] ?? 0);
    $sale_date = date('Y-m-d H:i:s');

    // Use prepared statement to avoid SQL injection and capture errors
    $stmt = $conn->prepare("INSERT INTO sales (product, quantity, total_amount, sale_date) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sids", $product, $quantity, $total_amount, $sale_date);
        if (!$stmt->execute()) {
            $form_error = "Insert failed: " . $stmt->error;
        } else {
            $stmt->close();
            header("Location: sales.php");
            exit;
        }
    } else {
        $form_error = "Prepare failed: " . $conn->error;
    }
}


$result = $conn->query("SELECT * FROM sales ORDER BY sale_date DESC");
$rows = [];
if ($result === false) {
    // Capture query error but avoid calling methods on a boolean
    $query_error = $conn->error;
} else {
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sales Management</title>
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
   
</head>
<body>
<div class="container">

    
    <aside class="sidebar">
        <div class="logo">
            <img src="images/logo.png" alt="Logo">
        </div>
        <h2>Dashboard</h2>
        <ul>
            <li><a href="dashboard2.php">Dashboard</a></li>
            <li><a href="products.php">Inventory</a></li>
            <li><a href="customers.php">Customers</a></li>
            <li><a href="#">Waste</a></li>
            <li><a href="sales.php" class="active">Sales</a></li>
            <li><a href="staff_admin.php">Staff</a></li>
            <li><a href="#">Reports</a></li>
        </ul>
    </aside>

  
    <main class="main">
        <header>
            <h1>Sales</h1>
            
            <div style="display:flex; gap:10px; align-items:center;">
                    <button class="btn" id="addSaleBtn">+ Add Sale</button>
                    <?php include 'includes/theme-toggle.php'; ?>
                </div>
              
        </header>
        <section class="box">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Total Amount</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($rows as $row): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['product']) ?></td>
                        <td><?= $row['quantity'] ?></td>
                        <td>$<?= number_format($row['total_amount'], 2) ?></td>
                        <td><?= $row['sale_date'] ?></td>
                        <td>
                            <a href="delete_sale.php?id=<?= $row['id'] ?>" class="action-btn delete-btn" onclick="return confirm('Delete this sale?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>


<div class="modal" id="addSaleModal">
    <div class="modal-content">
        <span class="close" id="closeModal">&times;</span>
        <h2 style="margin-top:0; color:#4a6c6f; text-align:center;">Add Sale</h2>
        <form method="POST" action="sales.php">
            <input type="hidden" name="add_sale" value="1">
            <label>Product:</label>
            <input type="text" name="product" required>
            <label>Quantity:</label>
            <input type="number" name="quantity" min="1" required>
            <label>Total Amount:</label>
            <input type="number" name="total_amount" step="0.01" min="0" required>
            <button type="submit">Add Sale</button>
        </form>
    </div>
</div>
<script>
    document.getElementById('addSaleBtn').onclick = function() {
        document.getElementById('addSaleModal').style.display = 'block';
    };
    document.getElementById('closeModal').onclick = function() {
        document.getElementById('addSaleModal').style.display = 'none';
    };
    window.onclick = function(event) {
        if (event.target == document.getElementById('addSaleModal')) {
            document.getElementById('addSaleModal').style.display = 'none';
        }
    };
</script>
</body>
</html>