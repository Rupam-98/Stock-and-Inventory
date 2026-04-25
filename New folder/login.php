<?php
session_start();

// ── DB CONNECTION ─────────────────────────────────────────────────────────
$host = 'localhost';
$dbname = 'six_sems';
$dbuser = 'postgres';
$dbpass = '1035';

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $dbuser, $dbpass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die('Database connection failed: ' . $e->getMessage());
}

$error = '';
$loginType = 'student'; // Default to student

// ── HANDLE LOGIN ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['login_type'] ?? 'student';
    
    if ($type === 'admin') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (!$username || !$password) {
            $error = '⚠️ Please enter both username and password.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
                $stmt->execute([$username]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($admin && password_verify($password, $admin['password'])) {
                    // Login successful
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_name'] = $admin['name'];
                    $_SESSION['admin_user'] = $admin['username'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['admin_role'] = $admin['role'];
                    header('Location: admin_dashboard.php');
                    exit;
                } else {
                    $error = '❌ Invalid username or password.';
                }
            } catch (Exception $e) {
                $error = '❌ Login error: ' . $e->getMessage();
            }
        }
        $loginType = 'admin';
    }
    elseif ($type === 'student') {
        $student_id = trim($_POST['student_id'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (!$student_id || !$password) {
            $error = '⚠️ Please enter both Student ID and password.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
                $stmt->execute([$student_id]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student && password_verify($password, $student['password'])) {
                    // Login successful
                    $_SESSION['student'] = $student;
                    header('Location: aa (2).php');
                    exit;
                } else {
                    $error = '❌ Invalid Student ID or password.';
                }
            } catch (Exception $e) {
                $error = '❌ Login error: ' . $e->getMessage();
            }
        }
        $loginType = 'student';
    } 
    elseif ($type === 'department') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (!$username || !$password) {
            $error = '⚠️ Please enter both username and password.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * FROM departments WHERE username = ?");
                $stmt->execute([$username]);
                $dept = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dept && password_verify($password, $dept['password'])) {
                    // Login successful
                    $_SESSION['dept_id'] = $dept['id'];
                    $_SESSION['dept_name'] = $dept['name'];
                    $_SESSION['dept_user'] = $dept['username'];
                    $_SESSION['dept_email'] = $dept['email'];
                    header('Location: deptt_dashboard(2).php');
                    exit;
                } else {
                    $error = '❌ Invalid username or password.';
                }
            } catch (Exception $e) {
                $error = '❌ Login error: ' . $e->getMessage();
            }
        }
        $loginType = 'department';
    }
}

// ── REDIRECT IF ALREADY LOGGED IN ─────────────────────────────────────────
if (isset($_SESSION['student'])) {
    header('Location: aa (2).php');
    exit;
}
if (isset($_SESSION['dept_id'])) {
    header('Location: deptt_dashboard(2).php');
    exit;
}
if (isset($_SESSION['admin_id'])) {
    header('Location: admin_dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="login.css">
</head>
<body>

    <div class="login-container">
        <!-- Header -->
        <div class="login-header">
            <div class="login-logo">
                <img src="uni_logo.jpeg" alt="University Logo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 14px;">
            </div>
            <h1>NLU PORTAL</h1>
            <p>Inventory Management System</p>
        </div>

        <!-- Body -->
        <div class="login-body">

            <!-- Tab Selection -->
            <div class="login-tabs">
                <button class="tab-btn active" onclick="switchTab('admin', this)">Admin</button>
                <button class="tab-btn" onclick="switchTab('department', this)">Department</button>
                <button class="tab-btn" onclick="switchTab('student', this)">Student</button>
            </div>

            <?php if ($error): ?>
                <div class="alert error">
                    <span><?= $error ?></span>
                </div>
            <?php endif; ?>

            <!-- Admin Login Tab -->
            <form method="POST" class="tab-content active" id="tab-admin">
                <input type="hidden" name="login_type" value="admin">

                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="Enter admin username" required autofocus>
                </div>

                <div class="form-group password-group">
                    <label>Password</label>
                    <input type="password" name="password" id="admin-pwd" placeholder="Enter your password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword('admin-pwd')">👁️</button>
                </div>

                <button type="submit" class="btn btn-primary">Log In</button>

                <!-- <div class="demo-info">
                    <strong>📝 Demo Admin Credentials:</strong>
                    <code>Username: superadmin</code>
                    <code>Password: admin123</code>
                </div> -->
            </form>

            <!-- Student Login Tab -->
            <form method="POST" class="tab-content" id="tab-student">
                <input type="hidden" name="login_type" value="student">

                <div class="form-group">
                    <label>Student ID</label>
                    <input type="text" name="student_id" placeholder="e.g., STU-001" required>
                </div>

                <div class="form-group password-group">
                    <label>Password</label>
                    <input type="password" name="password" id="student-pwd" placeholder="Enter your password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword('student-pwd')">👁️</button>
                </div>

                <button type="submit" class="btn btn-primary">Log In</button>

                <!-- <div class="demo-info">
                    <strong>📝 Demo Student Credentials:</strong>
                    <code>ID: STU-001</code>
                    <code>Password: student123</code>
                </div> -->
            </form>

            <!-- Department Login Tab -->
            <form method="POST" class="tab-content" id="tab-department">
                <input type="hidden" name="login_type" value="department">

                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="Enter department username" required>
                </div>

                <div class="form-group password-group">
                    <label>Password</label>
                    <input type="password" name="password" id="dept-pwd" placeholder="Enter your password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword('dept-pwd')"></button>
                </div>

                <button type="submit" class="btn btn-primary">Log In</button>

                <!-- <div class="demo-info">
                    <strong>📝 Demo Department Admin:</strong>
                    <code>Username: admin_cs</code>
                    <code>Password: admin123</code>
                </div> -->
            </form>

        </div>

        <!-- Footer -->
        <div class="login-footer">
            🔐 Secure • Encrypted • Protected
        </div>
    </div>

    <script>
        function switchTab(tab, btn) {
            // Update tab buttons
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            // Update form visibility
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            if (tab === 'admin') {
                document.getElementById('tab-admin').classList.add('active');
                document.querySelector('#tab-admin input[name="username"]').focus();
            } else if (tab === 'student') {
                document.getElementById('tab-student').classList.add('active');
                document.querySelector('#tab-student input[name="student_id"]').focus();
            } else if (tab === 'department') {
                document.getElementById('tab-department').classList.add('active');
                document.querySelector('#tab-department input[name="username"]').focus();
            }
        }

        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const type = input.type === 'password' ? 'text' : 'password';
            input.type = type;
        }

        // Remove alert after 5 seconds
        const alert = document.querySelector('.alert');
        if (alert) {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        }
    </script>

</body>
</html>
