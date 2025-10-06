<?php
// staff_admin.php
// For now, staff & admin data is static. Later you can connect this to a MySQL database.
$staff = [
    ["id" => 1, "name" => "John Doe", "role" => "Barista", "status" => "Active"],
    ["id" => 2, "name" => "Jane Smith", "role" => "Cashier", "status" => "Active"],
    ["id" => 3, "name" => "Mark Johnson", "role" => "Cleaner", "status" => "Inactive"]
];

$admins = [
    ["id" => 1, "name" => "Alice Admin", "email" => "alice@coffee.com"],
    ["id" => 2, "name" => "Bob Manager", "email" => "bob@coffee.com"]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staffing & Admin Panel</title>
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
                <li><a href="customers.php">Customers</a></li>
                <li><a href="#">Waste</a></li>
                <li><a href="#">Sales</a></li>
                <li><a href="staff_admin.php" class="active">Staff</a></li>
                <li><a href="#">Reports</a></li>
            </ul>
        </aside>

        <!-- Main -->
        <main class="main">
            <header>
                <h1>Staffing & Admin Management</h1>
                <button class="btn">+ Add Staff</button>
            </header>

            <!-- Staff Table -->
            <section class="box">
                <h2>Staff List</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($staff as $s): ?>
                        <tr>
                            <td><?php echo $s['id']; ?></td>
                            <td><?php echo $s['name']; ?></td>
                            <td><?php echo $s['role']; ?></td>
                            <td><?php echo $s['status']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <!-- Admin Table -->
            <section class="box">
                <h2>Admin Accounts</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($admins as $a): ?>
                        <tr>
                            <td><?php echo $a['id']; ?></td>
                            <td><?php echo $a['name']; ?></td>
                            <td><?php echo $a['email']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
