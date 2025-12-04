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
    <style>
        /* --- Calendar Styles --- */
        .calendar { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
        .calendar-header, .calendar-day { text-align: center; padding: 8px; border-radius: 4px; }
        .calendar-header { font-weight: bold; color: var(--subtext); font-size: 0.9em; }
        .calendar-day { background-color: var(--muted); }
        .calendar-day.other-month { color: var(--subtext); opacity: 0.4; background: none; }
        .calendar-day.today { background-color: var(--accent); color: white; font-weight: bold; }
        .calendar-day.has-delivery { background-color: #c62828; color: white; cursor: pointer; position: relative; font-weight: bold; } /* Red for upcoming */
        .calendar-day.has-delivery.received { background-color: #28a745; } /* Green for received */
        .calendar-day.has-delivery::after { content: ''; position: absolute; top: 4px; right: 4px; width: 6px; height: 6px; background: white; border-radius: 50%; }
        .calendar-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .calendar-nav button {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1.5em;
            color: var(--subtext);
        }
        .calendar-nav button:hover { color: var(--text); }
        .calendar-title { font-weight: bold; font-size: 1.1em; color: var(--accent); }
        .delivery-tooltip { display: none; position: absolute; background: var(--panel); color: var(--text); border: 1px solid #ddd; padding: 8px; border-radius: 6px; z-index: 10; font-size: 0.85em; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .has-delivery:hover .delivery-tooltip { display: block; }

        /* --- Filter Buttons --- */
        .chart-filters { display: flex; gap: 8px; margin-bottom: 15px; justify-content: flex-end; }
        .chart-filters .btn {
            background: var(--muted);
            color: var(--subtext);
            border: 1px solid transparent;
            padding: 6px 12px;
            font-size: 0.9rem;
            text-decoration: none;
        }
        .chart-filters .btn.active, .chart-filters .btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }
        body.dark-mode .chart-filters .btn {
            background: #2a3b4d;
            border-color: #444;
        }
        body.dark-mode .chart-filters .btn.active, body.dark-mode .chart-filters .btn:hover {
            background: var(--accent);
            border-color: var(--accent);
        }
        .chart-box h3 {
            margin-bottom: 0; /* Adjust for filter buttons */
        }

        /* --- Delivery Details Modal Styling --- */
        #deliveryDetailsContent h3 {
            color: var(--accent);
            margin-top: 15px;
            margin-bottom: 5px;
            font-size: 1.2em;
        }
        #deliveryDetailsContent p.contact-info {
            font-size: 0.85em;
            color: var(--subtext);
            margin-bottom: 10px;
        }
        #deliveryDetailsContent ul { list-style-type: disc; padding-left: 20px; margin-bottom: 15px; }
        #deliveryDetailsContent ul li { margin-bottom: 3px; }
        #deliveryDetailsContent hr { border: 0; border-top: 1px solid var(--muted); margin: 15px 0; }
    </style>
    <style>
        /* --- Notification Styles --- */
        .notification-wrapper { position: relative; }
        .notification-bell { cursor: pointer; position: relative; }
        .notification-bell svg { width: 24px; height: 24px; fill: var(--subtext); }
        .notification-bell:hover svg { fill: var(--text); }
        .notification-count {
            position: absolute;
            top: -5px;
            right: -8px;
            background-color: #c62828;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 11px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--panel);
        }
        .notification-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            width: 320px;
            background-color: var(--panel);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-radius: 8px;
            z-index: 1001;
            overflow: hidden;
        }
        .notification-header { padding: 12px 16px; border-bottom: 1px solid var(--muted); }
        .notification-header h3 { margin: 0; font-size: 1rem; }
        .notification-body { max-height: 300px; overflow-y: auto; }
        .notification-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid var(--muted);
            text-decoration: none;
            color: var(--text);
            transition: background-color 0.2s;
        }
        .notification-item:last-child { border-bottom: none; }
        .notification-item:hover { background-color: var(--muted); }
        .notification-icon {
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
        }
        .notification-icon.stock { background-color: #ffebee; }
        .notification-icon.delivery { background-color: #e0f2f1; }
        .notification-icon svg { width: 18px; height: 18px; }
        .notification-icon.stock svg { fill: #c62828; }
        .notification-icon.delivery svg { fill: #00796b; }
        .notification-content { font-size: 0.9rem; line-height: 1.4; }
        .notification-content ul { font-size: 0.85rem; color: var(--subtext); list-style-position: inside; padding-left: 5px; margin-top: 5px; }
        .notification-content strong { color: var(--accent); }

        /* Show/Hide animation */
        .notification-dropdown.show {
            display: block;
            animation: fadeIn 0.2s ease-out;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
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
                <div class="header-actions" style="display:flex; gap:10px; align-items:center;">
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

            <div class="content-wrapper" style="flex-grow: 1; overflow-y: auto; padding: 2px;">

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
                    <div style="display:flex; justify-content: space-between; align-items: center;">
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
    <div class="modal-content" id="chartModalContent" style="max-width: 80%; width: 900px;">
        <span class="close" id="closeChartModal">&times;</span>
        <h2 id="chartModalTitle">Chart View</h2>
        <div class="chart-container" style="height: 70vh;">
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
        <div class="form-actions" style="margin-top: 20px;">
            <button type="button" class="cancel-btn" id="closeDeliveryDetailsBtn">Close</button>
        </div>
    </div>
</div>

<!-- Generic Confirmation Modal -->
<div class="modal" id="confirmModal">
    <div class="modal-content">
        <span class="close" id="closeConfirmModal">&times;</span>
        <h2>Please Confirm</h2>
        <p id="confirmMessage" style="text-align: center; margin: 20px 0;"></p>
        <div class="form-actions">
            <button type="button" class="confirm-btn-yes" id="confirmYesBtn">Confirm</button>
            <button type="button" class="cancel-btn" id="confirmCancelBtn">Cancel</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Store chart instances to make them accessible for modal creation
    const chartInstances = {};

    // --- Chart 1: Sales vs Waste ---
    const salesVsWasteCtx = document.getElementById('salesVsWasteChart').getContext('2d');
    const salesVsWasteData = <?= json_encode($sales_vs_waste_data) ?>;
    const forecastPoints = <?= json_encode($forecast_points) ?>;
    const timeRange = '<?= $time_range ?>';
    let timeUnit = 'day';
    let timeFormat = { month: 'short', day: 'numeric' };
    if (timeRange === 'daily') {
        timeUnit = 'hour';
        timeFormat = { hour: 'numeric' };
    }
    
    // Combine historical and forecast labels
    const allTimePoints = salesVsWasteData.map(d => d.time_point).concat(forecastPoints.map(d => d.time_point));
    const allLabels = allTimePoints.map(d => new Date(d).toLocaleString('en-US', timeFormat));

    chartInstances.salesVsWasteChart = new Chart(salesVsWasteCtx, {
        type: 'line',
        data: {
            labels: allLabels,
            datasets: [
                {
                    label: 'Total Sales',
                    data: salesVsWasteData.map(d => parseFloat(d.sales)),
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.1)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Forecasted Sales',
                    // Pad with nulls for historical part, then add forecast data
                    data: Array(salesVsWasteData.length - 1).fill(null).concat([salesVsWasteData[salesVsWasteData.length-1]?.sales]).concat(forecastPoints.map(d => d.sales)),
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderDash: [5, 5],
                    backgroundColor: 'rgba(75, 192, 192, 0.05)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Total Waste Cost',
                    data: salesVsWasteData.map(d => parseFloat(d.waste)),
                    borderColor: 'rgba(255, 99, 132, 1)',
                    backgroundColor: 'rgba(255, 99, 132, 0.1)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Forecasted Waste',
                    data: Array(salesVsWasteData.length - 1).fill(null).concat([salesVsWasteData[salesVsWasteData.length-1]?.waste]).concat(forecastPoints.map(d => d.waste)),
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderDash: [5, 5],
                    backgroundColor: 'rgba(255, 99, 132, 0.05)',
                    fill: true,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { callback: value => '₱' + value } } },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += '₱' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                            return label;
                        }
                    } 
                },
                legend: {
                    position: 'top',
                    onClick: (e, legendItem, legend) => {
                        const chart = legend.chart;
                        const clickedIndex = legendItem.datasetIndex;

                        // Determine which group was clicked (Sales: 0, 1; Waste: 2, 3)
                        const isSalesGroup = clickedIndex === 0 || clickedIndex === 1;

                        // Check if the clicked group is already isolated
                        const salesMeta = chart.getDatasetMeta(0);
                        const wasteMeta = chart.getDatasetMeta(2);
                        const isSalesIsolated = !salesMeta.hidden && wasteMeta.hidden;
                        const isWasteIsolated = salesMeta.hidden && !wasteMeta.hidden;

                        if ((isSalesGroup && isSalesIsolated) || (!isSalesGroup && isWasteIsolated)) {
                            // If the clicked group is already isolated, show all datasets
                            chart.data.datasets.forEach((_, i) => chart.getDatasetMeta(i).hidden = false);
                        } else {
                            // Otherwise, isolate the clicked group
                            chart.getDatasetMeta(0).hidden = !isSalesGroup; // Sales
                            chart.getDatasetMeta(1).hidden = !isSalesGroup; // Forecasted Sales
                            chart.getDatasetMeta(2).hidden = isSalesGroup;  // Waste
                            chart.getDatasetMeta(3).hidden = isSalesGroup;  // Forecasted Waste
                        }

                        chart.update();
                    }
                }
            }
        }
    });

    // --- Chart 2: Top Selling Products ---
    const topProductsCtx = document.getElementById('topProductsChart').getContext('2d');
    const topProductsData = <?= json_encode($top_products_data) ?>;
    chartInstances.topProductsChart = new Chart(topProductsCtx, {
        type: 'bar',
        data: {
            labels: topProductsData.map(p => p.name),
            datasets: [{
                label: 'Units Sold',
                data: topProductsData.map(p => p.total_sold),
                backgroundColor: [
                    'rgba(111, 166, 168, 0.7)',
                    'rgba(134, 182, 184, 0.7)',
                    'rgba(158, 198, 200, 0.7)',
                    'rgba(181, 214, 216, 0.7)',
                    'rgba(205, 230, 232, 0.7)'
                ],
                borderColor: 'rgba(74, 108, 111, 1)',
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true } }
        }
    });

    // --- Chart 3: Inventory Stock Levels ---
    const inventoryStockCtx = document.getElementById('inventoryStockChart').getContext('2d');
    const inventoryStockData = <?= json_encode($inventory_stock_data) ?>;
    if (inventoryStockData.length > 0) {
        const lowStockThreshold = 2; // 1-2 is Low Stock
        const mediumStockThreshold = 4; // 3-4 is Medium Stock
        
        // Filter data to only show low and medium stock items by default
        const defaultFilteredInventory = inventoryStockData.filter(p => p.stock <= mediumStockThreshold && p.stock > 0);

        chartInstances.inventoryStockChart = new Chart(inventoryStockCtx, {
            type: 'bar',
            data: {
                labels: defaultFilteredInventory.map(p => p.name), // Initial labels
                datasets: [{
                    label: 'Low Stock',
                    data: defaultFilteredInventory.map(p => p.stock <= lowStockThreshold ? p.stock : null),
                    backgroundColor: 'rgba(255, 99, 132, 0.7)',
                }, {
                    label: 'Medium Stock',
                    data: defaultFilteredInventory.map(p => (p.stock > lowStockThreshold && p.stock <= mediumStockThreshold) ? p.stock : null),
                    backgroundColor: 'rgba(255, 206, 86, 0.7)',
                }]
            },
            options: {
                indexAxis: 'y', // This makes the bar chart horizontal
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, beginAtZero: true, title: { display: true, text: 'Stock Quantity' } },
                    y: { stacked: true, ticks: { autoSkip: false } }
                },
                plugins: { 
                    legend: { 
                        position: 'top',
                        onClick: (e, legendItem, legend) => {
                            const chart = legend.chart;
                            const index = legendItem.datasetIndex;
                            const clickedLabel = legendItem.text;

                            // Check if the clicked item is the only one visible
                            const isOnlyVisible = legend.legendItems.filter(li => !li.hidden).length === 1 && !legendItem.hidden;

                            if (isOnlyVisible) {
                                // If it's the only one visible, clicking it again should show all datasets
                                // Revert to Default View (Low + Medium visible)
                                chart.getDatasetMeta(0).hidden = false; // Low
                                chart.getDatasetMeta(1).hidden = false; // Medium
                            } else {
                                // Otherwise, hide all other datasets and show only the clicked one
                                legend.legendItems.forEach((item, i) => chart.getDatasetMeta(i).hidden = (i !== index));
                            }

                            // Filter the product list to show only items from the selected category
                            let currentFilteredProducts = [];
                            if (isOnlyVisible) {
                                // If reverting to default, use the default filtered list
                                currentFilteredProducts = defaultFilteredInventory;
                            } else {
                                // If isolating, filter based on the clicked label
                                currentFilteredProducts = inventoryStockData.filter(p => {
                                    if (clickedLabel === 'Low Stock' && p.stock <= lowStockThreshold) return true;
                                    if (clickedLabel === 'Medium Stock' && p.stock > lowStockThreshold && p.stock <= mediumStockThreshold) return true;
                                    return false;
                                });
                            }

                            // Update chart data
                            chart.data.labels = currentFilteredProducts.map(p => p.name);
                            chart.data.datasets[0].data = currentFilteredProducts.map(p => p.stock <= lowStockThreshold ? p.stock : null);
                            chart.data.datasets[1].data = currentFilteredProducts.map(p => (p.stock > lowStockThreshold && p.stock <= mediumStockThreshold) ? p.stock : null);

                            chart.update();
                        }
                    } 
                }
            }
        });
    }

    // --- Calendar for Upcoming Deliveries ---
    const deliveryCalendarEl = document.getElementById('deliveryCalendar');
    const deliveries = <?= json_encode($upcoming_deliveries) ?>;
    const deliveriesByDate = deliveries.reduce((acc, delivery) => {
        const date = delivery.restock_schedule;
        if (!acc[date]) acc[date] = {};
        if (!acc[date][delivery.company_name]) {
            acc[date][delivery.company_name] = {
                products: [],
                last_received: delivery.last_received_date,
                contact_person: delivery.contact_person,
                phone: delivery.phone
            };
        }
        if(delivery.product_name) {
            acc[date][delivery.company_name].products.push({ name: delivery.product_name, qty: delivery.default_quantity });
        }
        return acc;
    }, {});

    function renderCalendar(month, year) {
        const today = new Date(); // For highlighting today's date
        today.setHours(0, 0, 0, 0);

        const firstDayOfMonth = new Date(year, month, 1);
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const startingDay = firstDayOfMonth.getDay(); // 0 = Sunday, 1 = Monday...

        let calendarNavHTML = `<div class="calendar-nav"><button id="prevMonth">&lt;</button><span class="calendar-title">${firstDayOfMonth.toLocaleString('default', { month: 'long', year: 'numeric' })}</span><button id="nextMonth">&gt;</button></div>`;

        let calendarHTML = '<div class="calendar-header">Sun</div><div class="calendar-header">Mon</div><div class="calendar-header">Tue</div><div class="calendar-header">Wed</div><div class="calendar-header">Thu</div><div class="calendar-header">Fri</div><div class="calendar-header">Sat</div>';

        // Add empty cells for days before the 1st
        for (let i = 0; i < startingDay; i++) {
            calendarHTML += '<div class="calendar-day other-month"></div>';
        }

        // Add days of the month
        for (let day = 1; day <= daysInMonth; day++) {
            const currentDate = new Date(year, month, day, 12); // Use noon to avoid timezone-related date shifts
            // Format the date to 'YYYY-MM-DD' without timezone conversion
            const y = currentDate.getFullYear();
            const m = String(currentDate.getMonth() + 1).padStart(2, '0');
            const d = String(currentDate.getDate()).padStart(2, '0');
            const dateString = `${y}-${m}-${d}`;
            let dayClass = 'calendar-day';

            // Create a date object for the current day in the loop to compare with today
            const loopDate = new Date(year, month, day);
            loopDate.setHours(0, 0, 0, 0);

            if (loopDate.getTime() === today.getTime()) {
                dayClass += ' today';
            }

            const deliveryInfo = deliveriesByDate[dateString];
            if (deliveryInfo) {
                dayClass += ' has-delivery';
                // Check if ALL suppliers for that day have received their delivery
                let tooltipHTML = '';
                const allReceived = Object.values(deliveryInfo).every(d => d.last_received === dateString);
                if (allReceived) dayClass += ' received';

                tooltipHTML = '<div class="delivery-tooltip">';
                Object.keys(deliveriesByDate[dateString]).forEach(company => { // Corrected: Iterate over company names (keys)
                    tooltipHTML += `<div>- ${company}</div>`;
                });
                tooltipHTML += '</div>';
                calendarHTML += `<div class="${dayClass}" data-date="${dateString}">${day}${tooltipHTML}</div>`;
            } else {
                calendarHTML += `<div class="${dayClass}" data-date="${dateString}">${day}</div>`;
            }
        }

        deliveryCalendarEl.innerHTML = calendarNavHTML + `<div class="calendar">${calendarHTML}</div>`;

        // Re-attach event listeners for the new buttons and cells
        document.getElementById('prevMonth').onclick = () => {
            currentMonth = (currentMonth === 0) ? 11 : currentMonth - 1;
            if (currentMonth === 11) currentYear--;
            renderCalendar(currentMonth, currentYear);
        };
        document.getElementById('nextMonth').onclick = () => {
            currentMonth = (currentMonth === 11) ? 0 : currentMonth + 1;
            if (currentMonth === 0) currentYear++;
            renderCalendar(currentMonth, currentYear);
        };
        attachCalendarClickEvents();
    }

    function attachCalendarClickEvents() {
        const deliveryModal = document.getElementById('deliveryDetailsModal');
        const deliveryModalTitle = document.getElementById('deliveryModalTitle');
        const deliveryDetailsContent = document.getElementById('deliveryDetailsContent');
        document.querySelectorAll('.calendar-day.has-delivery').forEach(dayCell => {
            dayCell.addEventListener('click', function() {
                const dateString = this.dataset.date;
                const deliveriesOnDate = deliveriesByDate[dateString] || {};
                
                const formattedDate = new Date(dateString + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                deliveryModalTitle.textContent = `Deliveries for ${formattedDate}`;
                
                let contentHtml = '';
                for (const companyName in deliveriesOnDate) {
                    const delivery = deliveriesOnDate[companyName];
                    const isReceived = delivery.last_received === dateString;

                    // Show details for all scheduled deliveries
                    contentHtml += `<h3>${companyName}</h3>`;
                    contentHtml += `<p class="contact-info">Contact: ${delivery.contact_person || 'N/A'} (${delivery.phone || 'N/A'})</p>`;
                    
                    if (isReceived) {
                        contentHtml += '<p><strong>Status:</strong> <span style="color: #28a745;">Received</span></p>';
                    } else {
                        contentHtml += '<p><strong>Status:</strong> <span style="color: #c62828;">Pending</span></p>';
                    }

                        if (delivery.products.length > 0) {
                            contentHtml += '<ul>';
                            delivery.products.forEach(product => {
                                contentHtml += `<li>${product.name} (Expected Qty: ${product.qty || 1})</li>`;
                            });
                            contentHtml += '</ul>';
                        } else {
                        contentHtml += '<p>No specific products assigned for this delivery.</p>';
                        }
                    contentHtml += '<hr>';
                }

                if (contentHtml === '') {
                    contentHtml = '<p>All scheduled deliveries for this date have been received.</p>';
                }
                deliveryDetailsContent.innerHTML = contentHtml;
                deliveryModal.style.display = 'block';
            });
        });
    }

    let currentMonth, currentYear;

    if (deliveryCalendarEl) {
        const initialDate = new Date();
        currentMonth = initialDate.getMonth();
        currentYear = initialDate.getFullYear();
        renderCalendar(currentMonth, currentYear);

        // --- Make Calendar Clickable ---
        const deliveryModal = document.getElementById('deliveryDetailsModal');
        document.getElementById('closeDeliveryModal').onclick = () => deliveryModal.style.display = 'none';
        document.getElementById('closeDeliveryDetailsBtn').onclick = () => deliveryModal.style.display = 'none';
    }

    // --- Chart Modal Logic ---
    const chartModal = document.getElementById('chartModal');
    const chartModalTitle = document.getElementById('chartModalTitle');
    const modalChartCanvas = document.getElementById('modalChartCanvas').getContext('2d');
    const closeChartModalBtn = document.getElementById('closeChartModal');
    let modalChartInstance = null;

    function openChartInModal(chartInstance, title) {
        if (!chartInstance) return;

        // If a chart already exists in the modal, destroy it first
        if (modalChartInstance) {
            modalChartInstance.destroy();
        }

        chartModalTitle.textContent = title;

        // Create a new chart in the modal with the same data and type
        modalChartInstance = new Chart(modalChartCanvas, {
            type: chartInstance.config.type,
            data: chartInstance.config.data,
            options: {
                ...chartInstance.config.options, // Inherit all options from the original chart
                maintainAspectRatio: false // Ensure it fills the modal container
            }
        });

        chartModal.style.display = 'block';
    }

    // Add click listeners to all chart canvases on the page
    document.querySelectorAll('.chart-container canvas').forEach(canvas => {
        canvas.closest('.chart-container').style.cursor = 'pointer'; // Add pointer cursor
        canvas.addEventListener('click', () => {
            const chartId = canvas.id;
            const title = canvas.closest('.chart-box').querySelector('h3').textContent;
            openChartInModal(chartInstances[chartId], title);
        });
    });

    closeChartModalBtn.addEventListener('click', () => chartModal.style.display = 'none');

    // --- Logout Confirmation ---
    const confirmModal = document.getElementById('confirmModal');
    const logoutLink = document.querySelector('a[href="logout.php"]');
    logoutLink.addEventListener('click', function(e) {
        e.preventDefault();
        const confirmBtn = document.getElementById('confirmYesBtn');
        document.getElementById('confirmMessage').textContent = 'Are you sure you want to log out?';
        confirmBtn.textContent = 'Yes, Logout';
        confirmBtn.className = 'confirm-btn-yes btn-logout-yes'; // Add logout specific class
        confirmModal.style.display = 'block';
        confirmBtn.onclick = function() {
            window.location.href = 'logout.php';
        };
    });
    document.getElementById('closeConfirmModal').addEventListener('click', () => confirmModal.style.display = 'none');
    document.getElementById('confirmCancelBtn').addEventListener('click', () => confirmModal.style.display = 'none');
    window.addEventListener('click', (e) => { 
        if (e.target == confirmModal) confirmModal.style.display = 'none'; 
        if (e.target == chartModal) chartModal.style.display = 'none';
        if (e.target == document.getElementById('deliveryDetailsModal')) document.getElementById('deliveryDetailsModal').style.display = 'none';
    });

    // --- Notification Dropdown Logic ---
    const notificationBell = document.getElementById('notificationBell');
    const notificationDropdown = document.getElementById('notificationDropdown');

    notificationBell.addEventListener('click', function(event) {
        event.stopPropagation(); // Prevent the window click from closing it immediately
        notificationDropdown.classList.toggle('show');
    });

    // Close dropdown if clicking outside of it
    window.addEventListener('click', function(event) {
        if (!notificationDropdown.contains(event.target) && !notificationBell.contains(event.target)) {
            notificationDropdown.classList.remove('show');
        }
    });
});
</script>
</body>
</html>