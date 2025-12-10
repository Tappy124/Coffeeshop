<?php
session_start();
include "includes/db.php";

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $role = 'Customer'; // All new registrations default to Customer role

    // Validation
    if (empty($name) || empty($username) || empty($password) || empty($confirm_password)) {
        $error_message = "All fields are required.";
    } elseif (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $error_message = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } else {
        // Check if username already exists
        $stmt = $conn->prepare("SELECT id FROM staff WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error_message = "This email is already registered. Please use a different email or <a href='login.php'>login here</a>.";
            $stmt->close();
        } else {
            $stmt->close();
            
            // Hash the password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert new user
            $stmt = $conn->prepare("INSERT INTO staff (name, username, password, role, status) VALUES (?, ?, ?, ?, 'Active')");
            $stmt->bind_param("ssss", $name, $username, $hashed_password, $role);
            
            if ($stmt->execute()) {
                $success_message = "Registration successful! You can now <a href='login.php'>login here</a>.";
                
                // Optional: Auto-login after registration
                // Uncomment below if you want users to be automatically logged in
                /*
                $user_id = $stmt->insert_id;
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = $role;
                
                if ($role === 'Admin') {
                    header("Location: admin_dashboard.php");
                } else {
                    header("Location: staff_dashboard.php");
                }
                exit;
                */
            } else {
                $error_message = "Registration failed. Please try again.";
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Bigger Brew</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/extracted_styles.css">
    <link rel="icon" type="image/x-icon" href="images/logo.png">
</head>
<body class="login-body">
    <div class="login-container">
        <img src="images/logo.png" alt="Bigger Brew Logo">
        <h1>Create Account</h1>
        <p>Register to access the Bigger Brew system</p>

        <?php if (!empty($success_message)): ?>
            <div class="success-message">
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="error-message">
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php" id="registerForm">
            <label for="name">Full Name</label>
            <input type="text" name="name" id="name" placeholder="Enter your full name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">

            <label for="username">Email Address</label>
            <input type="email" name="username" id="username" placeholder="your.email@example.com" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

            <label for="password">Password</label>
            <div class="password-input-container">
                <input type="password" name="password" id="password" placeholder="Minimum 8 characters" required>
                <span class="toggle-password-visibility" id="togglePassword"></span>
                <span class="password-strength-text" id="passwordStrength"></span>
            </div>
            <div class="validation-errors" id="passwordValidation">
                <ul id="passwordRequirements"></ul>
            </div>

            <label for="confirm_password">Confirm Password</label>
            <div class="password-input-container">
                <input type="password" name="confirm_password" id="confirm_password" placeholder="Re-enter your password" required>
                <span class="toggle-password-visibility" id="toggleConfirmPassword"></span>
                <span class="confirm-password-strength-text" id="confirmPasswordMatch"></span>
            </div>

            <button type="submit">Create Account</button>
        </form>

        <div class="text-right" style="margin-top: 20px;">
            <a href="login.php" class="muted-link">Already have an account? Login here</a>
        </div>
        <div class="text-right" style="margin-top: 10px;">
            <a href="index.php" class="muted-link">Back to Homepage</a>
        </div>
    </div>

    <script>
        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        const confirmPasswordInput = document.getElementById('confirm_password');

        togglePassword.addEventListener('click', function() {
            const type = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = type;
            this.classList.toggle('visible');
        });

        toggleConfirmPassword.addEventListener('click', function() {
            const type = confirmPasswordInput.type === 'password' ? 'text' : 'password';
            confirmPasswordInput.type = type;
            this.classList.toggle('visible');
        });

        // Password strength indicator
        const passwordStrength = document.getElementById('passwordStrength');
        const passwordValidation = document.getElementById('passwordValidation');
        const passwordRequirements = document.getElementById('passwordRequirements');

        function checkPasswordStrength(password) {
            let strength = 0;
            const requirements = [];

            if (password.length >= 8) {
                strength++;
            } else {
                requirements.push('At least 8 characters');
            }

            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) {
                strength++;
            } else {
                requirements.push('Both uppercase and lowercase letters');
            }

            if (/\d/.test(password)) {
                strength++;
            } else {
                requirements.push('At least one number');
            }

            if (/[^a-zA-Z0-9]/.test(password)) {
                strength++;
            } else {
                requirements.push('At least one special character');
            }

            return { strength, requirements };
        }

        passwordInput.addEventListener('input', function() {
            const password = this.value;
            const { strength, requirements } = checkPasswordStrength(password);

            passwordStrength.className = 'password-strength-text';
            
            if (password.length === 0) {
                passwordStrength.textContent = '';
                passwordValidation.style.display = 'none';
            } else if (strength <= 1) {
                passwordStrength.textContent = 'Weak';
                passwordStrength.classList.add('strength-weak');
                showRequirements(requirements);
            } else if (strength === 2) {
                passwordStrength.textContent = 'Medium';
                passwordStrength.classList.add('strength-medium');
                showRequirements(requirements);
            } else if (strength === 3) {
                passwordStrength.textContent = 'Good';
                passwordStrength.classList.add('strength-medium');
                showRequirements(requirements);
            } else {
                passwordStrength.textContent = 'Strong';
                passwordStrength.classList.add('strength-strong');
                passwordValidation.style.display = 'none';
            }
        });

        function showRequirements(requirements) {
            if (requirements.length > 0) {
                passwordValidation.style.display = 'block';
                passwordRequirements.innerHTML = requirements.map(req => `<li>${req}</li>`).join('');
            } else {
                passwordValidation.style.display = 'none';
            }
        }

        // Confirm password match indicator
        const confirmPasswordMatch = document.getElementById('confirmPasswordMatch');

        function checkPasswordMatch() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (confirmPassword.length === 0) {
                confirmPasswordMatch.textContent = '';
                confirmPasswordMatch.className = 'confirm-password-strength-text';
            } else if (password === confirmPassword) {
                confirmPasswordMatch.textContent = 'Match';
                confirmPasswordMatch.className = 'confirm-password-strength-text password-match';
            } else {
                confirmPasswordMatch.textContent = 'No Match';
                confirmPasswordMatch.className = 'confirm-password-strength-text password-mismatch';
            }
        }

        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        passwordInput.addEventListener('input', checkPasswordMatch);

        // Form validation on submit
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match. Please check and try again.');
                confirmPasswordInput.focus();
                return false;
            }

            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long.');
                passwordInput.focus();
                return false;
            }
        });
    </script>
</body>
</html>
