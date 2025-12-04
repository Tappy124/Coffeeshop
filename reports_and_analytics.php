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

// --- CSV Export Logic ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $report_type = trim($_GET['report_type'] ?? 'overview');
    $start_date = trim($_GET['start_date'] ?? date('Y-m-01'));
    $end_date = trim($_GET['end_date'] ?? date('Y-m-t'));
    $start_datetime = $start_date . " 00:00:00";
    $end_datetime = $end_date . " 23:59:59";

    $data = [];
    $filename = "report.csv";
    $headers = [];

    // Re-run the correct query based on the report type
    switch ($report_type) {
        case 'sales_summary':
            $filename = "sales_summary_{$start_date}_to_{$end_date}.csv";
            $headers = ['Product Name', 'Total Quantity Sold', 'Total Sales'];
            $stmt = $conn->prepare("SELECT mp.product_name as name, SUM(s.quantity) as total_quantity, SUM(s.total_amount) as total_sales FROM sales s JOIN menu_product mp ON s.product_id = mp.product_id WHERE s.sale_date >= ? AND s.sale_date <= ? GROUP BY mp.product_name ORDER BY total_sales DESC");
            $stmt->bind_param("ss", $start_datetime, $end_datetime);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            break;
        case 'waste_summary':
            $filename = "waste_summary_{$start_date}_to_{$end_date}.csv";
            $headers = ['Product Name', 'Reason', 'Total Quantity', 'Total Waste Cost'];
            $stmt = $conn->prepare("SELECT COALESCE(mp.product_name, p.name) as name, w.reason, SUM(w.quantity) as total_quantity, SUM(w.waste_cost) as total_waste_cost FROM waste w LEFT JOIN products p ON w.product_id = p.id AND w.product_id NOT LIKE 'DR%' LEFT JOIN menu_product mp ON w.product_id = mp.product_id AND w.product_id LIKE 'DR%' WHERE w.waste_date >= ? AND w.waste_date <= ? GROUP BY name, w.reason ORDER BY total_waste_cost DESC");
            $stmt->bind_param("ss", $start_datetime, $end_datetime);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            break;
        case 'inventory_turnover':
            $filename = "inventory_turnover_{$start_date}_to_{$end_date}.csv";
            $headers = ['Product Name', 'Total Sold', 'Total Wasted', 'Current Stock'];
            $stmt = $conn->prepare("
                SELECT COALESCE(mp.product_name, p.name) as name, COALESCE(s.total_sold, 0) AS total_sold, COALESCE(w.total_wasted, 0) AS total_wasted, p.stock AS current_stock
                FROM (SELECT DISTINCT product_id FROM sales UNION SELECT DISTINCT product_id FROM waste) all_items
                LEFT JOIN (SELECT product_id, SUM(quantity) AS total_sold FROM sales WHERE sale_date >= ? AND sale_date <= ? GROUP BY product_id) s ON all_items.product_id = s.product_id
                LEFT JOIN (SELECT product_id, SUM(quantity) AS total_wasted FROM waste WHERE waste_date >= ? AND waste_date <= ? GROUP BY product_id) w ON all_items.product_id = w.product_id
                LEFT JOIN products p ON all_items.product_id = p.id AND all_items.product_id NOT LIKE 'DR%'
                LEFT JOIN menu_product mp ON all_items.product_id = mp.product_id AND all_items.product_id LIKE 'DR%'
                WHERE s.total_sold > 0 OR w.total_wasted > 0 ORDER BY s.total_sold DESC
            ");
            $stmt->bind_param("ssss", $start_datetime, $end_datetime, $start_datetime, $end_datetime);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            break;
        case 'profitability':
            $filename = "profitability_analysis_{$start_date}_to_{$end_date}.csv";
            $headers = ['Product Name', 'Total Revenue', 'Cost of Goods Sold', 'Net Profit'];
            $stmt = $conn->prepare("
                SELECT 
                    mp.product_name as name,
                    SUM(s.total_amount) as total_revenue,
                    SUM(s.cogs) as total_cogs,
                    (SUM(s.total_amount) - SUM(s.cogs)) as net_profit
                FROM menu_product mp
                JOIN (
                    SELECT product_id, total_amount, cogs, sale_date
                    FROM sales 
                    WHERE sale_date >= ? AND sale_date <= ? 
                ) AS s ON mp.product_id = s.product_id
                GROUP BY mp.product_id, mp.product_name
                ORDER BY net_profit DESC
            ");
            $stmt->bind_param("ss", $start_datetime, $end_datetime);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            break;
    }

    // Set headers to force download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // Create a file pointer connected to the output stream
    $output = fopen('php://output', 'w');

    // Write the column headers
    fputcsv($output, $headers);

    // Loop over the data and write each row
    foreach ($data as $row) {
        // The keys might not match the header order, so we need to re-order them.
        // This is a simple way, but for more complex reports, you might need to map keys explicitly.
        fputcsv($output, $row);
    }

    fclose($output);
    $conn->close();
    exit();
}


// --- Whitelisting for Security ---
$report_types_whitelist = [
    'overview',
    'sales_summary',
    'waste_summary',
    'inventory_turnover',
    'profitability'
];
$report_type = trim($_GET['report_type'] ?? 'overview'); // Keep for filter state
if (!in_array($report_type, $report_types_whitelist)) {
    $report_type = 'overview'; // Fallback to a safe default
}

// --- Filtering Logic ---
$start_date = trim($_GET['start_date'] ?? date('Y-m-01')); // Default to start of current month
$end_date = trim($_GET['end_date'] ?? date('Y-m-t'));   // Default to end of current month
$error_message = '';

// Validate that the end date is not before the start date
if (!empty($start_date) && !empty($end_date) && $end_date < $start_date) {
    $error_message = "Error: The 'To' date cannot be earlier than the 'From' date. Please select a valid date range.";
    // To prevent queries from running with invalid dates, we can reset the report type or data
    $report_type = 'invalid_date';
}

// --- Fetch all categories for the filter dropdowns (moved here to be available for all report types) ---
$category_result = $conn->query("
    (SELECT DISTINCT category FROM menu_product WHERE category IS NOT NULL AND category != '')
    UNION
    (SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '')
    ORDER BY category ASC
");
$all_categories = $category_result->fetch_all(MYSQLI_ASSOC);

if ($report_type === 'overview') {
    $report_title = "Analytics Overview";
    $report_description = "A real-time summary of key business metrics across all historical data.";

    // --- Fetch Data for ALL Charts ---
    // 1. Sales Data (Top 5)
    $stmt_sales = $conn->prepare("SELECT mp.product_name as name, SUM(s.total_amount) as total_sales FROM sales s JOIN menu_product mp ON s.product_id = mp.product_id GROUP BY mp.product_name ORDER BY total_sales DESC LIMIT 5");
    $stmt_sales->execute();
    $sales_overview_data = $stmt_sales->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_sales->close();

    // 2. Waste Data by Reason
    $stmt_waste = $conn->prepare("SELECT reason, SUM(waste_cost) as total_waste_cost FROM waste GROUP BY reason ORDER BY total_waste_cost DESC");
    $stmt_waste->execute();
    $waste_overview_data = $stmt_waste->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_waste->close();

    // 3. Profitability Data (Top 5)
    $stmt_profit = $conn->prepare("
        SELECT 
            mp.product_name as name,
            SUM(s.total_amount) as total_revenue,
            SUM(s.cogs) as total_cogs,
            (SUM(s.total_amount) - SUM(s.cogs)) as net_profit
        FROM menu_product mp
        JOIN sales s ON mp.product_id = s.product_id
        GROUP BY mp.product_id, mp.product_name
        ORDER BY net_profit DESC 
        LIMIT 5");
    $stmt_profit->execute();
    $profit_overview_data = $stmt_profit->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_profit->close();

    // 4. Inventory Movement Data (Top 5)
    $stmt_inv = $conn->prepare("SELECT all_items.product_id, COALESCE(mp.product_name, p.name) as name, COALESCE(s.total_sold, 0) AS total_sold, COALESCE(w.total_wasted, 0) AS total_wasted, p.content as inventory_content FROM (SELECT DISTINCT product_id FROM sales UNION SELECT DISTINCT product_id FROM waste) all_items LEFT JOIN (SELECT product_id, SUM(quantity) AS total_sold FROM sales GROUP BY product_id) s ON all_items.product_id = s.product_id LEFT JOIN (SELECT product_id, SUM(quantity) AS total_wasted FROM waste GROUP BY product_id) w ON all_items.product_id = w.product_id LEFT JOIN products p ON all_items.product_id = p.id AND all_items.product_id NOT LIKE 'DR%' LEFT JOIN menu_product mp ON all_items.product_id = mp.product_id AND all_items.product_id LIKE 'DR%' WHERE s.total_sold > 0 OR w.total_wasted > 0 ORDER BY (COALESCE(s.total_sold, 0) + COALESCE(w.total_wasted, 0)) DESC LIMIT 5");
    $stmt_inv->execute();
    $inventory_overview_data = $stmt_inv->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_inv->close();
    // Process to add unit
    foreach ($inventory_overview_data as &$item) {
        if (strpos($item['product_id'], 'DR') === 0) {
            $item['unit'] = 'drink';
        } else {
            preg_match('/^(\d*\.?\d+)\s*(.*)$/i', $item['inventory_content'] ?? '', $matches);
            $item['unit'] = trim($matches[2] ?? 'unit');
        }
    }
    unset($item);

    // --- Fetch data for detailed charts to also show on overview ---
    // Sales Summary Data
    $stmt_sales_summary = $conn->prepare("SELECT mp.product_name as name, mp.category, SUM(s.total_amount) as total_sales FROM sales s JOIN menu_product mp ON s.product_id = mp.product_id GROUP BY mp.product_name, mp.category ORDER BY total_sales DESC");
    $stmt_sales_summary->execute();
    $sales_summary_data = $stmt_sales_summary->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_sales_summary->close();

    // Waste Summary Data (by product for a bar chart)
    $stmt_waste_summary = $conn->prepare("SELECT COALESCE(mp.product_name, p.name) as name, COALESCE(mp.category, p.category) as category, SUM(w.waste_cost) as total_waste_cost FROM waste w LEFT JOIN products p ON w.product_id = p.id AND w.product_id NOT LIKE 'DR%' LEFT JOIN menu_product mp ON w.product_id = mp.product_id AND w.product_id LIKE 'DR%' GROUP BY name, category ORDER BY total_waste_cost DESC");
    $stmt_waste_summary->execute();
    $waste_summary_data = $stmt_waste_summary->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_waste_summary->close();

    // Inventory Turnover Data
    $stmt_turnover = $conn->prepare("
        SELECT all_items.product_id, COALESCE(mp.product_name, p.name) as name, COALESCE(mp.category, p.category) as category, COALESCE(s.total_sold, 0) AS total_sold, COALESCE(w.total_wasted, 0) AS total_wasted, p.content as inventory_content
        FROM (SELECT DISTINCT product_id FROM sales UNION SELECT DISTINCT product_id FROM waste) all_items
        LEFT JOIN (SELECT product_id, SUM(quantity) AS total_sold FROM sales GROUP BY product_id) s ON all_items.product_id = s.product_id
        LEFT JOIN (SELECT product_id, SUM(quantity) AS total_wasted FROM waste GROUP BY product_id) w ON all_items.product_id = w.product_id
        LEFT JOIN products p ON all_items.product_id = p.id AND all_items.product_id NOT LIKE 'DR%'
        LEFT JOIN menu_product mp ON all_items.product_id = mp.product_id AND all_items.product_id LIKE 'DR%'
        WHERE s.total_sold > 0 OR w.total_wasted > 0
        ORDER BY (COALESCE(s.total_sold, 0) + COALESCE(w.total_wasted, 0)) DESC
    ");
    $stmt_turnover->execute();
    $inventory_turnover_data = $stmt_turnover->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_turnover->close();
    // Process to add unit
    foreach ($inventory_turnover_data as &$item) {
        if (strpos($item['product_id'], 'DR') === 0) {
            $item['unit'] = 'drink';
        } else {
            preg_match('/^(\d*\.?\d+)\s*(.*)$/i', $item['inventory_content'] ?? '', $matches);
            $item['unit'] = trim($matches[2] ?? 'unit');
        }
    }
    unset($item);

    // Profitability Analysis Data
    $stmt_profit_analysis = $conn->prepare("
        SELECT 
            mp.product_name as name,
            mp.category,
            SUM(s.total_amount) as total_revenue,
            SUM(s.cogs) as total_cogs,
            (SUM(s.total_amount) - SUM(s.cogs)) as net_profit
        FROM menu_product mp
        JOIN sales s ON mp.product_id = s.product_id
        GROUP BY mp.product_id, mp.product_name, mp.category
        ORDER BY name ASC");
    $stmt_profit_analysis->execute();
    $profitability_data = $stmt_profit_analysis->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_profit_analysis->close();
} else {
    // Logic for individual detailed reports
    $report_data = [];
    $report_title = "Report";
    $report_description = "Select a report type and date range to generate data.";
    $start_datetime = $start_date . " 00:00:00";
    $end_datetime = $end_date . " 23:59:59";

    switch ($report_type) {
        case 'sales_summary':
            $report_title = "Sales Summary";
            $report_description = "A detailed breakdown of sales transactions for each product within the selected date range.";
            $stmt = $conn->prepare("SELECT mp.product_name as name, mp.category, SUM(s.quantity) as total_quantity, SUM(s.total_amount) as total_sales FROM sales s JOIN menu_product mp ON s.product_id = mp.product_id WHERE s.sale_date >= ? AND s.sale_date <= ? GROUP BY mp.product_name, mp.category ORDER BY total_sales DESC");
            $stmt->bind_param("ss", $start_datetime, $end_datetime);
            $stmt->execute();
            $report_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            break;
        case 'waste_summary':
            $report_title = "Waste Summary";
            $report_description = "A detailed summary of all waste logs, highlighting costs and common reasons for the selected period.";
            $stmt = $conn->prepare("SELECT COALESCE(mp.product_name, p.name) as name, COALESCE(mp.category, p.category) as category, w.reason, SUM(w.quantity) as total_quantity, SUM(w.waste_cost) as total_waste_cost FROM waste w LEFT JOIN products p ON w.product_id = p.id AND w.product_id NOT LIKE 'DR%' LEFT JOIN menu_product mp ON w.product_id = mp.product_id AND w.product_id LIKE 'DR%' WHERE w.waste_date >= ? AND w.waste_date <= ? GROUP BY name, category, w.reason ORDER BY total_waste_cost DESC");
            $stmt->bind_param("ss", $start_datetime, $end_datetime);
            $stmt->execute();
            $report_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            break;
        case 'inventory_turnover':
            $report_title = "Inventory Turnover";
            $report_description = "Analysis of product movement, comparing units sold and wasted against current stock levels for the selected period.";
            $stmt = $conn->prepare("
                SELECT
                    COALESCE(mp.category, p.category) as category,
                    COALESCE(mp.product_name, p.name) as name,
                    p.stock AS current_stock,
                    COALESCE(s.total_sold, 0) AS total_sold,
                    COALESCE(w.total_wasted, 0) AS total_wasted
                FROM (SELECT DISTINCT product_id FROM sales UNION SELECT DISTINCT product_id FROM waste) all_items
                LEFT JOIN (SELECT product_id, SUM(quantity) AS total_sold FROM sales WHERE sale_date >= ? AND sale_date <= ? GROUP BY product_id) s ON all_items.product_id = s.product_id
                LEFT JOIN (SELECT product_id, SUM(quantity) AS total_wasted FROM waste WHERE waste_date >= ? AND waste_date <= ? GROUP BY product_id) w ON all_items.product_id = w.product_id
                LEFT JOIN products p ON all_items.product_id = p.id AND all_items.product_id NOT LIKE 'DR%'
                LEFT JOIN menu_product mp ON all_items.product_id = mp.product_id AND all_items.product_id LIKE 'DR%'
                WHERE s.total_sold > 0 OR w.total_wasted > 0
                ORDER BY s.total_sold DESC
            ");
            $stmt->bind_param("ssss", $start_datetime, $end_datetime, $start_datetime, $end_datetime);
            $stmt->execute();
            $report_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            break;
        case 'profitability':
            $report_title = "Profitability Analysis";
            $report_description = "Insights into product profitability, comparing sales revenue against costs for the selected period.";
            $stmt = $conn->prepare("
                SELECT 
                    mp.product_name as name,
                    mp.category,
                    SUM(s.total_amount) as total_revenue,
                    SUM(s.cogs) as total_cogs,
                    (SUM(s.total_amount) - SUM(s.cogs)) as net_profit
                FROM menu_product mp
                JOIN sales s ON mp.product_id = s.product_id
                WHERE s.sale_date >= ? AND s.sale_date <= ?
                GROUP BY mp.product_id, mp.product_name, mp.category
                ORDER BY net_profit DESC
            ");
            $stmt->bind_param("ss", $start_datetime, $end_datetime);
            $stmt->execute();
            $report_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            break;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports & Analytics</title>
    <link rel="stylesheet" href="css/modal.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Style for the date input placeholder to match sales_and_waste.php */
        .filter-form input[type="date"] {
            position: relative;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #ccc;
            background-color: var(--bg);
            color: var(--text);
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        
        /* Style the placeholder text for Webkit browsers (Chrome, Safari) */
        .filter-form input[type="date"]::-webkit-input-placeholder { color: #777; }
        
        /* Style the placeholder text for Firefox */
        .filter-form input[type="date"]:-moz-placeholder { color: #777; opacity: 1; }
        .filter-form input[type="date"]::-moz-placeholder { color: #777; opacity: 1; }
        
        /* Style the placeholder text for Edge & IE */
        .filter-form input[type="date"]:-ms-input-placeholder { color: #777; }
        .filter-form input[type="date"]::-ms-input-placeholder { color: #777; }
        
        /* Show placeholder when the input is not focused and has no value */
        .filter-form input[type="date"]:not(:focus):not([value]):not([value=""])::before {
            content: attr(placeholder);
            color: #777;
        }
        .filter-form input[type="date"]:not([value]):not([value=""]) {
            color: #777; /* Ensure placeholder text color is applied when value is empty */
        }
    </style>
</head>
<body>
<div class="container">
    <aside class="sidebar">
        <div class="logo"><img src="images/logo.png" alt="Logo"></div>
        <h2>Admin Dashboard</h2>
        <ul>
            <li><a href="admin_dashboard.php">Dashboard</a></li>
            <li><a href="inventory_products.php">Inventory Items</a></li>
            <li><a href="products_menu.php">Menu Items</a></li>
            <li><a href="supplier_management.php">Supplier</a></li>
            <li><a href="sales_and_waste.php">Sales & Waste</a></li>
            <li><a href="reports_and_analytics.php" class="active">Reports & Analytics</a></li>
            <li><a href="admin_forecasting.php">Forecasting</a></li>
            <li><a href="system_management.php">System Management</a></li>
            <li><a href="user_account.php">My Account</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </aside>

    <main class="main">
        <header>
            <div class="top-row">
                <h1>Reports & Analytics</h1>
                <div class="header-actions" style="display:flex; gap:10px; align-items:center;">
                </div>
            </div>
        </header>

        <div class="filter-bar">
            <form method="GET" action="reports_and_analytics.php" class="filter-form">
                <label for="report_type">Report Type:</label>
                <select name="report_type" id="report_type">
                    <option value="overview" <?= $report_type == 'overview' ? 'selected' : '' ?>>Analytics Overview</option>
                    <option value="sales_summary" <?= $report_type == 'sales_summary' ? 'selected' : '' ?>>Sales Summary</option>
                    <option value="waste_summary" <?= $report_type == 'waste_summary' ? 'selected' : '' ?>>Waste Summary</option>
                    <option value="inventory_turnover" <?= $report_type == 'inventory_turnover' ? 'selected' : '' ?>>Inventory Turnover</option>
                    <option value="profitability" <?= $report_type == 'profitability' ? 'selected' : '' ?>>Profitability Analysis</option>
                </select>

                <div id="date_filter_wrapper" style="<?= $report_type === 'overview' ? 'display:none;' : '' ?>">
                    <label for="start_date">From:</label>
                    <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>" placeholder="From Date">

                    <label for="end_date">To:</label>
                    <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>" placeholder="To Date">
                </div>

                <button type="submit" class="btn">Filter</button>
                <a href="reports_and_analytics.php" class="btn cancel-btn">Clear</a>
            </form>
        </div>

        <?php if ($report_type === 'overview'): ?>
            <section class="box">
                <h2><?= htmlspecialchars($report_title) ?></h2>
                <p><?= htmlspecialchars($report_description) ?></p>
                <div class="charts-wrapper">
                    <section class="charts">
                        <div class="chart-box">
                            <h3>Top 5 Products by Sales</h3>
                            <div class="chart-container"><canvas id="salesOverviewChart"></canvas></div>
                        </div>
                        <div class="chart-box">
                            <h3>Top 5 Most Profitable Products</h3>
                            <div class="chart-container"><canvas id="profitOverviewChart"></canvas></div>
                        </div>
                    </section>
                    <section class="charts">
                        <div class="chart-box">
                            <h3>Waste Cost by Reason</h3>
                            <div class="chart-container"><canvas id="wasteOverviewChart"></canvas></div>
                        </div>
                        <div class="chart-box">
                            <h3>Top 5 Products by Movement</h3>
                            <div class="chart-container"><canvas id="inventoryOverviewChart"></canvas></div>
                        </div>
                    </section>
                    <!-- Detailed Charts for Overview Page -->
                    <section class="charts">
                        <div class="chart-box sortable-chart">
                            <div class="chart-header">
                                <h3>Sales Summary by Product</h3>
                                <select class="chart-sorter" data-chart-id="salesSummaryChart">
                                    <option value="">All Categories</option>
                                    <?php foreach($all_categories as $cat) echo "<option value='".htmlspecialchars($cat['category'])."'>".htmlspecialchars($cat['category'])."</option>"; ?>
                                </select>
                            </div>
                            <div class="chart-container"><canvas id="salesSummaryChart"></canvas></div>
                        </div>
                        <div class="chart-box sortable-chart">
                             <div class="chart-header">
                                <h3>Waste Summary by Product</h3>
                                <select class="chart-sorter" data-chart-id="wasteSummaryChart">
                                    <option value="">All Categories</option>
                                    <?php foreach($all_categories as $cat) echo "<option value='".htmlspecialchars($cat['category'])."'>".htmlspecialchars($cat['category'])."</option>"; ?>
                                </select>
                            </div>
                            <div class="chart-container"><canvas id="wasteSummaryChart"></canvas></div>
                        </div>
                    </section>
                    <section class="charts">
                        <div class="chart-box sortable-chart">
                            <div class="chart-header">
                                <h3>Inventory Turnover</h3>
                                <select class="chart-sorter" data-chart-id="inventoryTurnoverChart">
                                    <option value="">All Categories</option>
                                    <?php foreach($all_categories as $cat) echo "<option value='".htmlspecialchars($cat['category'])."'>".htmlspecialchars($cat['category'])."</option>"; ?>
                                </select>
                            </div>
                            <div class="chart-container"><canvas id="inventoryTurnoverChart"></canvas></div>
                        </div>
                        <div class="chart-box sortable-chart">
                            <div class="chart-header">
                                <h3>Profitability Analysis</h3>
                                <select class="chart-sorter" data-chart-id="profitabilityChart">
                                    <option value="">All Categories</option>
                                    <?php foreach($all_categories as $cat) echo "<option value='".htmlspecialchars($cat['category'])."'>".htmlspecialchars($cat['category'])."</option>"; ?>
                                </select>
                            </div>
                            <div class="chart-container"><canvas id="profitabilityChart"></canvas></div>
                        </div>
                    </section>
                </div>
            </section>
        <?php else: ?>
            <section class="box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <div>
                        <h2><?= htmlspecialchars($report_title) ?></h2>
                        <p><?= htmlspecialchars($report_description) ?></p>
                    </div>
                    <div>
                        <button class="btn" id="exportCsvBtn">Export to CSV</button>
                    </div>
                </div>

                <div class="table-container" style="max-height: 300px;">
                    <?php if (!empty($report_data)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <?php if ($report_type === 'sales_summary'): ?>
                                        <th>Product Name</th><th>Total Quantity Sold</th><th>Total Sales</th>
                                    <?php elseif ($report_type === 'waste_summary'): ?>
                                        <th>Product Name</th><th>Reason</th><th>Total Quantity</th><th>Total Waste Cost</th>
                                    <?php elseif ($report_type === 'inventory_turnover'): ?>
                                        <th>Product Name</th><th>Total Sold</th><th>Total Wasted</th><th>Current Stock</th>
                                    <?php elseif ($report_type === 'profitability'): ?>
                                        <th>Product Name</th><th>Total Revenue</th><th>Cost of Goods Sold</th><th>Net Profit</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="reportTableBody">
                                <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <?php if ($report_type === 'sales_summary'): ?>
                                        <td><?= htmlspecialchars($row['name']) ?></td>
                                        <td><?= htmlspecialchars($row['total_quantity']) ?></td>
                                        <td>₱<?= number_format($row['total_sales'], 2) ?></td>
                                    <?php elseif ($report_type === 'waste_summary'): ?>
                                        <td><?= htmlspecialchars($row['name']) ?></td>
                                        <td><?= htmlspecialchars($row['reason']) ?></td>
                                        <td><?= htmlspecialchars($row['total_quantity']) ?></td>
                                        <td>₱<?= number_format($row['total_waste_cost'], 2) ?></td>
                                    <?php elseif ($report_type === 'inventory_turnover'): ?>
                                        <td><?= htmlspecialchars($row['name']) ?></td>
                                        <td><?= htmlspecialchars($row['total_sold']) ?></td>
                                        <td><?= htmlspecialchars($row['total_wasted']) ?></td>
                                        <td><?= htmlspecialchars($row['current_stock']) ?></td>
                                    <?php elseif ($report_type === 'profitability'): ?>
                                        <td><?= htmlspecialchars($row['name']) ?></td>
                                        <td>₱<?= number_format($row['total_revenue'], 2) ?></td>
                                        <td>₱<?= number_format($row['total_cogs'], 2) ?></td>
                                        <td>₱<?= number_format($row['net_profit'], 2) ?></td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No data available for the selected criteria.</p>
                    <?php endif; ?>
                </div>

                <div class="chart-box sortable-chart" style="margin-top: 30px; padding: 20px; border-radius: 8px;">
                    <div class="chart-header">
                        <h3>Chart Visualization</h3>
                        <select class="chart-sorter" id="detailedReportSorter" data-chart-id="reportChart">
                            <option value="">All Categories</option>
                            <?php foreach($all_categories as $cat) echo "<option value='".htmlspecialchars($cat['category'])."'>".htmlspecialchars($cat['category'])."</option>"; ?>
                        </select>
                    </div>
                    <div class="chart-container" style="height: 400px;">
                        <canvas id="reportChart" style="<?= empty($report_data) ? 'display:none;' : '' ?>"></canvas>
                    </div>
                </div>
            </section>
        <?php endif; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Add styles for the new elements ---
    const style = document.createElement('style');
    style.textContent = `
        .loader-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3; /* Light grey */
            border-top: 4px solid var(--accent); /* Theme color */
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin { 
            0% { transform: rotate(0deg); } 
            100% { transform: rotate(360deg); } 
        }

        .chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .chart-header h3 { margin: 0; }
        .chart-sorter {
            padding: 5px 8px;
            border-radius: 4px;
            border: 1px solid #ccc;
            background-color: #e0f7fa;
            color: #00796b;
            cursor: pointer;
        }
    `;
    document.head.appendChild(style);
    // Store chart instances to make them accessible for modal creation
    const chartInstances = {};

    // --- Filter Form Logic ---
    const reportTypeSelect = document.getElementById('report_type');
    const dateFilterWrapper = document.getElementById('date_filter_wrapper');
    const filterForm = document.querySelector('.filter-form');

    reportTypeSelect.addEventListener('change', function() {
        // Show date filters only for detailed reports
        dateFilterWrapper.style.display = this.value === 'overview' ? 'none' : '';
    });

    filterForm.addEventListener('submit', function(e) {
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        if (startDate && endDate && endDate < startDate) {
            e.preventDefault(); // Stop the form from submitting

            // Use the generic confirmation modal to show the error
            const confirmModal = document.getElementById('confirmModal');
            const confirmMessage = document.getElementById('confirmMessage');
            const confirmYesBtn = document.getElementById('confirmYesBtn');
            const confirmCancelBtn = document.getElementById('confirmCancelBtn');

            confirmMessage.textContent = "Error: The 'To' date cannot be earlier than the 'From' date. Please select a valid date range.";
            confirmYesBtn.style.display = 'none'; // Hide the confirm button
            confirmCancelBtn.textContent = 'OK'; // Change cancel button to 'OK'

            confirmModal.style.display = 'block';
        }
    });



    const reportType = '<?= $report_type ?>';

    if (reportType === 'overview') {
        const salesData = <?= json_encode($sales_overview_data ?? []) ?>;
        const profitData = <?= json_encode($profit_overview_data ?? []) ?>;
        const wasteData = <?= json_encode($waste_overview_data ?? []) ?>;
        const inventoryData = <?= json_encode($inventory_overview_data ?? []) ?>;
        const salesSummaryData = <?= json_encode($sales_summary_data ?? []) ?>;
        const wasteSummaryData = <?= json_encode($waste_summary_data ?? []) ?>;
        const inventoryTurnoverData = <?= json_encode($inventory_turnover_data ?? []) ?>;
        const profitabilityData = <?= json_encode($profitability_data ?? []) ?>;

        // --- Generic Legend Click Handler ---
        const legendClickHandler = (e, legendItem, legend) => {
            const chart = legend.chart;
            const index = legendItem.datasetIndex;

            // Check if the clicked item is the only one visible
            const isOnlyVisible = legend.legendItems.filter(li => !li.hidden).length === 1 && !legendItem.hidden;

            if (isOnlyVisible) {
                // If it's the only one visible, clicking it again should show all datasets
                legend.legendItems.forEach((item, i) => {
                    chart.getDatasetMeta(i).hidden = null;
                });
            } else {
                // Otherwise, hide all other datasets and show only the clicked one
                legend.legendItems.forEach((item, i) => { chart.getDatasetMeta(i).hidden = (i !== index); });
            }
            chart.update();
        };

        // Sales Chart
        if (salesData.length > 0) {
            chartInstances.salesOverviewChart = new Chart(document.getElementById('salesOverviewChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: salesData.map(item => item.name),
                    datasets: [{ label: 'Total Sales', data: salesData.map(item => item.total_sales), backgroundColor: 'rgba(75, 192, 192, 0.6)' }]
                },
                options: { responsive: true, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => `Total Sales: ₱${ctx.parsed.y.toLocaleString()}` } } }, scales: { y: { beginAtZero: true, ticks: { callback: value => '₱' + value.toLocaleString() } } } }
            });
        }

        // Profit Chart
        if (profitData.length > 0) {
            chartInstances.profitOverviewChart = new Chart(document.getElementById('profitOverviewChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: profitData.map(item => item.name),
                    datasets: [
                        { label: 'Net Profit', data: profitData.map(item => item.net_profit), backgroundColor: 'rgba(75, 192, 192, 0.6)' },
                        { label: 'Cost of Goods Sold', data: profitData.map(item => item.total_cogs), backgroundColor: 'rgba(255, 159, 64, 0.6)' }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { callback: value => '₱' + value.toLocaleString() } } },
                    plugins: { 
                        legend: { position: 'top', onClick: legendClickHandler },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) { label += ': '; }
                                    label += '₱' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Waste Chart
        if (wasteData.length > 0) {
            chartInstances.wasteOverviewChart = new Chart(document.getElementById('wasteOverviewChart').getContext('2d'), {
                type: 'pie',
                data: {
                    labels: wasteData.map(item => item.reason),
                    datasets: [{ data: wasteData.map(item => item.total_waste_cost), backgroundColor: ['rgba(255, 99, 132, 0.7)', 'rgba(54, 162, 235, 0.7)', 'rgba(255, 206, 86, 0.7)'] }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: ctx => `${ctx.label}: ₱${ctx.parsed.toLocaleString()}` } } } }
            });
        }

        // Inventory Chart
        if (inventoryData.length > 0) {
            chartInstances.inventoryOverviewChart = new Chart(document.getElementById('inventoryOverviewChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: inventoryData.map(item => item.name),
                    datasets: [
                        { label: 'Sold', data: inventoryData.map(item => item.total_sold), backgroundColor: 'rgba(75, 192, 192, 0.6)' },
                        { label: 'Wasted', data: inventoryData.map(item => item.total_wasted), backgroundColor: 'rgba(255, 99, 132, 0.6)' }
                    ],
                    // Custom data for tooltips
                    units: inventoryData.map(item => item.unit)
                },
                options: { responsive: true, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }, plugins: { 
                    legend: { position: 'top', onClick: legendClickHandler },
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y} ${ctx.chart.data.units[ctx.dataIndex]}`
                        }
                    }
                } }
            });
        }

        // --- Detailed Charts on Overview ---
        // Sales Summary Chart
        if (salesSummaryData.length > 0) {
            chartInstances.salesSummaryChart = new Chart(document.getElementById('salesSummaryChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: salesSummaryData.map(item => item.name),
                    datasets: [{ label: 'Total Sales', data: salesSummaryData.map(item => item.total_sales), backgroundColor: 'rgba(74, 108, 111, 0.6)' }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => `Total Sales: ₱${ctx.parsed.y.toLocaleString()}` } } }, scales: { y: { beginAtZero: true, ticks: { callback: value => '₱' + value.toLocaleString() } } } }
            });
        }

        // Waste Summary Chart
        if (wasteSummaryData.length > 0) {
            chartInstances.wasteSummaryChart = new Chart(document.getElementById('wasteSummaryChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: wasteSummaryData.map(item => item.name),
                    datasets: [{ label: 'Total Waste Cost', data: wasteSummaryData.map(item => item.total_waste_cost), backgroundColor: 'rgba(255, 99, 132, 0.6)' }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => `Waste Cost: ₱${ctx.parsed.y.toLocaleString()}` } } }, scales: { y: { beginAtZero: true, ticks: { callback: value => '₱' + value.toLocaleString() } } } }
            });
        }

        // Inventory Turnover Chart
        if (inventoryTurnoverData.length > 0) {
            chartInstances.inventoryTurnoverChart = new Chart(document.getElementById('inventoryTurnoverChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: inventoryTurnoverData.map(item => item.name),
                    datasets: [
                        { label: 'Sold', data: inventoryTurnoverData.map(item => item.total_sold), backgroundColor: 'rgba(75, 192, 192, 0.6)' },
                        { label: 'Wasted', data: inventoryTurnoverData.map(item => item.total_wasted), backgroundColor: 'rgba(255, 99, 132, 0.6)' }
                    ],
                    units: inventoryTurnoverData.map(item => item.unit)
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }, plugins: { 
                    legend: { position: 'top', onClick: legendClickHandler },
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y} ${ctx.chart.data.units[ctx.dataIndex]}`
                        }
                    }
                
                } }
            });
        }

        // Profitability Analysis Chart
        if (profitabilityData.length > 0) {
            chartInstances.profitabilityChart = new Chart(document.getElementById('profitabilityChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: profitabilityData.map(item => item.name),
                    datasets: [
                        { label: 'Net Profit', data: profitabilityData.map(item => item.net_profit), backgroundColor: 'rgba(75, 192, 192, 0.6)' },
                        { label: 'Cost of Goods Sold', data: profitabilityData.map(item => item.total_cogs), backgroundColor: 'rgba(255, 159, 64, 0.6)' }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { callback: value => '₱' + value.toLocaleString() } } },
                    plugins: { 
                        legend: { position: 'top', onClick: legendClickHandler },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) { label += ': '; }
                                    label += '₱' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }

        // --- Category Sorter Logic for Charts ---
        const fullDataSets = {
            salesSummaryChart: salesSummaryData,
            wasteSummaryChart: wasteSummaryData,
            inventoryTurnoverChart: inventoryTurnoverData,
            profitabilityChart: profitabilityData
        };

        document.querySelectorAll('.chart-sorter').forEach(sorter => {
            sorter.addEventListener('change', function() {
                const chartId = this.dataset.chartId;
                const selectedCategory = this.value;
                const chartInstance = chartInstances[chartId];
                const fullData = fullDataSets[chartId];

                if (!chartInstance || !fullData) return;

                const filteredData = selectedCategory ? fullData.filter(item => item.category === selectedCategory) : fullData;

                // Update chart with filtered data
                chartInstance.data.labels = filteredData.map(item => item.name);

                // This part needs to be specific to each chart's dataset structure
                if (chartId === 'salesSummaryChart') {
                    chartInstance.data.datasets[0].data = filteredData.map(item => item.total_sales);
                } else if (chartId === 'wasteSummaryChart') {
                    chartInstance.data.datasets[0].data = filteredData.map(item => item.total_waste_cost);
                } else if (chartId === 'inventoryTurnoverChart') {
                    chartInstance.data.datasets[0].data = filteredData.map(item => item.total_sold);
                    chartInstance.data.datasets[1].data = filteredData.map(item => item.total_wasted);
                    chartInstance.data.units = filteredData.map(item => item.unit); // Update units
                } else if (chartId === 'profitabilityChart') {
                    chartInstance.data.datasets[0].data = filteredData.map(item => item.net_profit);
                    chartInstance.data.datasets[1].data = filteredData.map(item => item.total_cogs);
                }
                chartInstance.update();
            });
        });
    } else {
        // --- Individual Report Chart Logic ---
        const ctx = document.getElementById('reportChart').getContext('2d');
        const reportData = <?= json_encode($report_data ?? []) ?>;
        let reportChartInstance = null;
        
        if (reportData.length === 0) return;

        if (reportType === 'sales_summary') {
            reportChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: reportData.map(item => item.name),
                    datasets: [{ label: 'Total Sales (₱)', data: reportData.map(item => item.total_sales), backgroundColor: 'rgba(74, 108, 111, 0.6)' }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => `Total Sales: ₱${ctx.parsed.y.toLocaleString()}` } } }, scales: { y: { beginAtZero: true, ticks: { callback: value => '₱' + value.toLocaleString() } } } }
            });
        } else if (reportType === 'waste_summary') {
            // Aggregate waste data by reason for the pie chart
            const wasteByReason = reportData.reduce((acc, item) => {
                acc[item.reason] = (acc[item.reason] || 0) + parseFloat(item.total_waste_cost);
                return acc;
            }, {});
            reportChartInstance = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: Object.keys(wasteByReason),
                    datasets: [{
                        label: 'Waste Cost by Reason',
                        data: Object.values(wasteByReason),
                        backgroundColor: ['rgba(255, 99, 132, 0.7)', 'rgba(54, 162, 235, 0.7)', 'rgba(255, 206, 86, 0.7)', 'rgba(75, 192, 192, 0.7)', 'rgba(153, 102, 255, 0.7)']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: ctx => ctx.label + ': ₱' + ctx.parsed.toLocaleString() } } } }
            });
        } else if (reportType === 'inventory_turnover') {
            reportChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: reportData.map(item => item.name),
                    datasets: [
                        { label: 'Total Sold', data: reportData.map(item => item.total_sold), backgroundColor: 'rgba(75, 192, 192, 0.6)' },
                        { label: 'Total Wasted', data: reportData.map(item => item.total_wasted), backgroundColor: 'rgba(255, 99, 132, 0.6)' }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }, plugins: { legend: { position: 'top' } } }
            });
        } else if (reportType === 'profitability') {
            reportChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: reportData.map(item => item.name),
                    datasets: [
                        { label: 'Net Profit', data: reportData.map(item => item.net_profit), backgroundColor: 'rgba(75, 192, 192, 0.6)' },
                        { label: 'Cost of Goods Sold', data: reportData.map(item => item.total_cogs), backgroundColor: 'rgba(255, 159, 64, 0.6)' }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true, ticks: { callback: value => '₱' + value.toLocaleString() } }
                    },
                    plugins: { 
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) { label += ': '; }
                                    label += '₱' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }

        // --- Function to update the detailed report table ---
        function updateReportTable(filteredData) {
            const tableBody = document.getElementById('reportTableBody');
            if (!tableBody) return;

            tableBody.innerHTML = ''; // Clear existing rows

            if (filteredData.length === 0) {
                const colCount = tableBody.closest('table').querySelector('thead tr').childElementCount;
                tableBody.innerHTML = `<tr><td colspan="${colCount}" style="text-align:center;">No data available for this category.</td></tr>`;
                return;
            }

            filteredData.forEach(row => {
                let rowHtml = '<tr>';
                if (reportType === 'sales_summary') {
                    rowHtml += `<td>${escapeHtml(row.name)}</td>`;
                    rowHtml += `<td>${escapeHtml(row.total_quantity)}</td>`;
                    rowHtml += `<td>₱${Number(row.total_sales).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>`;
                } else if (reportType === 'waste_summary') {
                    rowHtml += `<td>${escapeHtml(row.name)}</td>`;
                    rowHtml += `<td>${escapeHtml(row.reason)}</td>`;
                    rowHtml += `<td>${escapeHtml(row.total_quantity)}</td>`;
                    rowHtml += `<td>₱${Number(row.total_waste_cost).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>`;
                } else if (reportType === 'inventory_turnover') {
                    rowHtml += `<td>${escapeHtml(row.name)}</td>`;
                    rowHtml += `<td>${escapeHtml(row.total_sold)}</td>`;
                    rowHtml += `<td>${escapeHtml(row.total_wasted)}</td>`;
                    rowHtml += `<td>${escapeHtml(row.current_stock)}</td>`;
                } else if (reportType === 'profitability') {
                    rowHtml += `<td>${escapeHtml(row.name)}</td>`;
                    rowHtml += `<td>₱${Number(row.total_revenue).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>`;
                    rowHtml += `<td>₱${Number(row.total_cogs).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>`;
                    rowHtml += `<td>₱${Number(row.net_profit).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>`;
                }
                rowHtml += '</tr>';
                tableBody.innerHTML += rowHtml;
            });
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }


        // --- Category Sorter for Detailed Report Chart ---
        document.getElementById('detailedReportSorter').addEventListener('change', function() {
            const selectedCategory = this.value;
            if (!reportChartInstance) return;

            const filteredData = selectedCategory ? reportData.filter(item => item.category === selectedCategory) : reportData;

            // Update the data table as well
            updateReportTable(filteredData);

            // Update chart with filtered data - logic must be specific to report type
            reportChartInstance.data.labels = filteredData.map(item => item.name);

            if (reportType === 'sales_summary') {
                reportChartInstance.data.datasets[0].data = filteredData.map(item => item.total_sales);
            } else if (reportType === 'waste_summary') {
                // Re-aggregate data for the pie chart
                const wasteByReason = filteredData.reduce((acc, item) => {
                    acc[item.reason] = (acc[item.reason] || 0) + parseFloat(item.total_waste_cost);
                    return acc;
                }, {});
                reportChartInstance.data.labels = Object.keys(wasteByReason);
                reportChartInstance.data.datasets[0].data = Object.values(wasteByReason);
            } else if (reportType === 'inventory_turnover') {
                reportChartInstance.data.datasets[0].data = filteredData.map(item => item.total_sold);
                reportChartInstance.data.datasets[1].data = filteredData.map(item => item.total_wasted);
            } else if (reportType === 'profitability') {
                reportChartInstance.data.datasets[0].data = filteredData.map(item => item.net_profit);
                reportChartInstance.data.datasets[1].data = filteredData.map(item => item.total_cogs);
            }

            reportChartInstance.update();
        });
    }

    // --- Chart Modal Logic ---
    const chartModal = document.getElementById('chartModal');
    const chartModalTitle = document.getElementById('chartModalTitle');
    const modalChartCanvas = document.getElementById('modalChartCanvas').getContext('2d');
    const closeChartModalBtn = document.getElementById('closeChartModal');
    let modalChartInstance = null;
    closeChartModalBtn.addEventListener('click', () => chartModal.style.display = 'none');

    function openChartInModal(chartInstance, title) {
        if (!chartInstance) return;

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
        canvas.addEventListener('click', () => {
            const chartId = canvas.id;
            const title = canvas.closest('.chart-box').querySelector('h3').textContent;
            openChartInModal(chartInstances[chartId], title);
        });
    });

    // --- Export to CSV Logic ---
    const exportBtn = document.getElementById('exportCsvBtn');
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            const confirmModal = document.getElementById('confirmModal');
            const confirmMessage = document.getElementById('confirmMessage');
            const confirmYesBtn = document.getElementById('confirmYesBtn');
            const confirmCancelBtn = document.getElementById('confirmCancelBtn');

            // Prepare the modal for loading state
            confirmMessage.innerHTML = 'Preparing your report for download...<div class="loader-spinner"></div>';
            confirmYesBtn.style.display = 'none';
            confirmCancelBtn.style.display = 'none';
            confirmModal.style.display = 'block';

            // Get current URL parameters
            const currentParams = new URLSearchParams(window.location.search);
            currentParams.set('export', 'csv'); // Add export parameter
            const exportUrl = 'reports_and_analytics.php?' + currentParams.toString();

            // Trigger the download after a short delay to allow the modal to show
            setTimeout(() => {
                window.location.href = exportUrl;
                // Hide the modal after a few seconds
                setTimeout(() => { confirmModal.style.display = 'none'; }, 3000);
            }, 500);
        });
    }

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
    window.addEventListener('click', (e) => { if (e.target == confirmModal) confirmModal.style.display = 'none'; });
    window.addEventListener('click', (e) => { if (e.target == chartModal) chartModal.style.display = 'none'; });
});
</script>
</body>
</html>