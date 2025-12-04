ZZ<?php
session_start();

// Redirect if the user hasn't started the forgot password process
if (!isset($_SESSION['otp']) || !isset($_SESSION['reset_user_id'])) {
    header("Location: forgot_password.php");
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered_otp = trim($_POST['otp']);

    if (time() > $_SESSION['otp_expiry']) {
        $error_message = "The OTP has expired. Please request a new one.";
        // Clear the expired OTP
        unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['reset_user_id'], $_SESSION['reset_username']);
    } elseif ($entered_otp == $_SESSION['otp']) {
        // OTP is correct, set a flag and redirect to reset password page
        $_SESSION['otp_verified'] = true;
        header("Location: reset_password.php");
        exit;
    } else {
        $error_message = "The OTP you entered is incorrect.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify OTP - Brewventory</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <style>
        body { display: flex; justify-content: center; align-items: center; height: 100vh; background-color: var(--bg); }
        .login-container { width: 100%; max-width: 400px; padding: 40px; background-color: var(--panel); border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); text-align: center; }
        .login-container img { width: 120px; height: 120px; margin-bottom: 20px; }
        .login-container h1 { color: var(--accent); margin-bottom: 10px; }
        .login-container p { color: var(--subtext); margin-bottom: 25px; }
        .login-container input { width: 100%; padding: 12px; margin-bottom: 15px; border-radius: 6px; border: 1px solid #ccc; font-size: 1rem; text-align: center; letter-spacing: 5px; }
        .login-container button { width: 100%; padding: 12px; border: none; border-radius: 6px; background-color: var(--accent); color: white; font-size: 1rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s; }
        .login-container button:hover { opacity: 0.9; }
        .login-container .back-link { display: block; margin-top: 20px; color: var(--accent); text-decoration: none; }
        .error-message { color: #c62828; background-color: #ffebee; padding: 10px; border-radius: 6px; margin-bottom: 15px; border: 1px solid #ffcdd2; }
        .action-links { display: flex; justify-content: space-between; margin-top: 20px; font-size: 0.9rem; }
        .action-links a { color: var(--subtext); text-decoration: none; }
        .action-links a:hover { color: var(--accent); }
        #cancelReset:hover {
            color: #c62828; /* Red hover for cancel */
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
        <h1>Verify Your Identity</h1>
        <p>An OTP has been sent to <strong><?= htmlspecialchars($_SESSION['reset_username']) ?></strong>. Please enter it below.</p>
        <form method="POST" action="verify_otp.php">
            <input type="text" name="otp" placeholder="Enter 6-digit OTP" required maxlength="6" pattern="\d{6}" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
            <button type="submit">Verify Code</button>
        </form>
        <div class="action-links">
            <a href="#" id="cancelReset">Cancel</a>
            <a href="#" id="resendOtpLink">Request a new code</a>
        </div>
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
    const confirmModal = document.getElementById('confirmModal');

    // --- Toast Message for Errors ---
    const errorMessage = <?= json_encode($error_message) ?>;
    const toast = document.getElementById("toast");

    if (errorMessage) {
        toast.textContent = errorMessage;
        toast.classList.add("show", "error"); // Add 'error' class for red background

        // Hide the toast after 30 seconds
        setTimeout(() => {
            toast.classList.remove("show", "error");
        }, 30000); // 30000 milliseconds = 30 seconds
    }

    // --- Cancel Confirmation Logic ---
    const cancelLink = document.getElementById('cancelReset');
    cancelLink.addEventListener('click', function(e) {
        e.preventDefault();
        const confirmMessage = document.getElementById('confirmMessage');
        const confirmYesBtn = document.getElementById('confirmYesBtn');

        confirmMessage.textContent = 'Are you sure you want to cancel the password reset?';
        confirmYesBtn.textContent = 'Yes, Cancel';
        confirmYesBtn.className = 'confirm-btn-yes'; // Reset style
        confirmModal.style.display = 'block';

        confirmYesBtn.onclick = function() {
            window.location.href = 'login.php';
        };
    });

    // --- Modal Closing Logic ---
    function closeModal() {
        confirmModal.style.display = 'none';
    }
    document.getElementById('closeConfirmModal').addEventListener('click', closeModal);
    document.getElementById('confirmCancelBtn').addEventListener('click', closeModal);
    window.addEventListener('click', (e) => { if (e.target === confirmModal) closeModal(); });

    // --- Resend OTP Logic ---
    const resendLink = document.getElementById('resendOtpLink');
    let resendInterval;

    function startCooldown(duration) {
        const endTime = Date.now() + duration * 1000;
        sessionStorage.setItem('resendOtpCooldown', endTime);

        resendLink.style.pointerEvents = 'none';
        resendLink.style.color = '#999';

        resendInterval = setInterval(() => {
            const remaining = Math.round((endTime - Date.now()) / 1000);
            if (remaining > 0) {
                resendLink.textContent = `Request a new code in ${remaining}s`;
            } else {
                clearInterval(resendInterval);
                resendLink.textContent = 'Request a new code';
                resendLink.style.pointerEvents = 'auto';
                resendLink.style.color = '';
                sessionStorage.removeItem('resendOtpCooldown');
            }
        }, 1000);
    }

    // Check for an existing cooldown on page load
    const cooldownEndTime = sessionStorage.getItem('resendOtpCooldown');
    if (cooldownEndTime && Date.now() < cooldownEndTime) {
        const remainingDuration = Math.round((cooldownEndTime - Date.now()) / 1000);
        if (remainingDuration > 0) {
            startCooldown(remainingDuration);
        }
    }

    resendLink.addEventListener('click', function(e) {
        e.preventDefault();

        // If a cooldown is already running, do nothing
        if (resendLink.style.pointerEvents === 'none') {
            return;
        }

        startCooldown(60); // Start a new 60-second cooldown

        // Make an AJAX call to resend the OTP
        fetch('resend_otp.php', { method: 'POST' })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    toast.textContent = 'A new OTP has been sent to your email.';
                    toast.classList.remove('error');
                    toast.classList.add('show');
                    setTimeout(() => toast.classList.remove('show'), 5000);
                } else {
                    toast.textContent = data.message || 'Could not resend OTP. Please try again later.';
                    toast.classList.add('show', 'error');
                    setTimeout(() => toast.classList.remove('show', 'error'), 5000);

                    // If it failed, re-enable the link immediately
                    clearInterval(resendInterval);
                    resendLink.textContent = 'Request a new code';
                    resendLink.style.pointerEvents = 'auto';
                    resendLink.style.color = '';
                }
            });
    });
});
</script>
</body>
</html>