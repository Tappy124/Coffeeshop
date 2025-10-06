<?php
include "db.php";
$result = $conn->query("SELECT * FROM customers");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Customer Management</title>
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
                <li><a href="products.php">Inventory</a></li>
                <li><a href="customers.php" class="active">Customers</a></li>
                <li><a href="#">Waste</a></li>
                <li><a href="#">Sales</a></li>
                <li><a href="staff_admin.php">Staff</a></li>
                <li><a href="#">Reports</a></li>
            </ul>
        </aside>

    <!-- Main -->
    <main class="main">
        <header>
            <h1>Customers</h1>
            <a href="add_customer.php" class="btn">+ Add Customer</a>
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
</body>
</html>
