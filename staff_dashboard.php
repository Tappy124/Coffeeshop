<?php
session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Staff') {
    header("Location: login.php");
    exit;
}

include "includes/db.php";

// --- Sorting Logic ---
$sort_by = $_GET['sort_by'] ?? 'category'; // Default sort column
$filter_category = trim($_GET['filter_category'] ?? ''); // For category-specific filtering
$sort_order = $_GET['sort_order'] ?? 'ASC';   // Default sort order

// Whitelist for security to prevent SQL injection in column names
$sort_columns_whitelist = ['name', 'category', 'stock'];
$sort_order_whitelist = ['ASC', 'DESC'];

if (!in_array($sort_by, $sort_columns_whitelist)) {
    $sort_by = 'category'; // Fallback to a safe default
}
if (!in_array(strtoupper($sort_order), $sort_order_whitelist)) {
    $sort_order = 'ASC'; // Fallback to a safe default
}

// --- Define stock thresholds ---
$medium_stock_threshold = 4; // Items with stock at or below this level will be shown by default.

// --- Fetch ALL products for the chart (unfiltered) ---
// The 'products' table now only contains ingredients, so no filter is needed.
$chart_products_stmt = $conn->prepare("SELECT name, stock, content FROM products");
$chart_products_stmt->execute();
$chart_products_result = $chart_products_stmt->get_result();
$all_products_for_chart = $chart_products_result->fetch_all(MYSQLI_ASSOC);
$chart_products_stmt->close();

// --- Fetch Upcoming Deliveries with Products for Notifications ---
$upcoming_deliveries = $conn->query("
    SELECT 
        s.company_name,
        s.restock_schedule, 
        s.last_received_date,
        p.name as product_name,
        p.content as product_content,
        sp.default_quantity
    FROM suppliers s
    LEFT JOIN supplier_products sp ON s.id = sp.supplier_id
    LEFT JOIN products p ON sp.product_id = p.id
    WHERE s.restock_schedule >= CURDATE() ORDER BY s.restock_schedule ASC
")->fetch_all(MYSQLI_ASSOC);


// --- Prepare Notifications Data ---
$notifications = [];
$low_stock_threshold = 2; // Define what is considered low stock

// 1. Low Stock Notifications
foreach ($all_products_for_chart as $item) {
    if ($item['stock'] <= $low_stock_threshold) {
        $notifications[] = [
            'type' => 'stock',
            'message' => "Low stock alert: <strong>" . htmlspecialchars($item['name']) . "</strong> (" . htmlspecialchars($item['content']) . ") has only " . $item['stock'] . " units left."
        ];
    }
}

// 2. Upcoming Delivery Notifications
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
        $notifications[] = ['type' => 'delivery', 'message' => "Delivery from <strong>" . htmlspecialchars($company_name) . "</strong> is scheduled for <strong>" . $formatted_date . "</strong>.<ul>" . $product_details . "</ul>"];
    }
}
$notification_count = count($notifications);
// --- Fetch all products for live stock view ---
// Set the default timezone to Philippine time for all date functions
date_default_timezone_set('Asia/Manila');

$sql = "SELECT name, category, stock FROM products";
$where_clauses = [];
$params = [];
$types = '';

if (!isset($_GET['sort_by']) && !isset($_GET['filter_category']) && !isset($_GET['sort_order'])) {
    $where_clauses[] = "stock <= ?";
    $params[] = $medium_stock_threshold;
    $types .= 'i';
}

// --- Fetch data for Today's Sales & Waste by Hour ---
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');

// Set the timezone for the database connection to match the application's timezone
$conn->query("SET time_zone = '+08:00'"); // For Asia/Manila

$hourly_sales_data = $conn->query("
    SELECT HOUR(sale_date) as hour, SUM(total_amount) as total_sales
    FROM sales
    WHERE sale_date BETWEEN '$today_start' AND '$today_end'
    GROUP BY HOUR(sale_date)
")->fetch_all(MYSQLI_ASSOC);

$hourly_waste_data = $conn->query("
    SELECT HOUR(waste_date) as hour, SUM(waste_cost) as total_waste
    FROM waste
    WHERE waste_date BETWEEN '$today_start' AND '$today_end'
    GROUP BY HOUR(waste_date)
")->fetch_all(MYSQLI_ASSOC);

// It's good practice to reset the timezone if the connection is used for other things later
// $conn->query("SET time_zone = 'SYSTEM'");

if (!empty($filter_category)) {
    $where_clauses[] = "category = ?";
    $params[] = $filter_category;
    $types .= 's';
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

if ($sort_by === 'category') {
    // Use a custom, logical order for categories instead of alphabetical.
    $category_order_list = "'Basic Ingredient', 'Powders', 'Syrup', 'Cups and Lids', 'Other Supplies', 'Sinkers'";
    // The second part of the order ensures items within the same category are sorted by name.
    $sql .= " ORDER BY FIELD(category, $category_order_list), name ASC";
} else {
    // Use standard sorting for other columns
    $sql .= " ORDER BY `$sort_by` $sort_order";
}
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$products = $result->fetch_all(MYSQLI_ASSOC);

// Calculate total daily sales and waste for the summary cards
$total_daily_sales = array_sum(array_column($hourly_sales_data, 'total_sales'));
$total_daily_waste_cost = array_sum(array_column($hourly_waste_data, 'total_waste'));

// --- Forecasting Logic for Hourly Chart ---
$forecast_hourly_data = [];
$current_hour = (int)date('G'); // 24-hour format of the current hour

// Only calculate forecast if there is some data from today
if ($total_daily_sales > 0 || $total_daily_waste_cost > 0) {
    $historical_sales = array_sum(array_column($hourly_sales_data, 'total_sales'));
    $historical_waste = array_sum(array_column($hourly_waste_data, 'total_waste'));
    
    // Use current hour + 1 as the number of periods to average over for more stability
    $historical_period_count = $current_hour + 1;

    $avg_hourly_sales = $historical_sales / $historical_period_count;
    $avg_hourly_waste = $historical_waste / $historical_period_count;

    // Create forecast points for the rest of the day
    for ($hour = $current_hour + 1; $hour < 24; $hour++) {
        $forecast_hourly_data[$hour] = [
            'sales' => $avg_hourly_sales,
            'waste' => $avg_hourly_waste
        ];
    }
}


// Prepare data for the hourly chart
$chart_hourly_data = array_fill(0, 24, ['sales' => 0, 'waste' => 0]);
foreach ($hourly_sales_data as $row) { $chart_hourly_data[(int)$row['hour']]['sales'] = (float)$row['total_sales']; }
foreach ($hourly_waste_data as $row) { $chart_hourly_data[(int)$row['hour']]['waste'] = (float)$row['total_waste']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Staff Dashboard</title>
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
    <link rel="stylesheet" href="css/extracted_styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">
                <img src="images/logo.png" alt="Logo">
            </div>
            <h2>Staff Dashboard</h2>
            <ul>
                <li><a href="staff_dashboard.php" class="active">Dashboard</a></li>
                <li><a href="staff_log_sales.php">Log Sale</a></li>
                <li><a href="staff_log_waste.php">Log Waste</a></li>
                <li><a href="staff_view_history.php">View History</a></li>
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
                                        <div class="notification-item">
                                            <div class="notification-icon <?= $notification['type'] ?>">
                                                <?php if ($notification['type'] === 'stock'): ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13,13H11V7H13M13,17H11V15H13M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z" /></svg>
                                                <?php else: // delivery ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M18,18.5A1.5,1.5 0 0,1 16.5,20A1.5,1.5 0 0,1 15,18.5A1.5,1.5 0 0,1 16.5,17A1.5,1.5 0 0,1 18,18.5M19.5,9.5H17V12H21.46L19.5,9.5M6,18.5A1.5,1.5 0 0,1 4.5,20A1.5,1.5 0 0,1 3,18.5A1.5,1.5 0 0,1 4.5,17A1.5,1.5 0 0,1 6,18.5M20,8L23,12V17H19.5A2.5,2.5 0 0,1 17,19.5A2.5,2.5 0 0,1 14.5,17H7.5A2.5,2.5 0 0,1 5,19.5A2.5,2.5 0 0,1 2.5,17H1V6C1,4.89 1.89,4 3,4H15V8H20Z" /></svg>
                                                <?php endif; ?>
                                            </div>
                                            <div class="notification-content"><?= $notification['message'] ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="content-wrapper content-scroll">

            <div class="filter-bar">
                <form action="staff_dashboard.php" method="GET" class="filter-form">
                    <select name="sort_by" id="sortBySelect">
                        <option value="name" <?= $sort_by == 'name' ? 'selected' : '' ?>>Sort by Name</option>
                        <option value="category" <?= $sort_by == 'category' ? 'selected' : '' ?>>Sort by Category</option>
                        <option value="stock" <?= $sort_by == 'stock' ? 'selected' : '' ?>>Sort by Stock</option>
                    </select>
                    <select name="sort_order" id="sortOrderSelect">
                        <option value="ASC" <?= $sort_order == 'ASC' ? 'selected' : '' ?>>Ascending</option>
                        <option value="DESC" <?= $sort_order == 'DESC' ? 'selected' : '' ?>>Descending</option>
                    </select>
                    <select name="filter_category" id="categoryFilterSelect" class="hidden">
                        <option value="">All Categories</option>
                        <option value="Basic Ingredient" <?= $filter_category == 'Basic Ingredient' ? 'selected' : '' ?>>Basic Ingredient</option>
                        <option value="Cups and Lids" <?= $filter_category == 'Cups and Lids' ? 'selected' : '' ?>>Cups and Lids</option>
                        <option value="Other Supplies" <?= $filter_category == 'Other Supplies' ? 'selected' : '' ?>>Other Supplies</option>
                        <option value="Powders" <?= $filter_category == 'Powders' ? 'selected' : '' ?>>Powders</option>
                        <option value="Syrup" <?= $filter_category == 'Syrup' ? 'selected' : '' ?>>Syrup</option>
                        <option value="Sinkers" <?= $filter_category == 'Sinkers' ? 'selected' : '' ?>>Sinkers</option>
                    </select>
                    <button type="submit" class="btn">Sort</button>
                    <a href="staff_dashboard.php" class="btn cancel-btn">Clear</a>
                </form>
            </div>

            <section class="cards" aria-label="summary cards">
                <div class="card" >
                    <h3>Today's Sales</h3>
                    <p>₱<?= number_format($total_daily_sales, 2); ?></p>
                </div>
                <div class="card" >
                    <h3>Today's Waste</h3>
                    <p>₱<?= number_format($total_daily_waste_cost, 2); ?></p>
                </div>
            </section>

            <section class="box">
                <h2>Inventory Status</h2>
                <p class="muted-note">
                    Default view shows items with low or medium stock. Use the filter bar to see all items.
                </p>
                <div class="table-container table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Current Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($products)): ?>
                                <?php foreach ($products as $product): ?>
                                    <?php
                                        $low_stock_threshold = 2; // 1-2 is low stock (red)
                                        $medium_stock_threshold = 4; // 3-4 is medium (yellow)
                                        $stock_class = ''; // Default class
                                        if ($product['stock'] <= $low_stock_threshold) {
                                            $stock_class = 'low-stock-alert';
                                        } elseif ($product['stock'] <= $medium_stock_threshold) { // This will catch 4 and 5
                                            $stock_class = 'medium-stock-alert';
                                        }
                                    ?>
                                    <tr>
                                        <td class="<?= $stock_class ?>"><?= htmlspecialchars($product['name']) ?></td>
                                        <td class="<?= $stock_class ?>"><?= htmlspecialchars($product['category']) ?></td>
                                        <td class="<?= $stock_class ?>"><?= htmlspecialchars($product['stock']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3">No products found in inventory.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="charts">
                <div class="chart-box">
                    <h3>Stock Level Visualization</h3>
                    <div class="chart-container">
                        <canvas id="stockLevelChart"></canvas>
                    </div>
                </div>
                <div class="chart-box">
                    <h3>Today's Sales vs Waste Cost</h3>
                     <div class="chart-container">
                        <canvas id="dailySalesWasteChart"></canvas>
                    </div>
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


<!-- Generic Confirmation Modal -->
<div class="modal" id="confirmModal">
    <div class="modal-content">
        <span class="close" id="closeConfirmModal">&times;</span>
        <h2>Please Confirm</h2>
    <p id="confirmMessage" class="text-center"></p>
        <div class="form-actions">
            <button type="button" class="confirm-btn-yes" id="confirmYesBtn">Confirm</button>
            <button type="button" class="cancel-btn" id="confirmCancelBtn">Cancel</button>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Store chart instances to make them accessible for modal creation
    let stockChart, salesWasteChart;

    // --- Notification Dropdown Logic ---
    const notificationBell = document.getElementById('notificationBell');
    const notificationDropdown = document.getElementById('notificationDropdown');

    if (notificationBell) {
        notificationBell.addEventListener('click', function(event) {
            event.stopPropagation();
            notificationDropdown.classList.toggle('show');
        });
        window.addEventListener('click', function(event) {
            if (!notificationDropdown.contains(event.target) && !notificationBell.contains(event.target)) {
                notificationDropdown.classList.remove('show');
            }
        });
    }
    // --- Dynamic Sort Order Visibility ---
    const sortBySelect = document.getElementById('sortBySelect');
    const sortOrderSelect = document.getElementById('sortOrderSelect');
    const categoryFilterSelect = document.getElementById('categoryFilterSelect');

    function updateFilterVisibility() {
        if (sortBySelect.value === 'category') { // When "Sort by Category" is chosen, switch to filter mode
            sortOrderSelect.style.display = 'none'; // Hide Asc/Desc
            categoryFilterSelect.style.display = 'inline-block'; // Show category filter
        } else { // For all other sort options
            sortOrderSelect.style.display = 'inline-block'; // Show Asc/Desc
            categoryFilterSelect.style.display = 'none'; // Hide category filter
            categoryFilterSelect.value = ''; // Clear any selected category filter
        }
    }

    updateFilterVisibility();
    sortBySelect.addEventListener('change', updateFilterVisibility);

    const chartProductsData = <?= json_encode($all_products_for_chart) ?>;
    const chartHourlyData = <?= json_encode($chart_hourly_data) ?>;
    const forecastHourlyData = <?= json_encode($forecast_hourly_data) ?>;

    if (chartProductsData.length > 0) {
        const lowStockThreshold = 2;
        const mediumStockThreshold = 4;
        
        const ctx = document.getElementById('stockLevelChart').getContext('2d');

        // --- Default View: Filter for low and medium stock items ---
        const defaultFilteredProducts = chartProductsData.filter(p => p.stock <= mediumStockThreshold && p.stock > 0);

        stockChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: defaultFilteredProducts.map(p => p.name),
                datasets: [{
                    label: 'Low Stock',
                    data: defaultFilteredProducts.map(p => p.stock <= lowStockThreshold ? p.stock : null),
                    backgroundColor: 'rgba(255, 99, 132, 0.7)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }, {
                    label: 'Medium Stock',
                    data: defaultFilteredProducts.map(p => (p.stock > lowStockThreshold && p.stock <= mediumStockThreshold) ? p.stock : null),
                    backgroundColor: 'rgba(255, 206, 86, 0.7)',
                    borderColor: 'rgba(255, 206, 86, 1)',
                    borderWidth: 1
                }, {
                    label: 'Large Stock',
                    data: defaultFilteredProducts.map(p => p.stock > mediumStockThreshold ? p.stock : null),
                    backgroundColor: 'rgba(75, 192, 192, 0.7)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1,
                    hidden: true // Hide "Large Stock" by default
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        onClick: (e, legendItem, legend) => {
                            // --- Corrected "Isolate-on-Click" Logic ---
                            const chart = legend.chart;
                            const index = legendItem.datasetIndex;
                            const clickedLabel = legendItem.text;

                            // Check if the clicked item is the only one visible
                            const isOnlyVisible = legend.legendItems.filter(li => !li.hidden).length === 1 && !legendItem.hidden;

                            let filteredProducts = [];
                            
                            if (isOnlyVisible) {
                                // --- Revert to Default View ---
                                // User clicked the only visible item, so show default (Low + Medium)
                                filteredProducts = defaultFilteredProducts;
                                chart.getDatasetMeta(0).hidden = false; // Low
                                chart.getDatasetMeta(1).hidden = false; // Medium
                                chart.getDatasetMeta(2).hidden = true;  // Large
                            } else {
                                // --- Isolate Clicked Category ---
                                // Hide all other datasets and show only the clicked one
                                legend.legendItems.forEach((item, i) => {
                                    chart.getDatasetMeta(i).hidden = (i !== index);
                                });

                                // Filter the product list to show only items from the selected category
                                filteredProducts = chartProductsData.filter(p => {
                                    if (clickedLabel === 'Low Stock' && p.stock <= lowStockThreshold) return true;
                                    if (clickedLabel === 'Medium Stock' && p.stock > lowStockThreshold && p.stock <= mediumStockThreshold) return true;
                                    if (clickedLabel === 'Large Stock' && p.stock > mediumStockThreshold) return true;
                                    return false;
                                });
                            }

                            // Update chart data
                            chart.data.labels = filteredProducts.map(p => p.name);
                            chart.data.datasets[0].data = filteredProducts.map(p => p.stock <= lowStockThreshold ? p.stock : null);
                            chart.data.datasets[1].data = filteredProducts.map(p => (p.stock > lowStockThreshold && p.stock <= mediumStockThreshold) ? p.stock : null);
                            chart.data.datasets[2].data = filteredProducts.map(p => p.stock > mediumStockThreshold ? p.stock : null);

                            chart.update();
                        }
                    }
                },
                scales: {
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        title: { display: true, text: 'Stock Quantity' }
                    },
                    x: { 
                        stacked: true,
                        ticks: { autoSkip: false, maxRotation: 90, minRotation: 45 } 
                    }
                }
            }
        });
    }

    // --- Daily Sales vs Waste Chart ---
    if (chartHourlyData) {
        const dailySalesWasteCtx = document.getElementById('dailySalesWasteChart').getContext('2d');
        const labels = Array.from({length: 24}, (_, i) => {
            const ampm = i >= 12 ? 'PM' : 'AM';
            const hour = i % 12 || 12; // Convert 0 to 12 for 12 AM
            return `${hour} ${ampm}`;
        });

        // Prepare forecast datasets
        const currentHour = new Date().getHours();
        const historicalSalesData = chartHourlyData.map(d => d.sales);
        const historicalWasteData = chartHourlyData.map(d => d.waste);

        // The forecast line should connect to the last historical point
        const forecastSalesData = Array(currentHour).fill(null);
        forecastSalesData.push(historicalSalesData[currentHour]);
        const forecastWasteData = Array(currentHour).fill(null);
        forecastWasteData.push(historicalWasteData[currentHour]);

        for (let hour = currentHour + 1; hour < 24; hour++) {
            forecastSalesData.push(forecastHourlyData[hour] ? forecastHourlyData[hour].sales : null);
            forecastWasteData.push(forecastHourlyData[hour] ? forecastHourlyData[hour].waste : null);
        }

        salesWasteChart = new Chart(dailySalesWasteCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                     {
                        label: 'Sales',
                        data: historicalSalesData,
                        borderColor: 'rgba(75, 192, 192, 1)',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Forecasted Sales',
                        data: forecastSalesData,
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderDash: [5, 5],
                        backgroundColor: 'rgba(75, 192, 192, 0.05)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Waste',
                        data: historicalWasteData,
                        borderColor: 'rgba(255, 99, 132, 1)',
                        backgroundColor: 'rgba(255, 99, 132, 0.1)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Forecasted Waste',
                        data: forecastWasteData,
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
                scales: { 
                    y: { beginAtZero: true, ticks: { callback: value => '₱' + value } },
                    x: { ticks: { autoSkip: true, maxTicksLimit: 12 } } // Prevent overcrowding on x-axis
                },
                plugins: {
                    tooltip: {
                        mode: 'index', intersect: false,
                        callbacks: { label: (context) => `${context.dataset.label}: ₱${context.parsed.y.toFixed(2)}` }
                    },
                    legend: {
                        position: 'top',
                        onClick: (e, legendItem, legend) => {
                            const chart = legend.chart;
                            const clickedIndex = legendItem.datasetIndex;
                            const isSalesGroup = clickedIndex === 0 || clickedIndex === 1;
                            const salesMeta = chart.getDatasetMeta(0);
                            const wasteMeta = chart.getDatasetMeta(2);
                            const isSalesIsolated = !salesMeta.hidden && wasteMeta.hidden;
                            const isWasteIsolated = salesMeta.hidden && !wasteMeta.hidden;

                            if ((isSalesGroup && isSalesIsolated) || (!isSalesGroup && isWasteIsolated)) {
                                chart.data.datasets.forEach((_, i) => chart.getDatasetMeta(i).hidden = false);
                            } else {
                                chart.getDatasetMeta(0).hidden = !isSalesGroup;
                                chart.getDatasetMeta(1).hidden = !isSalesGroup;
                                chart.getDatasetMeta(2).hidden = isSalesGroup;
                                chart.getDatasetMeta(3).hidden = isSalesGroup;
                            }
                            chart.update();
                        }
                    }
                }
            }
        });
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
            options: { // You can define specific, larger options for the modal
                ...chartInstance.config.options // Inherit all options from the original chart, including the new onClick logic
            }
        });

        chartModal.style.display = 'block';
    }

    // Add click listeners to the original chart canvases
    document.getElementById('stockLevelChart').addEventListener('click', () => {
        openChartInModal(stockChart, 'Stock Level Visualization');
    });
    document.getElementById('dailySalesWasteChart').addEventListener('click', () => {
        openChartInModal(salesWasteChart, "Today's Sales vs Waste Cost");
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
    });
});
</script>
</body>
</html>