<?php
session_start();
include("../Connection/conn.php");

// Protect Page
if (!isset($_SESSION['role']) || $_SESSION['role'] != "dept_admin") {
    header("Location: ../login.php");
    exit();
}

$admin_id = $_SESSION['dept_admin_id'];

// Get Dept Admin Info
$admin_query = pg_query_params(
    $conn,
    "SELECT * FROM dept_admin WHERE dept_admin_id = $1",
    array($admin_id)
);
$admin = pg_fetch_assoc($admin_query);

$dept_id = $admin['dept_id'];

// Stats
// $students = pg_fetch_result(
//     pg_query_params($conn, "SELECT COUNT(*) FROM students WHERE dept_id = $1", array($dept_id)),
//     0, 0
// );

// $items = pg_fetch_result(
//     pg_query_params($conn, "SELECT COUNT(*) FROM inventory WHERE dept_id = $1", array($dept_id)),
//     0, 0
// );

// $requests = pg_fetch_result(
//     pg_query_params($conn, "
//         SELECT COUNT(*) FROM requests r
//         JOIN inventory i ON r.item_id = i.item_id
//         WHERE i.dept_id = $1 AND r.status = 'Pending'
//     ", array($dept_id)),
//     0, 0
// );
?>

<!DOCTYPE html>
<html>

<head>
    <title>Dept Admin Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/main.css">

    <style>
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 220px;
            height: 100vh;
            background: #1f2937;
            color: white;
            padding: 20px;
        }

        .sidebar a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 10px;
            margin-bottom: 5px;
            border-radius: 5px;
        }

        .sidebar a:hover {
            background: #374151;
        }

        .main-content {
            margin-left: 220px;
            padding: 20px;
            background: #f4f6f9;
            min-height: 100vh;
        }

        .card-box {
            padding: 20px;
            border-radius: 15px;
            color: white;
        }

        .bg1 {
            background: linear-gradient(45deg, #4e73df, #224abe);
        }

        .bg2 {
            background: linear-gradient(45deg, #1cc88a, #17a673);
        }

        .bg3 {
            background: linear-gradient(45deg, #f6c23e, #dda20a);
        }
    </style>
</head>

<body>

    <div class="sidebar">
        <h4>Dept Panel</h4>

        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="#"><i class="bi bi-box"></i> Inventory</a>
        <a href="#"><i class="bi bi-file-text"></i> Requests</a>
        <a href="#"><i class="bi bi-people"></i> Students</a>
        <a href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">

        <h3 class="mb-4">Welcome, <?php echo $admin['name']; ?></h3>

        <div class="row g-4">


            <!-- <div class="col-md-4">
            <div class="card-box bg1">
                <h5>Total Students</h5>
                <h2><?php echo $students; ?></h2>
            </div>
        </div>

        
        <div class="col-md-4">
            <div class="card-box bg2">
                <h5>Inventory Items</h5>
                <h2><?php echo $items; ?></h2>
            </div>
        </div>

        
        <div class="col-md-4">
            <div class="card-box bg3">
                <h5>Pending Requests</h5>
                <h2><?php echo $requests; ?></h2>
            </div>
        </div> -->

        </div>

    </div>

</body>

</html>