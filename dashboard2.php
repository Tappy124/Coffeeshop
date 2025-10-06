<?php
// dashboard.php
// You can later connect this to your database for real data
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard</title>
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
                <li><a href="dashboard.php" class="active">Dashboard</a></li>
                <li><a href="products.php">Inventory</a></li>
                <li><a href="customers.php">Customers</a></li>
                <li><a href="#">Waste</a></li>
                <li><a href="#">Sales</a></li>
                <li><a href="staff_admin.php">Staff</a></li>
                <li><a href="#">Reports</a></li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main">
            <header>
                <h1>Analytics Dashboard</h1>
                <input type="text" placeholder="Search...">
            </header>

            <section class="cards">
                <div class="card">
                    <h3>Daily Sales</h3>
                    <p>$1250</p>
                </div>
                <div class="card">
                    <h3>Waste Cost</h3>
                    <p>$55</p>
                </div>
                <div class="card">
                    <h3>New Customers</h3>
                    <p>+30</p>
                </div>
            </section>

            <section class="charts">
                <div class="chart-box">
                    <h3>Sales Performance</h3>
                    <div class="chart">[Chart Here]</div>
                </div>
                <div class="chart-box">
                    <h3>Waste Tracking</h3>
                    <div class="chart">[Chart Here]</div>
                </div>
            </section>

            <section class="bottom">
                <div class="box">
                    <h3>Customer Management</h3>
                    <p>Track new and loyal customers.</p>
                </div>
                <div class="box">
                    <h3>Inventory Low Stock</h3>
                    <p>List of items to reorder.</p>
                </div>
                <div class="box">
                    <h3>Upcoming Deliveries</h3>
                    <p>Next delivery schedule.</p>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
