<?php
session_start();

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- Authentication Check ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit;
}

include "includes/db.php";

// --- Add User ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $name = trim($_POST['name']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $role = trim($_POST['role']);
    $status = 'Active';

    if (!empty($name) && !empty($username) && !empty($password) && !empty($role) && $password === $confirm_password) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO staff (name, username, password, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $name, $username, $hashed_password, $role, $status);
        $stmt->execute();
        $stmt->close();

        echo "<script>localStorage.setItem('systemMessage','User added successfully!');window.location.href='system_management.php';</script>";
        exit;
    }
}

// --- Edit User ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $id = (int)$_POST['edit_id'];
    $name = trim($_POST['edit_name']);
    $username = trim($_POST['edit_username']);
    $role = trim($_POST['edit_role']);
    $status = trim($_POST['edit_status']);

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE staff SET name=?, username=?, role=?, status=? WHERE id=?");
        $stmt->bind_param("ssssi", $name, $username, $role, $status, $id);
        $stmt->execute();
        $stmt->close();
        echo "<script>localStorage.setItem('systemMessage','User updated successfully!');window.location.href='system_management.php';</script>";
        exit;
    }
}

// --- Deactivate ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_user'])) {
    $id = (int)$_POST['deactivate_id'];
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE staff SET status='Inactive' WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        if ($id === (int)$_SESSION['user_id']) {
            echo "<script>localStorage.setItem('systemMessage','Your account has been set to Inactive. Logging out...');setTimeout(()=>window.location.href='logout.php',1500);</script>";
            exit;
        }
    }
    echo "<script>localStorage.setItem('systemMessage','User status set to Inactive!');window.location.href='system_management.php';</script>";
    exit;
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $id = (int)$_POST['delete_id'];
    if ($id > 0 && $id !== (int)$_SESSION['user_id']) {
        $stmt = $conn->prepare("DELETE FROM staff WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    echo "<script>localStorage.setItem('systemMessage','User permanently deleted!');window.location.href='system_management.php';</script>";
    exit;
}

// --- Search & Sort ---
$search = trim($_GET['search'] ?? '');
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'ASC';
$sort_columns_whitelist = ['id','name','username','role','status'];
if (!in_array($sort_by,$sort_columns_whitelist)) $sort_by='name';
if (!in_array(strtoupper($sort_order),['ASC','DESC'])) $sort_order='ASC';

$sql = "SELECT * FROM staff";
$params = [];
$types = '';
if (!empty($search)) { $sql .= " WHERE name LIKE ? OR username LIKE ? OR role LIKE ?"; $like = "%$search%"; $params = [$like,$like,$like]; $types='sss'; }
$sql .= " ORDER BY `$sort_by` $sort_order";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types,...$params);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>System Management</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
    <link rel="stylesheet" href="css/extracted_styles.css">
</head>
<body>
<div class="container">
  <!-- ...existing HTML... -->
  <?php // Reuse the existing markup below (kept concise here) ?>
  <!-- Sidebar and main content -->
  <aside class="sidebar"> ...existing code... </aside>
  <main class="main"> ...existing code... </main>
</div>
<script>
// Minimal JS to preserve modal and form behaviors (kept concise for clarity).
document.addEventListener('DOMContentLoaded', function(){
  // Basic modal handling and form confirmations are implemented in other pages; keep this minimal.
});
</script>
</body>
</html>
                    // If username is unique, proceed to confirmation
                    document.getElementById('confirmMessage').textContent = 'Are you sure you want to add this new user?';
                    document.getElementById('confirmYesBtn').textContent = 'Yes, Add User';
                    confirmModal.style.display = 'block';

                    document.getElementById('confirmYesBtn').onclick = function() {
                        addUserForm.submit();
                    };
                }
            })
            .catch(error => {
                console.error('Error checking username:', error);
                alert('An error occurred while checking the username. Please try again.');
            });
    });

    // --- Password Strength Meter ---
    function setupPasswordStrength(inputId, strengthTextId) {
        const passwordInput = document.getElementById(inputId);
        const strengthText = document.getElementById(strengthTextId);

        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let score = 0;

            if (password.length === 0) {
                strengthText.textContent = '';
                strengthText.className = '';
                return;
            }

            if (password.length >= 8) score++;
            if (/[a-z]/.test(password)) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^a-zA-Z0-9]/.test(password)) score++;

            let strength = '';
            let strengthClass = '';

            if (score >= 5) {
                strength = 'Strong';
                strengthClass = 'strength-strong';
            } else if (score >= 4) {
                strength = 'Medium';
                strengthClass = 'strength-medium';
            } else if (score >= 3) {
                strength = 'Weak';
                strengthClass = 'strength-weak';
            } else {
                strength = 'Very Weak';
                strengthClass = 'strength-very-weak';
            }

            strengthText.textContent = strength;
            strengthText.className = strengthClass;
        });
    }

    setupPasswordStrength('password', 'password-strength-text');

    // --- Password Match Indicator ---
    const passwordInputForMatch = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const confirmPasswordText = document.getElementById('confirm-password-strength-text');

    function validatePasswordMatch() {
        if (confirmPasswordInput.value.length > 0) {
            if (passwordInputForMatch.value === confirmPasswordInput.value) {
                confirmPasswordText.textContent = 'Match password';
                confirmPasswordText.className = 'password-match';
            } else {
                confirmPasswordText.textContent = "Doesn't match password";
                confirmPasswordText.className = 'password-mismatch';
            }
        } else {
            confirmPasswordText.textContent = '';
            confirmPasswordText.className = '';
        }
    }
    passwordInputForMatch.addEventListener('input', validatePasswordMatch);
    confirmPasswordInput.addEventListener('input', validatePasswordMatch);

    // --- Password Visibility Toggle ---
    document.querySelectorAll('.toggle-password-visibility').forEach(toggle => {
        toggle.addEventListener('click', function() {
            const inputId = this.dataset.for;
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                this.classList.add('visible');
            } else {
                input.type = 'password';
                this.classList.remove('visible');
            }
        });
    });

    // --- Edit User Form Confirmation ---
    editUserForm.addEventListener('submit', function(e) {
        e.preventDefault(); // Stop submission to show confirmation
        document.getElementById('confirmMessage').textContent = 'Are you sure you want to update this user\'s details?';
        document.getElementById('confirmYesBtn').textContent = 'Yes, Update';
        confirmModal.style.display = 'block';
        document.getElementById('confirmYesBtn').onclick = () => editUserForm.submit();
    });

    // --- Edit Button Logic ---
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('edit_id').value = btn.dataset.id;
            document.getElementById('edit_name').value = btn.dataset.name;
            document.getElementById('edit_username').value = btn.dataset.username;
            document.getElementById('edit_role').value = btn.dataset.role;
            document.getElementById('edit_status').value = btn.dataset.status;
            editModal.style.display = 'block';
        });
    });

    // --- Deactivate Button Logic ---
    document.querySelectorAll('.deactivate-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const form = e.target.closest('form');
            const userId = form.querySelector('input[name="deactivate_id"]').value;
            const currentUserId = '<?= $_SESSION['user_id'] ?>';
            const userName = form.closest('tr').querySelector('td:nth-child(2)').textContent;
            
            let message = `Are you sure you want to set the user "${userName}" to Inactive? They will no longer be able to log in.`;
            if (userId === currentUserId) {
                message = `<strong>Warning:</strong> You are about to make your own account Inactive. You will be logged out and will not be able to log back in. Are you sure?`;
            }
            document.getElementById('confirmMessage').innerHTML = message;
            document.getElementById('confirmYesBtn').textContent = 'Yes, Deactivate';
            confirmModal.style.display = 'block';

            document.getElementById('confirmYesBtn').onclick = function() {
                form.submit();
            };
        });
    });

    // --- Permanent Delete Button Logic ---
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const form = e.target.closest('form');
            const userName = form.closest('tr').querySelector('td:nth-child(2)').textContent;
            
            document.getElementById('confirmMessage').innerHTML = `Are you sure you want to <strong>PERMANENTLY DELETE</strong> the user "${userName}"?<br><br>This action cannot be undone.`;
            document.getElementById('confirmYesBtn').textContent = 'Yes, Delete';
            document.getElementById('confirmYesBtn').style.backgroundColor = '#c62828'; // Make button red for delete
            confirmModal.style.display = 'block';

            document.getElementById('confirmYesBtn').onclick = function() {
                form.submit();
                document.getElementById('confirmYesBtn').style.backgroundColor = ''; // Reset color
            };
        });
    });


    // --- Toast Message ---
    const toast = document.getElementById("toast");
    const msg = localStorage.getItem("systemMessage");
    if (msg) {
        toast.textContent = msg;
        toast.classList.add("show");
        setTimeout(() => toast.classList.remove("show"), 3000);
        localStorage.removeItem("systemMessage");
    }
    const errorMsg = localStorage.getItem("systemError");
    if (errorMsg) {
        toast.textContent = errorMsg;
        toast.classList.add("show", "error"); // Add error class for styling
        // Show error for longer
        setTimeout(() => toast.classList.remove("show", "error"), 5000);
        localStorage.removeItem("systemError");
    }

    // --- Logout Confirmation ---
    const logoutLink = document.querySelector('a[href="logout.php"]');
    logoutLink.addEventListener('click', function(e) {
        e.preventDefault();
        const confirmBtn = document.getElementById('confirmYesBtn');
        document.getElementById('confirmMessage').textContent = 'Are you sure you want to log out?';
        confirmBtn.textContent = 'Yes, Logout';
        confirmBtn.className = 'confirm-btn-yes btn-logout-yes'; // Add logout specific class
        confirmModal.style.display = 'block';
        confirmBtn.onclick = function() {
            window.location.href = 'logout.php';
        };
    });

    // --- Live Search Filter ---
    const searchInput = document.getElementById('searchInput');
    const tableBody = document.getElementById('userTableBody');
    const tableRows = tableBody.getElementsByTagName('tr');
    const noResultsRow = tableBody.querySelector('td[colspan="6"]');

    searchInput.addEventListener('keyup', function() {
        const searchTerm = searchInput.value.toLowerCase();
        let visibleRows = 0;

        for (let i = 0; i < tableRows.length; i++) {
            const row = tableRows[i];
            // Skip the 'No users found' row
            if (row.contains(noResultsRow)) continue;

            const rowText = row.textContent.toLowerCase();

            if (rowText.includes(searchTerm)) {
                row.style.display = ''; // Show row
                visibleRows++;
            } else {
                row.style.display = 'none'; // Hide row
            }
        }

        if (noResultsRow) {
            // Show 'No users found' row if no other rows are visible
            noResultsRow.parentElement.style.display = (visibleRows === 0) ? '' : 'none';
        }
    });
});
</script>
<style>
.status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
}
.status-active {
    background-color: #e0f2f1; /* light green */
    color: #00796b; /* dark green */
}
.status-inactive {
    background-color: #ffebee; /* light red */
    color: #c62828; /* dark red */
}
.toast.error {
    background-color: #c62828; /* Red for errors */
}

.action-btn.deactivate-btn {
    background-color: #fffde7;
    color: #f57f17;
    border: 1px solid #fff9c4;
}
.action-btn.deactivate-btn:hover {
    background-color: #fff9c4;
    color: #e65100;
}

</style>
</body>
</html>