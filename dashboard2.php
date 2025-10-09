<?php
// fetch metrics
$mysqli = new mysqli("localhost", "root", "", "coffee_shop");
if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: " . $mysqli->connect_error);
}

$daily_sales = 0;
$result = $mysqli->query("SELECT SUM(total_amount) AS sales FROM sales WHERE DATE(sale_date) = CURDATE()");
if ($result && $row = $result->fetch_assoc()) {
    $daily_sales = $row['sales'] ?? 0;
}
if ($result) $result->free();

$waste_cost = 0;
$result = $mysqli->query("SELECT SUM(cost) AS waste FROM waste WHERE DATE(waste_date) = CURDATE()");
if ($result && $row = $result->fetch_assoc()) {
    $waste_cost = $row['waste'] ?? 0;
}
if ($result) $result->free();

$new_customers = 0;
$result = $mysqli->query("SELECT COUNT(*) AS new_cust FROM customers WHERE DATE(joined_at) = CURDATE()");
if ($result && $row = $result->fetch_assoc()) {
    $new_customers = $row['new_cust'] ?? 0;
}
if ($result) $result->free();

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Analytics Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo" style="text-align:center; padding:20px 0;">
                <img src="images/logo.png" alt="Logo" style="max-width:110px; height:auto;">
            </div>
            <h2 style="padding-left:18px; color:#fff;">Dashboard</h2>
            <ul style="list-style:none; padding-left:18px;">
                <li><a href="dashboard2.php" class="active">Dashboard</a></li>
                <li><a href="products.php">Inventory</a></li>
                <li><a href="customers.php">Customers</a></li>
                <li><a href="#">Waste</a></li>
                <li><a href="sales.php">Sales</a></li>
                <li><a href="staff_admin.php">Staff</a></li>
                <li><a href="#">Reports</a></li>
            </ul>
        </aside>

        <main class="main">
            <header>
                <h1 style="margin:0;">Analytics Dashboard</h1>
                <input type="text" placeholder="Search..." style="padding:8px 10px; border-radius:6px; border:1px solid #ccc;">
            </header>

            <section class="cards" aria-label="summary cards">
                <div class="card" style="background:#fff; padding:18px; border-radius:8px;">
                    <h3 style="margin:0 0 8px 0;">Daily Sales</h3>
                    <p style="margin:0; font-weight:700;">₱<?php echo number_format($daily_sales, 2); ?></p>
                </div>
                <div class="card" style="background:#fff; padding:18px; border-radius:8px;">
                    <h3 style="margin:0 0 8px 0;">Waste Cost</h3>
                    <p style="margin:0; font-weight:700;">₱<?php echo number_format($waste_cost, 2); ?></p>
                </div>
                <div class="card" style="background:#fff; padding:18px; border-radius:8px;">
                    <h3 style="margin:0 0 8px 0;">New Customers</h3>
                    <p style="margin:0; font-weight:700;">+<?php echo intval($new_customers); ?></p>
                </div>
            </section>

            <section class="charts" style="margin-top:20px;">
                <div class="chart-box" style="background:#fafafa; padding:18px; border-radius:8px; min-height:180px;">
                    <h3>Sales Performance</h3>
                    <div class="chart" style="padding-top:40px; color:#888;">[Chart Here]</div>
                </div>
                <div class="chart-box" style="background:#fafafa; padding:18px; border-radius:8px; min-height:180px;">
                    <h3>Waste Tracking</h3>
                    <div class="chart" style="padding-top:40px; color:#888;">[Chart Here]</div>
                </div>
            </section>

            <section class="bottom" style="margin-top:20px;">
                <div class="box" style="background:#fff; padding:18px; border-radius:8px;">
                    <h3>Customer Management</h3>
                    <p>Track new and loyal customers.</p>
                </div>
                <div class="box" style="background:#fff; padding:18px; border-radius:8px;">
                    <h3>Inventory Low Stock</h3>
                    <p>List of items to reorder.</p>
                </div>
                <div class="box" style="background:#fff; padding:18px; border-radius:8px;">
                    <h3>Upcoming Deliveries</h3>
                    <p>Next delivery schedule.</p>
                </div>
            </section>
        </main>
    </div>
</body>
</html>

