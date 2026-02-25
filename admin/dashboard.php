<?php
session_start();
include("../Conection/conn.php");

// Protect Page
if(!isset($_SESSION['role']) || $_SESSION['role'] != "admin"){
    header("Location: ../login.php");
    exit();
}

// Fetch Statistics
$total_departments = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM department"), 0, 0);
$total_students = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM students"), 0, 0);
// $total_items = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM inventory"), 0, 0);
// $total_requests = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM requests"), 0, 0);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>

    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background-color: #f4f6f9;
        }

        .sidebar {
            height: 100vh;
            background: #1f2937;
            color: white;
            padding: 20px;
        }

        .sidebar a {
            color: white;
            text-decoration: none;
            display: block;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 5px;
        }

        .sidebar a:hover {
            background: #374151;
        }

        .card {
            border: none;
            border-radius: 15px;
        }

        .stat-card {
            padding: 20px;
            color: white;
            border-radius: 15px;
        }

        .bg1 { background: linear-gradient(45deg, #4e73df, #224abe); }
        .bg2 { background: linear-gradient(45deg, #1cc88a, #17a673); }
        .bg3 { background: linear-gradient(45deg, #36b9cc, #2c9faf); }
        .bg4 { background: linear-gradient(45deg, #f6c23e, #dda20a); }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">

        <!-- Sidebar -->
        <div class="col-md-2 sidebar">
            <h4 class="mb-4">Admin Panel</h4>
            <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="#"><i class="bi bi-building"></i> Departments</a>
            <a href="#"><i class="bi bi-people"></i> Students</a>
            <a href="#"><i class="bi bi-box"></i> Inventory</a>
            <a href="#"><i class="bi bi-file-text"></i> Requests</a>
            <a href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 p-4">
            <h3 class="mb-4">Dashboard Overview</h3>

            <div class="row g-4">

                <div class="col-md-3">
                    <div class="stat-card bg1">
                        <h5>Total Departments</h5>
                        <h2><?php echo $total_departments; ?></h2>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card bg2">
                        <h5>Total Students</h5>
                        <h2><?php echo $total_students; ?></h2>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card bg3">
                        <h5>Total Inventory Items</h5>
                        <h2><?php echo $total_items; ?></h2>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card bg4">
                        <h5>Total Requests</h5>
                        <h2><?php echo $total_requests; ?></h2>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

</body>
</html>
