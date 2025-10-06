<?php
include "db.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name  = $_POST['name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];

    $sql = "INSERT INTO customers (name, email, phone) VALUES ('$name', '$email', '$phone')";
    if ($conn->query($sql)) {
        header("Location: customers.php");
        exit;
    } else {
        echo "Error: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Customer</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="main" style="padding:20px;">
    <h1>Add New Customer</h1>
    <form method="POST">
        <label>Customer Name</label><br>
        <input type="text" name="name" required><br><br>

        <label>Email</label><br>
        <input type="email" name="email"><br><br>

        <label>Phone</label><br>
        <input type="text" name="phone"><br><br>

        <button type="submit" class="btn">Save Customer</button>
    </form>
</div>
</body>
</html>
