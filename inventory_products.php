<?php
session_start();

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) { // Allow both Admin and Staff
    header("Location: login.php");
    exit;
}

include "includes/db.php";

/**
 * Generates a new product ID based on category.
 * Example: For 'Syrup', it finds the last ID like 'SYxx', increments it to 'SYyy', and returns it.
 * @param mysqli $conn The database connection.
 * @param string $category The product category.
 * @return string The new product ID.
 */
function generateProductId(mysqli $conn, string $category): string {
    $prefixes = [
        'Basic Ingredient' => 'BI',
        'Cups and Lids' => 'CL',
        'Other Supplies' => 'OS',
        'Powders' => 'PW',
        'Syrup' => 'SY',
        'Sinkers' => 'SK',
        'Outsource Products' => 'OP'
    ];
    $prefix = $prefixes[$category] ?? 'XX'; // Default to 'XX' if category not found

    // Find the highest numeric part of the ID for the given prefix
    $sql = "SELECT MAX(CAST(SUBSTRING(id, 3) AS UNSIGNED)) as max_num FROM products WHERE id LIKE ?";
    $stmt = $conn->prepare($sql);
    $like_prefix = $prefix . '%';
    $stmt->bind_param("s", $like_prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    $next_num = ($row['max_num'] ?? 0) + 1;

    return $prefix . str_pad($next_num, 2, '0', STR_PAD_LEFT);
}

/**
 * Calculates the total content in a base unit (G or ML).
 * @param int $stock The number of packages.
 * @param string|null $content The content per package (e.g., "1 KG", "500 ML").
 * @return float|null The total content in the base unit, or null if not applicable.
 */
function calculateTotalContentStock(int $stock, ?string $content): float {
    if ($stock < 0) {
        return 0.0; // Stock cannot be negative
    }
    if (empty($content)) {
        return 0.0; // If content is empty, assume 0 total content
    }

    preg_match('/^(\d*\.?\d+)\s*(.*)$/i', $content, $matches);
    $value = (float)($matches[1] ?? 0);
    $unit = strtoupper(trim($matches[2] ?? ''));

    if ($value <= 0) return 0.0; // If content value is 0, total content is 0

    $total = $stock * $value;

    if ($unit === 'KG' || $unit === 'L') {
        return $total * 1000; // Convert KG to G, L to ML
    }
    return $total; // Assumes G, ML, PCS, etc. are already base units
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $name     = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $price    = (float)($_POST['price'] ?? 0);
    $stock    = (int)($_POST['stock'] ?? 0);
    $content_value = trim($_POST['content_value'] ?? '');
    $content_unit = trim($_POST['content_unit'] ?? '');

    // Combine value and unit into the content string, only if a value is provided.
    $content = '';
    if (!empty($content_value)) {
        $content = $content_value . ' ' . $content_unit;
    }

    $total_content_stock = calculateTotalContentStock($stock, $content);

    $new_id   = generateProductId($conn, $category);

    $stmt = $conn->prepare("INSERT INTO products (id, name, category, price, stock, content, total_content_stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sssdiss", $new_id, $name, $category, $price, $stock, $content, $total_content_stock);
        $stmt->execute();
        $stmt->close();
        echo "<script>
            localStorage.setItem('productMessage', 'Product Added Successfully!');
            window.location.href='inventory_products.php';
        </script>";
        exit;
    } else {
        $error = "Prepare failed: " . $conn->error;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_product'])) {
    $id       = trim($_POST['edit_id'] ?? '');
    $name     = trim($_POST['edit_name'] ?? '');
    $category = trim($_POST['edit_category'] ?? ''); // Keep category for the update
    $price    = (float)($_POST['edit_price'] ?? 0);
    $stock    = (int)($_POST['edit_stock'] ?? 0);
    $content_value = trim($_POST['edit_content_value'] ?? '');
    $content_unit = trim($_POST['edit_content_unit'] ?? '');

    // Combine value and unit into the content string.
    $content = '';
    if (!empty($content_value)) {
        $content = $content_value . ' ' . $content_unit;
    }

    $conn->begin_transaction();
    try {
        if (!empty($id) && !empty($category)) {
            // 1. Get the original product details before updating
            $stmt_orig = $conn->prepare("SELECT stock, total_content_stock FROM products WHERE id = ? FOR UPDATE");
            $stmt_orig->bind_param("s", $id);
            $stmt_orig->execute();
            $original_product = $stmt_orig->get_result()->fetch_assoc();
            $stmt_orig->close();

            if ($original_product) {
                // 2. Calculate the change in stock and the corresponding change in content
                $stock_difference = $stock - $original_product['stock'];
                $content_per_package = calculateTotalContentStock(1, $content); // Get content of a single package
                $content_change = $stock_difference * $content_per_package;

                // 3. Adjust the existing total_content_stock
                $new_total_content_stock = ($original_product['total_content_stock'] ?? 0) + $content_change;

                // 4. Update the product with the new values
                $stmt = $conn->prepare("UPDATE products SET name = ?, category = ?, price = ?, stock = ?, content = ?, total_content_stock = ? WHERE id = ?");
                $stmt->bind_param("ssdisds", $name, $category, $price, $stock, $content, $new_total_content_stock, $id);
                $stmt->execute();
                $stmt->close();
            }
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        // Optionally handle the error, e.g., by setting a message
    }

    echo "<script>
        localStorage.setItem('productMessage', 'Product Updated Successfully!');
        window.location.href='inventory_products.php';
    </script>";
    exit;
}

// --- Search and Sort Logic ---
$filter_category = trim($_GET['filter_category'] ?? ''); // For category-specific filtering
$search = trim($_GET['search'] ?? '');
$sort_by = $_GET['sort_by'] ?? 'category'; // Default sort column
$sort_order = $_GET['sort_order'] ?? 'ASC';   // Default sort order

// Whitelist for security to prevent SQL injection in column names/order
$sort_columns_whitelist = ['id', 'name', 'category', 'price', 'stock', 'content', 'total_content_stock'];
$sort_order_whitelist = ['ASC', 'DESC'];

if (!in_array($sort_by, $sort_columns_whitelist)) {
    $sort_by = 'category'; // Fallback to a safe default
}
if (!in_array(strtoupper($sort_order), $sort_order_whitelist)) {
    $sort_order = 'ASC'; // Fallback to a safe default
}

$sql = "SELECT id, name, category, price, stock, content, total_content_stock FROM products";
$where_clauses = [];
$params = [];
$types = '';

if (!empty($search)) {
    // Search across multiple relevant columns
    $where_clauses[] = "(id LIKE ? OR name LIKE ? OR category LIKE ? OR price LIKE ? OR stock LIKE ?)";
    $like_search = "%" . $search . "%";
    // Add a parameter for each column being searched
    $params = [$like_search, $like_search, $like_search, $like_search, $like_search];
    $types .= 'sssss';
}

if (!empty($filter_category)) {
    $where_clauses[] = "category = ?";
    $params[] = $filter_category;
    $types .= 's';
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

if ($sort_by === 'category') {
    // Custom sort order for categories
    $category_order_list = "'Basic Ingredient', 'Cups and Lids', 'Other Supplies', 'Powders', 'Syrup', 'Sinkers', 'Outsource Products'";
    if ($sort_order === 'DESC') {
        $category_order_list = "'Outsource Products', 'Sinkers', 'Syrup', 'Powders', 'Other Supplies', 'Cups and Lids', 'Basic Ingredient'";
    }
    $sql .= " ORDER BY FIELD(category, $category_order_list), name $sort_order";
} else {
    $sql .= " ORDER BY `$sort_by` $sort_order";
}

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

/**
 * Calculates the total content based on stock and content string.
 * e.g., (5, "1L") => "5L", (3, "500g") => "1500g"
 * @param int $stock The stock quantity.
 * @param string $content The content string (e.g., "1L", "500g").
 * @return string The calculated total content string.
 */
function formatTotalContent(?float $total_content_stock, ?string $content): string {
    if ($total_content_stock === null) {
        return 'N/A';
    }

    // Determine the base unit from the original content string
    preg_match('/^(\d*\.?\d+)\s*(.*)$/', $content, $matches);
    $unit_part = strtoupper(trim($matches[2] ?? ''));

    // Define base units
    $base_unit = 'PCS'; // Default to PCS if no unit is specified
    if (in_array($unit_part, ['KG', 'G'])) $base_unit = 'G';
    if (in_array($unit_part, ['L', 'ML'])) $base_unit = 'ML';
    if (in_array($unit_part, ['PCS', 'SACKS', 'ROLL/S'])) $base_unit = $unit_part;

    return number_format($total_content_stock, 2) . ' ' . $base_unit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Product Management</title>
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
                <li><a href="inventory_products.php" class="active">Inventory Items</a></li>
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
            <div class="top-row">
                <h1>Inventory Management</h1>
                <div class="header-actions" style="display:flex; gap:10px; align-items:center;">
                    <button class="btn" id="openAddProduct">+ Add Product</button>
                </div>
            </div>
        </header>

        <div class="filter-bar">
            <form action="inventory_products.php" method="GET" class="filter-form">
                <input type="text" name="search" id="searchInput" placeholder="Search Products..." value="<?= htmlspecialchars($search) ?>">
                <select name="sort_by" id="sortBySelect">
                    <option value="name" <?= $sort_by == 'name' ? 'selected' : '' ?>>Sort by Name</option>
                    <option value="category" <?= $sort_by == 'category' ? 'selected' : '' ?>>Sort by Category</option>
                    <option value="price" <?= $sort_by == 'price' ? 'selected' : '' ?>>Sort by Price</option>
                    <option value="stock" <?= $sort_by == 'stock' ? 'selected' : '' ?>>Sort by Stock</option>
                    <option value="total_content_stock" <?= $sort_by == 'total_content_stock' ? 'selected' : '' ?>>Sort by Total Content</option>
                </select>
                <select name="sort_order" id="sortOrderSelect">
                    <option value="ASC" <?= $sort_order == 'ASC' ? 'selected' : '' ?>>Ascending</option>
                    <option value="DESC" <?= $sort_order == 'DESC' ? 'selected' : '' ?>>Descending</option>
                </select>
                <select name="filter_category" id="categoryFilterSelect" style="display: none;">
                    <option value="">All Categories</option>
                    <option value="Basic Ingredient" <?= $filter_category == 'Basic Ingredient' ? 'selected' : '' ?>>Basic Ingredient</option>
                    <option value="Cups and Lids" <?= $filter_category == 'Cups and Lids' ? 'selected' : '' ?>>Cups and Lids</option>
                    <option value="Other Supplies" <?= $filter_category == 'Other Supplies' ? 'selected' : '' ?>>Other Supplies</option>
                    <option value="Powders" <?= $filter_category == 'Powders' ? 'selected' : '' ?>>Powders</option>
                    <option value="Syrup" <?= $filter_category == 'Syrup' ? 'selected' : '' ?>>Syrup</option>
                    <option value="Sinkers" <?= $filter_category == 'Sinkers' ? 'selected' : '' ?>>Sinkers</option>
                    <option value="Outsource Products" <?= $filter_category == 'Outsource Products' ? 'selected' : '' ?>>Outsource Products</option>
                </select>
                <button type="submit" class="btn">Filter</button>
                <a href="inventory_products.php" class="btn cancel-btn">Clear</a>
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
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Content</th>
                            <th>Total Content (Base Unit)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="productTableBody">
                    <?php if ($result && $result->num_rows): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['id']) ?></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= htmlspecialchars($row['category']) ?></td>
                                <td>₱<?= number_format($row['price'], 2) ?></td>
                                <td><?= htmlspecialchars($row['stock']) ?></td>
                                <td><?= htmlspecialchars($row['content'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars(formatTotalContent($row['total_content_stock'], $row['content'])) ?></td>
                                <td>
                                    <button class="action-btn edit-btn"
                                        data-id="<?= htmlspecialchars($row['id']) ?>"
                                        data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
                                        data-category="<?= htmlspecialchars($row['category'], ENT_QUOTES) ?>"
                                        data-price="<?= htmlspecialchars($row['price']) ?>"
                                        data-stock="<?= htmlspecialchars($row['stock']) ?>"
                                        data-content="<?= htmlspecialchars($row['content'] ?? '', ENT_QUOTES) ?>">
                                        Edit
                                    </button>
                                    <form method="POST" action="delete_product.php?id=<?= htmlspecialchars($row['id']) ?>" style="display:inline;">
                                        <!-- The form action will be handled by JS, but this is a good fallback -->
                                        <input type="hidden" name="delete_product" value="1">
                                        <button type="button" class="action-btn delete-btn">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8">No products found.</td></tr>
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
        <h2 id="addProductTitle">Add New Product</h2>
        <form method="POST" action="inventory_products.php" id="addProductForm">
            <input type="hidden" name="add_product" value="1">
            <label>Product Name</label>
            <input type="text" name="name" required placeholder="e.g., Arabica Beans">
           <label>Category</label>
                <select name="category" required>
                    <option value="" disabled selected>Select a category</option>
                    <option value="Basic Ingredient">Basic Ingredient</option>
                    <option value="Cups and Lids">Cups and Lids</option>
                    <option value="Other Supplies">Other Supplies</option>
                    <option value="Powders">Powders</option>
                    <option value="Syrup">Syrup</option>
                    <option value="Sinkers">Sinkers</option>
                    <option value="Outsource Products">Outsource Products</option>
                </select>

            <label>Price</label>
            <input type="number" step="0.01" name="price" required placeholder="e.g., 150.50">
            <label>Stock</label>
            <input type="number" name="stock" required placeholder="e.g., 100">
            
            <label>Content</label>
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="number" step="any" name="content_value" id="add_content_value" placeholder="e.g., 1.5" style="margin-bottom: 0;">
                <select name="content_unit" id="add_content_unit" required style="margin-bottom: 0;">
                    <option value="" disabled selected>Unit</option>
                    <option value="KG">KG</option>
                    <option value="G">G</option>
                    <option value="ML">ML</option>
                    <option value="L">L</option>
                    <option value="SACKS">SACKS</option>
                    <option value="PCS">PCS</option>
                    <option value="ROLL/S">ROLL/S</option>
                </select>
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
        <h2 id="editProductTitle">Edit Product</h2>
        <form method="POST" action="inventory_products.php" id="editProductForm">
            <input type="hidden" name="edit_product" value="1">
            <input type="hidden" name="edit_id" id="edit_id">

            <label for="edit_name">Product Name</label>
            <input type="text" name="edit_name" id="edit_name" required placeholder="e.g., Arabica Beans">

            <label for="edit_category">Category</label>
            <select name="edit_category" id="edit_category" required>
                <option value="" disabled>Select a category</option>
                <option value="Basic Ingredient">Basic Ingredient</option>
                <option value="Cups and Lids">Cups and Lids</option>
                <option value="Other Supplies">Other Supplies</option>
                <option value="Powders">Powders</option>
                <option value="Syrup">Syrup</option>
                <option value="Sinkers">Sinkers</option>
                <option value="Outsource Products">Outsource Products</option>
            </select>

            <label for="edit_price">Price</label>
            <input type="number" step="0.01" name="edit_price" id="edit_price" required placeholder="e.g., 150.50">

            <label for="edit_stock">Stock</label>
            <input type="number" name="edit_stock" id="edit_stock" required placeholder="e.g., 100">

            <label>Content</label>
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="number" step="any" name="edit_content_value" id="edit_content_value" placeholder="e.g., 1.5" style="margin-bottom: 0;">
                <select name="edit_content_unit" id="edit_content_unit" required style="margin-bottom: 0;">
                    <option value="" disabled selected>Unit</option>
                    <option value="KG">KG</option>
                    <option value="G">G</option>
                    <option value="ML">ML</option>
                    <option value="L">L</option>
                    <option value="SACKS">SACKS</option>
                    <option value="PCS">PCS</option>
                    <option value="ROLL/S">ROLL/S</option>
                </select>
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
    <div class="modal-content" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" style="max-width: 500px;">
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
                const contentValueInput = document.getElementById('edit_content_value');
                const contentUnitSelect = document.getElementById('edit_content_unit');

                // Populate the edit form
                document.getElementById('edit_id').value = btn.dataset.id;
                document.getElementById('edit_name').value = btn.dataset.name;
                document.getElementById('edit_category').value = btn.dataset.category;
                document.getElementById('edit_price').value = btn.dataset.price;
                document.getElementById('edit_stock').value = btn.dataset.stock;

                // Parse the content string (e.g., "500g") into value and unit
                const contentStr = btn.dataset.content || '';
                const match = contentStr.match(/^(\d*\.?\d+)\s*([a-zA-Z\/]+)\s*$/);

                if (match) {
                    const value = match[1];
                    const unit = match[2].toUpperCase();
                    contentValueInput.value = value;
                    
                    // Check if the parsed unit exists in the select options
                    const unitOption = Array.from(contentUnitSelect.options).find(opt => opt.value === unit);
                    if (unitOption) {
                        contentUnitSelect.value = unit;
                    } else {
                        contentUnitSelect.value = ""; // Fallback to default if unit is not found
                    }
                } else {
                    // Clear fields if content is empty or doesn't match
                    contentValueInput.value = '';
                    contentUnitSelect.value = '';
                }
                
                // Show the modal
                editModal.style.display = 'block';
                editModal.setAttribute('aria-hidden', 'false');
            });
        });

        function closeEditModal() {
            editModal.style.display = 'none';
            editModal.setAttribute('aria-hidden', 'true');
        }

        closeEditBtn.addEventListener('click', closeEditModal);
        cancelEditBtn.addEventListener('click', closeEditModal);

        // --- Intercept Form Submissions ---
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            formToSubmit = addForm;
            confirmMessage.textContent = 'Are you sure you want to save this product?';
            confirmModal.style.display = 'block';
        });

        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            formToSubmit = editForm;
            confirmMessage.textContent = 'Are you sure you want to update this product?';
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
            } else if (urlToRedirect) {
                window.location.href = urlToRedirect;
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
        }); // This was missing a closing parenthesis
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
            if (e.key === 'Escape') { closeAddModal(); closeEditModal(); }
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

        function updateSortOrderLabels() {
            const selectedSort = sortBySelect.value;
            const numericColumns = ['price', 'stock', 'total_content_stock'];

            if (selectedSort === 'category') { // When "Sort by Category" is chosen, switch to filter mode
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

        // Update labels on initial page load
        updateSortOrderLabels();
        // Update labels whenever the sort column changes
        sortBySelect.addEventListener('change', updateSortOrderLabels);

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

        // --- Content Unit Logic ---
        function handleUnitChange(valueInput, unitSelect) {
            const selectedUnit = unitSelect.value.toUpperCase();
            const integerUnits = ['SACKS', 'PCS', 'ROLL/S'];

            if (integerUnits.includes(selectedUnit)) {
                valueInput.step = '1'; // Enforce whole numbers
                valueInput.value = parseInt(valueInput.value, 10) || ''; // Remove decimals if any
            } else {
                valueInput.step = 'any'; // Allow decimals
            }
        }

        document.getElementById('add_content_unit').addEventListener('change', () => handleUnitChange(document.getElementById('add_content_value'), document.getElementById('add_content_unit')));
        document.getElementById('edit_content_unit').addEventListener('change', () => handleUnitChange(document.getElementById('edit_content_value'), document.getElementById('edit_content_unit')));

    });

    // --- Live Search Filter ---
    const searchInput = document.getElementById('searchInput');
    const tableBody = document.getElementById('productTableBody');
    const tableRows = tableBody.getElementsByTagName('tr');
    const noResultsRow = tableBody.querySelector('td[colspan="6"]');

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