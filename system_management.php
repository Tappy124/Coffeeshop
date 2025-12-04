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
    $status = 'Active'; // New users are active by default

    // Basic validation
    if (!empty($name) && !empty($username) && !empty($password) && !empty($role) && $password === $confirm_password) {
        // The duplicate username check is now handled by JavaScript before form submission.
        // We proceed directly with adding the user.
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO staff (name, username, password, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $name, $username, $hashed_password, $role, $status);
        $stmt->execute();
        $stmt->close();

        echo "<script>
            localStorage.setItem('systemMessage', 'User added successfully!');
            window.location.href='system_management.php';
        </script>";
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

        echo "<script>
            localStorage.setItem('systemMessage', 'User updated successfully!');
            window.location.href='system_management.php';
        </script>";
        exit;
    }
}

// --- Deactivate User (Soft Delete) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_user'])) {
    $id = (int)$_POST['deactivate_id']; // The ID of the user to "deactivate"

    if ($id > 0) {
        // Soft delete: Set the user's status to 'Inactive' instead of deleting the record.
        $stmt = $conn->prepare("UPDATE staff SET status = 'Inactive' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        // If an admin deactivates their own account, log them out.
        if ($id === (int)$_SESSION['user_id']) {
            echo "<script>
                localStorage.setItem('systemMessage', 'Your account has been set to Inactive. You are now being logged out.');
                setTimeout(() => { window.location.href='logout.php'; }, 2000);
            </script>";
            exit;
        }
    }
    echo "<script>
        localStorage.setItem('systemMessage', 'User status set to Inactive!');
        window.location.href='system_management.php';
    </script>";
    exit;
}

// --- Permanent Delete User ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $id = (int)$_POST['delete_id'];
    if ($id > 0 && $id !== (int)$_SESSION['user_id']) { // Prevent admin from deleting themselves
        $stmt = $conn->prepare("DELETE FROM staff WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    echo "<script>
        localStorage.setItem('systemMessage', 'User permanently deleted!');
        window.location.href='system_management.php';
    </script>";
    exit;
}

// --- Search & Sort Logic ---
$search = trim($_GET['search'] ?? '');
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'ASC';
$sort_columns_whitelist = ['id', 'name', 'username', 'role', 'status'];
if (!in_array($sort_by, $sort_columns_whitelist)) $sort_by = 'name';
if (!in_array(strtoupper($sort_order), ['ASC', 'DESC'])) $sort_order = 'ASC';

$sql = "SELECT * FROM staff";
$params = [];
$types = '';

if (!empty($search)) {
    $sql .= " WHERE name LIKE ? OR username LIKE ? OR role LIKE ?";
    $like = "%$search%";
    $params = [$like, $like, $like];
    $types = 'sss';
}
$sql .= " ORDER BY `$sort_by` $sort_order";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Management</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
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
                <div class="header-actions" style="display:flex; gap:10px; align-items:center;">
                    <button class="btn" id="openAddUser">+ Add User</button>
                </div>
            </div>
        </header>

        <div class="filter-bar">
            <form method="GET" action="system_management.php" class="filter-form">
                <input type="text" name="search" id="searchInput" placeholder="Search by name, username, or role..." value="<?= htmlspecialchars($search) ?>">
                <select name="sort_by">
                    <option value="name" <?= $sort_by == 'name' ? 'selected' : '' ?>>Sort by Name</option>
                    <option value="username" <?= $sort_by == 'username' ? 'selected' : '' ?>>Sort by Username</option>
                    <option value="role" <?= $sort_by == 'role' ? 'selected' : '' ?>>Sort by Role</option>
                    <option value="status" <?= $sort_by == 'status' ? 'selected' : '' ?>>Sort by Status</option>
                </select>
                <select name="sort_order">
                    <option value="ASC" <?= $sort_order == 'ASC' ? 'selected' : '' ?>>Ascending</option>
                    <option value="DESC" <?= $sort_order == 'DESC' ? 'selected' : '' ?>>Descending</option>
                </select>
                <button type="submit" class="btn">Filter</button>
                <a href="system_management.php" class="btn cancel-btn">Clear</a>
            </form>
        </div>

        <section class="box">
            <h2>User Accounts</h2>
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
                    <?php if ($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['id']) ?></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= htmlspecialchars($row['username']) ?></td>
                                <td><?= htmlspecialchars($row['role']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= strtolower(htmlspecialchars($row['status'])) ?>">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="action-btn edit-btn"
                                        data-id="<?= $row['id'] ?>"
                                        data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
                                        data-username="<?= htmlspecialchars($row['username'], ENT_QUOTES) ?>"
                                        data-role="<?= htmlspecialchars($row['role'], ENT_QUOTES) ?>"
                                        data-status="<?= htmlspecialchars($row['status'], ENT_QUOTES) ?>">
                                        Edit
                                    </button>
                                    <?php if ($row['status'] === 'Active'): ?>
                                        <form method="POST" action="system_management.php" style="display:inline;" class="deactivate-form">
                                            <input type="hidden" name="deactivate_user" value="1">
                                            <input type="hidden" name="deactivate_id" value="<?= $row['id'] ?>">
                                            <button type="button" class="action-btn deactivate-btn">Deactivate</button>
                                        </form>
                                    <?php else: // Status is 'Inactive' ?>
                                        <?php if ($row['id'] !== $_SESSION['user_id']): // Prevent admin from deleting themselves ?>
                                            <form method="POST" action="system_management.php" style="display:inline;" class="delete-form">
                                                <input type="hidden" name="delete_user" value="1">
                                                <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                                <button type="button" class="action-btn delete-btn">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6">No users found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- Add User Modal -->
<div class="modal" id="addUserModal">
    <div class="modal-content">
        <span class="close" id="closeAddModal">&times;</span>
        <h2>Add New User</h2>
        <form method="POST" action="system_management.php" id="addUserForm">
            <input type="hidden" name="add_user" value="1">
            <label>Full Name</label>
            <input type="text" name="name" required placeholder="e.g., Jane Doe">
            <label>Username (must be a valid email)</label>
            <input type="email" name="username" required placeholder="e.g., user@gmail.com">
            <label>Password</label>
            <div class="password-input-container">
                <input type="password" name="password" id="password" required placeholder="Create a password">
                <span id="password-strength-text"></span>
                <span class="toggle-password-visibility" data-for="password"></span>
            </div>
            <label>Confirm Password</label>
            <div class="password-input-container">
                <input type="password" name="confirm_password" id="confirm_password" required placeholder="Confirm password">
                <span id="confirm-password-strength-text"></span>
                <span class="toggle-password-visibility" data-for="confirm_password"></span>
            </div>
            <div id="password-validation-errors" class="validation-errors"></div>
            <label>Role</label>
            <select name="role" required>
                <option value="" disabled selected>Select a role</option>
                <option value="Admin">Admin</option>
                <option value="Staff">Staff</option>
            </select>
            <div class="form-actions">
                <button type="submit">Save User</button>
                <button type="button" class="cancel-btn" id="cancelAdd">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal" id="editUserModal">
    <div class="modal-content">
        <span class="close" id="closeEditModal">&times;</span>
        <h2>Edit User</h2>
        <form method="POST" action="system_management.php" id="editUserForm">
            <input type="hidden" name="edit_user" value="1">
            <input type="hidden" name="edit_id" id="edit_id">
            <label>Full Name</label>
            <input type="text" name="edit_name" id="edit_name" required>
            <label>Username (must be a valid email)</label>
            <input type="email" name="edit_username" id="edit_username" required>
            <label>Role</label>
            <select name="edit_role" id="edit_role" required>
                <option value="Admin">Admin</option>
                <option value="Staff">Staff</option>
            </select>
            <label>Status</label>
            <select name="edit_status" id="edit_status" required>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
            <div class="form-actions">
                <button type="submit">Update User</button>
                <button type="button" class="cancel-btn" id="cancelEdit">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal" id="confirmModal">
    <div class="modal-content">
        <span class="close" id="closeConfirmModal" style="color: #c62828;">&times;</span>
        <h2>Please Confirm</h2>
        <p id="confirmMessage" style="text-align: center; margin: 20px 0;"></p>
        <div class="form-actions">
            <button type="button" class="confirm-btn-yes" id="confirmYesBtn">Yes, Delete</button>
            <button type="button" class="cancel-btn" id="confirmCancelBtn">Cancel</button>
        </div>
    </div>
</div>


<!-- Toast Message -->
<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Modals ---
    const addModal = document.getElementById('addUserModal');
    const editModal = document.getElementById('editUserModal');
    const confirmModal = document.getElementById('confirmModal');
    const editUserForm = document.getElementById('editUserForm');
    const addUserForm = document.getElementById('addUserForm');
    const openAddBtn = document.getElementById('openAddUser');

    openAddBtn.addEventListener('click', () => addModal.style.display = 'block');

    function closeModal(modal) {
        if (modal) modal.style.display = 'none';
    }

    document.getElementById('closeAddModal').addEventListener('click', () => closeModal(addModal));
    document.getElementById('cancelAdd').addEventListener('click', () => closeModal(addModal));
    document.getElementById('closeEditModal').addEventListener('click', () => closeModal(editModal));
    document.getElementById('cancelEdit').addEventListener('click', () => closeModal(editModal));
    document.getElementById('closeConfirmModal').addEventListener('click', () => closeModal(confirmModal));
    document.getElementById('confirmCancelBtn').addEventListener('click', () => closeModal(confirmModal));


    window.addEventListener('click', (e) => {
        if (e.target === confirmModal) closeModal(confirmModal);
        if (e.target === addModal) closeModal(addModal);
        if (e.target === editModal) closeModal(editModal);
    });

    // --- Add User Form Validation ---
    addUserForm.addEventListener('submit', function(e) {
        e.preventDefault(); // Stop submission to perform validation and show confirmation
        const password = addUserForm.querySelector('input[name="password"]').value;
        const confirmPassword = addUserForm.querySelector('input[name="confirm_password"]').value;
        const usernameInput = addUserForm.querySelector('input[name="username"]');
        const username = usernameInput.value;
        const errorContainer = document.getElementById('password-validation-errors');
        errorContainer.innerHTML = ''; // Clear previous errors
        errorContainer.style.display = 'none'; 

        // --- Detailed Password Validation ---
        let errors = [];
        if (password.length < 8) errors.push("be at least 8 characters long");
        if (!/[a-z]/.test(password)) errors.push("contain a lowercase letter");
        if (!/[A-Z]/.test(password)) errors.push("contain an uppercase letter");
        if (!/[0-9]/.test(password)) errors.push("contain a number");
        if (!/[^a-zA-Z0-9]/.test(password)) errors.push("contain a special character");

        if (errors.length > 0) {
            let errorHTML = '<strong>Password must:</strong><ul>';
            errors.forEach(error => {
                errorHTML += `<li>${error}</li>`;
            });
            errorHTML += '</ul>';
            errorContainer.innerHTML = errorHTML;
            errorContainer.style.display = 'block'; 
            addUserForm.querySelector('input[name="password"]').focus();
            return;
        }

        if (password !== confirmPassword) {
            errorContainer.innerHTML = '<strong>Error:</strong> Passwords do not match.';
            errorContainer.style.display = 'block';
            // Clear password fields for security and convenience
            addUserForm.querySelector('input[name="password"]').value = '';
            addUserForm.querySelector('input[name="confirm_password"]').value = '';
            addUserForm.querySelector('input[name="password"]').focus();
            return; // Stop further execution
        }

        // --- AJAX Check for Duplicate Username ---
        fetch(`api_check_username.php?username=${encodeURIComponent(username)}`)
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    errorContainer.innerHTML = `<strong>Error:</strong> The username "${username}" is already taken.`;
                    errorContainer.style.display = 'block';
                    usernameInput.focus();
                } else {
                    // If username is unique, proceed to confirmation
                    document.getElementById('confirmMessage').textContent = 'Are you sure you want to add this new user?';
                    document.getElementById('confirmYesBtn').textContent = 'Yes, Add User';
                    confirmModal.style.display = 'block';

                    document.getElementById('confirmYesBtn').onclick = function() {
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
        confirmModal.style.display = 'block';
        document.getElementById('confirmYesBtn').onclick = () => editUserForm.submit();
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
            confirmModal.style.display = 'block';

            document.getElementById('confirmYesBtn').onclick = function() {
                form.submit();
            };
        });
    });

    // --- Permanent Delete Button Logic ---
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const form = e.target.closest('form');
            const userName = form.closest('tr').querySelector('td:nth-child(2)').textContent;
            
            document.getElementById('confirmMessage').innerHTML = `Are you sure you want to <strong>PERMANENTLY DELETE</strong> the user "${userName}"?<br><br>This action cannot be undone.`;
            document.getElementById('confirmYesBtn').textContent = 'Yes, Delete';
            document.getElementById('confirmYesBtn').style.backgroundColor = '#c62828'; // Make button red for delete
            confirmModal.style.display = 'block';

            document.getElementById('confirmYesBtn').onclick = function() {
                form.submit();
                document.getElementById('confirmYesBtn').style.backgroundColor = ''; // Reset color
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
        toast.classList.add("show", "error"); // Add error class for styling
        // Show error for longer
        setTimeout(() => toast.classList.remove("show", "error"), 5000);
        localStorage.removeItem("systemError");
    }

    // --- Logout Confirmation ---
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

    // --- Live Search Filter ---
    const searchInput = document.getElementById('searchInput');
    const tableBody = document.getElementById('userTableBody');
    const tableRows = tableBody.getElementsByTagName('tr');
    const noResultsRow = tableBody.querySelector('td[colspan="6"]');

    searchInput.addEventListener('keyup', function() {
        const searchTerm = searchInput.value.toLowerCase();
        let visibleRows = 0;

        for (let i = 0; i < tableRows.length; i++) {
            const row = tableRows[i];
            // Skip the 'No users found' row
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
            // Show 'No users found' row if no other rows are visible
            noResultsRow.parentElement.style.display = (visibleRows === 0) ? '' : 'none';
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
    background-color: #e0f2f1; /* light green */
    color: #00796b; /* dark green */
}
.status-inactive {
    background-color: #ffebee; /* light red */
    color: #c62828; /* dark red */
}
.toast.error {
    background-color: #c62828; /* Red for errors */
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

</style>
</body>
</html>