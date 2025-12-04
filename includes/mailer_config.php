<?php
// === Include PHPMailer ===
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/src/Exception.php'; require_once __DIR__ . '/PHPMailer/src/PHPMailer.php'; require_once __DIR__ . '/PHPMailer/src/SMTP.php';

/**
 * Send a One-Time Password (OTP) email for password reset
 *
 * @param string $recipient_email The email address of the recipient
 * @param string $otp The generated one-time password
 * @return bool|string Returns true on success or the error message on failure
 */
function send_otp_email($recipient_email, $otp) {
    $mail = new PHPMailer(true);

    try {
        // === SMTP Server Settings ===
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';          // Gmail SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'sbigbrew637@gmail.com';   // <-- Your Gmail address
        $mail->Password   = 'ibof aryu qkgm retm';     // <-- 16-character Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Using SSL encryption
        $mail->Port       = 465;                        // SSL port

        // === Email Headers ===
        $mail->setFrom($mail->Username, 'Bigger Brew Inventory System');
        $mail->addAddress($recipient_email);            // Receiver email

        // === Email Content ===
        $mail->isHTML(true);
        $mail->Subject = 'Your Password Reset OTP';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif;'>
                <h2 style='color: #4CAF50;'>Bigger Brew Inventory System</h2>
                <p>Your One-Time Password (OTP) for password reset is:</p>
                <h2 style='color: #e53935;'>{$otp}</h2>
                <p>This code will expire in <b>10 minutes</b>.</p>
                <br>
                <small style='color: gray;'>If you didn’t request this, you can safely ignore this message.</small>
            </div>";
        $mail->AltBody = "Your One-Time Password (OTP) for Bigger Brew Inventory is: {$otp}. This code will expire in 10 minutes.";

        // === Send the email ===
        $mail->send();
        return true;

    } catch (Exception $e) {
        // Log or return detailed error message for debugging
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return "Mailer Error: " . $mail->ErrorInfo;
    }
}
?>
