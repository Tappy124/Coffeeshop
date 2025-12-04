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

// --- Handle Add Sale (New Recipe-Based Logic) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sale'])) {
    $product_id = $_POST['product_id'];
    $size = $_POST['size'];
    $quantity = (int)$_POST['quantity'];
    $staff_id = $_SESSION['user_id'];
    $sale_date = date('Y-m-d H:i:s');

    $conn->begin_transaction();

    try {
        // 1. Fetch the recipe and the current total content stock for each ingredient
        $recipe_stmt = $conn->prepare("
            SELECT 
                pr.ingredient_product_id, 
                pr.amount_used, 
                i.name as ingredient_name,
                i.category as ingredient_category,
                i.price as ingredient_price,
                i.total_content_stock as current_total_stock,
                i.content as package_content
            FROM product_recipes pr 
            JOIN products i ON pr.ingredient_product_id = i.id 
            WHERE pr.drink_product_id = ? AND pr.size = ? FOR UPDATE
        ");
        $recipe_stmt->bind_param("ss", $product_id, $size);
        $recipe_stmt->execute();
        $recipe = $recipe_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $recipe_stmt->close();

        if (empty($recipe)) {
            throw new Exception("No recipe found for this product and size. Please contact an administrator.");
        }

        $cost_per_drink = 0;
        $cogs_breakdown_data = [];

        // 2. Check if there is enough stock for all ingredients
        foreach ($recipe as $ingredient) {
            $required_stock = $ingredient['amount_used'] * $quantity;
            if ($ingredient['current_total_stock'] < $required_stock) {
                throw new Exception("Insufficient stock for ingredient: " . htmlspecialchars($ingredient['ingredient_name']) . ". Required: {$required_stock}, Available: {$ingredient['current_total_stock']}.");
            }

            // --- COGS Calculation per ingredient ---
            preg_match('/^(\d*\.?\d+)\s*(.*)$/i', $ingredient['package_content'], $matches);
            $value_per_package = (float)($matches[1] ?? 0);
            $unit_name = strtoupper(trim($matches[2] ?? ''));
            if ($unit_name === 'KG' || $unit_name === 'L') $value_per_package *= 1000;

            if ($value_per_package > 0) {
                $cost_per_base_unit = (float)$ingredient['ingredient_price'] / $value_per_package;
                $ingredient_cost_for_one_drink = (float)$ingredient['amount_used'] * $cost_per_base_unit;
                $cost_per_drink += $ingredient_cost_for_one_drink;

                // Store data for the breakdown table (total cost for the entire quantity)
                $cogs_breakdown_data[] = [
                    'ingredient_id' => $ingredient['ingredient_product_id'],
                    'quantity_used' => (float)$ingredient['amount_used'] * $quantity,
                    'cost' => $ingredient_cost_for_one_drink * $quantity
                ];
            }
        }

        // 3. Fetch the correct price for the selected size
        $price_column_map = [
            '16oz' => 'price_small',
            '22oz' => 'price_medium',
            '1L' => 'price_large'
        ];
        $price_column = $price_column_map[$size] ?? 'price'; // Fallback to base price

        $price_stmt = $conn->prepare("SELECT `$price_column` as price FROM menu_product WHERE product_id = ?");
        $price_stmt->bind_param("s", $product_id);
        $price_stmt->execute();
        $product_price_data = $price_stmt->get_result()->fetch_assoc();
        $price_stmt->close();

        if (!$product_price_data || !isset($product_price_data['price'])) {
            throw new Exception("Could not determine the price for the selected size.");
        }
        $total_amount = $product_price_data['price'] * $quantity;
        $total_cogs = (float)$cost_per_drink * (int)$quantity;

        // 4. Insert the main sale record with total COGS
        $sale_stmt = $conn->prepare("INSERT INTO sales (product_id, size, quantity, total_amount, cogs, sale_date, staff_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $sale_stmt->bind_param("ssiddsi", $product_id, $size, $quantity, $total_amount, $total_cogs, $sale_date, $staff_id);
        $sale_stmt->execute();
        $new_sale_id = $conn->insert_id; // Get the ID of the sale we just created
        $sale_stmt->close();

        // 5. Insert records into the new sale_cogs_breakdown table
        $breakdown_stmt = $conn->prepare("INSERT INTO sale_cogs_breakdown (sale_id, ingredient_product_id, quantity_used, cost_at_time_of_sale) VALUES (?, ?, ?, ?)");
        foreach ($cogs_breakdown_data as $breakdown) {
            $breakdown_stmt->bind_param("isdd", $new_sale_id, $breakdown['ingredient_id'], $breakdown['quantity_used'], $breakdown['cost']);
            $breakdown_stmt->execute();
        }
        $breakdown_stmt->close();

        // 6. Deduct from total_content_stock and recalculate package stock for each ingredient
        foreach ($recipe as $ingredient) {
            $ingredient_id = $ingredient['ingredient_product_id'];
            $amount_used_per_drink = (float)$ingredient['amount_used'];
            $total_deduction = $amount_used_per_drink * $quantity;

            // UNIFIED LOGIC: Always deduct from total content and recalculate package stock.
            // This works for both weight/volume (G, ML) and piece-based (PCS) items.
            $new_total_content_stock = ($ingredient['current_total_stock'] ?? 0) - $total_deduction;
            
            // Get the content of a single package (e.g., 1000 for "1000 G", 100 for "100 PCS")
            $content_per_package = calculateTotalContentStock(1, $ingredient['package_content']);
            
            // Recalculate the number of full packages remaining.
            $new_package_stock = ($content_per_package > 0) ? floor($new_total_content_stock / $content_per_package) : 0;

            $update_sql = "UPDATE products SET total_content_stock = ?, stock = ? WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("dis", $new_total_content_stock, $new_package_stock, $ingredient_id);
            $stmt->execute();
            $stmt->close();
        }

        // If all queries succeeded, commit the transaction
        $conn->commit();

        // Success feedback
        echo "<script>
                localStorage.setItem('formMessage', 'Sale logged successfully!');
                window.location.href='staff_log_sales.php';
              </script>";

    } catch (Exception $e) {
        // If any step fails, roll back all database changes
        $conn->rollback();

        // Error feedback
        $error_message = $e->getMessage();
        echo "<script>
                localStorage.setItem('formError', 'Error: " . addslashes($error_message) . "');
                window.location.href='staff_log_sales.php';
              </script>";
    }
    exit;
}

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

// --- Fetch distinct categories for the filter dropdown ---
$category_result = $conn->query("SELECT DISTINCT category FROM menu_product ORDER BY category ASC");
$drink_categories = $category_result->fetch_all(MYSQLI_ASSOC);

// --- Fetch Finished Drink Products for the form dropdown ---
$products_result = $conn->query("SELECT product_id, product_name, category, price_small, price_medium, price_large FROM menu_product ORDER BY product_name ASC");
$products = [];
while ($row = $products_result->fetch_assoc()) {
    $sizes = [];
    if ($row['category'] === 'Hot Drinks') {
        // Hot drinks ONLY have a 12oz size, which uses the price_small column
        if (!empty($row['price_small'])) {
            $sizes['12oz'] = $row['price_small'];
        }
    } else {
        // All other drinks have these sizes
        if (!empty($row['price_small'])) $sizes['16oz'] = $row['price_small'];
        if (!empty($row['price_medium'])) $sizes['22oz'] = $row['price_medium'];
        if (!empty($row['price_large'])) $sizes['1L'] = $row['price_large'];
    }
 
    if (!empty($sizes)) {
        $products[] = [
            'id' => $row['product_id'],
            'name' => $row['product_name'],
            'category' => $row['category'],
            'sizes' => $sizes
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Log Sale</title>
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/form.css">
    <link rel="stylesheet" href="css/modal.css">
    <style>
        /* Apply modal form styles to the main content form */
        .main .box form input[type="number"],
        .main .box form input[type="text"],
        .main .box form select {
            width: 100%;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #bdbdbd;
            margin-bottom: 12px;
            background-color: var(--bg);
            color: var(--text);
            box-sizing: border-box;
            font-size: 1rem;
            appearance: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .main .box form select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%23888' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            padding-right: 36px;
        }
        .main .box form select:invalid { color: #777777; }
        .main .box form input:hover,
        .main .box form select:hover { border-color: var(--accent); }
        .main .box form input:focus,
        .main .box form select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(74, 108, 111, 0.2);
        }
        .main .box .form-actions button {
            width: 100%;
            padding: 12px 20px;
            margin-top: 10px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
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
                <li><a href="staff_log_sales.php" class="active">Log Sale</a></li>
                <li><a href="staff_log_waste.php">Log Waste</a></li>
                <li><a href="staff_view_history.php">View History</a></li>
                <li><a href="user_account.php">My Account</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </aside>

        <main class="main">
            <header>
                <h1>Log a New Sale</h1>
                <div class="header-actions" style="display:flex; gap:10px; align-items:center;">
                </div>
            </header>

            <section class="box" style="max-width: 500px; margin: 20px auto;">
                <h2 id="logSaleTitle">Sale Details</h2>
                <form method="POST" action="staff_log_sales.php" id="logSaleForm" autocomplete="off">
                    <input type="hidden" name="add_sale" value="1">

                    <label for="category_filter">Filter by Category (Optional)</label>
                    <select id="category_filter" name="category_filter">
                        <option value="">Show All Categories</option>
                        <?php foreach ($drink_categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat['category']) ?>">
                                <?= htmlspecialchars($cat['category']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label>Product</label>
                    <select name="product_id" id="product_id" required>
                        <option value="" disabled selected>Select a product</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= htmlspecialchars($product['id']) ?>" 
                                    data-sizes='<?= json_encode($product['sizes']) ?>'
                                    data-category="<?= htmlspecialchars($product['category']) ?>">
                                <?= htmlspecialchars($product['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="category">Category</label>
                    <input type="text" id="category" name="category" placeholder="Product category will appear here" readonly>

                    <label for="size">Size</label>
                    <select name="size" id="size" required disabled>
                        <option value="" disabled selected>Select a product first</option>
                    </select>

                    <label>Quantity</label>
                    <input type="number" name="quantity" required min="1" placeholder="e.g., 1">
                    <div class="form-actions">
                        <button type="submit" class="btn">Save Sale</button>
                        <button type="reset" class="cancel-btn">Clear Form</button>
                    </div>
                </form>
            </section>
        </main>
    </div>

    <!-- Generic Confirmation Modal -->
    <div class="modal" id="confirmModal" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle">
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

        // --- Toast Message Logic ---
        const toast = document.getElementById("toast");
        const msg = localStorage.getItem("formMessage");
        const errMsg = localStorage.getItem("formError");
        if (msg) {
            toast.textContent = msg;
            toast.classList.add("show");
            setTimeout(() => toast.classList.remove("show"), 3000);
            localStorage.removeItem("formMessage");
        }
        if (errMsg) {
            toast.textContent = errMsg;
            toast.classList.add("show", "error"); // Add error class for styling
            // Show error for longer
            setTimeout(() => toast.classList.remove("show", "error"), 5000);
            localStorage.removeItem("formError");
        }


        // --- Form Submission Confirmation ---
        const logSaleForm = document.getElementById('logSaleForm');
        logSaleForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const productName = document.getElementById('product_id').selectedOptions[0].text;
            const size = document.getElementById('size').value;
            const quantity = document.querySelector('input[name="quantity"]').value;

            if (!productName || !size || !quantity || quantity < 1) {
                alert('Please fill out all fields correctly.');
                return;
            }
            document.getElementById('confirmMessage').textContent = `Log ${quantity} x ${productName} (${size})?`;
            document.getElementById('confirmYesBtn').textContent = 'Yes, Log Sale';
            confirmModal.style.display = 'block';

            document.getElementById('confirmYesBtn').onclick = function() {
                logSaleForm.submit();
            };
        });

        // --- Modal Closing Logic ---
        function closeModal(modal) { if (modal) modal.style.display = 'none'; }
        document.getElementById('confirmCancelBtn').addEventListener('click', () => closeModal(confirmModal));
        window.addEventListener('click', (e) => {
            if (e.target === confirmModal) closeModal(confirmModal);
        });

        // --- Logout Confirmation ---
        const logoutLink = document.querySelector('a[href="logout.php"]');
        logoutLink.addEventListener('click', function(e) {
            e.preventDefault();
            const confirmBtn = document.getElementById('confirmYesBtn');
            document.getElementById('confirmMessage').textContent = 'Are you sure you want to log out?';
            confirmBtn.textContent = 'Yes, Logout';
            confirmBtn.className = 'confirm-btn-yes btn-logout-yes';
            confirmModal.style.display = 'block';
            confirmBtn.onclick = function() {
                window.location.href = 'logout.php';
            };
        });

        // --- Dynamic Size Dropdown ---
        const productSelect = document.getElementById('product_id');
        const sizeSelect = document.getElementById('size');
        const categoryInput = document.getElementById('category');

        productSelect.addEventListener('change', function() {
            // Clear previous options
            sizeSelect.innerHTML = '<option value="" disabled selected>Select a size</option>';
            sizeSelect.disabled = true;
            categoryInput.value = ''; // Clear category on new product selection

            const selectedOption = this.options[this.selectedIndex];
            const sizesData = selectedOption.dataset.sizes;
            const categoryData = selectedOption.dataset.category;

            if (sizesData) {
                const sizes = JSON.parse(sizesData);
                for (const size in sizes) {
                    const price = sizes[size];
                    const optionText = `${size.toUpperCase()} - ₱${parseFloat(price).toFixed(2)}`;
                    const option = new Option(optionText, size);
                    sizeSelect.add(option);
                }
                sizeSelect.disabled = false;
            }
            // When a product is selected, update the category input to match
            if (categoryData) {
                categoryInput.value = categoryData;
            }
        });

        // --- Filter Products by Category ---
        const categoryFilter = document.getElementById('category_filter');
        categoryFilter.addEventListener('change', function() {
            const selectedCategory = this.value;
            const productOptions = productSelect.options;

            // Reset product, size, and category display
            productSelect.value = '';
            categoryInput.value = '';
            sizeSelect.innerHTML = '<option value="" disabled selected>Select a product first</option>';
            sizeSelect.disabled = true;

            // Loop through all product options to show/hide them
            for (let i = 1; i < productOptions.length; i++) { // Start from 1 to skip "Select a product"
                const option = productOptions[i];
                const productCategory = option.dataset.category;

                // If "All Categories" is selected, or if the product's category matches, show it. Otherwise, hide it.
                if (selectedCategory === "" || productCategory === selectedCategory) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            }
        });

    });
    </script>
</body>
</html>