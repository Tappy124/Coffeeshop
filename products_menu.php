<?php
session_start();

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- Authentication Check ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') { // Admin only
    header("Location: login.php");
    exit;
}

include "includes/db.php";

/**
 * Generates a new menu product ID.
 * Example: Finds the last ID like 'DRxx', increments it to 'DRyy', and returns it.
 * @param mysqli $conn The database connection.
 * @return string The new product ID.
 */
function generateMenuProductId(mysqli $conn): string {
    $prefix = 'DR';

    // Find the highest numeric part of the ID for the 'DR' prefix
    $sql = "SELECT MAX(CAST(SUBSTRING(product_id, 3) AS UNSIGNED)) as max_num FROM menu_product WHERE product_id LIKE ?";
    $stmt = $conn->prepare($sql);
    $like_prefix = $prefix . '%';
    $stmt->bind_param("s", $like_prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($stmt) $stmt->close();

    $next_num = ($row['max_num'] ?? 0) + 1;

    return $prefix . str_pad($next_num, 2, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $name     = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $price_small = !empty($_POST['price_16oz']) ? (float)$_POST['price_16oz'] : null;
    $price_medium = !empty($_POST['price_22oz']) ? (float)$_POST['price_22oz'] : null;
    $price_large   = !empty($_POST['price_1L']) ? (float)$_POST['price_1L'] : null;
    
    $recipes = $_POST['recipes'] ?? [];
    // Set a default base price from the smallest available size for compatibility
    $price = $price_small ?? $price_medium ?? $price_large ?? 0.00;
    $drink_type = 'finished_drink';
    $new_id   = generateMenuProductId($conn);

    $stmt = $conn->prepare("INSERT INTO menu_product (product_id, product_name, category, price, product_type, price_small, price_medium, price_large) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sssdsddd", $new_id, $name, $category, $price, $drink_type, $price_small, $price_medium, $price_large);
        $stmt->execute();
        $stmt->close();

        // --- Handle Recipe Insertion ---
        if (!empty($recipes)) {
            $recipe_stmt = $conn->prepare("INSERT INTO product_recipes (drink_product_id, size, ingredient_product_id, amount_used, unit) VALUES (?, ?, ?, ?, ?)");
            foreach ($recipes as $size => $ingredients) {
                if (isset($ingredients['product_id'])) { // Check if any ingredients were added for this size
                    foreach ($ingredients['product_id'] as $index => $ingredient_id) {
                        $amount = (float)($ingredients['amount'][$index] ?? 0);
                        $unit = trim($ingredients['unit'][$index] ?? '');
                        if (!empty($ingredient_id) && $amount > 0 && !empty($unit)) {
                            $recipe_stmt->bind_param("sssds", $new_id, $size, $ingredient_id, $amount, $unit);
                            $recipe_stmt->execute();
                        }
                    }
                }
            }
            $recipe_stmt->close();
        }
        echo "<script>
            localStorage.setItem('productMessage', 'Menu Product Added Successfully!');
            window.location.href='products_menu.php';
        </script>";
        exit;
    } else {
        $error = "Prepare failed: " . $conn->error;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_product'])) {
    $id       = trim($_POST['edit_id'] ?? '');
    $name     = trim($_POST['edit_name'] ?? '');
    $category = trim($_POST['edit_category'] ?? '');
    $price_small = !empty($_POST['edit_price_16oz']) ? (float)$_POST['edit_price_16oz'] : null;
    $price_medium = !empty($_POST['edit_price_22oz']) ? (float)$_POST['edit_price_22oz'] : null;
    $price_large   = !empty($_POST['edit_price_1L']) ? (float)$_POST['edit_price_1L'] : null;

    // Set a default base price from the smallest available size for compatibility
    $price = $price_small ?? $price_medium ?? $price_large ?? 0.00;

    $recipes = $_POST['edit_recipes'] ?? [];

    if (!empty($id)) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE menu_product SET product_name = ?, category = ?, price = ?, price_small = ?, price_medium = ?, price_large = ? WHERE product_id = ?");
            $stmt->bind_param("ssdddds", $name, $category, $price, $price_small, $price_medium, $price_large, $id);
            $stmt->execute();
            $stmt->close();

            // First, delete all existing recipes for this product
            $conn->query("DELETE FROM product_recipes WHERE drink_product_id = '$id'");

            // Then, insert the new recipe data from the form
            $recipe_stmt = $conn->prepare("INSERT INTO product_recipes (drink_product_id, size, ingredient_product_id, amount_used, unit) VALUES (?, ?, ?, ?, ?)");
            foreach ($recipes as $size => $ingredients) {
                if (isset($ingredients['product_id'])) {
                    foreach ($ingredients['product_id'] as $index => $ingredient_id) {
                        $amount = (float)($ingredients['amount'][$index] ?? 0);
                        $unit = trim($ingredients['unit'][$index] ?? '');
                        if (!empty($ingredient_id) && $amount > 0 && !empty($unit)) {
                            $recipe_stmt->bind_param("sssds", $id, $size, $ingredient_id, $amount, $unit);
                            $recipe_stmt->execute();
                        }
                    }
                }
            }
            $recipe_stmt->close();
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            // Optionally, handle the error
        }
    }
    echo "<script>
        localStorage.setItem('productMessage', 'Menu Product Updated Successfully!');
        window.location.href='products_menu.php';
    </script>";
    exit;
}

// --- Search and Sort Logic ---
$search = trim($_GET['search'] ?? '');
$filter_category = trim($_GET['filter_category'] ?? ''); // For category-specific filtering
$sort_by = $_GET['sort_by'] ?? 'product_name'; // Default sort column
$sort_order = $_GET['sort_order'] ?? 'ASC';   // Default sort order

// Whitelist for security
$sort_columns_whitelist = ['product_id', 'product_name', 'category', 'price_small', 'price_medium', 'price_large'];
$sort_order_whitelist = ['ASC', 'DESC'];

if (!in_array($sort_by, $sort_columns_whitelist)) { 
    $sort_by = 'product_name'; // Fallback to a safe default
}
if (!in_array(strtoupper($sort_order), $sort_order_whitelist)) {
    $sort_order = 'ASC'; // Fallback to a safe default
}

$sql = "SELECT * FROM menu_product";
$where_clauses = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_clauses[] = "(product_id LIKE ? OR product_name LIKE ? OR category LIKE ?)";
    $like_search = "%" . $search . "%";
    $params = array_fill(0, 3, $like_search);
    $types .= 'sss';
}

if (!empty($filter_category)) {
    $where_clauses[] = "category = ?";
    $params[] = $filter_category;
    $types .= 's';
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql .= " ORDER BY `$sort_by` $sort_order";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Fetch distinct categories for the filter/add/edit dropdowns
$category_result = $conn->query("SELECT DISTINCT category FROM menu_product ORDER BY category ASC");
$drink_categories = $category_result->fetch_all(MYSQLI_ASSOC);

// --- Fetch all inventory products (with their units) for recipe modal ---
$inventory_products_result = $conn->query("SELECT id, name, measurement_unit FROM products ORDER BY name ASC");
$inventory_products = $inventory_products_result->fetch_all(MYSQLI_ASSOC);


?>
<!DOCTYPE html>
<html>
<head>
    <title>Menu Product Management</title>
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
    
</head>
<body>
<div class="container">
    <aside class="sidebar">
        <div class="logo">
            <img src="images/logo.png" alt="Logo">
        </div>
        <h2>Admin Dashboard</h2>
        <ul>
                <li><a href="admin_dashboard.php">Dashboard</a></li>
                <li><a href="inventory_products.php">Inventory Items</a></li>
                <li><a href="products_menu.php" class="active">Menu Items</a></li>
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
            <div class="top-row">
                <h1>Menu Product Management</h1>
                <div class="header-actions" style="display:flex; gap:10px; align-items:center;">
                    <button class="btn" id="openAddProduct">+ Add Menu Product</button>
                </div>
            </div>
        </header>

        <div class="filter-bar">
            <form action="products_menu.php" method="GET" class="filter-form">
                <input type="text" name="search" id="searchInput" placeholder="Search Menu Products..." value="<?= htmlspecialchars($search) ?>">
                <select name="sort_by" id="sortBySelect">
                    <option value="product_name" <?= $sort_by == 'product_name' ? 'selected' : '' ?>>Sort by Product Name</option>
                    <option value="category" <?= $sort_by == 'category' ? 'selected' : '' ?>>Sort by Category</option> 
                    <option value="price_small" <?= $sort_by == 'price_small' ? 'selected' : '' ?>>Sort by Price</option>
                </select>
                <select name="sort_order" id="sortOrderSelect">
                    <option value="ASC" <?= $sort_order == 'ASC' ? 'selected' : '' ?>>Ascending</option>
                    <option value="DESC" <?= $sort_order == 'DESC' ? 'selected' : '' ?>>Descending</option>
                </select>
                <select name="filter_category" id="categoryFilterSelect" style="display: none;">
                    <option value="">All Categories</option>
                    <?php foreach ($drink_categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['category']) ?>" <?= $filter_category == $cat['category'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['category']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn">Filter</button>
                <a href="products_menu.php" class="btn cancel-btn">Clear</a>
            </form>
        </div>

        <section class="box">
            <?php if (!empty($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Price (12oz/16oz)</th>
                            <th>Price (22oz)</th>
                            <th>Price (1L)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="productTableBody">
                    <?php if ($result && $result->num_rows): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['product_id']) ?></td>
                                <td><?= htmlspecialchars($row['product_name']) ?></td>
                                <td><?= htmlspecialchars($row['category']) ?></td>
                                <td>
                                    <?php if ($row['category'] === 'Hot Drinks'): ?>
                                        <?= $row['price_small'] ? '₱' . number_format($row['price_small'], 2) . ' (12oz)' : 'N/A' ?>
                                    <?php else: ?>
                                        <?= $row['price_small'] ? '₱' . number_format($row['price_small'], 2) . ' (16oz)' : 'N/A' ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= $row['price_medium'] ? '₱' . number_format($row['price_medium'], 2) : 'N/A' ?></td>
                                <td><?= $row['price_large'] ? '₱' . number_format($row['price_large'], 2) : 'N/A' ?></td>
                                <td>
                                    <button class="action-btn edit-btn" 
                                        data-id="<?= htmlspecialchars($row['product_id']) ?>"
                                        data-name="<?= htmlspecialchars($row['product_name'], ENT_QUOTES) ?>"
                                        data-category="<?= htmlspecialchars($row['category'], ENT_QUOTES) ?>"
                                        data-price_16oz="<?= htmlspecialchars($row['price_small'] ?? '') ?>"
                                        data-price_22oz="<?= htmlspecialchars($row['price_medium'] ?? '') ?>"
                                        data-price_1L="<?= htmlspecialchars($row['price_large'] ?? '') ?>">
                                        Edit
                                    </button>
                                    <form method="POST" action="delete_product.php?id=<?= htmlspecialchars($row['product_id']) ?>&type=menu" style="display:inline;">
                                        <!-- The form action will be handled by JS, but this is a good fallback -->
                                        <input type="hidden" name="delete_product" value="1">
                                        <button type="button" class="action-btn delete-btn">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7">No menu products found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>


<div class="modal" id="addProductModal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="addProductTitle">
        <span class="close" id="closeModal">&times;</span>
        <h2 id="addProductTitle">Add New Menu Product</h2>
        <form method="POST" action="products_menu.php" id="addProductForm">
            <input type="hidden" name="add_product" value="1">
            <label>Product Name</label>
            <input type="text" name="name" required placeholder="e.g., Iced Caramel Macchiato">
 
            <label>Category</label>
            <select name="category" required>
                <option value="" disabled selected>Select a category</option>
                <?php foreach ($drink_categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat['category']) ?>"><?= htmlspecialchars($cat['category']) ?></option>
                <?php endforeach; ?>
                <!-- Allow adding a new category -->
                <option value="new_category">-- Add New Category --</option>
            </select>
            <input type="text" name="new_category" id="new_category_input" style="display:none; margin-top: 8px;" placeholder="Enter new category name">
 
            <label>Price (16oz)</label>
            <input type="number" step="0.01" name="price_16oz" placeholder="e.g., 150.50">

            <label>Price (22oz)</label>
            <input type="number" step="0.01" name="price_22oz" placeholder="e.g., 170.00">

            <label>Price (1L)</label>
            <input type="number" step="0.01" name="price_1L" placeholder="e.g., 250.00">

            <p style="font-size: 0.8rem; color: var(--subtext); margin-top: -5px; margin-bottom: 15px;">At least one price is required. Leave blank if a size is not available.</p>
            <hr class="recipe-divider">
            <h3>Recipe Ingredients</h3>
            <p style="font-size: 0.8rem; color: var(--subtext); margin-top: -5px; margin-bottom: 15px;">Add the ingredients from your inventory required to make this drink for each size.</p>

            <!-- Recipe for 16oz -->
            <div class="recipe-section" id="recipe_16oz" style="display:none;">
                <h4>Recipe for 16oz</h4>
                <div class="ingredient-list"></div>
                <button type="button" class="btn-add-ingredient" data-size="16oz">+ Add Ingredient</button>
            </div>

            <!-- Recipe for 22oz -->
            <div class="recipe-section" id="recipe_22oz" style="display:none;">
                <h4>Recipe for 22oz</h4>
                <div class="ingredient-list"></div>
                <button type="button" class="btn-add-ingredient" data-size="22oz">+ Add Ingredient</button>
            </div>

            <!-- Recipe for 1L -->
            <div class="recipe-section" id="recipe_1L" style="display:none;">
                <h4>Recipe for 1L</h4>
                <div class="ingredient-list"></div>
                <button type="button" class="btn-add-ingredient" data-size="1L">+ Add Ingredient</button>
            </div>

            <!-- Recipe for 12oz (Hot Drinks) -->
            <div class="recipe-section" id="recipe_12oz" style="display:none;">
                <h4>Recipe for 12oz (Hot Drink)</h4>
                <div class="ingredient-list"></div>
                <button type="button" class="btn-add-ingredient" data-size="12oz">+ Add Ingredient</button>
            </div>
            <div class="form-actions">
                <button type="submit">Save Product</button>
                <button type="button" class="cancel-btn" id="cancelAddProduct">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Product Modal -->
<div class="modal" id="editProductModal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="editProductTitle">
        <span class="close" id="closeEditModal">&times;</span>
        <h2 id="editProductTitle">Edit Menu Product</h2>
        <form method="POST" action="products_menu.php" id="editProductForm">
            <input type="hidden" name="edit_product" value="1">
            <input type="hidden" name="edit_id" id="edit_id">

            <label for="edit_name">Product Name</label>
            <input type="text" name="edit_name" id="edit_name" required placeholder="e.g., Iced Caramel Macchiato">

            <label for="edit_category">Category</label>
            <select name="edit_category" id="edit_category" required>
                <option value="" disabled>Select a category</option>
                <?php foreach ($drink_categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat['category']) ?>"><?= htmlspecialchars($cat['category']) ?></option>
                <?php endforeach; ?>
                 <option value="new_category">-- Add New Category --</option>
            </select>
            <input type="text" name="edit_new_category" id="edit_new_category_input" style="display:none; margin-top: 8px;" placeholder="Enter new category name">
            <label for="edit_price_16oz">Price (16oz)</label>
            <input type="number" step="0.01" name="edit_price_16oz" id="edit_price_16oz" placeholder="Leave blank if N/A">

            <label for="edit_price_22oz">Price (22oz)</label>
            <input type="number" step="0.01" name="edit_price_22oz" id="edit_price_22oz" placeholder="Leave blank if N/A">

            <label for="edit_price_1l">Price (1L)</label>
            <input type="number" step="0.01" name="edit_price_1L" id="edit_price_1l" placeholder="Leave blank if N/A">

            <p style="font-size: 0.8rem; color: var(--subtext); margin-top: -5px; margin-bottom: 15px;">At least one price is required.</p>
            
            <hr class="recipe-divider">
            <h3>Recipe Ingredients</h3>
            <p style="font-size: 0.8rem; color: var(--subtext); margin-top: -5px; margin-bottom: 15px;">Edit the ingredients required to make this drink for each size.</p>

            <!-- Recipe sections for Edit Modal -->
            <div class="recipe-section" id="edit_recipe_12oz" style="display:none;">
                <h4>Recipe for 12oz (Hot Drink)</h4>
                <div class="ingredient-list"></div>
                <button type="button" class="btn-add-ingredient" data-size="12oz" data-modal="edit">+ Add Ingredient</button>
            </div>
            <div class="recipe-section" id="edit_recipe_16oz" style="display:none;">
                <h4>Recipe for 16oz</h4>
                <div class="ingredient-list"></div>
                <button type="button" class="btn-add-ingredient" data-size="16oz" data-modal="edit">+ Add Ingredient</button>
            </div>
            <div class="recipe-section" id="edit_recipe_22oz" style="display:none;">
                <h4>Recipe for 22oz</h4>
                <div class="ingredient-list"></div>
                <button type="button" class="btn-add-ingredient" data-size="22oz" data-modal="edit">+ Add Ingredient</button>
            </div>
            <div class="recipe-section" id="edit_recipe_1L" style="display:none;">
                <h4>Recipe for 1L</h4>
                <div class="ingredient-list"></div>
                <button type="button" class="btn-add-ingredient" data-size="1L" data-modal="edit">+ Add Ingredient</button>
            </div>

            <div class="form-actions">
                <button type="submit">Update Product</button>
                <button type="button" class="cancel-btn" id="cancelEditProduct">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Generic Confirmation Modal -->
<div class="modal" id="confirmModal" aria-hidden="true">
    <div class="modal-content" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle">
        <h2 id="confirmTitle">Please Confirm</h2>
        <p id="confirmMessage" style="margin: 20px 0; text-align: center; font-size: 1.1rem;"></p>
        <div class="form-actions">
            <button type="button" class="confirm-btn-yes">Confirm</button>
            <button type="button" class="cancel-btn" id="confirmCancel">Cancel</button>
        </div>
    </div>
</div>

<!-- Toast Message -->
<div id="toast" class="toast"></div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- Toast Message Logic ---
        const toast = document.getElementById("toast");
        const msg = localStorage.getItem("productMessage");

        // --- Confirmation Modal Logic ---
        const confirmModal = document.getElementById('confirmModal');
        const confirmMessage = document.getElementById('confirmMessage');
        const confirmYesBtn = confirmModal.querySelector('.confirm-btn-yes');
        const confirmCancelBtn = document.getElementById('confirmCancel');
        let formToSubmitForDelete = null;
        let formToSubmit = null;

        // --- Add Product Modal ---
        const addModal = document.getElementById('addProductModal');
        const addForm = document.getElementById('addProductForm');
        const openAddBtn = document.getElementById('openAddProduct');
        const cancelAddBtn = document.getElementById('cancelAddProduct');
        const closeAddBtn = document.getElementById('closeModal');

        openAddBtn.addEventListener('click', () => {
            addModal.style.display = 'block';
            addModal.setAttribute('aria-hidden', 'false');
        });

        function closeAddModal() {
            addModal.style.display = 'none';
            addModal.setAttribute('aria-hidden', 'true');
        }

        closeAddBtn.addEventListener('click', closeAddModal);
        cancelAddBtn.addEventListener('click', closeAddModal);

        // --- Edit Product Modal ---
        const editModal = document.getElementById('editProductModal');
        const editForm = document.getElementById('editProductForm');
        const cancelEditBtn = document.getElementById('cancelEditProduct');
        const closeEditBtn = document.getElementById('closeEditModal');
        const editButtons = document.querySelectorAll('.edit-btn');

        editButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                // Populate the edit form
                document.getElementById('edit_id').value = btn.dataset.id;
                document.getElementById('edit_name').value = btn.dataset.name;
                document.getElementById('edit_category').value = btn.dataset.category;
                document.getElementById('edit_price_16oz').value = btn.dataset.price_16oz;
                document.getElementById('edit_price_22oz').value = btn.dataset.price_22oz;
                document.getElementById('edit_price_1l').value = btn.dataset.price_1L;

                // --- New: Fetch and display recipe ---
                const productId = btn.dataset.id;
                // Clear previous recipe sections
                document.querySelectorAll('#editProductModal .recipe-section .ingredient-list').forEach(list => list.innerHTML = '');
                document.querySelectorAll('#editProductModal .recipe-section').forEach(section => section.style.display = 'none');

                fetch(`api_get_menu_recipe.php?product_id=${productId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.recipe) {
                            populateRecipeSections(data.recipe, 'edit');
                        }
                    });
                // Show the modal
                editModal.style.display = 'block';
                editModal.setAttribute('aria-hidden', 'false');
            });
        });

        function closeEditModal() {
            editModal.style.display = 'none';
            editModal.setAttribute('aria-hidden', 'true');
            // Clear recipe sections when closing
            document.querySelectorAll('#editProductModal .recipe-section .ingredient-list').forEach(list => list.innerHTML = '');
        }

        closeEditBtn.addEventListener('click', closeEditModal);
        cancelEditBtn.addEventListener('click', closeEditModal);

        // --- Intercept Form Submissions ---
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // --- Recipe Validation ---
            const price16oz = parseFloat(addForm.querySelector('input[name="price_16oz"]').value) || 0;
            const price22oz = parseFloat(addForm.querySelector('input[name="price_22oz"]').value) || 0;
            const price1L = parseFloat(addForm.querySelector('input[name="price_1L"]').value) || 0;
            let validationError = '';

            if (price16oz > 0 && addForm.querySelectorAll('#recipe_16oz .ingredient-item').length === 0) {
                validationError = 'Please add at least one ingredient for the 16oz size since a price has been set.';
            } else if (price22oz > 0 && addForm.querySelectorAll('#recipe_22oz .ingredient-item').length === 0) {
                validationError = 'Please add at least one ingredient for the 22oz size since a price has been set.';
            } else if (price1L > 0 && addForm.querySelectorAll('#recipe_1L .ingredient-item').length === 0) {
                validationError = 'Please add at least one ingredient for the 1L size since a price has been set.';
            }

            if (validationError) {
                const toast = document.getElementById("toast");
                toast.textContent = validationError;
                toast.classList.add("show", "error");
                setTimeout(() => {
                    toast.classList.remove("show", "error");
                }, 5000);
                return; // Stop the submission
            }
            // --- End of Recipe Validation ---


            formToSubmit = addForm;
            confirmMessage.textContent = 'Are you sure you want to save this menu product?';
            confirmModal.style.display = 'block';
        });

        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            formToSubmit = editForm;
            confirmMessage.textContent = 'Are you sure you want to update this menu product?';
            confirmModal.style.display = 'block';
        });

        // --- Handle Confirmation Actions ---
        function closeConfirmModal() {
            confirmModal.style.display = 'none';
            formToSubmitForDelete = null;
            formToSubmit = null;
        }

        confirmYesBtn.addEventListener('click', function() {
            if (formToSubmit) {
                formToSubmit.submit();
            } else if (formToSubmitForDelete) {
                formToSubmitForDelete.submit();
            }
        });

        // --- Handle Delete Confirmation ---
        const deleteButtons = document.querySelectorAll('.delete-btn');
        deleteButtons.forEach(btn => {
            btn.addEventListener('click', function(e) {
                formToSubmitForDelete = this.closest('form');
                const productName = this.closest('tr').querySelector('td:nth-child(2)').textContent;
                confirmMessage.textContent = `Are you sure you want to delete "${productName}"?`;
                confirmModal.style.display = 'block';
                confirmModal.setAttribute('aria-hidden', 'false');
            }
    )});

        confirmCancelBtn.addEventListener('click', closeConfirmModal);

        // Also close confirmation modal on Esc or outside click
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeConfirmModal();
        });
        window.addEventListener('click', (e) => { if (e.target === confirmModal) closeConfirmModal(); });

        // --- General Modal Behavior (close on outside click or Esc) ---
        window.addEventListener('click', (e) => {
            if (e.target === addModal) {
                closeAddModal();
            }
            if (e.target === editModal) {
                closeEditModal();
            }
        });

        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeAddModal();
                closeEditModal();
            }
        });

        // --- Show Toast on Load ---
        if (msg) {
            toast.textContent = msg;
            toast.classList.add("show");
            setTimeout(() => toast.classList.remove("show"), 3000);
            localStorage.removeItem("productMessage");
        }

        // --- Dynamic Sort Order Labels ---
        const sortBySelect = document.querySelector('select[name="sort_by"]');
        const sortOrderSelect = document.getElementById('sortOrderSelect');
        const categoryFilterSelect = document.getElementById('categoryFilterSelect');
        const ascOption = sortOrderSelect.querySelector('option[value="ASC"]');
        const descOption = sortOrderSelect.querySelector('option[value="DESC"]');

        function updateFilterVisibility() {
            const selectedSort = sortBySelect.value;
            const numericColumns = ['price_small', 'price_medium', 'price_large'];

            if (selectedSort === 'category') { // When "Sort by Category" is chosen
                sortOrderSelect.style.display = 'none'; // Hide Asc/Desc
                categoryFilterSelect.style.display = 'inline-block'; // Show category filter
            } else { // For all other sort options
                sortOrderSelect.style.display = 'inline-block'; // Show Asc/Desc
                categoryFilterSelect.style.display = 'none'; // Hide category filter

                // Set the text for Asc/Desc based on column type
                if (numericColumns.includes(selectedSort)) {
                    ascOption.textContent = 'Low to High';
                    descOption.textContent = 'High to Low';
                } else {
                    ascOption.textContent = 'A-Z';
                    descOption.textContent = 'Z-A';
                }
            }
        }

        updateFilterVisibility();
        sortBySelect.addEventListener('change', updateFilterVisibility);

        // --- Logout Confirmation ---
        const logoutLink = document.querySelector('a[href="logout.php"]');
        logoutLink.addEventListener('click', function(e) {
            e.preventDefault();
            confirmMessage.textContent = 'Are you sure you want to log out?';
            confirmModal.style.display = 'block';
            confirmYesBtn.textContent = 'Yes, Logout';
            confirmYesBtn.onclick = function() {
                window.location.href = 'logout.php';
            };
        });

        // --- New Category Input Logic ---
        function handleCategoryChange(selectElement, inputElement) {
            if (selectElement.value === 'new_category') {
                inputElement.style.display = 'block';
                inputElement.required = true;
                inputElement.focus();
            } else {
                inputElement.style.display = 'none';
                inputElement.required = false;
                inputElement.value = '';
            }

            // --- Special logic for Hot Drinks (12oz) ---
            const recipe12oz = document.getElementById('recipe_12oz');
            if (selectElement.value === 'Hot Drinks') {
                recipe12oz.style.display = 'block';
            } else {
                recipe12oz.style.display = 'none';
                // Clear ingredients if category is changed away from Hot Drinks
                recipe12oz.querySelector('.ingredient-list').innerHTML = '';
            }
        }

        const addCategorySelect = document.querySelector('#addProductForm select[name="category"]');
        const newCategoryInput = document.getElementById('new_category_input');
        addCategorySelect.addEventListener('change', () => handleCategoryChange(addCategorySelect, newCategoryInput));

        const editCategorySelect = document.getElementById('edit_category');
        const editNewCategoryInput = document.getElementById('edit_new_category_input');
        editCategorySelect.addEventListener('change', () => handleCategoryChange(editCategorySelect, editNewCategoryInput));

        // --- Dynamic Recipe Section Logic ---
        const inventoryProductsOptions = `
            <option value="" disabled selected>Select Ingredient</option>
            <?php foreach ($inventory_products as $product): ?>
                <option value="<?= htmlspecialchars($product['id']) ?>" data-unit="<?= htmlspecialchars($product['measurement_unit'] ?? 'pc') ?>"><?= htmlspecialchars($product['name']) ?></option>
            <?php endforeach; ?>
        `;

        document.querySelectorAll('.btn-add-ingredient').forEach(button => {
            button.addEventListener('click', function() {
                const size = this.dataset.size;
                const modalType = this.dataset.modal || 'add'; // 'add' or 'edit'
                const ingredientList = this.previousElementSibling;
                const ingredientItem = document.createElement('div');
                ingredientItem.classList.add('ingredient-item');
                ingredientItem.innerHTML = `
                    <select name="recipes[${size}][product_id][]" class="ingredient-select" required>${inventoryProductsOptions}</select>
                    <input type="number" name="recipes[${size}][amount][]" min="0.01" step="0.01" placeholder="Amount" required>
                    <select name="recipes[${size}][unit][]" required>
                        <option value="g">g</option>
                        <option value="ml">ml</option>
                        <option value="pc">pc</option>
                    </select>
                    <button type="button" class="btn-remove-ingredient">&times;</button>
                `;
                ingredientList.appendChild(ingredientItem);
            });
        });
        // --- New: Logic for Edit Modal ---
        document.querySelectorAll('#editProductModal .btn-add-ingredient').forEach(button => {
            button.addEventListener('click', function() {
                const size = this.dataset.size;
                const ingredientList = this.previousElementSibling;
                const ingredientItem = document.createElement('div');
                ingredientItem.classList.add('ingredient-item');
                ingredientItem.innerHTML = `
                    <select name="edit_recipes[${size}][product_id][]" class="ingredient-select" required>${inventoryProductsOptions}</select>
                    <input type="number" name="edit_recipes[${size}][amount][]" min="0.01" step="0.01" placeholder="Amount" required>
                    <select name="edit_recipes[${size}][unit][]" required><option value="g">g</option><option value="ml">ml</option><option value="pc">pc</option></select>
                    <button type="button" class="btn-remove-ingredient">&times;</button>
                `;
                ingredientList.appendChild(ingredientItem);
            });
        });

        // Event delegation for removing ingredients
        document.body.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('btn-remove-ingredient')) e.target.closest('.ingredient-item').remove();
        });

        // Event delegation for auto-selecting the unit
        addModal.addEventListener('change', function(e) {
            if (e.target && e.target.classList.contains('ingredient-select')) {
                const unitSelect = e.target.closest('.ingredient-item').querySelector('select[name*="[unit]"]');
                const selectedUnit = e.target.options[e.target.selectedIndex].dataset.unit;
                
                unitSelect.value = selectedUnit;
                unitSelect.disabled = !!selectedUnit; // Disable if a unit is found, enable if not
            }
        });

        // --- Show/hide recipe sections based on price input ---
        function toggleRecipeSection(priceInput, recipeSectionId) {
            const recipeSection = document.getElementById(recipeSectionId);
            if (priceInput.value && parseFloat(priceInput.value) > 0) {
                recipeSection.style.display = 'block';
            } else {
                recipeSection.style.display = 'none';
                // Clear ingredients if price is removed
                recipeSection.querySelector('.ingredient-list').innerHTML = '';
            }
        }

        const price16ozInput = addForm.querySelector('input[name="price_16oz"]');
        const price22ozInput = addForm.querySelector('input[name="price_22oz"]');
        const price1LInput = addForm.querySelector('input[name="price_1L"]');

        price16ozInput.addEventListener('input', () => toggleRecipeSection(price16ozInput, 'recipe_16oz'));
        price22ozInput.addEventListener('input', () => toggleRecipeSection(price22ozInput, 'recipe_22oz'));
        price1LInput.addEventListener('input', () => toggleRecipeSection(price1LInput, 'recipe_1L'));
        
        // --- New: Function to populate recipe sections in the Edit modal ---
        function populateRecipeSections(recipeData, modalType) {
            for (const size in recipeData) {
                const recipeSection = document.getElementById(`${modalType}_recipe_${size}`);
                if (recipeSection) {
                    recipeSection.style.display = 'block';
                    const ingredientList = recipeSection.querySelector('.ingredient-list');
                    ingredientList.innerHTML = ''; // Clear existing

                    recipeData[size].forEach(ingredient => {
                        const ingredientItem = document.createElement('div');
                        ingredientItem.classList.add('ingredient-item');
                        ingredientItem.innerHTML = `
                            <select name="edit_recipes[${size}][product_id][]" class="ingredient-select" required>${inventoryProductsOptions}</select>
                            <input type="number" name="edit_recipes[${size}][amount][]" min="0.01" step="0.01" value="${ingredient.amount}" required>
                            <select name="edit_recipes[${size}][unit][]" required><option value="g">g</option><option value="ml">ml</option><option value="pc">pc</option></select>
                            <button type="button" class="btn-remove-ingredient">&times;</button>
                        `;
                        // Pre-select the correct options
                        ingredientItem.querySelector('.ingredient-select').value = ingredient.ingredient_id;
                        ingredientItem.querySelector('select[name*="[unit]"]').value = ingredient.unit;
                        ingredientList.appendChild(ingredientItem);
                    });
                }
            }
        }

        // --- Re-enable disabled unit selects before form submission ---
        confirmYesBtn.addEventListener('click', function() {
            if (formToSubmit === addForm) {
                addForm.querySelectorAll('select[name*="[unit]"]:disabled').forEach(select => {
                    select.disabled = false;
                });
            }
        });


    });

    // --- Live Search Filter ---
    const searchInput = document.getElementById('searchInput');
    const tableBody = document.getElementById('productTableBody');
    const tableRows = tableBody.getElementsByTagName('tr');
    const noResultsRow = tableBody.querySelector('td[colspan="7"]');

    searchInput.addEventListener('keyup', function() {
        const searchTerm = searchInput.value.toLowerCase();
        let visibleRows = 0;
        for (let i = 0; i < tableRows.length; i++) {
            const row = tableRows[i];
            // Skip the 'No products found' row
            if (row.contains(noResultsRow)) continue;

            const rowText = row.textContent.toLowerCase();

            if (rowText.includes(searchTerm)) {
                row.style.display = ''; // Show row
                visibleRows++;
            } else {
                row.style.display = 'none'; // Hide row
            }
        }

        if (noResultsRow) {
            // Show 'No products found' row if no other rows are visible
            noResultsRow.parentElement.style.display = (visibleRows === 0) ? '' : 'none';
        }
    });
</script>
</body>
</html>