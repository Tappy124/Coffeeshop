<?php


$mysqli = new mysqli("localhost", "root", "", "coffee_shop");
if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: " . $mysqli->connect_error);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {
    $name = $mysqli->real_escape_string($_POST['name']);
    $role = $mysqli->real_escape_string($_POST['role']);
    $status = $mysqli->real_escape_string($_POST['status']);
    $mysqli->query("INSERT INTO staff (name, role, status) VALUES ('$name', '$role', '$status')");
    header("Location: staff_admin.php");
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_staff'])) {
    $id = (int)($_POST['edit_id'] ?? 0);
    $name = trim($_POST['edit_name'] ?? '');
    $role = trim($_POST['edit_role'] ?? '');
    $status = trim($_POST['edit_status'] ?? '');

    if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE staff SET name = ?, role = ?, status = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("sssi", $name, $role, $status, $id);
            $stmt->execute();
            $stmt->close();
        }
    }
    header("Location: staff_admin.php");
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_staff'])) {
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    if ($staff_id > 0) {
        $del = $mysqli->prepare("DELETE FROM staff WHERE id = ?");
        if ($del) {
            $del->bind_param("i", $staff_id);
            $del->execute();
            $del->close();
        }
    }
    header("Location: staff_admin.php");
    exit;
}


$staff = [];
$result = $mysqli->query("SELECT id, name, role, status FROM staff");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }
    $result->free();
}


$admins = [];
$result = $mysqli->query("SELECT id, name FROM staff WHERE LOWER(role) = 'admin' ORDER BY id ASC");
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
    <link rel="icon" type="image/x-icon" href="images/logo.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
   
</head>
<body>
    <div class="container">
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

        <main class="main">
            <header>
                <h1>Staffing & Admin Management</h1>
                <div style="display:flex; gap:10px; align-items:center;">
                    <button class="btn" id="addStaffBtn">+ Add Staff</button>
                    <?php include 'includes/theme-toggle.php'; ?>
                </div>
                
            </header>

            <section class="box">
                <h2>Staff List</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th><th>Name</th><th>Role</th><th>Status</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($staff as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['id']); ?></td>
                            <td><?php echo htmlspecialchars($s['name']); ?></td>
                            <td><?php echo htmlspecialchars($s['role']); ?></td>
                            <td><?php echo htmlspecialchars($s['status']); ?></td>
                            <td>
                                <button class="action-btn edit-btn" data-id="<?php echo $s['id']; ?>" data-name="<?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>" data-role="<?php echo htmlspecialchars($s['role'], ENT_QUOTES); ?>" data-status="<?php echo htmlspecialchars($s['status'], ENT_QUOTES); ?>">Edit</button>

                                <form method="POST" action="staff_admin.php" style="display:inline;" onsubmit="return confirm('Remove this staff?')">
                                    <input type="hidden" name="staff_id" value="<?php echo $s['id']; ?>">
                                    <button class="action-btn delete-btn" type="submit" name="delete_staff">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="box">
                <h2>Admin Accounts</h2>
                <p>To make/remove admin access, edit staff role.</p>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th><th>Name</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($admins as $a): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($a['id']); ?></td>
                            <td><?php echo htmlspecialchars($a['name']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>

  
    <div class="modal" id="addStaffModal">
        <div class="modal-content">
            <span class="close" id="closeModal">&times;</span>
            <h2 style="text-align:center; color:#4a6c6f;">Add Staff</h2>
            <form method="POST" action="staff_admin.php" id="addStaffForm">
                <input type="hidden" name="add_staff" value="1">
                <label>Name:</label>
                <input type="text" name="name" required>
                <label>Role:</label>
                <input type="text" name="role" required>
                <label>Status:</label>
                <select name="status" required>
                    <option>Active</option><option>Inactive</option>
                </select>
                <button type="submit" class="btn" style="margin-top:10px;">Add</button>
            </form>
        </div>
    </div>

    
    <div class="modal" id="editStaffModal">
        <div class="modal-content">
            <span class="close" id="closeEditModal">&times;</span>
            <h2 style="text-align:center; color:#4a6c6f;">Edit Staff</h2>
            <form method="POST" action="staff_admin.php" id="editStaffForm">
                <input type="hidden" name="edit_staff" value="1">
                <input type="hidden" name="edit_id" id="edit_id" value="">
                <label>Name:</label>
                <input type="text" name="edit_name" id="edit_name" required>
                <label>Role:</label>
                <input type="text" name="edit_role" id="edit_role" required>
                <label>Status:</label>
                <select name="edit_status" id="edit_status" required>
                    <option>Active</option><option>Inactive</option>
                </select>
                <button type="submit" class="btn" style="margin-top:10px;">Save</button>
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

     
        const editButtons = document.querySelectorAll('.edit-btn');
        const editModal = document.getElementById('editStaffModal');
        const closeEdit = document.getElementById('closeEditModal');

        editButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('edit_id').value = btn.getAttribute('data-id');
                document.getElementById('edit_name').value = btn.getAttribute('data-name');
                document.getElementById('edit_role').value = btn.getAttribute('data-role');
                document.getElementById('edit_status').value = btn.getAttribute('data-status');
                editModal.style.display = 'block';
            });
        });

        closeEdit.onclick = function() {
            editModal.style.display = 'none';
        };

        
        window.onclick = function(event) {
            const addModal = document.getElementById('addStaffModal');
            if (event.target === addModal) addModal.style.display = 'none';
            if (event.target === editModal) editModal.style.display = 'none';
        };
    </script>
</body>
</html>
