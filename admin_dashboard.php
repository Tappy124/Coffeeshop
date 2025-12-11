<?php
session_start();

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- Authentication Check ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit;
}

include "includes/db.php";

// --- Time Range Filtering Logic ---
$time_range = trim($_GET['range'] ?? 'weekly'); // Default to weekly
$interval_sql = "INTERVAL 6 DAY"; // Default for weekly
$date_format_sql = '%Y-%m-%d'; // For grouping daily

switch ($time_range) {
    case 'daily':
        // For daily, we look at the last 24 hours, grouped by hour
        $interval_sql = "INTERVAL 23 HOUR";
        $date_format_sql = '%Y-%m-%d %H:00';
        break;
    case 'monthly':
        // For monthly, we look at the last 30 days, grouped by day
        $interval_sql = "INTERVAL 29 DAY";
        break;
}

// --- Fetch Metrics for Summary Cards ---
$total_sales = 0;
$result = $conn->query("SELECT SUM(total_amount) AS sales FROM sales WHERE sale_date >= NOW() - $interval_sql");
if ($result && $row = $result->fetch_assoc()) {
    $total_sales = $row['sales'] ?? 0;
}
if ($result) $result->free();

$total_waste_cost = 0;
// Use the pre-calculated waste_cost from the waste table
$result = $conn->query("SELECT SUM(waste_cost) AS waste FROM waste WHERE waste_date >= NOW() - $interval_sql");
if ($result && $row = $result->fetch_assoc()) {
    $total_waste_cost = $row['waste'] ?? 0;
}
if ($result) $result->free();

// --- Fetch Data for Charts ---
$sales_vs_waste_data = $conn->query("
    SELECT 
        DATE_FORMAT(all_dates.date_point, '$date_format_sql') AS time_point,
        COALESCE(s.total_sales, 0) AS sales,
        COALESCE(w.total_waste, 0) AS waste
    FROM (
        SELECT DATE_SUB(NOW(), INTERVAL (a.a + (10 * b.a)) " . ($time_range === 'daily' ? 'HOUR' : 'DAY') . ") AS date_point
        FROM (SELECT 0 AS a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a
        CROSS JOIN (SELECT 0 AS a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b
    ) AS all_dates
    LEFT JOIN (SELECT DATE_FORMAT(sale_date, '$date_format_sql') as sale_day, SUM(total_amount) as total_sales FROM sales GROUP BY sale_day) s ON s.sale_day = DATE_FORMAT(all_dates.date_point, '$date_format_sql')
    LEFT JOIN (SELECT DATE_FORMAT(waste_date, '$date_format_sql') as waste_day, SUM(waste_cost) as total_waste FROM waste GROUP BY waste_day) w ON w.waste_day = DATE_FORMAT(all_dates.date_point, '$date_format_sql')
    WHERE all_dates.date_point BETWEEN NOW() - $interval_sql AND NOW()
    GROUP BY time_point
    ORDER BY time_point ASC
")->fetch_all(MYSQLI_ASSOC);

// --- Forecasting Logic for Sales vs Waste Chart ---
$forecast_points = [];
if (count($sales_vs_waste_data) > 0) {
    $total_historical_sales = array_sum(array_column($sales_vs_waste_data, 'sales'));
    $total_historical_waste = array_sum(array_column($sales_vs_waste_data, 'waste'));
    $historical_period_count = count($sales_vs_waste_data);

    $avg_sales = $total_historical_sales / $historical_period_count;
    $avg_waste = $total_historical_waste / $historical_period_count;

    $last_historical_point = end($sales_vs_waste_data)['time_point'];
    $forecast_periods = 0;
    $forecast_interval_unit = '';

    if ($time_range === 'daily') {
        $forecast_periods = 6; // Forecast next 6 hours
        $forecast_interval_unit = 'HOUR';
    } else {
        $forecast_periods = 7; // Forecast next 7 days
        $forecast_interval_unit = 'DAY';
    }

    for ($i = 1; $i <= $forecast_periods; $i++) {
        $forecast_points[] = [
            'time_point' => date('Y-m-d H:i:s', strtotime("$last_historical_point +$i $forecast_interval_unit")),
            'sales' => $avg_sales,
            'waste' => $avg_waste
        ];
    }
}

$top_products_data = $conn->query("SELECT mp.product_name as name, SUM(s.quantity) as total_sold FROM sales s JOIN menu_product mp ON s.product_id = mp.product_id WHERE s.sale_date >= NOW() - $interval_sql GROUP BY mp.product_id ORDER BY total_sold DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// --- Fetch Data for Info Boxes ---
$inventory_stock_data = $conn->query("SELECT name, stock, content FROM products ORDER BY stock ASC")->fetch_all(MYSQLI_ASSOC);
$upcoming_deliveries = $conn->query("
    SELECT 
        s.company_name, s.contact_person, s.phone,
        s.restock_schedule, 
        s.last_received_date,
        p.name as product_name, 
        p.content as product_content,
        sp.default_quantity 
    FROM suppliers s
    LEFT JOIN supplier_products sp ON s.id = sp.supplier_id
    LEFT JOIN products p ON sp.product_id = p.id
    WHERE s.restock_schedule >= CURDATE() ORDER BY s.restock_schedule ASC")->fetch_all(MYSQLI_ASSOC);

// --- Fetch Products for Modals ---
$products = $conn->query("SELECT id, name, stock FROM products ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// --- Prepare Notifications Data ---
$notifications = [];
$low_stock_threshold = 2; // Define what is considered low stock

// 1. Low Stock Notifications
foreach ($inventory_stock_data as $item) {
    if ($item['stock'] <= $low_stock_threshold) {
        $notifications[] = [
            'type' => 'stock', 'message' => "Low stock: <strong>" . htmlspecialchars($item['name']) . "</strong> (" . htmlspecialchars($item['content']) . ") has only " . $item['stock'] . " units left.", 'link' => 'inventory_products.php?search=' . urlencode($item['name'])
        ];
    }
}

// 2. Upcoming Delivery Notifications (from calendar)
$deliveries_by_date_and_supplier = [];
foreach ($upcoming_deliveries as $delivery) {
    $schedule_date = $delivery['restock_schedule'];
    $company_name = $delivery['company_name'];
    $is_received = ($delivery['last_received_date'] === $schedule_date);

    if (!$is_received) {
        if (!isset($deliveries_by_date_and_supplier[$schedule_date][$company_name])) {
            $deliveries_by_date_and_supplier[$schedule_date][$company_name] = [];
        }
        if ($delivery['product_name']) {
            $deliveries_by_date_and_supplier[$schedule_date][$company_name][] = $delivery;
        }
    }
}

foreach ($deliveries_by_date_and_supplier as $schedule_date => $suppliers) {
    foreach ($suppliers as $company_name => $products) {
        $today = new DateTime(); $today->setTime(0, 0, 0);
        $delivery_date_obj = new DateTime($schedule_date); $delivery_date_obj->setTime(0, 0, 0);
        $interval = $today->diff($delivery_date_obj);
        $days_diff = (int)$interval->format('%r%a');
        $formatted_date = ($days_diff === 0) ? "Today" : (($days_diff === 1) ? "Tomorrow" : $delivery_date_obj->format('M d'));

        $product_details = "";
        foreach ($products as $product) {
            $product_details .= "<li>" . htmlspecialchars($product['product_name']) . " (" . htmlspecialchars($product['default_quantity']) . " x " . htmlspecialchars($product['product_content']) . ")</li>";
        }
        $notifications[] = ['type' => 'delivery', 'message' => "Delivery from <strong>" . htmlspecialchars($company_name) . "</strong> is scheduled for <strong>" . $formatted_date . "</strong>.<ul>" . $product_details . "</ul>", 'link' => 'supplier_management.php?search=' . urlencode($company_name)];
    }
}
$notification_count = count($notifications);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Analytics Dashboard</title>
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
    <link rel="stylesheet" href="css/extracted_styles.css">
    <!-- calendar/notification styles moved to css/extracted_styles.css -->
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">
                <img src="images/logo.png" alt="Logo">
            </div>
            <h2>Admin Dashboard</h2>
            <ul>
                <li><a href="admin_dashboard.php" class="active">Dashboard</a></li>
                <li><a href="inventory_products.php">Inventory Items</a></li>
                <li><a href="products_menu.php">Menu Items</a></li>
                <li><a href="supplier_management.php">Supplier</a></li>
                <li><a href="sales_and_waste.php">Sales & Waste</a></li>
                <li><a href="reports_and_analytics.php">Reports & Analytics</a></li>
                <li><a href="admin_forecasting.php">Forecasting</a></li>
                <li><a href="system_management.php">System Management</a></li>
                <li><a href="user_account.php">My Account</a></li>
            <li><a href="logout.php">Logout</a></li>
            </ul>
        </aside>

        <main class="main">
            <header>
                <h1>Dashboard</h1>
                <div class="header-actions flex-gap-center">
                    <div class="notification-wrapper">
                        <div class="notification-bell" id="notificationBell">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21,19V20H3V19L5,17V11C5,7.9 7.03,5.17 10,4.29V4A2,2 0 0,1 12,2A2,2 0 0,1 14,4V4.29C16.97,5.17 19,7.9 19,11V17L21,19M14,21A2,2 0 0,1 12,23A2,2 0 0,1 10,21H14Z"/></svg>
                            <?php if ($notification_count > 0): ?>
                                <span class="notification-count"><?= $notification_count ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="notification-dropdown" id="notificationDropdown">
                            <div class="notification-header"><h3>Notifications</h3></div>
                            <div class="notification-body">
                                <?php if (empty($notifications)): ?>
                                    <div class="notification-item">No new notifications.</div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notification): ?>
                                        <a href="<?= $notification['link'] ?>" class="notification-item">
                                            <div class="notification-icon <?= $notification['type'] ?>">
                                                <?php if ($notification['type'] === 'stock'): ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13,13H11V7H13M13,17H11V15H13M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z" /></svg>
                                                <?php else: // delivery ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M18,18.5A1.5,1.5 0 0,1 16.5,20A1.5,1.5 0 0,1 15,18.5A1.5,1.5 0 0,1 16.5,17A1.5,1.5 0 0,1 18,18.5M19.5,9.5H17V12H21.46L19.5,9.5M6,18.5A1.5,1.5 0 0,1 4.5,20A1.5,1.5 0 0,1 3,18.5A1.5,1.5 0 0,1 4.5,17A1.5,1.5 0 0,1 6,18.5M20,8L23,12V17H19.5A2.5,2.5 0 0,1 17,19.5A2.5,2.5 0 0,1 14.5,17H7.5A2.5,2.5 0 0,1 5,19.5A2.5,2.5 0 0,1 2.5,17H1V6C1,4.89 1.89,4 3,4H15V8H20Z" /></svg>
                                                <?php endif; ?>
                                            </div>
                                            <div class="notification-content"><?= $notification['message'] ?></div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="content-wrapper content-scroll">

            <section class="cards" aria-label="summary cards">
                <div class="card" >
                    <h3 >Sales</h3>
                    <p>₱<?php echo number_format($total_sales, 2); ?></p>
                </div>
                <div class="card" >
                    <h3>Waste</h3>
                    <p>₱<?php echo number_format($total_waste_cost, 2); ?></p>
                </div>
            </section>

            <section class="charts">
                <div class="chart-box">
                    <div class="chart-header">
                        <h3>Sales vs. Waste</h3>
                        <div class="chart-filters">
                            <a href="?range=daily" class="btn <?= $time_range == 'daily' ? 'active' : '' ?>">Daily</a>
                            <a href="?range=weekly" class="btn <?= $time_range == 'weekly' ? 'active' : '' ?>">Weekly</a>
                            <a href="?range=monthly" class="btn <?= $time_range == 'monthly' ? 'active' : '' ?>">Monthly</a>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="salesVsWasteChart"></canvas>
                    </div>
                </div>
                <div class="chart-box">
                    <h3>Top 5 Selling Products</h3>
                    <div class="chart-container">
                        <canvas id="topProductsChart"></canvas>
                    </div>
                </div>
            </section>

            <section class="charts">
                <div class="chart-box">
                    <h3>Inventory Stock Levels</h3>
                    <div class="chart-container">
                        <canvas id="inventoryStockChart"></canvas>
                    </div>
                </div>
                <div class="box">
                    <h3>Upcoming Deliveries</h3>
                    <div id="deliveryCalendar"></div>
                </div>
            </section>

            </div> <!-- End of content-wrapper -->
        </main>
    </div>

<!-- Chart Modal -->
<div class="modal" id="chartModal">
    <div class="modal-content modal-content-wide" id="chartModalContent">
        <span class="close" id="closeChartModal">&times;</span>
        <h2 id="chartModalTitle">Chart View</h2>
        <div class="chart-container chart-container-large">
            <canvas id="modalChartCanvas"></canvas>
        </div>
    </div>
</div>

<!-- Delivery Details Modal -->
<div class="modal" id="deliveryDetailsModal">
    <div class="modal-content">
        <span class="close" id="closeDeliveryModal">&times;</span>
        <h2 id="deliveryModalTitle">Deliveries for [Date]</h2>
        <div id="deliveryDetailsContent">
            <!-- Delivery details will be loaded here -->
        </div>
        <div class="form-actions mt-15">
            <button type="button" class="cancel-btn" id="closeDeliveryDetailsBtn">Close</button>
        </div>
    </div>
</div>

<!-- Generic Confirmation Modal -->
<div class="modal" id="confirmModal">
    <div class="modal-content">
        <span class="close" id="closeConfirmModal">&times;</span>
        <h2>Please Confirm</h2>
    <p id="confirmMessage" class="text-center confirm-message"></p>
        <div class="form-actions">
            <button type="button" class="confirm-btn-yes" id="confirmYesBtn">Confirm</button>
            <button type="button" class="cancel-btn" id="confirmCancelBtn">Cancel</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="js/admin-charts.js"></script>
<script src="js/admin-calendar.js"></script>
<script src="js/admin-ui.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Store chart instances
    const chartInstances = {};

    // Data from PHP
    const salesVsWasteData = <?= json_encode($sales_vs_waste_data) ?>;
    const forecastPoints = <?= json_encode($forecast_points) ?>;
    const timeRange = '<?= $time_range ?>';
    const topProductsData = <?= json_encode($top_products_data) ?>;
    const inventoryStockData = <?= json_encode($inventory_stock_data) ?>;
    const deliveries = <?= json_encode($upcoming_deliveries) ?>;

    // Initialize all charts
    initializeSalesVsWasteChart(chartInstances, salesVsWasteData, forecastPoints, timeRange);
    initializeTopProductsChart(chartInstances, topProductsData);
    initializeInventoryStockChart(chartInstances, inventoryStockData);
    attachChartClickHandlers(chartInstances);

    // Initialize delivery calendar
    initializeDeliveryCalendar(deliveries);

    // Initialize UI interactions
    initializeAllUI();
});
</script>
</body>
</html>