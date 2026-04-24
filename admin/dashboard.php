<?php
session_start();
include("../Connection/conn.php");

// Protect Page
if (!isset($_SESSION['role']) || $_SESSION['role'] != "admin") {
    header("Location: ../login.php");
    exit();
}

// Fetch Statistics
$total_departments = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM department"), 0, 0);
$total_assets = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM inventory"), 0, 0);
$low_stock = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM inventory WHERE

    quantity < 5"), 0, 0);
?>

<!DOCTYPE html>
<html>

<head>
    <title>Admin Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

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

        /* Dashboard Cards */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .card-box {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: 0.3s;
        }

        .card-box:hover {
            transform: translateY(-5px);
        }

        .card-box h5 {
            color: #6b7280;
            font-size: 14px;
        }

        .card-box h2 {
            margin-top: 10px;
            font-weight: bold;
        }

        /* Colored Cards */
        .bg1 { border-left: 5px solid #4e73df; }
        .bg2 { border-left: 5px solid #1cc88a; }
        .bg3 { border-left: 5px solid #36b9cc; }
        .bg4 { border-left: 5px solid #f6c23e; }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        header input {
            padding: 8px;
            border-radius: 8px;
            border: 1px solid #ccc;
        }

        .btn-custom {
            background: #4e73df;
            color: white;
            border-radius: 8px;
            padding: 8px 15px;
            border: none;
        }

        .btn-custom:hover {
            background: #2e59d9;
        }
    </style>
</head>

<body>

<div class="container-fluid">
    <div class="row">

        <?php include("sidebar.php"); ?>

        <!-- Main Content -->
        <div class="col-md-10 p-4">

            <header>
                <h3>Dashboard Overview</h3>
                <div>
                    <input type="text" placeholder="Search...">
                    <button class="btn-custom">+ New Entry</button>
                </div>
            </header>

            <!-- Cards -->
            <div class="dashboard-cards">

                <div class="card-box bg1">
                    <h5>Total Departments</h5>
                    <h2><?php echo $total_departments; ?></h2>
                </div>

                <div class="card-box bg2">
                    <h5>Total Assets</h5>
                    <h2><?= $total_assets ?></h2>
                </div>

                <div class="card-box bg3">
                    <h5>Low Stock</h5>
                    <h2><?= $low_stock ?></h2>
                </div>

                <!-- <div class="card-box bg4">
                    <h5>Maintenance</h5>
                    <h2><?= $maintenance ?></h2>
                </div> -->

               <!-- <div class="card-box">
                    <h5>Total Value</h5>
                    <h2>₹<?= number_format($value, 2) ?></h2>
                </div> -->

            </div>

        </div>

    </div>
</div>

</body>
</html>