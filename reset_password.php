<?php
session_start();
include "includes/db.php";

// Redirect if the user hasn't verified their OTP
if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
    header("Location: login.php");
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $user_id = $_SESSION['reset_user_id'];

    // --- Server-Side Validation ---
    $errors = [];
    if (strlen($new_password) < 8) $errors[] = "be at least 8 characters long";
    if (!preg_match('/[a-z]/', $new_password)) $errors[] = "contain a lowercase letter";
    if (!preg_match('/[A-Z]/', $new_password)) $errors[] = "contain an uppercase letter";
    if (!preg_match('/[0-9]/', $new_password)) $errors[] = "contain a number";
    if (!preg_match('/[^a-zA-Z0-9]/', $new_password)) $errors[] = "contain a special character";

    if (!empty($errors)) {
        $error_message = "Password must: " . implode(', ', $errors) . ".";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "The new passwords do not match.";
    } else {
        // Check if the new password is the same as the old one
        $stmt_check = $conn->prepare("SELECT password FROM staff WHERE id = ?");
        $stmt_check->bind_param("i", $user_id);
        $stmt_check->execute();
        $result = $stmt_check->get_result();
        $user = $result->fetch_assoc();
        $stmt_check->close();

        if ($user && password_verify($new_password, $user['password'])) {
            $error_message = "New password cannot be the same as your old password.";
        } else {
            // All checks passed, update the password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            $stmt_update = $conn->prepare("UPDATE staff SET password = ? WHERE id = ?");
            $stmt_update->bind_param("si", $hashed_password, $user_id);
            
            if ($stmt_update->execute()) {
                // Clean up session variables
                unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['reset_user_id'], $_SESSION['reset_username'], $_SESSION['otp_verified']);
                
                // Redirect to login with a success message
                $_SESSION['success_message'] = "Your password has been reset successfully. Please log in.";
                header("Location: login.php");
                exit;
            } else {
                $error_message = "An error occurred. Please try again.";
            }
            $stmt_update->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password - Brewventory</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <style>
        body { display: flex; justify-content: center; align-items: center; height: 100vh; background-color: var(--bg); }
        .login-container { width: 100%; max-width: 400px; padding: 40px; background-color: var(--panel); border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); text-align: center; }
        .login-container img { width: 120px; height: 120px; margin-bottom: 20px; }
        .login-container h1 { color: var(--accent); margin-bottom: 10px; }
        .login-container p { color: var(--subtext); margin-bottom: 25px; }
        .login-container input { width: 100%; padding: 12px; margin-bottom: 15px; border-radius: 6px; border: 1px solid #ccc; font-size: 1rem; }
        .login-container button { width: 100%; padding: 12px; border: none; border-radius: 6px; background-color: var(--accent); color: white; font-size: 1rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s; }
        .login-container button:hover { opacity: 0.9; }
        .error-message { color: #c62828; background-color: #ffebee; padding: 10px; border-radius: 6px; margin-bottom: 15px; border: 1px solid #ffcdd2; text-align: left; }
        
        /* --- Password validation styles from user_account.php --- */
        .password-input-container { position: relative; }
        .password-input-container input { padding-right: 120px; margin-bottom: 15px; }
        .toggle-password-visibility {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            cursor: pointer; user-select: none; width: 20px; height: 20px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'%3E%3C/path%3E%3Ccircle cx='12' cy='12' r='3'%3E%3C/circle%3E%3C/svg%3E");
            background-size: contain; background-repeat: no-repeat; opacity: 0.6; transition: opacity 0.2s;
        }
        .toggle-password-visibility.visible {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24'%3E%3C/path%3E%3Cline x1='1' y1='1' x2='23' y2='23'%3E%3C/line%3E%3C/svg%3E");
        }
        #password-strength-text, #confirm-password-strength-text {
            position: absolute; right: 40px; top: 50%; transform: translateY(-50%);
            font-size: 0.85rem; font-weight: 600; padding: 2px 8px; border-radius: 4px;
            min-width: 75px; text-align: center;
        }
        .strength-very-weak, .strength-weak { color: #c62828; }
        .strength-medium { color: #f57f17; }
        .strength-strong { color: #2e7d32; }
        .password-match { color: #2e7d32; }
        .password-mismatch { color: #c62828; }
        .validation-errors {
            display: none; background-color: #ffebee; color: #c62828;
            border: 1px solid #ffcdd2; border-radius: 6px;
            padding: 10px 15px; margin-top: -10px; margin-bottom: 15px; font-size: 0.9rem; text-align: left;
        }
        .validation-errors ul { margin: 5px 0 0 0; padding-left: 20px; }
        /* Hide browser's default password reveal icon */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-webkit-password-reveal-button {
            display: none;
        }
        /* Style for the error toast notification */
        .toast.error {
            background-color: #c62828; /* Red for errors */
        }
    </style>
</head>
<body>
    <div class="login-container">
        <img src="images/logo.png" alt="Bigger Brew Logo">
        <h1>Create New Password</h1>
        <p>Please enter and confirm your new password.</p>
        <form method="POST" action="reset_password.php" id="resetPasswordForm">
            <div class="password-input-container">
                <input type="password" name="new_password" id="new_password" placeholder="New Password" required>
                <span class="toggle-password-visibility" data-for="new_password"></span>
                <span id="password-strength-text"></span>
            </div>
            <div class="password-input-container">
                <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm New Password" required>
                <span class="toggle-password-visibility" data-for="confirm_password"></span>
                <span id="confirm-password-strength-text"></span>
            </div>
            <div id="password-validation-errors" class="validation-errors"></div>
            <button type="submit">Reset Password</button>
        </form>
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
            if (password.length === 0) { strengthText.textContent = ''; strengthText.className = ''; return; }
            if (password.length >= 8) score++;
            if (/[a-z]/.test(password)) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^a-zA-Z0-9]/.test(password)) score++;

            let strength = '', strengthClass = '';
            if (score >= 5) { strength = 'Strong'; strengthClass = 'strength-strong'; } 
            else if (score >= 4) { strength = 'Medium'; strengthClass = 'strength-medium'; } 
            else if (score >= 3) { strength = 'Weak'; strengthClass = 'strength-weak'; } 
            else { strength = 'Very Weak'; strengthClass = 'strength-very-weak'; }
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
        if (confirmPasswordInput.value.length > 0) {
            if (passwordInputForMatch.value === confirmPasswordInput.value) {
                confirmPasswordText.textContent = 'Match';
                confirmPasswordText.className = 'password-match';
            } else {
                confirmPasswordText.textContent = "Mismatch";
                confirmPasswordText.className = 'password-mismatch';
            }
        } else {
            confirmPasswordText.textContent = '';
            confirmPasswordText.className = '';
        }
    }
    passwordInputForMatch.addEventListener('input', validatePasswordMatch);
    confirmPasswordInput.addEventListener('input', validatePasswordMatch);

    // --- Form Submission Validation ---
    const resetForm = document.getElementById('resetPasswordForm');
    resetForm.addEventListener('submit', function(e) {
        const newPassword = document.getElementById('new_password').value;
        const errorContainer = document.getElementById('password-validation-errors');
        errorContainer.innerHTML = '';
        errorContainer.style.display = 'none';

        let errors = [];
        if (newPassword.length < 8) errors.push("be at least 8 characters long");
        if (!/[a-z]/.test(newPassword)) errors.push("contain a lowercase letter");
        if (!/[A-Z]/.test(newPassword)) errors.push("contain an uppercase letter");
        if (!/[0-9]/.test(newPassword)) errors.push("contain a number");
        if (!/[^a-zA-Z0-9]/.test(newPassword)) errors.push("contain a special character");

        if (errors.length > 0) {
            e.preventDefault();
            let errorHTML = '<strong>Password must:</strong><ul>' + errors.map(err => `<li>${err}</li>`).join('') + '</ul>';
            errorContainer.innerHTML = errorHTML;
            errorContainer.style.display = 'block';
            return; // Stop if validation fails
        }

        // If validation passes, show confirmation modal
        e.preventDefault(); // Prevent submission to show modal
        const confirmModal = document.getElementById('confirmModal');
        document.getElementById('confirmMessage').textContent = 'Are you sure you want to reset your password?';
        const confirmYesBtn = document.getElementById('confirmYesBtn');
        confirmYesBtn.textContent = 'Yes, Reset';
        confirmYesBtn.className = 'confirm-btn-yes'; // Reset style
        confirmModal.style.display = 'block';

        confirmYesBtn.onclick = function() {
            resetForm.submit();
        };
    });

    // --- Modal Closing Logic ---
    const confirmModal = document.getElementById('confirmModal');
    document.getElementById('closeConfirmModal').addEventListener('click', () => confirmModal.style.display = 'none');
    document.getElementById('confirmCancelBtn').addEventListener('click', () => confirmModal.style.display = 'none');
    window.addEventListener('click', (e) => { if (e.target === confirmModal) confirmModal.style.display = 'none'; });

    // --- Toast Message for Errors ---
    const errorMessage = <?= json_encode($error_message) ?>;
    const toast = document.getElementById("toast");

    if (errorMessage) {
        toast.textContent = errorMessage;
        toast.classList.add("show", "error"); // Add 'error' class for red background
        setTimeout(() => {
            toast.classList.remove("show", "error");
        }, 30000); // 30000 milliseconds = 30 seconds
    }
});
</script>
</body>
</html>