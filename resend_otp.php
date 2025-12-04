<?php
session_start();
header('Content-Type: application/json');

// Include necessary files
include "includes/db.php";
require 'includes/mailer_config.php';

// Check if user is in the password reset process
if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_username'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid session. Please start over.']);
    exit;
}

$user_id = $_SESSION['reset_user_id'];
$username = $_SESSION['reset_username'];

try {
    // Generate a new 6-digit OTP
    $otp = rand(100000, 999999);

    // Update the session with the new OTP and expiry time
    $_SESSION['otp'] = $otp;
    $_SESSION['otp_expiry'] = time() + 600; // OTP expires in 10 minutes

    // Send the new OTP via email
    $mail_result = send_otp_email($username, $otp);

    if ($mail_result === true) {
        echo json_encode(['success' => true, 'message' => 'A new OTP has been sent.']);
    } else {
        // If mailer returns an error string
        throw new Exception($mail_result);
    }
} catch (Exception $e) {
    // Log the error and return a generic failure message
    error_log("Resend OTP failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not send a new OTP. Please try again later.']);
}

$conn->close();
?>