<?php
// staff_admin.php
$mysqli = new mysqli("localhost", "root", "", "coffee_shop");
if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: " . $mysqli->connect_error);
}

// Handle Add Staff form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {
    $name = $mysqli->real_escape_string($_POST['name']);
    $role = $mysqli->real_escape_string($_POST['role']);
    $status = $mysqli->real_escape_string($_POST['status']);
    $mysqli->query("INSERT INTO staff (name, role, status) VALUES ('$name', '$role', '$status')");
    header("Location: staff_admin.php");
    exit;
}

// Fetch staff data
$staff = [];
$result = $mysqli->query("SELECT id, name, role, status FROM staff");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }
    $result->free();
}

// Fetch admin data
$admins = [];
$result = $mysqli->query("SELECT id, name, email FROM admins");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }
    $result->free();
}
$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staffing & Admin Panel</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
</head>
<body>
    <div class="container">

        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo">
                <img src="images/logo.png" alt="Logo">
            </div>
            <h2>Dashboard</h2>
            <ul>
                <li><a href="dashboard2.php">Dashboard</a></li>
                <li><a href="products.php">Inventory</a></li>
                <li><a href="customers.php">Customers</a></li>
                <li><a href="#">Waste</a></li>
                <li><a href="sales.php">Sales</a></li>
                <li><a href="staff_admin.php" class="active">Staff</a></li>
                <li><a href="#">Reports</a></li>
            </ul>
        </aside>

        <!-- Main -->
        <main class="main">
            <header>
                <h1>Staffing & Admin Management</h1>
                <button class="btn" id="addStaffBtn">+ Add Staff</button>
            </header>

            <!-- Staff Table -->
            <section class="box">
                <h2>Staff List</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($staff as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['id']); ?></td>
                            <td><?php echo htmlspecialchars($s['name']); ?></td>
                            <td><?php echo htmlspecialchars($s['role']); ?></td>
                            <td><?php echo htmlspecialchars($s['status']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <!-- Admin Table -->
            <section class="box">
                <h2>Admin Accounts</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($admins as $a): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($a['id']); ?></td>
                            <td><?php echo htmlspecialchars($a['name']); ?></td>
                            <td><?php echo htmlspecialchars($a['email']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>

    <!-- Add Staff Modal -->
    <div class="modal" id="addStaffModal">
        <div class="modal-content" style="box-shadow: 0 8px 24px rgba(0,0,0,0.18); border: 1px solid #e0e0e0;">
            <span class="close" id="closeModal">&times;</span>
            <h2 style="margin-top:0; color:#4a6c6f; text-align:center;">Add Staff</h2>
            <form method="POST" action="staff_admin.php" style="display:flex; flex-direction:column; gap:12px;">
                <input type="hidden" name="add_staff" value="1">
                <label style="font-weight:500; color:#333;">Name:</label>
                <input type="text" name="name" required style="padding:8px; border-radius:4px; border:1px solid #bdbdbd;">
                <label style="font-weight:500; color:#333;">Role:</label>
                <input type="text" name="role" required style="padding:8px; border-radius:4px; border:1px solid #bdbdbd;">
                <label style="font-weight:500; color:#333;">Status:</label>
                <select name="status" required style="padding:8px; border-radius:4px; border:1px solid #bdbdbd;">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
                <button type="submit" class="btn" style="background:#4a6c6f; color:#fff; border:none; border-radius:4px; padding:10px; font-weight:600; margin-top:10px; cursor:pointer;">Add</button>
            </form>
        </div>
    </div>
    <script>
        document.getElementById('addStaffBtn').onclick = function() {
            document.getElementById('addStaffModal').style.display = 'block';
        };
        document.getElementById('closeModal').onclick = function() {
            document.getElementById('addStaffModal').style.display = 'none';
        };
        window.onclick = function(event) {
            if (event.target == document.getElementById('addStaffModal')) {
                document.getElementById('addStaffModal').style.display = 'none';
            }
        };
    </script>
</body>
</html>
