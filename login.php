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
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: var(--bg);
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 40px;
            background-color: var(--panel);
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            text-align: center;
        }
        .login-container img {
            width: 120px;
            height: 120px;
            margin-bottom: 20px;
        }
        .login-container h1 {
            color: var(--accent);
            margin-bottom: 25px;
        }
        .login-container input {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 6px;
            border: 1px solid #ccc;
            font-size: 1rem;
        }
        .login-container button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            background-color: var(--accent);
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .login-container button:hover {
            opacity: 0.9;
        }
        .error-message {
            color: #c62828;
            background-color: #ffebee;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 15px;
            border: 1px solid #ffcdd2;
        }
        /*.success-message {
            color: #00796b;
            background-color: #e0f2f1;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 15px;
            border: 1px solid #ffcdd2;
        } */
        /* Style for the error toast notification */
        .toast.error {
            background-color: #c62828; /* Red for errors */
        }

        /* --- Password Visibility Toggle Styles --- */
        .password-input-container {
            position: relative;
            margin-bottom: 15px;
        }
        .password-input-container input {
            margin-bottom: 0;
            padding-right: 45px; /* Make space for the icon */
        }
        .toggle-password-visibility {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            user-select: none;
            width: 20px;
            height: 20px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'%3E%3C/path%3E%3Ccircle cx='12' cy='12' r='3'%3E%3C/circle%3E%3C/svg%3E");
            background-size: contain;
            background-repeat: no-repeat;
            opacity: 0.6;
            transition: opacity 0.2s;
        }
        .toggle-password-visibility:hover {
            opacity: 1;
        }
        .toggle-password-visibility.visible {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24'%3E%3C/path%3E%3Cline x1='1' y1='1' x2='23' y2='23'%3E%3C/line%3E%3C/svg%3E");
        }
        /* Hide browser's default password reveal icon */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-webkit-password-reveal-button {
            display: none;
        }

        /* --- Loader Styles --- */
        .loader-overlay {
            display: none; /* Hidden by default */
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(4px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .loader-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3; /* Light grey */
            border-top: 5px solid var(--accent); /* Theme color */
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
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
        <div style="text-align: right; margin-top: 15px;">
            <a href="forgot_password.php" style="color: var(--subtext); font-size: 0.9rem; text-decoration: none;">Forgot Password?</a>
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