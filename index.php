<?php
session_start();
include "includes/db.php";

// Initialize cart if it doesn't exist
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Handle Add to Cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $product_id = $_POST['product_id'];
    $product_name = $_POST['product_name'];
    $size = $_POST['size'];
    $price = (float)$_POST['price'];
    $quantity = (int)$_POST['quantity'];

    $cart_item_id = $product_id . '_' . $size;

    if (isset($_SESSION['cart'][$cart_item_id])) {
        $_SESSION['cart'][$cart_item_id]['quantity'] += $quantity;
    } else {
        $_SESSION['cart'][$cart_item_id] = [
            'product_id' => $product_id,
            'product_name' => $product_name,
            'size' => $size,
            'price' => $price,
            'quantity' => $quantity
        ];
    }

    header('Location: index.php#cart');
    exit;
}

// Handle Remove from Cart
if (isset($_GET['remove_from_cart'])) {
    $cart_item_id = $_GET['remove_from_cart'];
    unset($_SESSION['cart'][$cart_item_id]);
    header('Location: index.php#cart');
    exit;
}

// Handle Update Cart Quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    foreach ($_POST['quantity'] as $cart_item_id => $quantity) {
        $quantity = (int)$quantity;
        if ($quantity > 0) {
            $_SESSION['cart'][$cart_item_id]['quantity'] = $quantity;
        } else {
            unset($_SESSION['cart'][$cart_item_id]);
        }
    }
    header('Location: index.php#cart');
    exit;
}

// Handle Place Order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $customer_name = trim($_POST['customer_name']);
    $customer_contact = trim($_POST['customer_contact']);
    $order_notes = trim($_POST['order_notes'] ?? '');

    if (!empty($customer_name) && !empty($_SESSION['cart'])) {
        $conn->begin_transaction();
        try {
            // Calculate total
            $total_amount = 0;
            foreach ($_SESSION['cart'] as $item) {
                $total_amount += $item['price'] * $item['quantity'];
            }

            // Insert into sales table for each cart item
            $stmt = $conn->prepare("INSERT INTO sales (product_id, size, quantity, price, total_amount, sale_date, customer_name, customer_contact, order_notes) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)");
            
            foreach ($_SESSION['cart'] as $item) {
                $item_total = $item['price'] * $item['quantity'];
                $stmt->bind_param("ssiddss", 
                    $item['product_id'], 
                    $item['size'], 
                    $item['quantity'], 
                    $item['price'], 
                    $item_total,
                    $customer_name,
                    $customer_contact,
                    $order_notes
                );
                $stmt->execute();
            }
            $stmt->close();

            $conn->commit();

            // Clear cart
            $_SESSION['cart'] = [];
            $_SESSION['order_success'] = "Thank you! Your order has been placed successfully. Order for: $customer_name";

            header('Location: index.php#order-success');
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error placing order. Please try again.";
        }
    } else {
        $error_message = "Please provide your name and add items to cart.";
    }
}

// Fetch menu products
$filter_category = trim($_GET['category'] ?? '');
$search = trim($_GET['search'] ?? '');

$sql = "SELECT product_id, product_name, category, price_small, price_medium, price_large FROM menu_product WHERE product_type = 'finished_drink'";
$where_clauses = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_clauses[] = "(product_name LIKE ? OR category LIKE ?)";
    $like_search = "%" . $search . "%";
    $params = array_merge($params, [$like_search, $like_search]);
    $types .= 'ss';
}

if (!empty($filter_category)) {
    $where_clauses[] = "category = ?";
    $params[] = $filter_category;
    $types .= 's';
}

if (!empty($where_clauses)) {
    $sql .= " AND " . implode(' AND ', $where_clauses);
}

$sql .= " ORDER BY category ASC, product_name ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$menu_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get categories for filter
$categories = $conn->query("SELECT DISTINCT category FROM menu_product WHERE product_type = 'finished_drink' ORDER BY category ASC")->fetch_all(MYSQLI_ASSOC);

// Calculate cart total
$cart_total = 0;
$cart_count = 0;
foreach ($_SESSION['cart'] as $item) {
    $cart_total += $item['price'] * $item['quantity'];
    $cart_count += $item['quantity'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bigger Brew - Order Online</title>
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/landing.css">
</head>
<body class="landing-page">
    <!-- Header -->
    <header class="landing-header">
        <div class="header-container">
            <div class="logo-section">
                <img src="images/logo.png" alt="Bigger Brew Logo" class="header-logo">
                <h1>Bigger Brew</h1>
            </div>
            <nav class="header-nav">
                <a href="#menu" class="nav-link">Menu</a>
                <a href="#cart" class="nav-link cart-link">
                    Cart 
                    <?php if ($cart_count > 0): ?>
                        <span class="cart-badge"><?= $cart_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="login.php" class="nav-link">Staff Login</a>
            </nav>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <h2>Welcome to Bigger Brew</h2>
            <p>Freshly brewed coffee and delicious drinks, made just for you</p>
            <a href="#menu" class="btn btn-primary btn-large">Order Now</a>
        </div>
    </section>

    <!-- Order Success Message -->
    <?php if (isset($_SESSION['order_success'])): ?>
        <div class="success-banner" id="order-success">
            <div class="container">
                <p><?= htmlspecialchars($_SESSION['order_success']) ?></p>
            </div>
        </div>
        <?php unset($_SESSION['order_success']); ?>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="error-banner">
            <div class="container">
                <p><?= htmlspecialchars($error_message) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Menu Section -->
    <section class="menu-section" id="menu">
        <div class="container">
            <h2 class="section-title">Our Menu</h2>

            <!-- Filter Bar -->
            <div class="menu-filters">
                <form method="GET" action="index.php#menu" class="filter-form">
                    <input type="text" name="search" placeholder="Search drinks..." value="<?= htmlspecialchars($search) ?>" class="search-input">
                    <select name="category" class="category-select" onchange="this.form.submit()">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat['category']) ?>" <?= $filter_category == $cat['category'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['category']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary">Filter</button>
                    <?php if (!empty($search) || !empty($filter_category)): ?>
                        <a href="index.php#menu" class="btn btn-outline">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Menu Items Grid -->
            <div class="menu-grid">
                <?php if (empty($menu_items)): ?>
                    <p class="no-items">No items found.</p>
                <?php else: ?>
                    <?php foreach ($menu_items as $item): ?>
                        <div class="menu-item">
                            <div class="item-header">
                                <h4><?= htmlspecialchars($item['product_name']) ?></h4>
                                <span class="item-category"><?= htmlspecialchars($item['category']) ?></span>
                            </div>
                            <div class="item-prices">
                                <?php if ($item['price_small']): ?>
                                    <div class="price-option">
                                        <span class="size-label">16oz</span>
                                        <span class="price">₱<?= number_format($item['price_small'], 2) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($item['price_medium']): ?>
                                    <div class="price-option">
                                        <span class="size-label">22oz</span>
                                        <span class="price">₱<?= number_format($item['price_medium'], 2) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($item['price_large']): ?>
                                    <div class="price-option">
                                        <span class="size-label">1L</span>
                                        <span class="price">₱<?= number_format($item['price_large'], 2) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button class="btn btn-add-to-cart" onclick="openAddToCartModal('<?= htmlspecialchars($item['product_id']) ?>', '<?= htmlspecialchars(addslashes($item['product_name'])) ?>', <?= $item['price_small'] ?? 0 ?>, <?= $item['price_medium'] ?? 0 ?>, <?= $item['price_large'] ?? 0 ?>)">
                                Add to Cart
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Cart Section -->
    <section class="cart-section" id="cart">
        <div class="container">
            <h2 class="section-title">Your Cart</h2>

            <?php if (empty($_SESSION['cart'])): ?>
                <p class="empty-cart">Your cart is empty. <a href="#menu">Browse our menu</a></p>
            <?php else: ?>
                <form method="POST" action="index.php" class="cart-form">
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Size</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th>Subtotal</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($_SESSION['cart'] as $cart_item_id => $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                                    <td><?= htmlspecialchars($item['size']) ?></td>
                                    <td>₱<?= number_format($item['price'], 2) ?></td>
                                    <td>
                                        <input type="number" name="quantity[<?= $cart_item_id ?>]" value="<?= $item['quantity'] ?>" min="1" class="quantity-input">
                                    </td>
                                    <td>₱<?= number_format($item['price'] * $item['quantity'], 2) ?></td>
                                    <td>
                                        <a href="index.php?remove_from_cart=<?= urlencode($cart_item_id) ?>#cart" class="btn-remove">Remove</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="cart-total">
                                <td colspan="4"><strong>Total:</strong></td>
                                <td colspan="2"><strong>₱<?= number_format($cart_total, 2) ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="cart-actions">
                        <button type="submit" name="update_cart" class="btn btn-secondary">Update Cart</button>
                        <button type="button" onclick="document.getElementById('checkoutModal').style.display='block'" class="btn btn-primary">Proceed to Checkout</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <!-- Add to Cart Modal -->
    <div id="addToCartModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeAddToCartModal()">&times;</span>
            <h2>Add to Cart</h2>
            <form method="POST" action="index.php" id="addToCartForm">
                <input type="hidden" name="add_to_cart" value="1">
                <input type="hidden" name="product_id" id="modal_product_id">
                <input type="hidden" name="product_name" id="modal_product_name">
                <input type="hidden" name="price" id="modal_price">

                <h3 id="modal_product_title"></h3>

                <div class="form-group">
                    <label>Select Size:</label>
                    <div class="size-options" id="size_options">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>

                <div class="form-group">
                    <label for="modal_quantity">Quantity:</label>
                    <input type="number" name="quantity" id="modal_quantity" value="1" min="1" required class="quantity-input">
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Add to Cart</button>
                    <button type="button" onclick="closeAddToCartModal()" class="btn btn-outline">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Checkout Modal -->
    <div id="checkoutModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="document.getElementById('checkoutModal').style.display='none'">&times;</span>
            <h2>Checkout</h2>
            <form method="POST" action="index.php">
                <input type="hidden" name="place_order" value="1">

                <div class="form-group">
                    <label for="customer_name">Name: *</label>
                    <input type="text" name="customer_name" id="customer_name" required>
                </div>

                <div class="form-group">
                    <label for="customer_contact">Contact Number:</label>
                    <input type="tel" name="customer_contact" id="customer_contact" placeholder="09xx-xxx-xxxx">
                </div>

                <div class="form-group">
                    <label for="order_notes">Order Notes (optional):</label>
                    <textarea name="order_notes" id="order_notes" rows="3" placeholder="Any special instructions..."></textarea>
                </div>

                <div class="order-summary">
                    <h3>Order Summary</h3>
                    <p><strong>Total Items:</strong> <?= $cart_count ?></p>
                    <p><strong>Total Amount:</strong> ₱<?= number_format($cart_total, 2) ?></p>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Place Order</button>
                    <button type="button" onclick="document.getElementById('checkoutModal').style.display='none'" class="btn btn-outline">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="landing-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> Bigger Brew. All rights reserved.</p>
            <p><a href="login.php">Staff Login</a></p>
        </div>
    </footer>

    <script>
        // Add to Cart Modal
        function openAddToCartModal(productId, productName, priceSmall, priceMedium, priceLarge) {
            document.getElementById('modal_product_id').value = productId;
            document.getElementById('modal_product_name').value = productName;
            document.getElementById('modal_product_title').textContent = productName;

            const sizeOptions = document.getElementById('size_options');
            sizeOptions.innerHTML = '';

            if (priceSmall > 0) {
                sizeOptions.innerHTML += `
                    <label class="size-option">
                        <input type="radio" name="size" value="16oz" data-price="${priceSmall}" required>
                        <span>16oz - ₱${priceSmall.toFixed(2)}</span>
                    </label>`;
            }
            if (priceMedium > 0) {
                sizeOptions.innerHTML += `
                    <label class="size-option">
                        <input type="radio" name="size" value="22oz" data-price="${priceMedium}" required>
                        <span>22oz - ₱${priceMedium.toFixed(2)}</span>
                    </label>`;
            }
            if (priceLarge > 0) {
                sizeOptions.innerHTML += `
                    <label class="size-option">
                        <input type="radio" name="size" value="1L" data-price="${priceLarge}" required>
                        <span>1L - ₱${priceLarge.toFixed(2)}</span>
                    </label>`;
            }

            // Set price when size is selected
            const sizeInputs = sizeOptions.querySelectorAll('input[name="size"]');
            sizeInputs.forEach(input => {
                input.addEventListener('change', function() {
                    document.getElementById('modal_price').value = this.dataset.price;
                });
            });

            // Select first option by default
            if (sizeInputs.length > 0) {
                sizeInputs[0].checked = true;
                document.getElementById('modal_price').value = sizeInputs[0].dataset.price;
            }

            document.getElementById('addToCartModal').style.display = 'block';
        }

        function closeAddToCartModal() {
            document.getElementById('addToCartModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addToCartModal');
            const checkoutModal = document.getElementById('checkoutModal');
            if (event.target == addModal) {
                closeAddToCartModal();
            }
            if (event.target == checkoutModal) {
                checkoutModal.style.display = 'none';
            }
        }

        // Auto-hide success banner
        document.addEventListener('DOMContentLoaded', function() {
            const successBanner = document.querySelector('.success-banner');
            if (successBanner) {
                setTimeout(() => {
                    successBanner.style.opacity = '0';
                    setTimeout(() => successBanner.remove(), 500);
                }, 5000);
            }
        });
    </script>
</body>
</html>
