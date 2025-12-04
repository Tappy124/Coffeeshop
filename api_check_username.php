<?php
session_start();
header('Content-Type: application/json');

// Basic security checks
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin' || !isset($_GET['username'])) {
    echo json_encode(['exists' => false, 'error' => 'Unauthorized']);
    exit;
}

include "includes/db.php";

$username = trim($_GET['username']);
$response = ['exists' => false];

if (!empty($username)) {
    $stmt = $conn->prepare("SELECT id FROM staff WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $response['exists'] = true;
    }
    $stmt->close();
}

$conn->close();
echo json_encode($response);