<?php
include "includes/db.php";


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_customer'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $joined_at = date('Y-m-d H:i:s');
    $conn->query("INSERT INTO customers (name, email, phone, joined_at) VALUES ('$name', '$email', '$phone', '$joined_at')");
    header("Location: customers.php");
    exit;
}

$result = $conn->query("SELECT * FROM customers");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Customer Management</title>
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
            <li><a href="customers.php" class="active">Customers</a></li>
            <li><a href="#">Waste</a></li>
            <li><a href="sales.php">Sales</a></li>
            <li><a href="staff_admin.php">Staff</a></li>
            <li><a href="#">Reports</a></li>
        </ul>
    </aside>

    <main class="main">
        <header>
            <h1>Customers</h1>
            <div style="display:flex; gap:10px; align-items:center;">
                    <button class="btn" id="addCustomerBtn">+ Add Customer</button>
                    <?php include 'includes/theme-toggle.php'; ?>
                </div>
            
        </header>

        <section class="box">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= $row['name'] ?></td>
                        <td><?= $row['email'] ?></td>
                        <td><?= $row['phone'] ?></td>
                        <td><?= $row['joined_at'] ?></td>
                        <td>
                            <a href="edit_customer.php?id=<?= $row['id'] ?>">Edit</a> | 
                            <a href="delete_customer.php?id=<?= $row['id'] ?>" onclick="return confirm('Delete this customer?')">Delete</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>


<div class="modal" id="addCustomerModal">
    <div class="modal-content">
        <span class="close" id="closeModal">&times;</span>
        <h2>Add Customer</h2>
        <form method="POST" action="customers.php">
            <input type="hidden" name="add_customer" value="1">
            <label>Name:</label>
            <input type="text" name="name" required>
            <label>Email:</label>
            <input type="email" name="email" required>
            <label>Phone:</label>
            <input type="text" name="phone" required>
            <button type="submit">Add Customer</button>
        </form>
    </div>
</div>
<script>
    document.getElementById('addCustomerBtn').onclick = function() {
        document.getElementById('addCustomerModal').style.display = 'block';
    };
    document.getElementById('closeModal').onclick = function() {
        document.getElementById('addCustomerModal').style.display = 'none';
    };
    window.onclick = function(event) {
        if (event.target == document.getElementById('addCustomerModal')) {
            document.getElementById('addCustomerModal').style.display = 'none';
        }
    };
</script>
</body>
</html>
