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
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
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

        <main class="main">
            <header>
                <h1>Analytics Dashboard</h1>
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="text" placeholder="Search..." >
                    <?php include 'includes/theme-toggle.php'; ?>
                </div>
            </header>

            <section class="cards" aria-label="summary cards">
                <div class="card" >
                    <h3 >Daily Sales</h3>
                    <p>₱<?php echo number_format($daily_sales, 2); ?></p>
                </div>
                <div class="card" >
                    <h3>Waste Cost</h3>
                    <p>₱<?php echo number_format($waste_cost, 2); ?></p>
                </div>
                <div class="card" >
                    <h3>New Customers</h3>
                    <p>+<?php echo intval($new_customers); ?></p>
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
<script>
    // theme toggle: reads/stores 'theme' in localStorage and toggles body.dark-mode
    (function(){
        const body = document.body;
        const btn = document.getElementById('themeToggle');

        function applyTheme(mode){
            if(mode === 'dark'){
                body.classList.add('dark-mode');
                btn.textContent = 'Light';
                btn.setAttribute('aria-pressed','true');
            } else {
                body.classList.remove('dark-mode');
                btn.textContent = 'Dark';
                btn.setAttribute('aria-pressed','false');
            }
        }

        // init
        const saved = localStorage.getItem('site-theme');
        applyTheme(saved === 'dark' ? 'dark' : 'light');

        btn.addEventListener('click', ()=>{
            const isDark = body.classList.toggle('dark-mode');
            const newMode = isDark ? 'dark' : 'light';
            localStorage.setItem('site-theme', newMode);
            applyTheme(newMode);
        });
    })();
</script>
</html>

