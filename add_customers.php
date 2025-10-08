<?php

include "db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $joined_at = date('Y-m-d H:i:s');

    $conn->query("INSERT INTO customers (name, email, phone, joined_at) VALUES ('$name', '$email', '$phone', '$joined_at')");
    header("Location: customers.php");
    exit;
}

<!DOCTYPE html>
<html>
<head>
    <title>Add Customer</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .form-container {
            max-width: 400px;s
            margin: 40px auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        }
        .form-container h2 {
            margin-top: 0;
            color: #4a6c6f;
            text-align: center;
        }
        .form-container label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
        }
        .form-container input[type="text"], .form-container input[type="email"] {
            width: 100%;
            padding: 8px;
            margin-bottom: 14px;
            border-radius: 4px;
            border: 1px solid #bdbdbd;
        }
        .form-container button {
            background: #4a6c6f;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 10px 0;
            font-weight: 600;
            width: 100%;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Add Customer</h2>
        <form method="POST" action="add_customer.php">
            <label>Name:</label>
            <input type="text" name="name" required>
            <label>Email:</label>
            <input type="email" name="email" required>
            <label>Phone:</label>
            <input type="text" name="phone" required>
            <button type="submit">Add Customer</button>
        </form>
    </div>
    
</body>
</html>