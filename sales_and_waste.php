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

/**
 * Calculates the total content in a base unit (G or ML).
 * This is a helper function needed for the delivery logic.
 */
function calculateTotalContentStock(int $stock, ?string $content): float {
    if ($stock <= 0 || empty($content)) return 0.0;
    preg_match('/^(\d*\.?\d+)\s*(.*)$/i', $content, $matches);
    $value = (float)($matches[1] ?? 0);
    $unit = strtoupper(trim($matches[2] ?? ''));
    if ($value <= 0) return 0.0;
    $total = $stock * $value;
    if ($unit === 'KG' || $unit === 'L') return $total * 1000;
    return $total;
}

/**
 * Helper function to get recipe for a drink.
 * @return array The recipe ingredients.
 */
function getRecipe(mysqli $conn, string $product_id, string $size): array {
    $stmt = $conn->prepare("SELECT ingredient_product_id, amount_used FROM product_recipes WHERE drink_product_id = ? AND size = ?");
    $stmt->bind_param("ss", $product_id, $size);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

/**
 * Helper function to adjust stock.
 * @param string $direction 'add' or 'subtract'.
 */
function adjustStock(mysqli $conn, string $product_id, string $size, int $quantity, string $direction) {
    $recipe = getRecipe($conn, $product_id, $size);
    if (empty($recipe)) return; // No recipe, no stock change
    
    $operator = ($direction === 'add') ? '+' : '-';
    
    foreach ($recipe as $ingredient) {
        $ingredient_id = $ingredient['ingredient_product_id']; 
        $amount_change = (float)$ingredient['amount_used'] * $quantity;

        // Get current product details to recalculate package stock
        $prod_stmt = $conn->prepare("SELECT total_content_stock, content FROM products WHERE id = ? FOR UPDATE");
        $prod_stmt->bind_param("s", $ingredient_id);
        $prod_stmt->execute();
        $product = $prod_stmt->get_result()->fetch_assoc();
        $prod_stmt->close();

        if ($product) { // Recalculate package stock
            $new_total_content_stock = ($direction === 'add') 
                ? ($product['total_content_stock'] ?? 0) + $amount_change 
                : ($product['total_content_stock'] ?? 0) - $amount_change;
            $content_per_package = calculateTotalContentStock(1, $product['content']);
            $new_package_stock = ($content_per_package > 0) ? floor($new_total_content_stock / $content_per_package) : 0;
            $update_stmt = $conn->prepare("UPDATE products SET total_content_stock = ?, stock = ? WHERE id = ?");
            $update_stmt->bind_param("dis", $new_total_content_stock, $new_package_stock, $ingredient_id);
            $update_stmt->execute();
            $update_stmt->close();
        }
    }
}

/**
 * Recalculates COGS for a sale and updates the breakdown table.
 */
function updateCogsForSale(mysqli $conn, int $sale_id, string $product_id, string $size, int $quantity) {
    // 1. Get the recipe and current ingredient prices
    $recipe_stmt = $conn->prepare("
        SELECT pr.ingredient_product_id, pr.amount_used, i.price as ingredient_price, i.content as package_content
        FROM product_recipes pr JOIN products i ON pr.ingredient_product_id = i.id
        WHERE pr.drink_product_id = ? AND pr.size = ?
    ");
    $recipe_stmt->bind_param("ss", $product_id, $size);
    $recipe_stmt->execute();
    $recipe = $recipe_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recipe_stmt->close();

    $total_cogs = 0;
    $cogs_breakdown_data = [];

    // 2. Calculate new COGS
    foreach ($recipe as $ingredient) {
        preg_match('/^(\d*\.?\d+)\s*(.*)$/i', $ingredient['package_content'], $matches);
        $value_per_package = (float)($matches[1] ?? 0);
        if (strtoupper(trim($matches[2] ?? '')) === 'KG' || strtoupper(trim($matches[2] ?? '')) === 'L') $value_per_package *= 1000;

        if ($value_per_package > 0) {
            $cost_per_base_unit = (float)$ingredient['ingredient_price'] / $value_per_package;
            $ingredient_cost_for_sale = (float)$ingredient['amount_used'] * $quantity * $cost_per_base_unit;
            $total_cogs += $ingredient_cost_for_sale;
            $cogs_breakdown_data[] = ['id' => $ingredient['ingredient_product_id'], 'cost' => $ingredient_cost_for_sale, 'qty' => (float)$ingredient['amount_used'] * $quantity];
        }
    }

    // 3. Update the main sales table with the new total COGS
    $conn->query("UPDATE sales SET cogs = $total_cogs WHERE id = $sale_id");
}

// --- Edit Sale ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_sale'])) {
    $sale_id = (int)$_POST['edit_sale_id'];
    $new_product_id = $_POST['edit_product_id'];
    $new_size = $_POST['edit_size'];
    $new_quantity = (int)$_POST['edit_quantity'];

    $conn->begin_transaction();
    try {
        // 1. Get original sale details
        $orig_sale_res = $conn->query("SELECT product_id, size, quantity FROM sales WHERE id = $sale_id");
        $original_sale = $orig_sale_res->fetch_assoc();

        if ($original_sale) {
            // 2. Restore stock for the original item
            adjustStock($conn, $original_sale['product_id'], $original_sale['size'], $original_sale['quantity'], 'add');

            // 3. Fetch new product's price
            $price_column_map = [
                '16oz' => 'price_small',
                '22oz' => 'price_medium',
                '1L'   => 'price_large'
            ];
            $price_col = $price_column_map[$new_size] ?? 'price';

            $price_stmt = $conn->prepare("SELECT `$price_col` as price FROM menu_product WHERE product_id = ?");
            $price_stmt->bind_param("s", $new_product_id);
            $price_stmt->execute();
            $product_price = $price_stmt->get_result()->fetch_assoc()['price'] ?? 0;
            $new_total_amount = $product_price * $new_quantity;

            // 4. Recalculate COGS and update the sales record
            updateCogsForSale($conn, $sale_id, $new_product_id, $new_size, $new_quantity);

            // 5. Update sale record
            $stmt_update = $conn->prepare("UPDATE sales SET product_id = ?, size = ?, quantity = ?, total_amount = ? WHERE id = ?"); // COGS is updated in the helper function
            $stmt_update->bind_param("ssidi", $new_product_id, $new_size, $new_quantity, $new_total_amount, $sale_id);
            $stmt_update->execute();
            $stmt_update->close();

            // 6. Deduct stock for the new/updated item
            adjustStock($conn, $new_product_id, $new_size, $new_quantity, 'subtract');
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        // Handle error if needed
    }

    echo "<script>
        localStorage.setItem('swMessage', 'Sale record updated successfully!');
        window.location.href = 'sales_and_waste.php?" . http_build_query($_GET) . "';
    </script>";
    exit;
}

// --- Delete Sale ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_sale'])) {
    $sale_id = (int)$_POST['delete_sale_id'];

    $conn->begin_transaction();
    try {
        // 1. Get sale details to restore stock
        $sale_res = $conn->query("SELECT product_id, size, quantity FROM sales WHERE id = $sale_id");
        $sale = $sale_res->fetch_assoc();

        if ($sale) {
            // 2. Restore stock
            adjustStock($conn, $sale['product_id'], $sale['size'], $sale['quantity'], 'add');

            // 3. Delete the sale record
            $conn->query("DELETE FROM sales WHERE id = $sale_id");

            // 4. Delete the associated COGS breakdown records (ON DELETE CASCADE handles this automatically now)
            // $conn->query("DELETE FROM sale_cogs_breakdown WHERE sale_id = $sale_id"); // This line is no longer needed but kept for clarity
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }

    echo "<script>
        localStorage.setItem('swMessage', 'Sale record deleted successfully!');
        window.location.href = 'sales_and_waste.php?" . http_build_query($_GET) . "';
    </script>";
    exit;
}

// --- Edit Waste ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_waste'])) {
    $waste_id = (int)$_POST['edit_waste_id'];
    $new_product_id = $_POST['edit_product_id'];
    $new_quantity = (int)$_POST['edit_quantity'];
    $new_reason = $_POST['edit_reason'];
    $new_size = $_POST['edit_size'] ?? null;

    $conn->begin_transaction();
    try {
        // 1. Get original waste details
        $orig_waste_res = $conn->query("SELECT product_id, size, quantity FROM waste WHERE id = $waste_id");
        $original_waste = $orig_waste_res->fetch_assoc();

        if ($original_waste) {
            // 2. Restore stock for the original item
            if (strpos($original_waste['product_id'], 'DR') === 0) { // Menu Item
                adjustStock($conn, $original_waste['product_id'], $original_waste['size'], $original_waste['quantity'], 'add');
            } else { // Inventory Item
                // Restore both package stock and total content stock
                $prod_res = $conn->query("SELECT content, total_content_stock FROM products WHERE id = '{$original_waste['product_id']}' FOR UPDATE");
                $prod_details = $prod_res->fetch_assoc();
                $new_total_content_stock = ($prod_details['total_content_stock'] ?? 0) + $original_waste['quantity'];
                $conn->query("UPDATE products SET total_content_stock = {$new_total_content_stock} WHERE id = '{$original_waste['product_id']}'");
            }

            // 3. Recalculate waste cost for the new item/quantity
            $new_waste_cost = 0;
            if (strpos($new_product_id, 'DR') === 0) { // Menu Item
                // Cost is based on menu price
                $price_column_map = ['16oz' => 'price_small', '22oz' => 'price_medium', '1L' => 'price_large'];
                $price_col = $price_column_map[$new_size] ?? 'price';
                $price_stmt = $conn->prepare("SELECT `$price_col` as price FROM menu_product WHERE product_id = ?");
                $price_stmt->bind_param("s", $new_product_id);
                $price_stmt->execute();
                $product_price = $price_stmt->get_result()->fetch_assoc()['price'] ?? 0;
                $price_stmt->close();
                $new_waste_cost = $product_price * $new_quantity;
            } else { // Inventory Item
                // Cost is based on ingredient cost
                $prod_stmt = $conn->prepare("SELECT content, price FROM products WHERE id = ?");
                $prod_stmt->bind_param("s", $new_product_id);
                $prod_stmt->execute();
                $product = $prod_stmt->get_result()->fetch_assoc();
                $prod_stmt->close();

                if ($product) {
                    $content_per_package = calculateTotalContentStock(1, $product['content']);
                    if ($content_per_package > 0) {
                        $cost_per_base_unit = (float)$product['price'] / $content_per_package;
                        $new_waste_cost = $new_quantity * $cost_per_base_unit;
                    }
                }
            }

            // 4. Update waste record with new details and cost
            $stmt_update = $conn->prepare("UPDATE waste SET product_id = ?, size = ?, quantity = ?, reason = ?, waste_cost = ? WHERE id = ?");
            $stmt_update->bind_param("ssisdi", $new_product_id, $new_size, $new_quantity, $new_reason, $new_waste_cost, $waste_id);
            $stmt_update->execute();
            $stmt_update->close();

            // 5. Deduct stock for the new/updated item
            if (strpos($new_product_id, 'DR') === 0) { // Menu Item
                adjustStock($conn, $new_product_id, $new_size, $new_quantity, 'subtract');
            } else { // Inventory Item
                // Deduct both package stock and total content stock
                $prod_res = $conn->query("SELECT content, total_content_stock FROM products WHERE id = '{$new_product_id}' FOR UPDATE");
                $prod_details = $prod_res->fetch_assoc();
                $new_total_content_stock = ($prod_details['total_content_stock'] ?? 0) - $new_quantity;
                $conn->query("UPDATE products SET total_content_stock = {$new_total_content_stock} WHERE id = '{$new_product_id}'");
            }
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }

    echo "<script>
        localStorage.setItem('swMessage', 'Waste record updated successfully!');
        window.location.href = 'sales_and_waste.php?" . http_build_query($_GET) . "';
    </script>";
    exit;
}

// --- Delete Waste ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_waste'])) {
    $waste_id = (int)$_POST['delete_waste_id'];
    $conn->begin_transaction();
    try {
        // 1. Get waste details to restore stock
        $waste_res = $conn->query("SELECT product_id, size, quantity FROM waste WHERE id = $waste_id FOR UPDATE");
        $waste = $waste_res->fetch_assoc();

        if ($waste) {
            if (strpos($waste['product_id'], 'DR') === 0) {
                // It's a menu item, restore ingredients using the shared function
                adjustStock($conn, $waste['product_id'], $waste['size'], $waste['quantity'], 'add');
            } else {
                // It's an inventory item, restore total content and then recalculate package stock
                $prod_res = $conn->query("SELECT content, total_content_stock FROM products WHERE id = '{$waste['product_id']}' FOR UPDATE");
                $prod_details = $prod_res->fetch_assoc();
                if ($prod_details) {
                    $new_total_content_stock = ($prod_details['total_content_stock'] ?? 0) + $waste['quantity'];
                    $content_per_package = calculateTotalContentStock(1, $prod_details['content']);
                    $new_package_stock = ($content_per_package > 0) ? floor($new_total_content_stock / $content_per_package) : 0;

                    $update_stmt = $conn->prepare("UPDATE products SET total_content_stock = ?, stock = ? WHERE id = ?");
                    $update_stmt->bind_param("dis", $new_total_content_stock, $new_package_stock, $waste['product_id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
            
            // 2. Delete the waste record
            $conn->query("DELETE FROM waste WHERE id = $waste_id");
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }

    echo "<script>
        localStorage.setItem('swMessage', 'Waste record deleted and stock restored!');
        window.location.href = 'sales_and_waste.php?" . http_build_query($_GET) . "';
    </script>";
    exit;
}

// --- Delete Waste ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_waste'])) {
    $waste_id = (int)$_POST['delete_waste_id'];
    $conn->begin_transaction();
    try {
        // 1. Get waste details to restore stock
        $waste_res = $conn->query("SELECT product_id, size, quantity FROM waste WHERE id = $waste_id FOR UPDATE");
        $waste = $waste_res->fetch_assoc();

        if ($waste) {
            if (strpos($waste['product_id'], 'DR') === 0) {
                // It's a menu item, restore ingredients using the shared function
                adjustStock($conn, $waste['product_id'], $waste['size'], $waste['quantity'], 'add');
            } else {
                // It's an inventory item, restore its total content and then recalculate package stock
                $prod_res = $conn->query("SELECT content, total_content_stock FROM products WHERE id = '{$waste['product_id']}' FOR UPDATE");
                $prod_details = $prod_res->fetch_assoc();
                if ($prod_details) {
                    $new_total_content_stock = ($prod_details['total_content_stock'] ?? 0) + $waste['quantity'];
                    $content_per_package = calculateTotalContentStock(1, $prod_details['content']);
                    $new_package_stock = ($content_per_package > 0) ? floor($new_total_content_stock / $content_per_package) : 0;

                    $update_stmt = $conn->prepare("UPDATE products SET total_content_stock = ?, stock = ? WHERE id = ?");
                    $update_stmt->bind_param("dis", $new_total_content_stock, $new_package_stock, $waste['product_id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
            
            // 2. Delete the waste record
            $conn->query("DELETE FROM waste WHERE id = $waste_id");
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }

    echo "<script>
        localStorage.setItem('swMessage', 'Waste record deleted and stock restored!');
        window.location.href = 'sales_and_waste.php?" . http_build_query($_GET) . "';
    </script>";
    exit;
}

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
$sort_columns_whitelist = ['date', 'amount', 'product', 'quantity', 'staff'];
$sort_order_whitelist = ['ASC', 'DESC'];
if (!in_array($sort_by, $sort_columns_whitelist)) $sort_by = 'date';
if (!in_array(strtoupper($sort_order), $sort_order_whitelist)) $sort_order = 'DESC';


// --- Map sort alias to actual DB columns ---
$sales_sort_col = 'sale_date';
if ($sort_by === 'amount') $sales_sort_col = 's.total_amount';
if ($sort_by === 'product') $sales_sort_col = 'mp.product_name';
if ($sort_by === 'quantity') $sales_sort_col = 's.quantity';
if ($sort_by === 'staff') $sales_sort_col = 'st.name';

$waste_sort_col = 'w.waste_date';
if ($sort_by === 'product') $waste_sort_col = 'product_name';
if ($sort_by === 'quantity') $waste_sort_col = 'w.quantity';
if ($sort_by === 'staff') $waste_sort_col = 'st.name';

// --- Fetch Sales Data ---
$sales = [];
if ($data_type === 'all' || $data_type === 'sales') {
    $sql_sales = "
        SELECT 
            s.id, s.product_id, mp.product_name, s.quantity, s.total_amount, s.sale_date, st.name as staff_name, s.size 
        FROM sales s 
        LEFT JOIN menu_product mp ON s.product_id = mp.product_id 
        LEFT JOIN staff st ON s.staff_id = st.id
    ";
    $params_sales = [];
    $types_sales = '';
    $where_sales = [];

    if (!empty($start_date)) {
        $where_sales[] = "sale_date >= ?";
        $params_sales[] = $start_date . " 00:00:00";
        $types_sales .= 's';
    }
    if (!empty($end_date)) {
        $where_sales[] = "sale_date <= ?";
        $params_sales[] = $end_date . " 23:59:59";
        $types_sales .= 's';
    }

    if (!empty($where_sales)) {
        $sql_sales .= " WHERE " . implode(' AND ', $where_sales);
    }

    $sql_sales .= " ORDER BY $sales_sort_col $sort_order";
    $stmt_sales = $conn->prepare($sql_sales);
    if (!empty($params_sales)) $stmt_sales->bind_param($types_sales, ...$params_sales);
    $stmt_sales->execute();
    $result_sales = $stmt_sales->get_result();
    $sales = $result_sales->fetch_all(MYSQLI_ASSOC);
    $stmt_sales->close();
}

// --- Fetch Waste Data ---
$waste_logs = [];
if ($data_type === 'all' || $data_type === 'waste') {
    $sql_waste = "
        SELECT 
            w.id, w.product_id, w.waste_cost,
            COALESCE(mp.product_name, p.name) as product_name, 
            w.quantity, w.reason, w.waste_date, st.name as staff_name, w.size
        FROM waste w 
        LEFT JOIN products p ON w.product_id = p.id AND w.product_id NOT LIKE 'DR%'
        LEFT JOIN menu_product mp ON w.product_id = mp.product_id AND w.product_id LIKE 'DR%'
        LEFT JOIN staff st ON w.staff_id = st.id
    ";
    $params_waste = [];
    $types_waste = '';
    $where_waste = [];

    if (!empty($start_date)) {
        $where_waste[] = "waste_date >= ?";
        $params_waste[] = $start_date . " 00:00:00";
        $types_waste .= 's';
    }
    if (!empty($end_date)) {
        $where_waste[] = "waste_date <= ?";
        $params_waste[] = $end_date . " 23:59:59";
        $types_waste .= 's';
    }

    if (!empty($where_waste)) {
        $sql_waste .= " WHERE " . implode(' AND ', $where_waste);
    }

    $sql_waste .= " ORDER BY $waste_sort_col $sort_order";
    $stmt_waste = $conn->prepare($sql_waste);
    if (!empty($params_waste)) $stmt_waste->bind_param($types_waste, ...$params_waste);
    $stmt_waste->execute();
    $result_waste = $stmt_waste->get_result();
    $waste_logs = $result_waste->fetch_all(MYSQLI_ASSOC);
    $stmt_waste->close();
}

// Fetch products for edit modals
$all_products_for_js = [];

// Fetch Inventory Products (with content to extract unit)
$inventory_result = $conn->query("SELECT id, name, content FROM products ORDER BY name ASC");
while ($row = $inventory_result->fetch_assoc()) {
    preg_match('/^(\d*\.?\d+)\s*(.*)$/i', $row['content'] ?? '', $matches);
    $unit = trim($matches[2] ?? 'PCS'); // Default to PCS if no unit is found
    $all_products_for_js[] = ['id' => $row['id'], 'name' => $row['name'], 'unit' => $unit, 'type' => 'inventory'];
}

// Fetch Menu Products (these don't have a 'unit' in the same way, but we can add a placeholder)
$menu_result = $conn->query("SELECT product_id as id, product_name as name FROM menu_product ORDER BY product_name ASC");
while ($row = $menu_result->fetch_assoc()) {
    $all_products_for_js[] = ['id' => $row['id'], 'name' => $row['name'], 'unit' => 'N/A', 'type' => 'menu']; // 'N/A' or empty string for menu items
}

usort($all_products_for_js, function($a, $b) {
    return strcmp($a['name'], $b['name']); // Sort by name alphabetically
});

// --- Fetch menu products separately for the Edit Sale modal dropdown ---
$menu_products = [];
$menu_result_for_modal = $conn->query("SELECT product_id as id, product_name as name FROM menu_product ORDER BY name ASC");
while ($row = $menu_result_for_modal->fetch_assoc()) {
    $menu_products[] = $row;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales & Waste Analytics</title>
    <link rel="stylesheet" href="css/modal.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/extracted_styles.css">
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <!-- date input styles moved to css/extracted_styles.css (.filter-date-input) -->
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
                <li><a href="sales_and_waste.php" class="active">Sales & Waste</a></li>
                <li><a href="reports_and_analytics.php">Reports & Analytics</a></li>
                <li><a href="admin_forecasting.php">Forecasting</a></li>
                <li><a href="system_management.php">System Management</a></li>
                <li><a href="user_account.php">My Account</a></li>
                <li><a href="logout.php">Logout</a></li>
        </ul>
    </aside>

    <main class="main">
        <header>
            <div class="top-row">
                <h1>Sales & Waste Analytics</h1>
                <div class="header-actions flex-gap-center">
                </div>
            </div>
        </header>

        <div class="filter-bar">
            <form method="GET" action="sales_and_waste.php" class="filter-form">
                <label for="start_date">From:</label>
                <input class="filter-date-input" type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>" placeholder="From Date">

                <label for="end_date">To:</label>
                <input class="filter-date-input" type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>" placeholder="To Date">

                <select name="data_type">
                    <option value="all" <?= $data_type == 'all' ? 'selected' : '' ?>>All Records</option>
                    <option value="sales" <?= $data_type == 'sales' ? 'selected' : '' ?>>Sales Only</option>
                    <option value="waste" <?= $data_type == 'waste' ? 'selected' : '' ?>>Waste Only</option>
                </select>

                <select name="sort_by">
                    <option value="date" <?= $sort_by == 'date' ? 'selected' : '' ?>>Sort by Date</option>
                    <option value="amount" <?= $sort_by == 'amount' ? 'selected' : '' ?>>Sort by Amount/Cost</option>
                    <option value="product" <?= $sort_by == 'product' ? 'selected' : '' ?>>Sort by Product Name</option>
                    <option value="staff" <?= $sort_by == 'staff' ? 'selected' : '' ?>>Sort by Staff</option>
                </select>

                <select name="sort_order">
                    <option value="DESC" <?= $sort_order == 'DESC' ? 'selected' : '' ?>>Descending</option>
                    <option value="ASC" <?= $sort_order == 'ASC' ? 'selected' : '' ?>>Ascending</option>
                </select>

                <button type="submit" class="btn">Filter</button>
                <a href="sales_and_waste.php" class="btn cancel-btn">Clear</a>
                <!-- Note: CSV Export can be added here by appending a parameter like &export=csv to the current URL -->
            </form>
        </div>

        <?php if ($data_type === 'all' || $data_type === 'sales'): ?>
        <section class="box">
            <h2>Sales Records</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Size</th>
                            <th>Quantity</th>
                            <th>Total Amount</th>
                            <th>Staff</th>
                            <th>Sale Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($sales)): ?>
                        <?php foreach($sales as $sale): ?>
                            <tr>
                                <td><?= htmlspecialchars($sale['id']) ?></td>
                                <td><?= htmlspecialchars($sale['product_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($sale['size'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($sale['quantity']) ?></td>
                                <td>₱<?= number_format($sale['total_amount'], 2) ?></td>
                                <td><?= htmlspecialchars($sale['staff_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($sale['sale_date']))) ?></td>
                                <td>
                                    <button class="action-btn edit-btn edit-sale-btn"
                                        data-id="<?= $sale['id'] ?>"
                                        data-product-id="<?= htmlspecialchars($sale['product_id']) ?>"
                                        data-size="<?= htmlspecialchars($sale['size'] ?? '') ?>"
                                        data-quantity="<?= htmlspecialchars($sale['quantity']) ?>">
                                        Edit
                                    </button>
                                    <form method="POST" action="sales_and_waste.php" class="delete-form inline">
                                        <input type="hidden" name="delete_sale" value="1">
                                        <input type="hidden" name="delete_sale_id" value="<?= $sale['id'] ?>">
                                        <button type="button" class="action-btn delete-btn">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8">No sales records found for the selected criteria.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($data_type === 'all' || $data_type === 'waste'): ?>
    <section class="box mt-24">
            <h2>Waste Records</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Size</th>
                            <th>Quantity Wasted</th>
                            <th>Reason</th>
                            <th>Staff</th>
                            <th>Waste Cost</th>
                            <th>Waste Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($waste_logs)): ?>
                        <?php foreach($waste_logs as $log): ?>
                            <tr>
                                <td><?= htmlspecialchars($log['id']) ?></td>
                                <td><?= htmlspecialchars($log['product_name'] ?? 'N/A') ?></td>
                                <td>
                                    <?php
                                        echo str_starts_with($log['product_id'], 'DR') ? htmlspecialchars($log['size'] ?? 'N/A') : 'N/A';
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        $unit_display = !str_starts_with($log['product_id'], 'DR') 
                                            ? htmlspecialchars($log['size'] ?? 'unit') 
                                            : 'drink';
                                        echo htmlspecialchars($log['quantity']) . " ($unit_display)";
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($log['reason'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($log['staff_name'] ?? 'N/A') ?></td>
                                <td>₱<?= number_format($log['waste_cost'] ?? 0, 2) ?></td>
                                <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($log['waste_date']))) ?></td>
                                <td>
                                    <button class="action-btn edit-btn edit-waste-btn"
                                        data-id="<?= $log['id'] ?>"
                                        data-product-id="<?= htmlspecialchars($log['product_id']) ?>"
                                        data-size="<?= htmlspecialchars($log['size'] ?? '') ?>"
                                        data-quantity="<?= htmlspecialchars($log['quantity']) ?>" 
                                        data-reason="<?= htmlspecialchars($log['reason']) ?>">
                                        Edit
                                    </button>
                                    <form method="POST" action="sales_and_waste.php" class="delete-form inline">
                                        <input type="hidden" name="delete_waste" value="1">
                                        <input type="hidden" name="delete_waste_id" value="<?= $log['id'] ?>">
                                        <button type="button" class="action-btn delete-btn">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8">No waste logs found for the selected criteria.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

    </main>
</div>

<!-- Edit Sale Modal -->
<div class="modal" id="editSaleModal">
    <div class="modal-content">
        <span class="close" id="closeEditSaleModal">&times;</span>
        <h2>Edit Sale Record</h2>
        <form method="POST" action="sales_and_waste.php?<?= http_build_query($_GET) ?>" id="editSaleForm">
            <input type="hidden" name="edit_sale" value="1">
            <input type="hidden" name="edit_sale_id" id="edit_sale_id">
            <label>Product</label>
            <select name="edit_product_id" id="edit_sale_product_id" required disabled>
                <option value="" disabled>Select a product</option>
                <?php foreach ($menu_products as $product): /* Sales are only for menu items */ ?>
                    <option value="<?= htmlspecialchars($product['id']) ?>"><?= htmlspecialchars($product['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Quantity</label>
            <input type="number" name="edit_quantity" id="edit_sale_quantity" required min="1">
            <label>Size</label>
            <select name="edit_size" id="edit_sale_size" required disabled>
                <option value="16oz">16oz</option>
                <option value="22oz">22oz</option>
                <option value="12oz">12oz</option>
                <option value="1L">1L</option>
            </select>
            <div class="form-actions">
                <button type="submit">Update Sale</button>
                <button type="button" class="cancel-btn" id="cancelEditSale">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Waste Modal -->
<div class="modal" id="editWasteModal">
    <div class="modal-content">
        <span class="close" id="closeEditWasteModal">&times;</span>
        <h2>Edit Waste Record</h2>
        <form method="POST" action="sales_and_waste.php?<?= http_build_query($_GET) ?>" id="editWasteForm">
            <input type="hidden" name="edit_waste" value="1">
            <input type="hidden" name="edit_waste_id" id="edit_waste_id">
            <label>Product</label>
            <select name="edit_product_id" id="edit_waste_product_id" required disabled>
                <option value="" disabled>Select a product</option>
                <?php foreach ($all_products_for_js as $product): ?>
                    <option value="<?= htmlspecialchars($product['id']) ?>"><?= htmlspecialchars($product['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Quantity Wasted</label>
            <div class="flex-gap-center">
                <input class="mb-12" type="number" name="edit_quantity" id="edit_waste_quantity" required min="1">
                <input class="unit-display" type="text" id="edit_waste_unit_display" readonly placeholder="Unit">
            </div>
            <label>Reason</label>
            <select name="edit_reason" id="edit_waste_reason" required>
                <option value="Expired">Expired</option>
                <option value="Damaged">Damaged</option>
                <option value="Spilled">Spilled</option>
                <option value="Error">Error</option>
                <option value="Other">Other</option>
            </select>
            <div id="edit_waste_size_wrapper" class="hidden">
                <label>Size</label>
                <select name="edit_size" id="edit_waste_size" disabled>
                    <option value="16oz">16oz</option>
                    <option value="12oz">12oz</option>
                    <option value="22oz">22oz</option>
                    <option value="1L">1L</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit">Update Waste Log</button>
                <button type="button" class="cancel-btn" id="cancelEditWaste">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirmation Modal -->
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

<!-- Toast Message -->
<div id="toast" class="toast"></div>

<script>
    // Data for all products, to be used by JavaScript
    const allProductsData = [
        <?php foreach ($all_products_for_js as $product): ?>
        { id: '<?= htmlspecialchars($product['id']) ?>', name: '<?= htmlspecialchars($product['name']) ?>', unit: '<?= htmlspecialchars($product['unit'] ?? '') ?>' },
        <?php endforeach; ?>
    ];


document.addEventListener('DOMContentLoaded', function() {
    const editSaleModal = document.getElementById('editSaleModal');
    const editWasteModal = document.getElementById('editWasteModal');
    const confirmModal = document.getElementById('confirmModal');

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

    function closeModal(modal) { if (modal) modal.style.display = 'none'; }

    // --- Edit Sale Modal Logic ---
    document.querySelectorAll('.edit-sale-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('edit_sale_id').value = btn.dataset.id;
            document.getElementById('edit_sale_product_id').value = btn.dataset.productId;
            document.getElementById('edit_sale_quantity').value = btn.dataset.quantity;
            document.getElementById('edit_sale_size').value = btn.dataset.size;
            editSaleModal.style.display = 'block';
        });
    });

    // --- Edit Waste Modal Logic ---
    const editWasteSizeWrapper = document.getElementById('edit_waste_size_wrapper');
    const editWasteProductIdSelect = document.getElementById('edit_waste_product_id');
    const editWasteUnitDisplay = document.getElementById('edit_waste_unit_display');
    document.querySelectorAll('.edit-waste-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('edit_waste_id').value = btn.dataset.id;
            const productId = btn.dataset.productId;
            document.getElementById('edit_waste_product_id').value = productId;
            document.getElementById('edit_waste_quantity').value = btn.dataset.quantity;
            document.getElementById('edit_waste_size').value = btn.dataset.size;
            document.getElementById('edit_waste_reason').value = btn.dataset.reason;
            // Show size selector only for menu items
            const isMenuItem = productId.startsWith('DR');
            editWasteSizeWrapper.style.display = isMenuItem ? 'block' : 'none';

            // Determine and set the unit placeholder
            const productData = allProductsData.find(p => p.id === productId);
            if (productData) {
                editWasteUnitDisplay.placeholder = (productData.unit === 'N/A') ? 'drink' : productData.unit;
            } else {
                editWasteUnitDisplay.placeholder = 'Unit'; // Fallback if product data not found
            }
            editWasteModal.style.display = 'block';
        });
    });

    // Add event listener to the product dropdown in the EDIT WASTE modal
    editWasteProductIdSelect.addEventListener('change', function() {
        const selectedProductId = this.value;
        const isMenuItem = selectedProductId.startsWith('DR');

        // Show/hide the size dropdown for menu items
        editWasteSizeWrapper.style.display = isMenuItem ? 'block' : 'none';

        // Find the product in our JS data to get its unit
        const productData = allProductsData.find(p => p.id === selectedProductId);
        
        // Update the unit display
        if (productData) {
            editWasteUnitDisplay.placeholder = (productData.unit === 'N/A') ? 'drink' : productData.unit;
        } else {
            editWasteUnitDisplay.placeholder = 'Unit'; // Fallback if product data not found
        }
    });


    // --- Edit Sale Form Confirmation ---
    const editSaleForm = document.getElementById('editSaleForm');
    if (editSaleForm) {
        editSaleForm.addEventListener('submit', function(e) {
            e.preventDefault();
            document.getElementById('confirmMessage').textContent = 'Are you sure you want to update this sale record? Inventory will be adjusted.';
            document.getElementById('confirmYesBtn').textContent = 'Yes, Update';
            confirmModal.style.display = 'block';
            document.getElementById('confirmYesBtn').onclick = () => {
                // Re-enable disabled fields before submitting
                document.getElementById('edit_sale_product_id').disabled = false;
                document.getElementById('edit_sale_size').disabled = false;
                editSaleForm.submit();
            };
        });
    }

    // --- Edit Waste Form Confirmation ---
    const editWasteForm = document.getElementById('editWasteForm');
    if (editWasteForm) {
        editWasteForm.addEventListener('submit', function(e) {
            e.preventDefault();
            document.getElementById('confirmMessage').textContent = 'Are you sure you want to update this waste record? Inventory will be adjusted.';
            document.getElementById('confirmYesBtn').textContent = 'Yes, Update';
            confirmModal.style.display = 'block';
            document.getElementById('confirmYesBtn').onclick = () => {
                // Re-enable disabled fields before submitting
                document.getElementById('edit_waste_product_id').disabled = false;
                document.getElementById('edit_waste_size').disabled = false;
                editWasteForm.submit();
            };
        });
    }

    // --- Delete Confirmation Logic ---
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const form = e.target.closest('form');
            const recordType = form.querySelector('input[name="delete_sale"]') ? 'sale' : 'waste';
            const productName = form.closest('tr').querySelector('td:nth-child(2)').textContent;
            
            document.getElementById('confirmMessage').textContent = `Are you sure you want to delete this ${recordType} record for "${productName}"? This action cannot be undone.`;
            document.getElementById('confirmYesBtn').textContent = 'Yes, Delete';
            confirmModal.style.display = 'block';

            document.getElementById('confirmYesBtn').onclick = () => form.submit();
        });
    });

    // --- Modal Closing Logic ---
    document.getElementById('closeEditSaleModal').addEventListener('click', () => closeModal(editSaleModal));
    document.getElementById('cancelEditSale').addEventListener('click', () => closeModal(editSaleModal));
    document.getElementById('closeEditWasteModal').addEventListener('click', () => closeModal(editWasteModal));
    document.getElementById('cancelEditWaste').addEventListener('click', () => closeModal(editWasteModal));
    document.getElementById('closeConfirmModal').addEventListener('click', () => closeModal(confirmModal));
    document.getElementById('confirmCancelBtn').addEventListener('click', () => closeModal(confirmModal));

    window.addEventListener('click', (e) => {
        if (e.target === editSaleModal) closeModal(editSaleModal);
        if (e.target === editWasteModal) closeModal(editWasteModal);
        if (e.target === confirmModal) closeModal(confirmModal);
    });

    // --- Toast Message ---
    const toast = document.getElementById("toast");
    const msg = localStorage.getItem("swMessage");
    if (msg) {
        toast.textContent = msg;
        toast.classList.add("show");
        setTimeout(() => toast.classList.remove("show"), 3000);
        localStorage.removeItem("swMessage");
    }

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
</body>
</html>