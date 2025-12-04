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

// --- Fetch available categories for dropdowns ---
$menu_categories_result = $conn->query("SELECT DISTINCT category FROM menu_product WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$menu_categories = $menu_categories_result->fetch_all(MYSQLI_ASSOC);

$inventory_categories_result = $conn->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$inventory_categories = $inventory_categories_result->fetch_all(MYSQLI_ASSOC);


// --- Helper function for forecasting ---
function generate_forecast(mysqli $conn, string $type, ?string $category, int $forecast_days, int $historical_days = 30) {
    $data = [
        'historical' => [],
        'forecast' => [],
        'summary' => "Please select a category and generate a forecast.",
        'warning' => null
    ];

    $table = '';
    $join_on = '';
    $date_col = '';

    if ($type === 'sales') {
        $table = 'sales';
        $join_on = 'JOIN menu_product mp ON s.product_id = mp.product_id';
        $date_col = 's.sale_date';
        $category_col = 'mp.category';
    } elseif ($type === 'inventory') {
        $table = 'sales'; // Consumption is based on sales
        $join_on = 'JOIN product_recipes pr ON s.product_id = pr.drink_product_id AND s.size = pr.size JOIN products p ON pr.ingredient_product_id = p.id';
        $date_col = 's.sale_date';
        $category_col = 'p.category';
    } else {
        return $data; // Invalid type
    }

    $sql = "SELECT DATE($date_col) as record_day, SUM(s.quantity) as total_quantity
            FROM $table s $join_on
            WHERE $date_col >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    
    $params = [$historical_days];
    $types = 'i';

    if (!empty($category)) {
        $sql .= " AND $category_col = ?";
        $params[] = $category;
        $types .= 's';
    }

    $sql .= " GROUP BY record_day ORDER BY record_day ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $historical_data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($historical_data) > 0) {
        $total_quantity_sold = array_sum(array_column($historical_data, 'total_quantity'));
        $average_daily_consumption = $total_quantity_sold / $historical_days;

        $total_forecasted_demand = round($average_daily_consumption * $forecast_days);
        $category_name = htmlspecialchars($category ?: 'All Categories');
        $data['summary'] = "Forecasted demand for <strong>{$category_name}</strong> over the next {$forecast_days} days is approximately <strong>{$total_forecasted_demand} units</strong>.";

        // Add a warning for low data
        if (count($historical_data) < 5) { // If data exists for fewer than 5 days
            $data['warning'] = "<strong>Note:</strong> The historical data is limited, which may affect the forecast's accuracy.";
        }

        // Generate data points for the chart
        $last_historical_date = end($historical_data)['record_day'];
        for ($i = 1; $i <= $forecast_days; $i++) {
            $future_date = date('Y-m-d', strtotime("{$last_historical_date} +{$i} days"));
            $data['forecast'][] = ['x' => $future_date, 'y' => round($average_daily_consumption, 2)];
        }
        foreach ($historical_data as $row) {
            $data['historical'][] = ['x' => $row['record_day'], 'y' => $row['total_quantity']];
        }
    } else {
        $category_name = htmlspecialchars($category ?: 'All Categories');
        $data['summary'] = "Not enough historical data for '{$category_name}' to generate a forecast.";
    }

    return $data;
}

// --- Forecasting Logic ---
$selected_sales_category = trim($_GET['sales_category'] ?? '');
$selected_inventory_category = trim($_GET['inventory_category'] ?? '');
$forecast_period_days = (int)($_GET['forecast_period'] ?? 30);

// Generate forecasts for both types. If no category is selected, it will forecast for all.
$sales_forecast_result = generate_forecast($conn, 'sales', $selected_sales_category, $forecast_period_days);
$inventory_forecast_result = generate_forecast($conn, 'inventory', $selected_inventory_category, $forecast_period_days);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Demand Forecasting</title>
    <link rel="stylesheet" href="css/modal.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <style>
        .forecast-warning {
            background-color: #fffde7;
            color: #f57f17;
            padding: 10px 15px;
            border-radius: 6px;
            margin-top: 10px;
            border: 1px solid #fff9c4;
        }
        body.dark-mode .forecast-warning {
            background-color: #42341a;
            color: #ffe082;
            border-color: #5a4822;
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
            <li><a href="reports_and_analytics.php">Reports & Analytics</a></li>
            <li><a href="admin_forecasting.php" class="active">Forecasting</a></li>
            <li><a href="system_management.php">System Management</a></li>
            <li><a href="user_account.php">My Account</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </aside>

    <main class="main">
        <header>
            <div class="top-row">
                <h1>Demand Forecasting</h1>
                <div class="header-actions" style="display:flex; gap:10px; align-items:center;">
                </div>
            </div>
        </header>

        <div class="filter-bar">
            <form method="GET" action="admin_forecasting.php" class="filter-form">
                <div>
                    <label for="forecast_period">Forecast Period:</label>
                    <select name="forecast_period" id="forecast_period">
                        <option value="7" <?= $forecast_period_days == 7 ? 'selected' : '' ?>>Next 7 Days</option>
                        <option value="30" <?= $forecast_period_days == 30 ? 'selected' : '' ?>>Next 30 Days</option>
                        <option value="90" <?= $forecast_period_days == 90 ? 'selected' : '' ?>>Next 90 Days</option>
                    </select>
                </div>
                <button type="submit" class="btn">Generate Forecast</button>
                <a href="admin_forecasting.php" class="btn cancel-btn">Clear</a>
            </form>
        </div>

        <div class="content-wrapper" style="flex-grow: 1; overflow-y: auto; padding: 2px;">
            <section class="box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2>Sales Forecast (Menu Items)</h2>
                    <form method="GET" action="admin_forecasting.php" class="filter-form" style="gap: 8px;">
                        <input type="hidden" name="forecast_period" value="<?= $forecast_period_days ?>">
                        <input type="hidden" name="inventory_category" value="<?= htmlspecialchars($selected_inventory_category) ?>">
                        <select name="sales_category" onchange="this.form.submit()">
                            <option value="">All Menu Categories</option>
                            <?php foreach ($menu_categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['category']) ?>" <?= $selected_sales_category == $cat['category'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <p><?= $sales_forecast_result['summary'] ?></p>
                <?php if ($sales_forecast_result['warning']): ?>
                    <div class="forecast-warning"><?= $sales_forecast_result['warning'] ?></div>
                <?php endif; ?>
                <div class="chart-container" style="margin-top: 20px; height: 350px; cursor: pointer;">
                    <canvas id="salesForecastChart"></canvas>
                </div>
            </section>

            <section class="box" style="margin-top: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2>Inventory Consumption Forecast</h2>
                     <form method="GET" action="admin_forecasting.php" class="filter-form" style="gap: 8px;">
                        <input type="hidden" name="forecast_period" value="<?= $forecast_period_days ?>">
                        <input type="hidden" name="sales_category" value="<?= htmlspecialchars($selected_sales_category) ?>">
                        <select name="inventory_category" onchange="this.form.submit()">
                            <option value="">All Inventory Categories</option>
                            <?php foreach ($inventory_categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['category']) ?>" <?= $selected_inventory_category == $cat['category'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <p><?= $inventory_forecast_result['summary'] ?></p>
                <?php if ($inventory_forecast_result['warning']): ?>
                    <div class="forecast-warning"><?= $inventory_forecast_result['warning'] ?></div>
                <?php endif; ?>
                <div class="chart-container" style="margin-top: 20px; height: 350px; cursor: pointer;">
                    <canvas id="inventoryForecastChart"></canvas>
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
    const salesForecastResult = <?= json_encode($sales_forecast_result) ?>;
    const inventoryForecastResult = <?= json_encode($inventory_forecast_result) ?>;

    // Store chart instances to make them accessible for modal creation
    const chartInstances = {};

    function createForecastChart(canvasId, historicalData, forecastData, historicalLabel, forecastLabel) {
        const ctx = document.getElementById(canvasId).getContext('2d');
        if (!ctx) return;

        if (historicalData.length === 0 && forecastData.length === 0) {
            // Optional: Display a message on the canvas if there's no data
            ctx.font = "16px 'Segoe UI'";
            ctx.fillStyle = getComputedStyle(document.body).getPropertyValue('--subtext').trim();
            ctx.textAlign = "center";
            ctx.fillText("No data to display for the selected criteria.", ctx.canvas.width / 2, ctx.canvas.height / 2);
            return null; // Return null if no chart is created
        }

        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                datasets: [{
                    label: historicalLabel,
                    data: historicalData,
                    borderColor: 'rgba(74, 108, 111, 1)',
                    backgroundColor: 'rgba(74, 108, 111, 0.2)',
                    fill: true,
                    tension: 0.4 // This creates the "wavy" effect
                }, {
                    label: forecastLabel,
                    data: forecastData,
                    borderColor: 'rgba(255, 99, 132, 1)',
                    // borderDash: [5, 5], // Removed for a solid line
                    backgroundColor: 'rgba(255, 99, 132, 0.1)',
                    fill: true, // Fill the area below the forecast line
                    tension: 0.4 // This creates the "wavy" effect
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: { type: 'time', time: { unit: 'day' }, title: { display: true, text: 'Date' } },
                    y: { beginAtZero: true, title: { display: true, text: 'Units' } }
                },
                plugins: { 
                    legend: { 
                        position: 'top',
                        onClick: (e, legendItem, legend) => {
                            const chart = legend.chart;
                            const index = legendItem.datasetIndex;

                            // Check if the clicked item is the only one visible
                            const isOnlyVisible = legend.legendItems.filter(li => !li.hidden).length === 1 && !legendItem.hidden;

                            if (isOnlyVisible) {
                                // If it's the only one visible, clicking it again should show all datasets
                                legend.legendItems.forEach((item, i) => chart.getDatasetMeta(i).hidden = null);
                            } else {
                                // Otherwise, hide all other datasets and show only the clicked one
                                legend.legendItems.forEach((item, i) => chart.getDatasetMeta(i).hidden = (i !== index));
                            }
                            chart.update();
                        }
                    } 
                }
            }
        });
        chartInstances[canvasId] = chart; // Store the chart instance
        return chart;
    }

    // Create the two charts
    const salesChart = createForecastChart('salesForecastChart', salesForecastResult.historical, salesForecastResult.forecast, 'Historical Sales', 'Forecasted Sales');
    const inventoryChart = createForecastChart('inventoryForecastChart', inventoryForecastResult.historical, inventoryForecastResult.forecast, 'Historical Consumption', 'Forecasted Consumption');

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
        canvas.addEventListener('click', () => {
            const chartId = canvas.id;
            // Get the title from the h2 element within the same section box
            const title = canvas.closest('.box').querySelector('h2').textContent;
            openChartInModal(chartInstances[chartId], title);
        });
    });

    closeChartModalBtn.addEventListener('click', () => chartModal.style.display = 'none');
    window.addEventListener('click', (e) => { if (e.target == chartModal) chartModal.style.display = 'none'; });

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
});
</script>
</body>
</html>