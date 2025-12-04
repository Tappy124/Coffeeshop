<?php
session_start();

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- Authentication Check ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Staff') {
    header("Location: login.php");
    exit;
}
include "includes/db.php";

// Set the default timezone to Philippine time
date_default_timezone_set('Asia/Manila');


// --- Filtering & Sorting Logic ---
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$data_type = trim($_GET['data_type'] ?? 'all'); // 'all', 'sales', 'waste'
$sort_by = trim($_GET['sort_by'] ?? 'date');
$sort_order = trim($_GET['sort_order'] ?? 'DESC');

// Validate that the end date is not before the start date
if (!empty($start_date) && !empty($end_date) && $end_date < $start_date) {
    // To prevent queries from running with invalid dates, we'll just show no data.
    // The JS validation will provide the user-facing error.
    $data_type = 'invalid_date'; 
}

// --- Whitelisting for Security ---
$sort_columns_whitelist = ['date', 'product', 'quantity', 'staff', 'type'];
$sort_order_whitelist = ['ASC', 'DESC'];
if (!in_array($sort_by, $sort_columns_whitelist)) $sort_by = 'date';
if (!in_array(strtoupper($sort_order), $sort_order_whitelist)) $sort_order = 'DESC';

// --- Fetch Data ---
$combined_data = [];
$sort_col_map = [
    'date' => 'record_date',
    'product' => 'product_name',
    'quantity' => 'quantity',
    'staff' => 'staff_name',
    'type' => 'record_type'
];
$order_by_col = $sort_col_map[$sort_by] ?? 'record_date';

$sql_sales = "
    SELECT 
        'Sale' as record_type, s.id,
        s.total_amount as record_value,
        mp.product_name, s.size, s.quantity, s.total_amount as detail_value, NULL as detail_text, 
        s.sale_date as record_date, st.name as staff_name
    FROM sales s 
    LEFT JOIN menu_product mp ON s.product_id = mp.product_id 
    LEFT JOIN staff st ON s.staff_id = st.id
";

$sql_waste = "
    SELECT 
        'Waste' as record_type, w.id,
        w.waste_cost as record_value,
        COALESCE(mp.product_name, p.name) as product_name, 
        w.size, w.quantity, NULL as detail_value, w.reason as detail_text, 
        w.waste_date as record_date, st.name as staff_name
    FROM waste w 
    LEFT JOIN products p ON w.product_id = p.id AND w.product_id NOT LIKE 'DR%'
    LEFT JOIN menu_product mp ON w.product_id = mp.product_id AND w.product_id LIKE 'DR%'
    LEFT JOIN staff st ON w.staff_id = st.id
";

$sql_combined = "";
if ($data_type === 'all') {
    $sql_combined = "($sql_sales) UNION ALL ($sql_waste)";
} elseif ($data_type === 'sales') {
    $sql_combined = "($sql_sales)";
} elseif ($data_type === 'waste') {
    $sql_combined = "($sql_waste)";
}

$params = [];
$types = '';
$where_clauses = [];

if (!empty($start_date)) {
    $where_clauses[] = "record_date >= ?";
    $params[] = $start_date . " 00:00:00";
    $types .= 's';
}
if (!empty($end_date)) {
    $where_clauses[] = "record_date <= ?";
    $params[] = $end_date . " 23:59:59";
    $types .= 's';
}

$final_sql = "SELECT * FROM ({$sql_combined}) AS combined_data";
if (!empty($where_clauses)) {
    $final_sql .= " WHERE " . implode(' AND ', $where_clauses);
}
$final_sql .= " ORDER BY $order_by_col $sort_order";

$stmt = $conn->prepare($final_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$combined_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Calculate totals for the chart from the fetched data ---
$total_sales_value = 0;
$total_waste_cost = 0;
foreach ($combined_data as $record) {
    if ($record['record_type'] === 'Sale') {
        $total_sales_value += $record['record_value'] ?? 0;
    } else {
        $total_waste_cost += $record['record_value'] ?? 0;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View History</title>
    <link rel="stylesheet" href="css/modal.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Style for the date input placeholder */
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

    </style>
</head>
<body>
<div class="container">
    <aside class="sidebar">
        <div class="logo"><img src="images/logo.png" alt="Logo"></div>
        <h2>Staff Dashboard</h2>
        <ul>
            <li><a href="staff_dashboard.php">Dashboard</a></li>
            <li><a href="staff_log_sales.php">Log Sale</a></li>
            <li><a href="staff_log_waste.php">Log Waste</a></li>
            <li><a href="staff_view_history.php" class="active">View History</a></li>
            <li><a href="user_account.php">My Account</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </aside>

    <main class="main">
        <header>
            <h1>View History</h1>
            <div class="header-actions" style="display:flex; gap:10px; align-items:center;">
            </div>
        </header>
        <p style="font-size: 0.9rem; color: var(--subtext); margin-bottom: 20px; line-height: 1.5;">
            Review all previously logged sales and waste records. This is a read-only feature to monitor activities, verify entries, and enhance transparency.
        </p>

        

        <div class="filter-bar">
            <form method="GET" action="staff_view_history.php" class="filter-form">
                <label for="start_date">From:</label>
                <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>" placeholder="From Date">
                <label for="end_date">To:</label>
                <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>" placeholder="To Date">
                <select name="data_type">
                    <option value="all" <?= $data_type == 'all' ? 'selected' : '' ?>>All Records</option>
                    <option value="sales" <?= $data_type == 'sales' ? 'selected' : '' ?>>Sales Only</option>
                    <option value="waste" <?= $data_type == 'waste' ? 'selected' : '' ?>>Waste Only</option>
                </select>
                <select name="sort_by">
                    <option value="date" <?= $sort_by == 'date' ? 'selected' : '' ?>>Sort by Date</option>
                    <option value="product" <?= $sort_by == 'product' ? 'selected' : '' ?>>Sort by Product</option>
                    <option value="type" <?= $sort_by == 'type' ? 'selected' : '' ?>>Sort by Type</option>
                    <option value="staff" <?= $sort_by == 'staff' ? 'selected' : '' ?>>Sort by Staff</option>
                </select>
                <select name="sort_order">
                    <option value="DESC" <?= $sort_order == 'DESC' ? 'selected' : '' ?>>Descending</option>
                    <option value="ASC" <?= $sort_order == 'ASC' ? 'selected' : '' ?>>Ascending</option>
                </select>
                <button type="submit" class="btn">Filter</button>
                <a href="staff_view_history.php" class="btn cancel-btn">Clear</a>
            </form>
        </div>

        <section class="box">
            <h2>All Transaction Records</h2>
            <div class="table-container" style="max-height: 350px; margin-top: 20px;">
                <table>
                <thead><tr><th>Type</th><th>Product</th><th>Size</th><th>Qty</th><th>Amount</th><th>Reason</th><th>Staff</th><th>Date</th></tr></thead>
                <tbody>
                <?php if (!empty($combined_data)): foreach($combined_data as $record): ?>
                    <tr>
                        <td>
                            <span class="status-badge status-<?= strtolower(htmlspecialchars($record['record_type'])) ?>">
                                <?= htmlspecialchars($record['record_type']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($record['product_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($record['size'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($record['quantity']) ?></td>
                        <td>
                            <?php if ($record['record_type'] === 'Sale'): ?>
                                ₱<?= number_format($record['detail_value'], 2) ?>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($record['record_type'] === 'Waste'): ?>
                                <?= htmlspecialchars($record['detail_text'] ?? 'N/A') ?>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($record['staff_name'] ?? 'N/A') ?></td>
                        <td><?= date('M d, Y h:i A', strtotime($record['record_date'])) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8" style="text-align:center;">No records found for the selected criteria.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </section>
        <section class="box" style="margin-top: 24px;">
            <h3>Sales vs. Waste Transactions</h3>
            <div class="chart-container" style="height: 300px; margin-top: 15px; cursor: pointer;">
                <canvas id="salesWasteCountChart"></canvas>
            </div>
        </section>
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


<!-- Confirmation Modal for Logout -->
<div class="modal" id="confirmModal">
    <div class="modal-content">
        <h2 id="confirmTitle">Please Confirm</h2>
        <p id="confirmMessage" style="text-align: center; margin: 20px 0;"></p>
        <div class="form-actions">
            <button type="button" class="confirm-btn-yes" id="confirmYesBtn">Confirm</button>
            <button type="button" class="cancel-btn" id="confirmCancelBtn">Cancel</button>
        </div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const confirmModal = document.getElementById('confirmModal'); 
    let salesWasteChart = null; // To hold the chart instance

    function closeModal(modal) { if (modal) modal.style.display = 'none'; }

    // --- Filter Form Date Validation ---
    const filterForm = document.querySelector('.filter-form');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            if (startDate && endDate && endDate < startDate) {
                e.preventDefault(); // Stop the form from submitting

                // Use the generic confirmation modal to show the error
                const confirmMessage = document.getElementById('confirmMessage');
                const confirmYesBtn = document.getElementById('confirmYesBtn');
                const confirmCancelBtn = document.getElementById('confirmCancelBtn');

                confirmMessage.textContent = "Error: The 'To' date cannot be earlier than the 'From' date. Please select a valid date range.";
                confirmYesBtn.style.display = 'none'; // Hide the confirm button
                confirmCancelBtn.textContent = 'OK'; // Change cancel button to 'OK'

                confirmModal.style.display = 'block';
                // Reset the button behavior when the modal is closed
                confirmCancelBtn.onclick = () => { closeModal(confirmModal); confirmYesBtn.style.display = 'inline-block'; confirmCancelBtn.textContent = 'Cancel'; };
            }
        });
    }

    document.getElementById('confirmCancelBtn').addEventListener('click', () => closeModal(confirmModal));
    window.addEventListener('click', (e) => { if (e.target === confirmModal) closeModal(confirmModal); });

    // --- Toast Message Logic ---
    const toast = document.getElementById("toast");
    const msg = localStorage.getItem("historyMessage");
    const errMsg = localStorage.getItem("historyError"); // For future use
    if (msg) {
        toast.textContent = msg;
        toast.classList.add("show");
        setTimeout(() => toast.classList.remove("show"), 3000);
        localStorage.removeItem("historyMessage");
    }
    if (errMsg) {
        toast.textContent = errMsg;
        toast.classList.add("show", "error");
        setTimeout(() => toast.classList.remove("show", "error"), 5000);
        localStorage.removeItem("historyError");
    }

    // --- Chart for Combined View ---
    const combinedData = <?= json_encode($combined_data) ?>;
    const totalSalesValue = <?= $total_sales_value ?>;
    const totalWasteCost = <?= $total_waste_cost ?>;

    if (totalSalesValue > 0 || totalWasteCost > 0) {
        const ctx = document.getElementById('salesWasteCountChart');
        salesWasteChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Total Sales Value', 'Total Waste Cost'],
                datasets: [{
                    label: 'Amount (₱)',
                    data: [totalSalesValue, totalWasteCost],
                    backgroundColor: ['rgba(75, 192, 192, 0.7)', 'rgba(255, 99, 132, 0.7)'],
                    borderColor: ['rgba(75, 192, 192, 1)', 'rgba(255, 99, 132, 1)'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        onClick: (e, legendItem, legend) => {
                            const chart = legend.chart; 
                            const meta = chart.getDatasetMeta(0);
                            const clickedIndex = legendItem.index; 
    
                            // Correctly check if the clicked item is the only one visible
                            const visibleItems = meta.data.filter(item => !item.hidden).length;
                            const isClickedVisible = !meta.data[clickedIndex].hidden;
                            const isOnlyVisible = visibleItems === 1 && isClickedVisible;
    
                            if (isOnlyVisible) { 
                                // If it's the only one visible, clicking it again should show all segments 
                                meta.data.forEach(item => item.hidden = false);
                            } else { 
                                // Otherwise, hide all other segments and show only the clicked one 
                                meta.data.forEach((item, i) => item.hidden = (i !== clickedIndex));
                            }
                            chart.update();
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.label}: ₱${context.parsed.toFixed(2)}`
                        }
                    } 
                }
            }
        });
        ctx.addEventListener('click', () => openChartInModal(salesWasteChart, "Sales vs. Waste Transactions"));
    }

    // --- Chart Modal Logic ---
    const chartModal = document.getElementById('chartModal');
    const chartModalTitle = document.getElementById('chartModalTitle');
    const modalChartCanvas = document.getElementById('modalChartCanvas').getContext('2d');
    const closeChartModalBtn = document.getElementById('closeChartModal');
    let modalChartInstance = null;

    function openChartInModal(chartInstance, title) {
        if (!chartInstance) return;
        if (modalChartInstance) modalChartInstance.destroy();

        chartModalTitle.textContent = title;
        modalChartInstance = new Chart(modalChartCanvas, {
            type: chartInstance.config.type,
            data: chartInstance.config.data,
            options: {
                ...chartInstance.config.options,
                plugins: {
                    ...chartInstance.config.options.plugins,
                    legend: {
                        ...chartInstance.config.options.plugins.legend,
                        onClick: chartInstance.config.options.plugins.legend.onClick
                    }
                }
            }
        });
        chartModal.style.display = 'block';
    }

    closeChartModalBtn.addEventListener('click', () => closeModal(chartModal));
    window.addEventListener('click', (e) => { 
        if (e.target == chartModal) closeModal(chartModal);
    });


    // --- Logout Confirmation ---
    const logoutLink = document.querySelector('a[href="logout.php"]');
    logoutLink.addEventListener('click', function(e) {
        e.preventDefault();
        document.getElementById('confirmMessage').textContent = 'Are you sure you want to log out?';
        document.getElementById('confirmYesBtn').textContent = 'Yes, Logout';
        confirmModal.style.display = 'block';
        document.getElementById('confirmYesBtn').onclick = function() {
            window.location.href = 'logout.php';
        };
    });
});
</script>
<style>
.status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
}
.status-sale {
    background-color: #e0f2f1; /* light green */
    color: #00796b; /* dark green */
}
.status-waste {
    background-color: #ffebee; /* light red */
    color: #c62828; /* dark red */
}
</style>
</body>
</html>