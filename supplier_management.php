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

$today = date('Y-m-d');

// --- Add Supplier ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supplier'])) {
    $company_name = trim($_POST['company_name']);
    $contact_person = trim($_POST['contact_person']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $restock_schedule = trim($_POST['restock_schedule']);
    $supplied_products_data = $_POST['products'] ?? []; // Array like ['prod_id' => ['selected' => 'on', 'quantity' => '10']]

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO suppliers (company_name, contact_person, email, phone, address, restock_schedule) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $company_name, $contact_person, $email, $phone, $address, $restock_schedule);
        $stmt->execute();
        $new_supplier_id = $conn->insert_id;
        $stmt->close();

        // Insert into the supplier_products table with quantity
        if (!empty($supplied_products_data)) {
            $stmt_link = $conn->prepare("INSERT INTO supplier_products (supplier_id, product_id, default_quantity) VALUES (?, ?, ?)");
            foreach ($supplied_products_data as $product_id => $data) {
                if (isset($data['selected'])) {
                    $quantity = (int)($data['quantity'] ?? 1);
                    $stmt_link->bind_param("isi", $new_supplier_id, $product_id, $quantity);
                    $stmt_link->execute();
                }
            }
            $stmt_link->close();
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }

    echo "<script>
        localStorage.setItem('supplierMessage', 'Supplier added successfully!');
        window.location.href='supplier_management.php';
    </script>";
    exit;
}

// --- Edit Supplier ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_supplier'])) {
    $id = (int)$_POST['edit_id'];
    $company_name = trim($_POST['edit_company_name']);
    $contact_person = trim($_POST['edit_contact_person']);
    $email = trim($_POST['edit_email']);
    $phone = trim($_POST['edit_phone']);
    $address = trim($_POST['edit_address']);
    $restock_schedule = trim($_POST['edit_restock_schedule']);
    $supplied_products_data = $_POST['edit_products'] ?? [];

    $conn->begin_transaction();
    try {
        // Update the main suppliers table
        $stmt = $conn->prepare("UPDATE suppliers SET company_name=?, contact_person=?, email=?, phone=?, address=?, restock_schedule=? WHERE id=?");
        $stmt->bind_param("ssssssi", $company_name, $contact_person, $email, $phone, $address, $restock_schedule, $id);
        $stmt->execute();
        $stmt->close();

        // Update the linked products: delete old ones, then insert new ones
        $stmt_delete = $conn->prepare("DELETE FROM supplier_products WHERE supplier_id = ?");
        $stmt_delete->bind_param("i", $id);
        $stmt_delete->execute();
        $stmt_delete->close();

        if (!empty($supplied_products_data)) {
            $stmt_link = $conn->prepare("INSERT INTO supplier_products (supplier_id, product_id, default_quantity) VALUES (?, ?, ?)");
            foreach ($supplied_products_data as $product_id => $data) {
                if (isset($data['selected'])) {
                    $quantity = (int)($data['quantity'] ?? 1);
                    $stmt_link->bind_param("isi", $id, $product_id, $quantity);
                    $stmt_link->execute();
                }
            }
            $stmt_link->close();
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }

    echo "<script>
        localStorage.setItem('supplierMessage', 'Supplier updated successfully!');
        window.location.href='supplier_management.php';
    </script>";
    exit;
}

// --- Delete Supplier ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_supplier'])) {
    $id = (int)$_POST['delete_id'];
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM suppliers WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    echo "<script>
        localStorage.setItem('supplierMessage', 'Supplier deleted successfully!');
        window.location.href='supplier_management.php';
    </script>";
    exit;
}

// --- Unreceive (Reverse) Delivery ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unreceive_delivery'])) {
    $supplier_id = (int)$_POST['supplier_id'];
    $last_delivery_log_id = (int)$_POST['last_delivery_log_id'];

    $conn->begin_transaction();
    try {
        // 1. Get all items from the last delivery log
        $log_stmt = $conn->prepare("SELECT product_id, quantity_received FROM delivery_logs WHERE id >= ? AND supplier_id = ?");
        $log_stmt->bind_param("ii", $last_delivery_log_id, $supplier_id);
        $log_stmt->execute();
        $delivery_items = $log_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $log_stmt->close();

        // 2. Reverse the stock for each item
        foreach ($delivery_items as $item) {
            // Fetch product details to calculate total content to subtract
            $prod_stmt = $conn->prepare("SELECT content FROM products WHERE id = ?");
            $prod_stmt->bind_param("s", $item['product_id']);
            $prod_stmt->execute();
            $product = $prod_stmt->get_result()->fetch_assoc();
            $prod_stmt->close();

            $content_to_subtract = calculateTotalContentStock($item['quantity_received'], $product['content'] ?? '');

            $update_stmt = $conn->prepare("UPDATE products SET stock = stock - ?, total_content_stock = total_content_stock - ? WHERE id = ?");
            $update_stmt->bind_param("ids", $item['quantity_received'], $content_to_subtract, $item['product_id']);
            $update_stmt->execute();
            $update_stmt->close();
        }

        // 3. Clear the last delivery info from the supplier
        $conn->query("UPDATE suppliers SET last_received_date = NULL, last_delivery_log_id = NULL WHERE id = $supplier_id");

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }
    echo "<script>localStorage.setItem('supplierMessage', 'Delivery reversed and stock restored!'); window.location.href='supplier_management.php';</script>";
    exit;
}

// --- Receive Delivery ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_delivery'])) {
    $supplier_id = (int)$_POST['supplier_id'];
    $quantities = $_POST['quantity']; 

    $conn->begin_transaction();
    try {
        // Get the supplier's scheduled restock date to use for logging
        $supplier_info_stmt = $conn->prepare("SELECT restock_schedule FROM suppliers WHERE id = ?");
        $supplier_info_stmt->bind_param("i", $supplier_id);
        $supplier_info_stmt->execute();
        $supplier_info = $supplier_info_stmt->get_result()->fetch_assoc();
        $supplier_info_stmt->close();

        $delivery_date = $supplier_info['restock_schedule'] ?? date('Y-m-d');

        $first_log_id = null;
        foreach ($quantities as $product_id => $quantity_received) {
            $quantity = (int)$quantity_received;
            if ($quantity > 0) {
                // Fetch product details to calculate total content
                $prod_stmt = $conn->prepare("SELECT content FROM products WHERE id = ?");
                $prod_stmt->bind_param("s", $product_id);
                $prod_stmt->execute();
                $product = $prod_stmt->get_result()->fetch_assoc();
                $prod_stmt->close();

                $content_to_add = calculateTotalContentStock($quantity, $product['content'] ?? '');

                // Update stock and total_content_stock
                $update_stmt = $conn->prepare("UPDATE products SET stock = stock + ?, total_content_stock = total_content_stock + ? WHERE id = ?");
                $update_stmt->bind_param("ids", $quantity, $content_to_add, $product_id);
                $update_stmt->execute();
                $update_stmt->close();

                // Log this specific item delivery using the scheduled date
                $log_stmt = $conn->prepare("INSERT INTO delivery_logs (supplier_id, product_id, quantity_received, delivery_date) VALUES (?, ?, ?, ?)");
                $log_stmt->bind_param("isis", $supplier_id, $product_id, $quantity, $delivery_date);
                $log_stmt->execute();
                if ($first_log_id === null) {
                    $first_log_id = $conn->insert_id;
                }
                $log_stmt->close();
            }
        }
        // Update the last received date (using the scheduled date) and log ID for the supplier
        $conn->query("UPDATE suppliers SET last_received_date = '$delivery_date', last_delivery_log_id = $first_log_id WHERE id = $supplier_id");
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }
    echo "<script>localStorage.setItem('supplierMessage', 'Delivery received and stock updated!'); window.location.href='supplier_management.php';</script>";
    exit;
}

// --- Search & Sort Logic ---
$search = trim($_GET['search'] ?? '');
$sort_by = $_GET['sort_by'] ?? 'restock_schedule';
$sort_order = $_GET['sort_order'] ?? 'ASC';
$sort_columns_whitelist = ['id', 'company_name', 'contact_person', 'email', 'restock_schedule', 'last_received_date'];
if (!in_array($sort_by, $sort_columns_whitelist)) $sort_by = 'restock_schedule';
if (!in_array(strtoupper($sort_order), ['ASC', 'DESC'])) $sort_order = 'ASC';

$sql = "SELECT id, company_name, contact_person, email, phone, address, restock_schedule, last_received_date, last_delivery_log_id FROM suppliers";
$params = [];
$types = '';

if (!empty($search)) {
    $sql .= " WHERE company_name LIKE ? OR contact_person LIKE ? OR email LIKE ?";
    $like = "%$search%";
    $params = [$like, $like, $like];
    $types = 'sss';
}
$sql .= " ORDER BY `$sort_by` $sort_order";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// --- Fetch all inventory products for modals ---
$inventory_products = $conn->query("SELECT id, name FROM products ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

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
    if ($unit === 'KG' || $unit === 'L') {
        return $total * 1000;
    }
    return $total;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Supplier Management</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/modal.css">
<link rel="stylesheet" href="css/extracted_styles.css">
<link rel="icon" type="image/x-icon" href="images/logo.png">
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
            <li><a href="supplier_management.php" class="active">Supplier</a></li>
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
                <h1>Supplier Management</h1>
                <div class="header-actions flex-gap-center">
                    <button class="btn" id="openAddSupplier">+ Add Supplier</button>
                </div>
            </div>
        </header>

        <div class="filter-bar">
            <form method="GET" action="supplier_management.php" class="filter-form">
                <input type="text" name="search" id="searchInput" placeholder="Search Supplier..." value="<?= htmlspecialchars($search) ?>">
                <select name="sort_by" id="sortBySelect">
                    <option value="company_name" <?= $sort_by=='company_name'?'selected':'' ?>>Sort by Company Name</option>
                    <option value="contact_person" <?= $sort_by=='contact_person'?'selected':'' ?>>Sort by Contact Person</option>
                    <option value="restock_schedule" <?= $sort_by=='restock_schedule'?'selected':'' ?>>Sort by Restock Date</option>
                </select>
                <select name="sort_order" id="sortOrderSelect">
                    <option value="ASC" <?= $sort_order=='ASC'?'selected':'' ?>>Ascending</option>
                    <option value="DESC" <?= $sort_order=='DESC'?'selected':'' ?>>Descending</option>
                </select>
                <button type="submit" class="btn">Filter</button>
                <a href="supplier_management.php" class="btn cancel-btn">Clear</a>
            </form>
        </div>

        <section class="box">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Company Name</th>
                            <th>Contact Person</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>Restock Schedule</th>
                            <th>Last Received</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="supplierTableBody">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['company_name']) ?></td>
                                <td><?= htmlspecialchars($row['contact_person']) ?></td>
                                <td><?= htmlspecialchars($row['email']) ?></td>
                                <td><?= htmlspecialchars($row['phone']) ?></td>
                                <td><?= htmlspecialchars($row['address']) ?></td>
                                <td><?= htmlspecialchars($row['restock_schedule']) ?></td>
                                <td><?= $row['last_received_date'] ? htmlspecialchars($row['last_received_date']) : 'N/A' ?></td>
                                <td>
                                    <button class="action-btn edit-btn"
                                        data-id="<?= $row['id'] ?>"
                                        data-company_name="<?= htmlspecialchars($row['company_name'], ENT_QUOTES) ?>"
                                        data-contact_person="<?= htmlspecialchars($row['contact_person'], ENT_QUOTES) ?>"
                                        data-email="<?= htmlspecialchars($row['email'], ENT_QUOTES) ?>"
                                        data-phone="<?= htmlspecialchars($row['phone'], ENT_QUOTES) ?>"
                                        data-address="<?= htmlspecialchars($row['address'], ENT_QUOTES) ?>"
                                        data-restock_schedule="<?= htmlspecialchars($row['restock_schedule'], ENT_QUOTES) ?>">
                                        Edit
                                    </button>
                                    <?php if (!empty($row['last_received_date']) && !empty($row['last_delivery_log_id'])): ?>
                                        <div class="unreceive-container">
                                            <button class="action-btn received">Received</button>
                                            <button class="action-btn unreceive-btn" data-id="<?= $row['id'] ?>" data-log-id="<?= $row['last_delivery_log_id'] ?>" data-company_name="<?= htmlspecialchars($row['company_name'], ENT_QUOTES) ?>">Unreceive</button>
                                        </div>
                                    <?php else: ?>
                                        <button class="action-btn receive-btn" data-id="<?= $row['id'] ?>" data-company_name="<?= htmlspecialchars($row['company_name'], ENT_QUOTES) ?>">Receive</button>
                                    <?php endif; ?>
                                    <form method="POST" action="supplier_management.php" class="inline">
                                        <input type="hidden" name="delete_supplier" value="1">
                                        <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                        <button type="button" class="action-btn delete-btn delete-confirm">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8">No suppliers found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- ✅ Add Supplier Modal -->
<div class="modal" id="addSupplierModal">
    <div class="modal-content">
        <span class="close" id="closeAddModal">&times;</span>
        <h2>Add Supplier</h2>

        <form method="POST" action="supplier_management.php" id="addForm">
            <input type="hidden" name="add_supplier" value="1">

            <label>Company Name</label>
            <input type="text" name="company_name" required placeholder="e.g., Brew & Co. Supplies">

            <label>Contact Person</label>
            <input type="text" name="contact_person" required placeholder="e.g., John Doe">

            <label>Suplier Email</label>
            <input type="email" name="email" required placeholder="e.g., contact@brewco.com">

            <label>Phone Number</label>
            <input type="text" name="phone" id="add_phone" required placeholder="e.g., 09123456789" pattern="09\d{9}" title="Phone number must be 11 digits and start with 09.">

            <label>Address</label>
            <input type="text" name="address" required placeholder="e.g., 123 Coffee St, Bean Town">

            <label>Restocking Schedule</label>
            <input type="date" name="restock_schedule" required min="<?= $today ?>">

            <label class="mt-15">Products Supplied</label>
            <div class="product-list">
                <?php foreach ($inventory_products as $product): ?>
                    <div class="product-item">
                        <input type="checkbox" name="products[<?= htmlspecialchars($product['id']) ?>][selected]" id="add_prod_<?= htmlspecialchars($product['id']) ?>" class="product-checkbox">
                        <label for="add_prod_<?= htmlspecialchars($product['id']) ?>"><?= htmlspecialchars($product['name']) ?></label>
                        <input type="number" name="products[<?= htmlspecialchars($product['id']) ?>][quantity]" min="1" value="1" class="product-quantity" disabled>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="form-actions">
                <button type="submit">Save Supplier</button>
                <button type="button" class="cancel-btn" id="cancelAdd">Cancel</button>
            </div>
        </form>
    </div>
</div>



<!-- ✅ Edit Supplier Modal -->
<div class="modal" id="editSupplierModal">
    <div class="modal-content">
        <span class="close" id="closeEditModal">&times;</span>
        <h2>Edit Supplier</h2>

        <form method="POST" action="supplier_management.php" id="editForm">
            <input type="hidden" name="edit_supplier" value="1">
            <input type="hidden" name="edit_id" id="edit_id">

            <label>Company Name</label>
            <input type="text" name="edit_company_name" id="edit_company_name" required placeholder="e.g., Brew & Co. Supplies">

            <label>Contact Person</label>
            <input type="text" name="edit_contact_person" id="edit_contact_person" required placeholder="e.g., John Doe">

            <label>Supplier Email</label>
            <input type="email" name="edit_email" id="edit_email" required placeholder="e.g., contact@brewco.com">

            <label>Phone Number</label>
            <input type="text" name="edit_phone" id="edit_phone" required placeholder="e.g., 09123456789" pattern="09\d{9}" title="Phone Number must be 11 digits and start with 09.">

            <label>Address</label>
            <input type="text" name="edit_address" id="edit_address" required placeholder="e.g., 123 Coffee St, Bean Town">

            <label>Restocking Schedule</label>
            <input type="date" name="edit_restock_schedule" id="edit_restock_schedule" required min="<?= $today ?>">

            <label class="mt-15">Products Supplied</label>
            <div class="product-list" id="edit_product_list">
                <?php foreach ($inventory_products as $product): ?>
                    <div class="product-item">
                        <input type="checkbox" name="edit_products[<?= htmlspecialchars($product['id']) ?>][selected]" id="edit_prod_<?= htmlspecialchars($product['id']) ?>" class="product-checkbox">
                        <label for="edit_prod_<?= htmlspecialchars($product['id']) ?>"><?= htmlspecialchars($product['name']) ?></label>
                        <input type="number" name="edit_products[<?= htmlspecialchars($product['id']) ?>][quantity]" min="1" value="1" class="product-quantity" disabled>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="form-actions">
                <button type="submit">Update Supplier</button>
                <button type="button" class="cancel-btn" id="cancelEdit">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Receive Delivery Modal -->
<div class="modal" id="receiveDeliveryModal">
    <div class="modal-content">
        <span class="close" id="closeReceiveModal">&times;</span>
        <h2 id="receiveModalTitle">Receive Delivery</h2>
        <form method="POST" action="supplier_management.php" id="receiveForm">
            <input type="hidden" name="receive_delivery" value="1">
            <input type="hidden" name="supplier_id" id="receive_supplier_id">
            <div id="receive_product_list">
                <!-- Product inputs will be dynamically inserted here -->
            </div>
        </form>
    </div>
</div>


<!-- Generic Confirmation Modal -->
<div class="modal" id="confirmModal">
    <div class="modal-content">
        <span class="close" id="closeConfirmModal">&times;</span>
        <h2>Please Confirm</h2>
        <p id="confirmMessage"></p>
        <div class="form-actions">
            <button type="button" class="confirm-btn-yes" id="confirmYesBtn">Confirm</button>
            <button type="button" class="cancel-btn" id="confirmCancelBtn">Cancel</button>
        </div>
    </div>
</div>

<!-- ✅ Toast Message -->
<div id="toast" class="toast"></div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const addModal = document.getElementById("addSupplierModal");
    const editModal = document.getElementById("editSupplierModal");
    const confirmModal = document.getElementById("confirmModal");
    const receiveModal = document.getElementById("receiveDeliveryModal");
    const toast = document.getElementById("toast");

    const openAdd = document.getElementById("openAddSupplier");
    const closeAdd = document.getElementById("closeAddModal");
    const cancelAdd = document.getElementById("cancelAdd");
    const closeEdit = document.getElementById("closeEditModal");
    const cancelEdit = document.getElementById("cancelEdit");
    const closeReceive = document.getElementById("closeReceiveModal");
    const addPhoneInput = document.getElementById("add_phone");
    const editPhoneInput = document.getElementById("edit_phone");

    // New generic modal elements
    const confirmMsg = document.getElementById("confirmMessage");
    const confirmYesBtn = document.getElementById("confirmYesBtn");
    document.getElementById('closeConfirmModal').addEventListener('click', () => confirmModal.style.display = "none");
    document.getElementById('confirmCancelBtn').addEventListener('click', () => confirmModal.style.display = "none");

    openAdd.onclick = () => addModal.style.display = "block";
    [closeAdd, cancelAdd].forEach(btn => btn.onclick = () => addModal.style.display = "none");
    [closeEdit, cancelEdit].forEach(btn => btn.onclick = () => editModal.style.display = "none");
    if(closeReceive) closeReceive.onclick = () => receiveModal.style.display = "none";

    // Edit Supplier Modal
    document.querySelectorAll(".edit-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            // Populate main form fields
            document.getElementById("edit_id").value = btn.dataset.id;
            document.getElementById("edit_company_name").value = btn.dataset.company_name;
            document.getElementById("edit_contact_person").value = btn.dataset.contact_person;
            document.getElementById("edit_email").value = btn.dataset.email;
            document.getElementById("edit_phone").value = btn.dataset.phone;
            document.getElementById("edit_address").value = btn.dataset.address;
            document.getElementById("edit_restock_schedule").value = btn.dataset.restock_schedule;

            // --- Fetch and check the products for this supplier ---
            const supplierId = btn.dataset.id;
            const productItems = document.querySelectorAll('#edit_product_list .product-item');
            
            // Reset all checkboxes and quantity inputs
            productItems.forEach(item => {
                const checkbox = item.querySelector('.product-checkbox');
                const quantityInput = item.querySelector('.product-quantity');
                checkbox.checked = false;
                quantityInput.value = '1';
                quantityInput.disabled = true;
            });

            // Use AJAX to get the products for the supplier
            fetch(`api_get_supplier_products.php?supplier_id=${supplierId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.products_details) {
                        data.products_details.forEach(prod => {
                            const checkbox = document.querySelector(`#edit_product_list input[name="edit_products[${prod.id}][selected]"]`);
                            const quantityInput = document.querySelector(`#edit_product_list input[name="edit_products[${prod.id}][quantity]"]`);
                            if (checkbox && quantityInput) {
                                checkbox.checked = true;
                                quantityInput.value = prod.default_quantity;
                                quantityInput.disabled = false;
                            }
                        });
                    }
                })
                .catch(error => console.error('Error fetching supplier products:', error));

            editModal.style.display = "block";
        });
    });

    // Receive Delivery Modal
    document.querySelectorAll(".receive-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            const supplierId = btn.dataset.id;
            const companyName = btn.dataset.company_name;

            document.getElementById("receive_supplier_id").value = supplierId;
            document.getElementById("receiveModalTitle").textContent = `Receive from: ${companyName}`;
            const productListDiv = document.getElementById("receive_product_list");
            productListDiv.innerHTML = '<p>Loading products...</p>'; // Loading state

            fetch(`api_get_supplier_products.php?supplier_id=${supplierId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.products_details) {
                        let formHtml = '';
                        data.products_details.forEach(prod => { // Pre-fill with default_quantity
                            formHtml += `<div><label>${prod.name}</label><input type="number" name="quantity[${prod.id}]" min="0" value="${prod.default_quantity}" placeholder="Enter quantity received"></div>`;
                        });
                        formHtml += '<div class="form-actions"><button type="submit">Confirm Delivery</button></div>';
                        productListDiv.innerHTML = formHtml;
                    } else {
                        productListDiv.innerHTML = '<p>No products are assigned to this supplier. Please edit the supplier to add products.</p>';
                    }
                    receiveModal.style.display = "block";
                });
        });
    });

    // Unreceive (Reverse) Delivery Confirmation
    document.querySelectorAll(".unreceive-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            const supplierId = btn.dataset.id;
            const logId = btn.dataset.logId;
            const companyName = btn.dataset.company_name;

            confirmMsg.innerHTML = `Are you sure you want to <strong>reverse the last delivery</strong> from <strong>${companyName}</strong>?<br><br>This will subtract the received quantities from your inventory.`;
            confirmYesBtn.textContent = 'Yes, Reverse';
            confirmModal.style.display = "block";
            confirmYesBtn.onclick = () => {
                // Create a form on the fly and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'supplier_management.php';
                form.innerHTML = `<input type="hidden" name="unreceive_delivery" value="1"><input type="hidden" name="supplier_id" value="${supplierId}"><input type="hidden" name="last_delivery_log_id" value="${logId}">`;
                document.body.appendChild(form);
                form.submit();
            };
        });
    });

    // Confirmation for Add
    document.getElementById("addForm").addEventListener("submit", (e) => {
        e.preventDefault();
        let message = 'Are you sure you want to add this supplier?';
        const selectedProducts = e.target.querySelectorAll('.product-checkbox:checked');
        
            if (selectedProducts.length > 0) {
            message += '<br><br><strong>Products to be supplied:</strong><ul class="products-list-inline">';
            selectedProducts.forEach(checkbox => {
                const itemDiv = checkbox.closest('.product-item');
                const label = itemDiv.querySelector('label').textContent;
                const quantity = itemDiv.querySelector('.product-quantity').value;
                message += `<li>${label} (Qty: ${quantity})</li>`;
            });
            message += '</ul>';
        }

        confirmMsg.innerHTML = message;
        confirmYesBtn.textContent = 'Yes, Add';
        confirmModal.style.display = "block";
        confirmYesBtn.onclick = () => e.target.submit();
    });

    // Confirmation for Edit
    document.getElementById("editForm").addEventListener("submit", (e) => {
        e.preventDefault();
        let message = 'Are you sure you want to update this supplier?';
        const selectedProducts = e.target.querySelectorAll('.product-checkbox:checked');
        
            if (selectedProducts.length > 0) {
            message += '<br><br><strong>New list of supplied products:</strong><ul class="products-list-inline">';
            selectedProducts.forEach(checkbox => {
                const itemDiv = checkbox.closest('.product-item');
                const label = itemDiv.querySelector('label').textContent;
                const quantity = itemDiv.querySelector('.product-quantity').value;
                message += `<li>${label} (Qty: ${quantity})</li>`;
            });
            message += '</ul>';
        } else {
            message += '<br><br><strong>Note:</strong> No products will be assigned to this supplier.';
        }

        confirmMsg.innerHTML = message;
        confirmYesBtn.textContent = 'Yes, Update';
        confirmModal.style.display = "block";
        confirmYesBtn.onclick = () => e.target.submit();
    });

    // Confirmation for Receive Delivery
    document.getElementById("receiveForm").addEventListener("submit", (e) => {
        e.preventDefault();
        confirmMsg.textContent = "Are you sure you want to confirm this delivery? Stock levels will be updated.";
        confirmYesBtn.textContent = 'Yes, Confirm';
        confirmModal.style.display = "block";
        confirmYesBtn.onclick = () => e.target.submit();
    });

    // Confirmation for Delete
    document.querySelectorAll(".delete-confirm").forEach(btn => {
        btn.addEventListener("click", (e) => {
            const form = e.target.closest("form");
            confirmMsg.textContent = "Are you sure you want to Delete this supplier?";
            confirmYesBtn.textContent = 'Yes, Delete';
            confirmModal.style.display = "block";
            confirmYesBtn.onclick = () => form.submit();
        });
    });

    // --- Phone Number Validation ---
    function validatePhone(inputElement) {
        const phone = inputElement.value;
        // The field is optional, so we only validate if it's not empty.
        if (phone && !/^09\d{9}$/.test(phone)) {
            inputElement.setCustomValidity("Phone Number must be 11 digits and start with 09.");
        } else {
            inputElement.setCustomValidity(""); // Valid
        }
    }

    // Add event listeners to validate on input
    addPhoneInput.addEventListener('input', () => validatePhone(addPhoneInput));
    editPhoneInput.addEventListener('input', () => validatePhone(editPhoneInput));

    // --- Dynamic Sort Order Labels ---
    const sortBySelect = document.getElementById('sortBySelect');
    const sortOrderSelect = document.getElementById('sortOrderSelect');
    const ascOption = sortOrderSelect.querySelector('option[value="ASC"]');
    const descOption = sortOrderSelect.querySelector('option[value="DESC"]');

    function updateSortLabels() {
        const selectedSort = sortBySelect.value;

        if (selectedSort === 'restock_schedule') {
            ascOption.textContent = 'Present to Future';
            descOption.textContent = 'Future to Present';
        } else { // For text-based columns like company_name, contact_person
            ascOption.textContent = 'A-Z';
            descOption.textContent = 'Z-A';
        }
    }

    // Update labels on initial page load and when the sort column changes
    if (sortBySelect && sortOrderSelect) {
        updateSortLabels();
        sortBySelect.addEventListener('change', updateSortLabels);
    }

    // --- Logout Confirmation ---
    const logoutLink = document.querySelector('a[href="logout.php"]');
    logoutLink.addEventListener('click', function(e) {
        e.preventDefault();
        confirmYesBtn.className = 'confirm-btn-yes'; // Reset classes first
        confirmMsg.textContent = 'Are you sure you want to log out?';
        confirmModal.style.display = 'block';
        confirmYesBtn.textContent = 'Yes, Logout';
        confirmYesBtn.classList.add('btn-logout-yes');
        confirmYesBtn.onclick = function() {
            window.location.href = 'logout.php';
        };
    });

    // --- Live Search Filter ---
    const searchInput = document.getElementById('searchInput');
    const tableBody = document.getElementById('supplierTableBody');
    const tableRows = tableBody.getElementsByTagName('tr');

    searchInput.addEventListener('keyup', function() {
        const searchTerm = searchInput.value.toLowerCase();

        for (let i = 0; i < tableRows.length; i++) {
            const row = tableRows[i];
            const rowText = row.textContent.toLowerCase();

            if (rowText.includes(searchTerm)) {
                row.style.display = ''; // Show row
            } else {
                row.style.display = 'none'; // Hide row
            }
        }
    });


    // ✅ Toast Success Message
    const msg = localStorage.getItem("supplierMessage");
    if (msg) {
        toast.textContent = msg;
        toast.classList.add("show");
        setTimeout(() => toast.classList.remove("show"), 3000);
        localStorage.removeItem("supplierMessage");
    }

    // --- Logic for enabling/disabling quantity inputs ---
    function handleProductCheckboxChange(event) {
        const checkbox = event.target;
        const quantityInput = checkbox.closest('.product-item').querySelector('.product-quantity');
        if (quantityInput) {
            quantityInput.disabled = !checkbox.checked;
        }
    }

    document.querySelectorAll('.product-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', handleProductCheckboxChange);
    });

    // Close modals when clicking outside
    window.onclick = (e) => {
        if (e.target === addModal) addModal.style.display = "none";
        if (e.target === editModal) editModal.style.display = "none";
        if (e.target === receiveModal) receiveModal.style.display = "none";
        if (e.target === confirmModal) confirmModal.style.display = "none";
    };
});
</script>
</body>
</html>
