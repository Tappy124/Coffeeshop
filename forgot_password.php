<?php
session_start();
include "includes/db.php";
require 'includes/mailer_config.php';

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);

    if (!empty($username) && filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("SELECT id, username FROM staff WHERE username = ? AND status = 'Active'");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user) {
            // Generate a 6-digit OTP
            $otp = rand(100000, 999999);

            // Store OTP and user info in session
            $_SESSION['otp'] = $otp;
            $_SESSION['otp_expiry'] = time() + 600; // OTP expires in 10 minutes
            $_SESSION['reset_user_id'] = $user['id'];
            $_SESSION['reset_username'] = $user['username'];

            // Send the OTP via email
            $mail_result = send_otp_email($user['username'], $otp);
            if ($mail_result === true) {
                header("Location: verify_otp.php");
                exit;
            } else {
                $detailed_error = htmlspecialchars($mail_result);
                $error_message = "Could not send OTP email. Please check the system's email configuration.";

                // Add a specific hint for the most common Gmail error.
                if (strpos($detailed_error, 'Could not authenticate') !== false) {
                    $error_message .= "<br><br><strong>Hint:</strong> This usually means the Gmail 'App Password' in <code>includes/mailer_config.php</code> is incorrect or has not been set.";
                }
            }
        } else {
            $error_message = "No active account found with that username/email.";
        }
    } else {
        $error_message = "Please enter a valid username/email address.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - Brewventory</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/extracted_styles.css">
    <link rel="icon" type="image/x-icon" href="images/logo.png">
</head>
<body class="login-body">
    <div class="login-container">
        <img src="images/logo.png" alt="Bigger Brew Logo">
        <h1>Forgot Password</h1>
        <p>Enter your username (email) to receive a password reset code.</p>
        <form method="POST" action="forgot_password.php">
            <input type="email" name="username" placeholder="Enter your username/email" required>
            <button type="submit">Send Reset Code</button>
        </form>
        <a href="login.php" class="back-link">Back to Login</a>
    </div>

    <!-- Toast Message -->
    <div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Toast Message for Login Errors ---
    const errorMessage = <?= json_encode($error_message) ?>;
    const toast = document.getElementById("toast");

    if (errorMessage) {
        // Use a temporary div to parse the HTML content of the error message
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = errorMessage;
        toast.textContent = tempDiv.textContent || tempDiv.innerText || "";

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