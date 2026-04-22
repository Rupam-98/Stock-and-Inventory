<?php
session_start();
include("Connection/conn.php");

if(isset($_POST['login'])){

    $username = $_POST['username'];
    $password = $_POST['password'];

    // ---------- Check Admin ----------
    $query = "SELECT * FROM admin_master WHERE username = $1";
    $result = pg_query_params($conn, $query, array($username));

    if(pg_num_rows($result) == 1){
        $admin = pg_fetch_assoc($result);

        if(password_verify($password, $admin['password'])){
            $_SESSION['role'] = "admin";
            $_SESSION['admin_id'] = $admin['admin_id'];
            header("Location: admin/dashboard.php");
            exit();
        }
    }

    // ---------- Check Dept Admin ----------
    $query = "SELECT * FROM dept_admin WHERE username = $1";
    $result = pg_query_params($conn, $query, array($username));

    if(pg_num_rows($result) == 1){
        $dept_admin = pg_fetch_assoc($result);

        if(password_verify($password, $dept_admin['password'])){
            $_SESSION['role'] = "dept_admin";
            $_SESSION['dept_admin_id'] = $dept_admin['dept_admin_id'];
            header("Location: Department/dept_dashboard.php");
            exit();
        }
    }

    // ---------- Check Student ----------
    $query = "SELECT * FROM students WHERE username = $1";
    $result = pg_query_params($conn, $query, array($username));

    if(pg_num_rows($result) == 1){
        $student = pg_fetch_assoc($result);

        if(password_verify($password, $student['password'])){
            $_SESSION['role'] = "student";
            $_SESSION['student_id'] = $student['student_id'];
            header("Location: student/dashboard.php");
            exit();
        }
    }

    echo "Invalid Username or Password!";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>University Inventory System</title>
    <link rel="stylesheet" href="Assets/CSS/login.css">
    <script src="Assets/js/login.js"></script>
</head>
<body>

<div class="login-container">
    
    <!-- Optional Logo -->
    <!-- <img src="assets/images/logo.png" alt="Logo"> -->

    <h2>University Inventory</h2>
    <p>Please login to continue</p>

    <form method="POST">
        <div class="input-group">
            <input type="text" name="username" placeholder="Username" required>
        </div>

        <div class="input-group">
            <input type="password" id="password" name="password" placeholder="Password" required>
            <span class="toggle-password" onclick="togglePassword()">👁</span>
        </div>

        <button type="submit" name="login">Login</button>
    </form>

    <?php
    if(isset($error)){
        echo "<div class='error'>$error</div>";
    }
    ?>
</div>

</body>
</html>
