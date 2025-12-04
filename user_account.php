<?php
session_start();

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- Authentication Check (allow both roles) ---
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include "includes/db.php";

$user_id = $_SESSION['user_id'];
$error_message = '';
$success_message = '';

// --- Fetch current user data ---
$stmt = $conn->prepare("SELECT name, username, password FROM staff WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    // This should not happen if the user is logged in, but as a safeguard:
    session_destroy();
    header("Location: login.php");
    exit;
}

$current_username = $user['username'];

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $new_username = trim($_POST['username']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // 1. Verify current password
    if (!password_verify($current_password, $user['password'])) {
        echo "<script>localStorage.setItem('formError', 'The \\'Current Password\\' you entered is incorrect.'); window.history.back();</script>";
        exit;
    } else {
        $update_clauses = [];
        $update_params = [];
        $update_types = '';

        // 2. Handle username change
        // Check for duplicate username only if it has been changed
        if ($new_username !== $current_username && !empty($new_username)) {
            // Check if the new username is already taken
            $stmt_check = $conn->prepare("SELECT id FROM staff WHERE username = ?");
            $stmt_check->bind_param("s", $new_username);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $error_message = "The username '{$new_username}' is already taken. Please choose another one.";
            } else { // Username is unique
                $update_clauses[] = "username = ?";
                $update_params[] = $new_username;
                $update_types .= 's';
            }
            $stmt_check->close();
        }

        // 3. Handle password change
        if (!empty($new_password)) {
            $errors = [];
            if (strlen($new_password) < 8) $errors[] = "be at least 8 characters long";
            if (!preg_match('/[a-z]/', $new_password)) $errors[] = "contain a lowercase letter";
            if (!preg_match('/[A-Z]/', $new_password)) $errors[] = "contain an uppercase letter";
            if (!preg_match('/[0-9]/', $new_password)) $errors[] = "contain a number";
            if (!preg_match('/[^a-zA-Z0-9]/', $new_password)) $errors[] = "contain a special character";
            if ($new_password === $current_password) {
                $error_message = "New password cannot be the same as the current password.";
            }


            if (!empty($errors)) {
                $error_message = "Password must: " . implode(', ', $errors) . ".";
            } elseif ($new_password !== $confirm_password) {
                $error_message = "The new passwords do not match.";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_clauses[] = "password = ?";
                $update_params[] = $hashed_password;
                $update_types .= 's';
            }
        }

        // 4. Execute update if there are changes and no errors
        if (empty($error_message) && !empty($update_clauses)) {
            $sql = "UPDATE staff SET " . implode(', ', $update_clauses) . " WHERE id = ?";
            $update_params[] = $user_id;
            $update_types .= 'i';

            $stmt_update = $conn->prepare($sql);
            $stmt_update->bind_param($update_types, ...$update_params);

            if ($stmt_update->execute()) {
                // If username was changed, update the session and the local variable
                if ($new_username !== $current_username) {
                    $_SESSION['username'] = $new_username;
                }
                echo "<script>localStorage.setItem('formMessage', 'Your account has been updated successfully!'); window.location.href='user_account.php';</script>";
                exit;
            } else {
                $error_message = "An error occurred while updating your account.";
            }
            $stmt_update->close();
        } elseif (empty($error_message) && empty($update_clauses)) {
            echo "<script>localStorage.setItem('formError', 'You didn\\'t make any changes.'); window.history.back();</script>";
            exit;
        } elseif (!empty($error_message)) {
            echo "<script>localStorage.setItem('formError', '" . addslashes($error_message) . "'); window.history.back();</script>";
            exit;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Account</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/form.css">
    <link rel="stylesheet" href="css/modal.css">
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <style>
        .main .box form input[type="text"],
        .main .box form input[type="password"],
        .main .box form input[type="email"],
        .main .box form select {
            width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #bdbdbd;
            margin-bottom: 12px; background-color: var(--bg); color: var(--text);
            box-sizing: border-box; font-size: 1rem;
        }
        .main .box form input:hover,
        .main .box form select:hover {
            border-color: var(--accent);
        }
        .main .box form input:focus,
        .main .box form select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(74, 108, 111, 0.2);
        }
        .main .box form input[readonly] { background-color: var(--muted); cursor: not-allowed; }
        .main .box .form-actions button { width: 100%; padding: 12px 20px; margin-top: 10px; border: none; border-radius: 6px; font-size: 16px; font-weight: 600; }
        .message { padding: 15px; margin-bottom: 20px; border-radius: 6px; border: 1px solid transparent; }
        .message.success { background-color: #e0f2f1; color: #00796b; border-color: #b2dfdb; }
        .message.error { background-color: #ffebee; color: #c62828; border-color: #ffcdd2; }
        .password-input-container { position: relative; }
        .password-input-container input { margin-bottom: 12px; padding-right: 45px; }
        .toggle-password-visibility {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            cursor: pointer; user-select: none; width: 20px; height: 20px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'%3E%3C/path%3E%3Ccircle cx='12' cy='12' r='3'%3E%3C/circle%3E%3C/svg%3E");
            background-size: contain; background-repeat: no-repeat; opacity: 0.6; transition: opacity 0.2s;
        }
        .toggle-password-visibility.visible {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24'%3E%3C/path%3E%3Cline x1='1' y1='1' x2='23' y2='23'%3E%3C/line%3E%3C/svg%3E");
        }
        .password-input-container input { padding-right: 120px; }
        #password-strength-text, #confirm-password-strength-text {
            position: absolute; right: 40px; top: 50%; transform: translateY(-50%);
            font-size: 0.85rem; font-weight: 600; padding: 2px 8px; border-radius: 4px;
            min-width: 75px; text-align: center; transition: color 0.3s ease;
        }
        .strength-very-weak, .strength-weak { color: #c62828; }
        .strength-medium { color: #f57f17; }
        .strength-strong { color: #2e7d32; }
        .password-match { color: #2e7d32; }
        .password-mismatch { color: #c62828; }
        .validation-errors {
            display: none; background-color: #ffebee; color: #c62828;
            border: 1px solid #ffcdd2; border-radius: 6px;
            padding: 10px 15px; margin-top: -5px; margin-bottom: 12px; font-size: 0.9rem;
        }
        .validation-errors ul { margin: 5px 0 0 0; padding-left: 20px; }
        /* Style for the error toast notification */
        .toast.error {
            background-color: #c62828; /* Red for errors */
        }
    </style>
</head>
<body>
<div class="container">
    <aside class="sidebar">
        <div class="logo"><img src="images/logo.png" alt="Logo"></div>
        <h2><?= htmlspecialchars($_SESSION['role']) ?> Dashboard</h2>
        <ul>
            <?php if ($_SESSION['role'] === 'Admin'): ?>
                <li><a href="admin_dashboard.php">Dashboard</a></li>
                <li><a href="inventory_products.php">Inventory Items</a></li>
                <li><a href="products_menu.php">Menu Items</a></li>
                <li><a href="supplier_management.php">Supplier</a></li>
                <li><a href="sales_and_waste.php">Sales & Waste</a></li>
                <li><a href="reports_and_analytics.php">Reports & Analytics</a></li>
                <li><a href="admin_forecasting.php">Forecasting</a></li>
                <li><a href="system_management.php">System Management</a></li>
                <li><a href="user_account.php" class="active">My Account</a></li>
                <li><a href="logout.php">Logout</a></li>
            <?php else: // Staff ?>
                <li><a href="staff_dashboard.php">Dashboard</a></li>
                <li><a href="staff_log_sales.php">Log Sale</a></li>
                <li><a href="staff_log_waste.php">Log Waste</a></li>
                <li><a href="staff_view_history.php">View History</a></li>
                <li><a href="user_account.php" class="active">My Account</a></li>
                <li><a href="logout.php">Logout</a></li>
            <?php endif; ?>
        </ul>
    </aside>

    <main class="main">
        <header>
            <h1>My Account</h1>
            <div class="header-actions" style="display:flex; gap:10px; align-items:center;">
            </div>
        </header>

        <section class="box" style="max-width: 600px; margin: 20px auto;">
            <h2>Edit Your Account Details</h2>
            <p style="font-size: 0.9rem; color: var(--subtext); margin-bottom: 20px;">
                Update your username or password here. You must provide your current password to make any changes.
            </p>

            <?php if ($error_message): ?>
                <div class="message error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <form method="POST" action="user_account.php" id="accountForm">
                <input type="hidden" name="update_account" value="1">

                <label>Full Name</label>
                <input type="text" value="<?= htmlspecialchars($user['name']) ?>" readonly>

                <label for="username">Username (must be a valid email)</label>
                <input type="email" id="username" name="username" value="<?= htmlspecialchars($current_username) ?>" required placeholder="e.g., user@gmail.com">

                <hr style="border: 0; border-top: 1px solid var(--muted); margin: 25px 0;">

                <label for="new_password">New Password (optional)</label>
                <div class="password-input-container">
                    <input type="password" id="new_password" name="new_password" placeholder="Leave blank to keep current">
                    <span id="password-strength-text"></span>
                    <span class="toggle-password-visibility" data-for="new_password"></span>
                </div>

                <label for="confirm_password">Confirm New Password</label>
                <div class="password-input-container">
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your new password">
                    <span id="confirm-password-strength-text"></span>
                    <span class="toggle-password-visibility" data-for="confirm_password"></span>
                </div>

                <div id="password-validation-errors" class="validation-errors"></div>

                <hr style="border: 0; border-top: 1px solid var(--muted); margin: 25px 0;">

                <label for="current_password">Current Password (Required to Save Changes)</label>
                <div class="password-input-container">
                    <input type="password" id="current_password" name="current_password" required placeholder="Enter your current password">
                    <span class="toggle-password-visibility" data-for="current_password"></span>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Update Account</button>
                </div>
            </form>
        </section>
    </main>
</div>

<!-- Generic Confirmation Modal -->
<div class="modal" id="confirmModal">
    <div class="modal-content" style="max-width: 450px; text-align: center;">
        <span class="close" id="closeConfirmModal">&times;</span>
        <h2 id="confirmTitle">Please Confirm</h2>
        <p id="confirmMessage" style="margin: 20px 0; font-size: 1.1rem;"></p>
        <div class="form-actions">
            <button type="button" class="confirm-btn-yes" id="confirmYesBtn">Confirm</button>
            <button type="button" class="cancel-btn" id="confirmCancelBtn">Cancel</button>
        </div>
    </div>
</div>

<!-- Toast Message -->
<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
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

    setupPasswordStrength('new_password', 'password-strength-text');

    // --- Password Match Indicator ---
    const passwordInputForMatch = document.getElementById('new_password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const confirmPasswordText = document.getElementById('confirm-password-strength-text');

    function validatePasswordMatch() {
        // Only show indicator if the confirm password field has text
        if (confirmPasswordInput.value.length > 0) {
            if (passwordInputForMatch.value === confirmPasswordInput.value) {
                confirmPasswordText.textContent = 'Match';
                confirmPasswordText.className = 'password-match';
            } else {
                confirmPasswordText.textContent = "Mismatch";
                confirmPasswordText.className = 'password-mismatch';
            }
        } else {
            // Clear the indicator if the field is empty
            confirmPasswordText.textContent = '';
            confirmPasswordText.className = '';
        }
    }

    // Validate on input in either field
    passwordInputForMatch.addEventListener('input', validatePasswordMatch);
    confirmPasswordInput.addEventListener('input', validatePasswordMatch);

    // --- Form Submission Validation ---
    const accountForm = document.getElementById('accountForm');
    accountForm.addEventListener('submit', function(e) {
        e.preventDefault(); // Stop submission to show confirmation
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        const errorContainer = document.getElementById('password-validation-errors');
        errorContainer.innerHTML = ''; // Clear previous errors
        errorContainer.style.display = 'none';

        // Only validate if a new password is being set
        if (newPassword.length > 0) {
            let errors = [];
            if (newPassword.length < 8) errors.push("be at least 8 characters long");
            if (!/[a-z]/.test(newPassword)) errors.push("contain a lowercase letter");
            if (!/[A-Z]/.test(newPassword)) errors.push("contain an uppercase letter");
            if (!/[0-9]/.test(newPassword)) errors.push("contain a number");
            if (!/[^a-zA-Z0-9]/.test(newPassword)) errors.push("contain a special character");

            if (errors.length > 0) {
                let errorHTML = '<strong>New Password must:</strong><ul>';
                errors.forEach(error => {
                    errorHTML += `<li>${error}</li>`;
                });
                errorHTML += '</ul>';
                errorContainer.innerHTML = errorHTML;
                errorContainer.style.display = 'block';
                document.getElementById('new_password').focus();
                return;
            }

            if (newPassword === document.getElementById('current_password').value) {
                e.preventDefault(); // Stop submission
                errorContainer.innerHTML = '<strong>Error:</strong> New password cannot be the same as the current password.';
                errorContainer.style.display = 'block';
                document.getElementById('new_password').focus();
                return;
            }

            if (newPassword !== confirmPassword) {
                errorContainer.innerHTML = '<strong>Error:</strong> New passwords do not match.';
                errorContainer.style.display = 'block';
                document.getElementById('confirm_password').focus();
                return;
            }
        }
        
        // If validation passes, show the confirmation modal
        const confirmMessage = document.getElementById('confirmMessage');
        
        confirmMessage.textContent = 'Are you sure you want to update your account details?';
        confirmYesBtn.textContent = 'Yes, Update';
        confirmYesBtn.className = 'confirm-btn-yes'; // Reset to default style
        confirmModal.style.display = 'block';

        confirmYesBtn.onclick = function() {
            accountForm.submit(); // Submit the form if confirmed
        };
    });

    // --- Modal Closing Logic ---
    const confirmModal = document.getElementById('confirmModal');
    const closeConfirmModalBtn = document.getElementById('closeConfirmModal');
    const confirmCancelBtn = document.getElementById('confirmCancelBtn');
    const confirmYesBtn = document.getElementById('confirmYesBtn');

    function closeModal() {
        confirmModal.style.display = 'none';
    }

    closeConfirmModalBtn.addEventListener('click', closeModal);
    confirmCancelBtn.addEventListener('click', closeModal);
    window.addEventListener('click', (e) => {
        if (e.target === confirmModal) {
            closeModal();
        }
    });

    // --- Logout Confirmation ---
    const logoutLink = document.querySelector('a[href="logout.php"]');
    if (logoutLink) {
        logoutLink.addEventListener('click', function(e) {
            e.preventDefault();
            const confirmMessage = document.getElementById('confirmMessage');

            confirmMessage.textContent = 'Are you sure you want to log out?';
            confirmYesBtn.textContent = 'Yes, Logout';
            confirmYesBtn.className = 'confirm-btn-yes btn-logout-yes'; // Apply logout style
            confirmModal.style.display = 'block';

            confirmYesBtn.onclick = function() {
                window.location.href = 'logout.php';
            };
        });
    }

    // --- Toast Message Logic ---
    const toast = document.getElementById("toast");
    const successMsg = localStorage.getItem("formMessage");
    if (successMsg) {
        toast.textContent = successMsg;
        toast.classList.remove("error"); // Ensure it's not styled as an error
        toast.classList.add("show");
        setTimeout(() => {
            toast.classList.remove("show");
            localStorage.removeItem("formMessage");
        }, 3000);
    }
    // Note: We are intentionally not using a localStorage error toast here
    // because validation errors are better displayed inline to keep form data.

    const errorMsg = localStorage.getItem("formError");
    if (errorMsg) {
        toast.textContent = errorMsg;
        toast.classList.add("show", "error");
        // Show error for longer
        setTimeout(() => {
            toast.classList.remove("show", "error");
            localStorage.removeItem("formError");
        }, 5000);
    }

});
</script>
</body>
</html>