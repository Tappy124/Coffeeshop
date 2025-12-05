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

// --- Handle Add Waste ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_waste'])) {
    $product_id = $_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    $product_type = $_POST['product_type']; // 'inventory' or 'menu'
    $reason = $_POST['reason'];
    $staff_id = $_SESSION['user_id']; // Log the staff member
    $waste_date = date('Y-m-d H:i:s');

    $conn->begin_transaction();

    try {
        if ($quantity <= 0) {
            throw new Exception("Quantity must be a positive number.");
        }

        $size = ($product_type === 'menu') ? ($_POST['size'] ?? null) : ($_POST['inventory_unit'] ?? null);
        $waste_cost = 0; // Initialize waste cost

        if ($product_type === 'inventory') {
            // --- Logic for Wasting an INVENTORY Item ---
            $prod_stmt = $conn->prepare("SELECT name, category, stock, content, total_content_stock, price FROM products WHERE id = ? FOR UPDATE");
            $prod_stmt->bind_param("s", $product_id);
            $prod_stmt->execute();
            $product = $prod_stmt->get_result()->fetch_assoc();
            $prod_stmt->close();

            if (!$product) throw new Exception("Inventory product not found.");
            
            // Calculate waste cost for inventory item
            $content_per_package = calculateTotalContentStock(1, $product['content']);
            if ($content_per_package > 0) {
                $cost_per_base_unit = (float)$product['price'] / $content_per_package;
                $waste_cost = $quantity * $cost_per_base_unit;
            }
            
            preg_match('/^(\d*\.?\d+)\s*(.*)$/i', $product['content'], $matches);
            $unit = strtoupper(trim($matches[2] ?? ''));
            $is_piece_based = in_array($unit, ['PCS', 'SACKS', 'ROLL/S']);
            
            // UNIFIED LOGIC: The 'quantity' entered is the base unit amount (e.g., grams, ml, or pieces).
            $content_to_deduct = $quantity;
            $new_total_content_stock = ($product['total_content_stock'] ?? 0) - $content_to_deduct;
            
            $content_per_package = calculateTotalContentStock(1, $product['content']);
            $new_package_stock = ($content_per_package > 0) ? floor($new_total_content_stock / $content_per_package) : 0;

            $update_sql = "UPDATE products SET total_content_stock = ?, stock = ? WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("dis", $new_total_content_stock, $new_package_stock, $product_id);
            $stmt->execute();
            $stmt->close();

        } elseif ($product_type === 'menu') {
            // --- Logic for Wasting a MENU Item (Finished Drink) ---
            if (empty($size)) throw new Exception("Size must be selected for a menu item.");

            // --- New Waste Cost Calculation: Based on Menu Price ---
            $price_column_map = [
                '16oz' => 'price_small',
                '22oz' => 'price_medium',
                '1L'   => 'price_large'
            ];
            $price_column = $price_column_map[$size] ?? 'price'; // Fallback

            $price_stmt = $conn->prepare("SELECT `$price_column` as price FROM menu_product WHERE product_id = ?");
            $price_stmt->bind_param("s", $product_id);
            $price_stmt->execute();
            $product_price_data = $price_stmt->get_result()->fetch_assoc();
            $price_stmt->close();

            $price_per_drink = $product_price_data['price'] ?? 0;
            $waste_cost = $price_per_drink * $quantity;

            // Use the same recipe-based deduction logic as sales
            $recipe_stmt = $conn->prepare("
                SELECT pr.ingredient_product_id, pr.amount_used, i.name as ingredient_name, i.total_content_stock as current_total_stock, i.content as package_content, i.price as ingredient_price, i.category as ingredient_category 
                FROM product_recipes pr
                JOIN products i ON pr.ingredient_product_id = i.id 
                WHERE pr.drink_product_id = ? AND pr.size = ?
            ");
            $recipe_stmt->bind_param("ss", $product_id, $size);
            $recipe_stmt->execute();
            $recipe = $recipe_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $recipe_stmt->close();

            if (empty($recipe)) throw new Exception("No recipe found for this menu item and size.");

            // Check stock for all ingredients
            foreach ($recipe as $ingredient) {
                $required_stock = $ingredient['amount_used'] * $quantity;
                if ($ingredient['current_total_stock'] < $required_stock) {
                    throw new Exception("Insufficient ingredient stock for: " . htmlspecialchars($ingredient['ingredient_name']));
                }
            }

            // Deduct stock for each ingredient
            foreach ($recipe as $ingredient) {
                $ingredient_id = $ingredient['ingredient_product_id'];
                $total_deduction = (float)$ingredient['amount_used'] * $quantity;

                preg_match('/^(\d*\.?\d+)\s*(.*)$/i', $ingredient['package_content'], $matches);
                $unit = strtoupper(trim($matches[2] ?? ''));
                $is_piece_based = in_array($unit, ['PCS', 'SACKS', 'ROLL/S']);

                // UNIFIED LOGIC for recipe ingredients
                $new_total_content_stock = ($ingredient['current_total_stock'] ?? 0) - $total_deduction;
                $content_per_package = calculateTotalContentStock(1, $ingredient['package_content']);
                $new_package_stock = ($content_per_package > 0) ? floor($new_total_content_stock / $content_per_package) : 0;
                
                $update_sql = "UPDATE products SET total_content_stock = ?, stock = ? WHERE id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("dis", $new_total_content_stock, $new_package_stock, $ingredient_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Insert the waste record with the calculated cost
        $waste_stmt = $conn->prepare("INSERT INTO waste (product_id, size, quantity, reason, waste_date, staff_id, waste_cost) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $waste_stmt->bind_param("ssissid", $product_id, $size, $quantity, $reason, $waste_date, $staff_id, $waste_cost);
        $waste_stmt->execute();
        $waste_stmt->close();
        // If all queries succeeded, commit the transaction
        $conn->commit();

        echo "<script>
            localStorage.setItem('formMessage', 'Waste logged successfully!');
            window.location.href='staff_log_waste.php';
        </script>";

    } catch (Exception $e) {
        // If any step fails, roll back all database changes
        $conn->rollback();
        $error_message = $e->getMessage();
        echo "<script>
                localStorage.setItem('formError', 'Error: " . addslashes($error_message) . "');
                window.location.href='staff_log_waste.php';
              </script>";
    }
    exit;
}

// --- Fetch ALL items (inventory and menu) for the dropdown ---
$all_products = [];
$all_categories = [];

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

// 1. Fetch Inventory Items
$inventory_result = $conn->query("SELECT id, name, category, stock, content FROM products ORDER BY name ASC");
while ($row = $inventory_result->fetch_assoc()) {
    preg_match('/^(\d*\.?\d+)\s*(.*)$/i', $row['content'] ?? '', $matches);
    $unit = trim($matches[2] ?? 'PCS');
    $all_products[] = ['id' => $row['id'], 'name' => $row['name'], 'category' => $row['category'], 'type' => 'inventory', 'unit' => $unit, 'stock' => $row['stock']];
    if (!in_array($row['category'], $all_categories) && !empty($row['category'])) $all_categories[] = $row['category'];
}

// 2. Fetch Menu Items
$menu_result = $conn->query("SELECT product_id, product_name, category, price_small, price_medium, price_large FROM menu_product ORDER BY product_name ASC");
while ($row = $menu_result->fetch_assoc()) {
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
        $all_products[] = ['id' => $row['product_id'], 'name' => $row['product_name'], 'category' => $row['category'], 'type' => 'menu', 'unit' => 'drink', 'sizes' => json_encode($sizes)];
        if (!in_array($row['category'], $all_categories) && !empty($row['category'])) $all_categories[] = $row['category'];
    }
}
sort($all_categories);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Log Waste</title>
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
    <link rel="stylesheet" href="css/extracted_styles.css">
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo"><img src="images/logo.png" alt="Logo"></div>
            <h2>Staff Dashboard</h2>
            <ul>
                <li><a href="staff_dashboard.php">Dashboard</a></li>
                <li><a href="staff_log_sales.php">Log Sale</a></li>
                <li><a href="staff_log_waste.php" class="active">Log Waste</a></li>
                <li><a href="staff_view_history.php">View History</a></li>
                <li><a href="user_account.php">My Account</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </aside>

        <main class="main">
            <header>
                <h1>Log a New Waste Record</h1>
                <div class="header-actions flex-gap-center">
                </div>
            </header>

            <section class="box max-width-500">
                <h2 id="logWasteTitle">Waste Details</h2>
                <form method="POST" action="staff_log_waste.php" id="logWasteForm" autocomplete="off">
                    <input type="hidden" name="add_waste" value="1"> 
                    <input type="hidden" name="product_type" id="product_type">

                    <label for="category_filter">Filter by Category (Optional)</label>
                    <select id="category_filter">
                        <option value="">Show All Categories</option>
                        <?php foreach ($all_categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Product</label>
                    <select name="product_id" id="product_id" required>
                        <option value="" disabled selected>Select a product</option>
                        <?php foreach ($all_products as $product): ?>
                            <option value="<?= htmlspecialchars($product['id']) ?>"
                                    data-type="<?= $product['type'] ?>"
                                    data-category="<?= htmlspecialchars($product['category']) ?>"
                                    data-unit="<?= htmlspecialchars($product['unit']) ?>"
                                    data-stock="<?= $product['stock'] ?? 'N/A' ?>"
                                    data-sizes='<?= $product['sizes'] ?? '' ?>'>
                                <?= htmlspecialchars($product['name']) ?> (Stock: <?= $product['stock'] ?? 'N/A' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="category">Category</label>
                    <input type="text" id="category" readonly placeholder="Product category appears here">

                    <div id="size_wrapper">
                        <label for="size">Size</label>
                        <select name="size" id="size"></select>
                    </div>

                    <label>Quantity Wasted</label>
                    <div class="flex-gap-center">
                        <input type="hidden" name="inventory_unit" id="inventory_unit_hidden">
                        <input type="number" name="quantity" required min="1" placeholder="e.g., 1" class="mb-12">
                        <input type="text" id="unit_display" readonly placeholder="Unit" class="unit-display">
                    </div>

                    <label>Reason</label>
                    <select name="reason" required>
                        <option value="" disabled selected>Select a reason</option>
                        <option value="Expired">Expired</option>
                        <option value="Damaged">Damaged</option>
                        <option value="Spilled">Spilled</option>
                        <option value="Error">Error</option>
                        <option value="Other">Other</option>
                    </select>
                    <div class="form-actions">
                        <button type="submit" class="btn">Save Waste Log</button>
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
            <p id="confirmMessage" class="text-center"></p>
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
        const logWasteForm = document.getElementById('logWasteForm');
        logWasteForm.addEventListener('submit', function(e) {
            e.preventDefault();
            document.getElementById('confirmMessage').textContent = 'Are you sure you want to log this waste record?';
            document.getElementById('confirmYesBtn').textContent = 'Yes, Log Waste';
            confirmModal.style.display = 'block';

            document.getElementById('confirmYesBtn').onclick = function() {
                logWasteForm.submit();
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

        // --- Dynamic Form Logic ---
        const productSelect = document.getElementById('product_id');
        const categoryInput = document.getElementById('category');
        const productTypeInput = document.getElementById('product_type');
        const unitDisplay = document.getElementById('unit_display');
        const sizeWrapper = document.getElementById('size_wrapper');
        const sizeSelect = document.getElementById('size');
        const inventoryUnitHidden = document.getElementById('inventory_unit_hidden');

        productSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const type = selectedOption.dataset.type;
            const category = selectedOption.dataset.category;
            const unit = selectedOption.dataset.unit;
            const sizes = selectedOption.dataset.sizes;

            // Set hidden type and visible category
            productTypeInput.value = type;
            categoryInput.value = category || '';
            unitDisplay.value = unit || '';
            inventoryUnitHidden.value = unit || '';

            // Show/hide size dropdown based on product type
            if (type === 'menu' && sizes) {
                sizeWrapper.style.display = 'block';
                sizeSelect.required = true;
                sizeSelect.innerHTML = '<option value="" disabled selected>Select a size</option>';
                const sizeData = JSON.parse(sizes);
                for (const sizeKey in sizeData) {
                    sizeSelect.add(new Option(sizeKey.toUpperCase(), sizeKey));
                }
            } else {
                sizeWrapper.style.display = 'none';
                sizeSelect.required = false;
                sizeSelect.innerHTML = '';
            }
        });

        // --- Category Filter Logic ---
        const categoryFilter = document.getElementById('category_filter');
        categoryFilter.addEventListener('change', function() {
            const selectedCategory = this.value;
            const productOptions = productSelect.options;

            // Reset form
            productSelect.value = '';
            categoryInput.value = '';
            productTypeInput.value = '';
            unitDisplay.value = '';
            sizeWrapper.style.display = 'none';
            sizeSelect.required = false;

            for (let i = 1; i < productOptions.length; i++) { // Skip placeholder
                const option = productOptions[i];
                const productCategory = option.dataset.category;

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