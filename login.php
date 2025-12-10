<?php
session_start();
include "includes/db.php";

// --- Login Cooldown Logic ---
$_SESSION['cooldown_just_triggered'] = false; // Reset the flag on each page load
$cooldown_active = false;
$remaining_time = 0;
if (isset($_SESSION['cooldown_until']) && time() < $_SESSION['cooldown_until']) {
    $cooldown_active = true;
    $remaining_time = $_SESSION['cooldown_until'] - time();
}
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        if ($cooldown_active) {
            $error_message = "Too many failed login attempts. Please wait for the cooldown period to end.";
        } else {
            // Proceed with login check

        $stmt = $conn->prepare("SELECT id, username, password, role, status FROM staff WHERE username = ? AND status = 'Active'");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        // Verify user exists, password is correct, and account is active
        if ($user && password_verify($password, $user['password'])) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            // Store user data in session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Clear any login attempt tracking on successful login
            unset($_SESSION['login_attempts']);
            unset($_SESSION['cooldown_until']);
            unset($_SESSION['cooldown_duration']);

            // Redirect based on role
            if ($user['role'] === 'Admin') {
                header("Location: admin_dashboard.php");
                exit;
            } elseif ($user['role'] === 'Staff') {
                header("Location: staff_dashboard.php");
                exit;
            } elseif ($user['role'] === 'Customer') {
                header("Location: index.php");
                exit;
            }
        } else {
            // Handle failed login attempt
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;

            $attempts_left = 3 - $_SESSION['login_attempts'];

            if ($attempts_left <= 0) {
                // Increment cooldown duration by 1 minute each time
                $_SESSION['cooldown_duration'] = ($_SESSION['cooldown_duration'] ?? 0) + 60; // Start with 60s, then 120s, etc.
                $_SESSION['cooldown_until'] = time() + $_SESSION['cooldown_duration'];
                
                // Reset attempts for the next cycle after cooldown
                $_SESSION['login_attempts'] = 0; // Reset attempts for the next cycle
                $_SESSION['cooldown_just_triggered'] = true; // Flag for JS to immediately disable the form

                $error_message = "0 attempts remaining. Too many failed logins. Please wait for " . floor($_SESSION['cooldown_duration'] / 60) . " minute(s).";
            } else {
                $error_message = "Invalid username or password. You have {$attempts_left} attempt(s) remaining.";
            }
        }
        }
    } else {
        $error_message = "Please enter both username and password.";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Brewventory</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/extracted_styles.css">
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <link rel="stylesheet" href="css/extracted_styles.css">
</head>
<body class="login-body">
    <div class="login-container">
        <img src="images/logo.png" alt="Bigger Brew Logo">
        <h1>Bigger Brew Inventory</h1>
        <form method="POST" action="login.php">
            <input type="text" name="username" id="username" placeholder="Username" required>
            <div class="password-input-container">
                <input type="password" name="password" id="password" placeholder="Password" required>
                <span class="toggle-password-visibility" data-for="password"></span>
            </div>
            <button type="submit" id="loginButton">Login</button>
        </form>
        <div class="text-right mt-15">
            <a href="forgot_password.php" class="muted-link">Forgot Password?</a>
        </div>
        <div class="text-center mt-15">
            <a href="register.php" class="muted-link">Don't have an account? Register here</a>
        </div>
    </div>

    <div id="loader-overlay" class="loader-overlay">
        <div class="loader-spinner"></div>
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

    // --- Form Submission Loader ---
    const loginForm = document.querySelector('form');
    const loaderOverlay = document.getElementById('loader-overlay');
 
    // --- Toast Message for Login Errors ---
    let errorMessage = <?= json_encode($error_message) ?>;
    const toast = document.getElementById("toast");
    const cooldownActive = <?= json_encode($cooldown_active) ?>;
    const cooldownJustTriggered = <?= json_encode($_SESSION['cooldown_just_triggered']) ?>;
    let remainingTime = <?= $remaining_time ?>;

    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const loginButton = document.getElementById('loginButton');

    function updateCooldownMessage() {
        const minutes = Math.floor(remainingTime / 60);
        const seconds = remainingTime % 60;
        toast.textContent = `Too many failed attempts. Please wait ${minutes}m ${seconds}s.`;
    }

    function startCooldownTimer() {
        // Disable form fields during cooldown
        usernameInput.disabled = true;
        passwordInput.disabled = true;
        loginButton.disabled = true;
        loginButton.style.cursor = 'not-allowed';
        loginButton.style.opacity = '0.7';

        // Show initial cooldown message
        toast.classList.add("show", "error");
        updateCooldownMessage();

        // Start countdown timer
        const cooldownInterval = setInterval(() => {
            remainingTime--;
            if (remainingTime > 0) {
                updateCooldownMessage();
            } else {
                clearInterval(cooldownInterval);
                toast.classList.remove("show", "error");
                usernameInput.disabled = false;
                passwordInput.disabled = false;
                loginButton.disabled = false;
                loginButton.style.cursor = 'pointer';
                loginButton.style.opacity = '1';
            }
        }, 1000);
    }

    // Check if cooldown is already active from a previous page load OR if it was just triggered
    if ((cooldownActive && remainingTime > 0) || cooldownJustTriggered) {
        startCooldownTimer();
    }

    // --- Form Submission Loader ---
    loginForm.addEventListener('submit', function(e) {
        // If the form is already disabled by the cooldown, do nothing.
        if (loginButton.disabled) {
            e.preventDefault();
            return;
        }
        e.preventDefault(); // Prevent the form from submitting immediately

        // Show loader
        loaderOverlay.style.display = 'flex';

        // Set a minimum loading time (e.g., 3 seconds) before submitting
        setTimeout(function() {
            loginForm.submit(); // Now submit the form
        }, 3000); // 3000 milliseconds = 3 seconds
    });
    if (errorMessage) {
        toast.textContent = errorMessage;
        toast.classList.add("show", "error"); // Add 'error' class for red background

        // Hide the toast after 30 seconds
        setTimeout(() => {
            toast.classList.remove("show", "error");
        }, 30000); // 30000 milliseconds = 30 seconds
    }

});
</script>
</body>
</html>