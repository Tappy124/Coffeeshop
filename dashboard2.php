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

            <?php
    // Connect to DB
    $mysqli = new mysqli("localhost", "root", "", "coffee_shop");
    if ($mysqli->connect_errno) {
        die("Failed to connect to MySQL: " . $mysqli->connect_error);
    }
    
    // Get Daily Sales (sum of today's sales)
    $daily_sales = 0;
    $result = $mysqli->query("SELECT SUM(total_amount) AS sales FROM sales WHERE DATE(sale_date) = CURDATE()");
    if ($row = $result->fetch_assoc()) {
        $daily_sales = $row['sales'] ?? 0;
    }
    $result->free();
    
    // Get Waste Cost (sum of today's waste cost)
    $waste_cost = 0;
    $result = $mysqli->query("SELECT SUM(cost) AS waste FROM waste WHERE DATE(waste_date) = CURDATE()");
    if ($row = $result->fetch_assoc()) {
        $waste_cost = $row['waste'] ?? 0;
    }
    $result->free();
    
    // Get New Customers (joined today)
    $new_customers = 0;
    $result = $mysqli->query("SELECT COUNT(*) AS new_cust FROM customers WHERE DATE(joined_at) = CURDATE()");
    if ($row = $result->fetch_assoc()) {
        $new_customers = $row['new_cust'] ?? 0;
    }
    $result->free();
    
    $mysqli->close();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Analytics Dashboard</title>
        <link rel="stylesheet" href="css/style.css">
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
                    <li><a href="dashboard2.php" class="active">Dashboard</a></li>
                    <li><a href="products.php">Inventory</a></li>
                    <li><a href="customers.php">Customers</a></li>
                    <li><a href="#">Waste</a></li>
                    <li><a href="sales.php">Sales</a></li>
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
                        <p>$<?php echo number_format($daily_sales, 2); ?></p>
                    </div>
                    <div class="card">
                        <h3>Waste Cost</h3>
                        <p>$<?php echo number_format($waste_cost, 2); ?></p>
                    </div>
                    <div class="card">
                        <h3>New Customers</h3>
                        <p>+<?php echo $new_customers; ?></p>
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

