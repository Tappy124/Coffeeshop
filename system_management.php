<?php
session_start();

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- Authentication Check ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit;
}

include "includes/db.php";

// --- Add User ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $name = trim($_POST['name']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $role = trim($_POST['role']);
    $status = 'Active';

    if (!empty($name) && !empty($username) && !empty($password) && !empty($role) && $password === $confirm_password) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO staff (name, username, password, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $name, $username, $hashed_password, $role, $status);
        $stmt->execute();
        $stmt->close();

        echo "<script>localStorage.setItem('systemMessage','User added successfully!');window.location.href='system_management.php';</script>";
        exit;
    }
}

// --- Edit User ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $id = (int)$_POST['edit_id'];
    $name = trim($_POST['edit_name']);
    $username = trim($_POST['edit_username']);
    $role = trim($_POST['edit_role']);
    $status = trim($_POST['edit_status']);

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE staff SET name=?, username=?, role=?, status=? WHERE id=?");
        $stmt->bind_param("ssssi", $name, $username, $role, $status, $id);
        $stmt->execute();
        $stmt->close();
        echo "<script>localStorage.setItem('systemMessage','User updated successfully!');window.location.href='system_management.php';</script>";
        exit;
    }
}

// --- Deactivate ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_user'])) {
    $id = (int)$_POST['deactivate_id'];
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE staff SET status='Inactive' WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        if ($id === (int)$_SESSION['user_id']) {
            echo "<script>localStorage.setItem('systemMessage','Your account has been set to Inactive. Logging out...');setTimeout(()=>window.location.href='logout.php',1500);</script>";
            exit;
        }
    }
    echo "<script>localStorage.setItem('systemMessage','User status set to Inactive!');window.location.href='system_management.php';</script>";
    exit;
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $id = (int)$_POST['delete_id'];
    if ($id > 0 && $id !== (int)$_SESSION['user_id']) {
        $stmt = $conn->prepare("DELETE FROM staff WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    echo "<script>localStorage.setItem('systemMessage','User permanently deleted!');window.location.href='system_management.php';</script>";
    exit;
}

// --- Search & Sort ---
$search = trim($_GET['search'] ?? '');
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'ASC';
$sort_columns_whitelist = ['id','name','username','role','status'];
if (!in_array($sort_by,$sort_columns_whitelist)) $sort_by='name';
if (!in_array(strtoupper($sort_order),['ASC','DESC'])) $sort_order='ASC';

$sql = "SELECT * FROM staff";
$params = [];
$types = '';
if (!empty($search)) { $sql .= " WHERE name LIKE ? OR username LIKE ? OR role LIKE ?"; $like = "%$search%"; $params = [$like,$like,$like]; $types='sss'; }
$sql .= " ORDER BY `$sort_by` $sort_order";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types,...$params);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>System Management</title>
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
            <li><a href="supplier_management.php">Supplier</a></li>
            <li><a href="sales_and_waste.php">Sales & Waste</a></li>
            <li><a href="reports_and_analytics.php">Reports & Analytics</a></li>
            <li><a href="admin_forecasting.php">Forecasting</a></li>
            <li><a href="system_management.php" class="active">System Management</a></li>
            <li><a href="user_account.php">My Account</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </aside>

    <main class="main">
        <header>
            <div class="top-row">
                <h1>System Management</h1>
                <div class="header-actions flex-gap-center">
                    <button class="btn add-btn" id="addUserBtn">+ Add New User</button>
                </div>
            </div>
        </header>

        <div class="filter-bar">
            <div class="filter-form">
                <input type="text" id="searchInput" placeholder="Search users...">
            </div>
        </div>

        <div class="content-wrapper content-scroll">
            <section class="box">
                <h2>User Management</h2>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['id']) ?></td>
                                        <td><?= htmlspecialchars($row['name']) ?></td>
                                        <td><?= htmlspecialchars($row['username']) ?></td>
                                        <td><?= htmlspecialchars($row['role']) ?></td>
                                        <td>
                                            <span class="status-badge <?= $row['status'] === 'Active' ? 'status-active' : 'status-inactive' ?>">
                                                <?= htmlspecialchars($row['status']) ?>
                                            </span>
                                        </td>
                                        <td class="action-buttons">
                                            <button class="action-btn edit-btn"
                                                data-id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['name']) ?>"
                                                data-username="<?= htmlspecialchars($row['username']) ?>"
                                                data-role="<?= htmlspecialchars($row['role']) ?>"
                                                data-status="<?= htmlspecialchars($row['status']) ?>">
                                                Edit
                                            </button>
                                            <?php if ($row['status'] === 'Active'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="deactivate_id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="deactivate_user" value="1">
                                                    <button type="button" class="action-btn deactivate-btn">Deactivate</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ((int)$row['id'] !== (int)$_SESSION['user_id']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="delete_user" value="1">
                                                    <button type="button" class="action-btn delete-btn">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="text-align:center;">No users found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>

<!-- Add User Modal -->
<div class="modal" id="addUserModal">
    <div class="modal-content">
        <span class="close" id="closeAddModal">&times;</span>
        <h2>Add New User</h2>
        <form method="POST" id="addUserForm">
            <div class="form-group">
                <label for="name">Full Name:</label>
                <input type="text" id="name" name="name" required>
            </div>
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group password-field">
                <label for="password">Password:</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" required>
                    <span class="toggle-password-visibility" data-for="password">👁</span>
                </div>
                <small id="password-strength-text" class="password-strength"></small>
            </div>
            <div class="form-group password-field">
                <label for="confirm_password">Confirm Password:</label>
                <div class="password-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" required>
                    <span class="toggle-password-visibility" data-for="confirm_password">👁</span>
                </div>
                <small id="confirm-password-strength-text" class="password-strength"></small>
            </div>
            <div class="form-group">
                <label for="role">Role:</label>
                <select id="role" name="role" required>
                    <option value="Admin">Admin</option>
                    <option value="Staff">Staff</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" name="add_user" class="btn">Add User</button>
                <button type="button" class="cancel-btn" id="cancelAddBtn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <span class="close" id="closeEditModal">&times;</span>
        <h2>Edit User</h2>
        <form method="POST" id="editUserForm">
            <input type="hidden" id="edit_id" name="edit_id">
            <div class="form-group">
                <label for="edit_name">Full Name:</label>
                <input type="text" id="edit_name" name="edit_name" required>
            </div>
            <div class="form-group">
                <label for="edit_username">Username:</label>
                <input type="text" id="edit_username" name="edit_username" required>
            </div>
            <div class="form-group">
                <label for="edit_role">Role:</label>
                <select id="edit_role" name="edit_role" required>
                    <option value="Admin">Admin</option>
                    <option value="Staff">Staff</option>
                </select>
            </div>
            <div class="form-group">
                <label for="edit_status">Status:</label>
                <select id="edit_status" name="edit_status" required>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" name="edit_user" class="btn">Update User</button>
                <button type="button" class="cancel-btn" id="cancelEditBtn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal" id="confirmModal">
    <div class="modal-content">
        <span class="close" id="closeConfirmModal">&times;</span>
        <h2>Please Confirm</h2>
        <p id="confirmMessage" class="text-center confirm-message"></p>
        <div class="form-actions">
            <button type="button" class="confirm-btn-yes" id="confirmYesBtn">Confirm</button>
            <button type="button" class="cancel-btn" id="confirmCancelBtn">Cancel</button>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Modal Elements ---
    const addUserModal = document.getElementById('addUserModal');
    const editModal = document.getElementById('editModal');
    const confirmModal = document.getElementById('confirmModal');
    const addUserBtn = document.getElementById('addUserBtn');
    const closeAddModal = document.getElementById('closeAddModal');
    const closeEditModal = document.getElementById('closeEditModal');
    const closeConfirmModal = document.getElementById('closeConfirmModal');
    const cancelAddBtn = document.getElementById('cancelAddBtn');
    const cancelEditBtn = document.getElementById('cancelEditBtn');
    const confirmCancelBtn = document.getElementById('confirmCancelBtn');
    const addUserForm = document.getElementById('addUserForm');
    const editUserForm = document.getElementById('editUserForm');

    // --- Open Add User Modal ---
    addUserBtn.addEventListener('click', () => {
        addUserModal.style.display = 'block';
    });

    // --- Close Modals ---
    closeAddModal.addEventListener('click', () => {
        addUserModal.style.display = 'none';
    });
    closeEditModal.addEventListener('click', () => {
        editModal.style.display = 'none';
    });
    closeConfirmModal.addEventListener('click', () => {
        confirmModal.style.display = 'none';
    });
    cancelAddBtn.addEventListener('click', () => {
        addUserModal.style.display = 'none';
    });
    cancelEditBtn.addEventListener('click', () => {
        editModal.style.display = 'none';
    });
    confirmCancelBtn.addEventListener('click', () => {
        confirmModal.style.display = 'none';
    });

    // Close modal when clicking outside
    window.addEventListener('click', (e) => {
        if (e.target === addUserModal) addUserModal.style.display = 'none';
        if (e.target === editModal) editModal.style.display = 'none';
        if (e.target === confirmModal) confirmModal.style.display = 'none';
    });

    // --- Add User Form Validation & Confirmation ---
    addUserForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm_password').value;

        if (password !== confirmPassword) {
            alert('Passwords do not match!');
            return;
        }

        // Check if username already exists
        fetch('api_check_username.php?username=' + encodeURIComponent(username))
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    alert('Username already exists. Please choose a different username.');
                } else {
                    // If username is unique, proceed to confirmation
                    document.getElementById('confirmMessage').textContent = 'Are you sure you want to add this new user?';
                    document.getElementById('confirmYesBtn').textContent = 'Yes, Add User';
                    document.getElementById('confirmYesBtn').style.backgroundColor = ''; // Reset color
                    confirmModal.style.display = 'block';

                    document.getElementById('confirmYesBtn').onclick = function() {
                        addUserModal.style.display = 'none';
                        confirmModal.style.display = 'none';
                        addUserForm.submit();
                    };
                }
            })
            .catch(error => {
                console.error('Error checking username:', error);
                alert('An error occurred while checking the username. Please try again.');
            });
    });

    // --- Password Strength Meter ---
    function setupPasswordStrength(inputId, strengthTextId) {
        const passwordInput = document.getElementById(inputId);
        const strengthText = document.getElementById(strengthTextId);

        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let score = 0;

            if (password.length === 0) {
                strengthText.textContent = '';
                strengthText.className = '';
                return;
            }

            if (password.length >= 8) score++;
            if (/[a-z]/.test(password)) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^a-zA-Z0-9]/.test(password)) score++;

            let strength = '';
            let strengthClass = '';

            if (score >= 5) {
                strength = 'Strong';
                strengthClass = 'strength-strong';
            } else if (score >= 4) {
                strength = 'Medium';
                strengthClass = 'strength-medium';
            } else if (score >= 3) {
                strength = 'Weak';
                strengthClass = 'strength-weak';
            } else {
                strength = 'Very Weak';
                strengthClass = 'strength-very-weak';
            }

            strengthText.textContent = strength;
            strengthText.className = strengthClass;
        });
    }

    setupPasswordStrength('password', 'password-strength-text');

    // --- Password Match Indicator ---
    const passwordInputForMatch = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const confirmPasswordText = document.getElementById('confirm-password-strength-text');

    function validatePasswordMatch() {
        if (confirmPasswordInput.value.length > 0) {
            if (passwordInputForMatch.value === confirmPasswordInput.value) {
                confirmPasswordText.textContent = 'Match password';
                confirmPasswordText.className = 'password-match';
            } else {
                confirmPasswordText.textContent = "Doesn't match password";
                confirmPasswordText.className = 'password-mismatch';
            }
        } else {
            confirmPasswordText.textContent = '';
            confirmPasswordText.className = '';
        }
    }
    passwordInputForMatch.addEventListener('input', validatePasswordMatch);
    confirmPasswordInput.addEventListener('input', validatePasswordMatch);

    // --- Password Visibility Toggle ---
    document.querySelectorAll('.toggle-password-visibility').forEach(toggle => {
        toggle.addEventListener('click', function() {
            const inputId = this.dataset.for;
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                this.classList.add('visible');
            } else {
                input.type = 'password';
                this.classList.remove('visible');
            }
        });
    });

    // --- Edit User Form Confirmation ---
    editUserForm.addEventListener('submit', function(e) {
        e.preventDefault(); // Stop submission to show confirmation
        document.getElementById('confirmMessage').textContent = 'Are you sure you want to update this user\'s details?';
        document.getElementById('confirmYesBtn').textContent = 'Yes, Update';
        document.getElementById('confirmYesBtn').style.backgroundColor = ''; // Reset color
        confirmModal.style.display = 'block';
        document.getElementById('confirmYesBtn').onclick = () => {
            editModal.style.display = 'none';
            confirmModal.style.display = 'none';
            editUserForm.submit();
        };
    });

    // --- Edit Button Logic ---
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('edit_id').value = btn.dataset.id;
            document.getElementById('edit_name').value = btn.dataset.name;
            document.getElementById('edit_username').value = btn.dataset.username;
            document.getElementById('edit_role').value = btn.dataset.role;
            document.getElementById('edit_status').value = btn.dataset.status;
            editModal.style.display = 'block';
        });
    });

    // --- Deactivate Button Logic ---
    document.querySelectorAll('.deactivate-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const form = e.target.closest('form');
            const userId = form.querySelector('input[name="deactivate_id"]').value;
            const currentUserId = '<?= $_SESSION['user_id'] ?>';
            const userName = form.closest('tr').querySelector('td:nth-child(2)').textContent;
            
            let message = `Are you sure you want to set the user "${userName}" to Inactive? They will no longer be able to log in.`;
            if (userId === currentUserId) {
                message = `<strong>Warning:</strong> You are about to make your own account Inactive. You will be logged out and will not be able to log back in. Are you sure?`;
            }
            document.getElementById('confirmMessage').innerHTML = message;
            document.getElementById('confirmYesBtn').textContent = 'Yes, Deactivate';
            document.getElementById('confirmYesBtn').style.backgroundColor = ''; // Reset color
            confirmModal.style.display = 'block';

            document.getElementById('confirmYesBtn').onclick = function() {
                confirmModal.style.display = 'none';
                form.submit();
            };
        });
    });

    // --- Permanent Delete Button Logic ---
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const form = e.target.closest('form');
            const userName = form.closest('tr').querySelector('td:nth-child(2)').textContent;
            
            document.getElementById('confirmMessage').innerHTML = `Are you sure you want to <strong>PERMANENTLY DELETE</strong> the user "${userName}"?<br><br>This action cannot be undone.`;
            document.getElementById('confirmYesBtn').textContent = 'Yes, Delete';
            document.getElementById('confirmYesBtn').style.backgroundColor = '#c62828'; // Make button red for delete
            confirmModal.style.display = 'block';

            document.getElementById('confirmYesBtn').onclick = function() {
                confirmModal.style.display = 'none';
                document.getElementById('confirmYesBtn').style.backgroundColor = ''; // Reset color
                form.submit();
            };
        });
    });

    // --- Toast Message ---
    const toast = document.getElementById("toast");
    const msg = localStorage.getItem("systemMessage");
    if (msg) {
        toast.textContent = msg;
        toast.classList.add("show");
        setTimeout(() => toast.classList.remove("show"), 3000);
        localStorage.removeItem("systemMessage");
    }
    const errorMsg = localStorage.getItem("systemError");
    if (errorMsg) {
        toast.textContent = errorMsg;
        toast.classList.add("show", "error");
        setTimeout(() => toast.classList.remove("show", "error"), 5000);
        localStorage.removeItem("systemError");
    }

    // --- Logout Confirmation ---
    const logoutLink = document.querySelector('a[href="logout.php"]');
    if (logoutLink) {
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
    }

    // --- Live Search Filter ---
    const searchInput = document.getElementById('searchInput');
    const tableBody = document.getElementById('userTableBody');
    const tableRows = tableBody.getElementsByTagName('tr');

    searchInput.addEventListener('keyup', function() {
        const searchTerm = searchInput.value.toLowerCase();
        let visibleRows = 0;

        for (let i = 0; i < tableRows.length; i++) {
            const row = tableRows[i];
            const rowText = row.textContent.toLowerCase();

            if (rowText.includes(searchTerm)) {
                row.style.display = '';
                visibleRows++;
            } else {
                row.style.display = 'none';
            }
        }
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
.status-active {
    background-color: #e0f2f1;
    color: #00796b;
}
.status-inactive {
    background-color: #ffebee;
    color: #c62828;
}
.toast.error {
    background-color: #c62828;
}
.action-btn.deactivate-btn {
    background-color: #fffde7;
    color: #f57f17;
    border: 1px solid #fff9c4;
}
.action-btn.deactivate-btn:hover {
    background-color: #fff9c4;
    color: #e65100;
}
.password-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.toggle-password-visibility {
    position: absolute;
    right: 10px;
    cursor: pointer;
    user-select: none;
    font-size: 1.2rem;
}
.toggle-password-visibility.visible {
    opacity: 0.5;
}
.password-strength {
    display: block;
    margin-top: 4px;
    font-size: 0.85rem;
    font-weight: 600;
}
.strength-strong {
    color: #2e7d32;
}
.strength-medium {
    color: #f57c00;
}
.strength-weak {
    color: #d84315;
}
.strength-very-weak {
    color: #c62828;
}
.password-match {
    color: #2e7d32;
}
.password-mismatch {
    color: #c62828;
}
</style>
</body>
</html>