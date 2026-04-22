<?php
session_start();
include("../Connection/conn.php");

// Protect Page
if (!isset($_SESSION['role']) || $_SESSION['role'] != "admin") {
    header("Location: ../login.php");
    exit();
}

// ADD
if (isset($_POST['add_department'])) {
    $dept_name = $_POST['dept_name'];
    $dept_id = $_POST['dept_id'];
    pg_query_params($conn, "INSERT INTO department (dept_id, dept_name) VALUES ($1, $2)", array($dept_id, $dept_name));

    echo "<script>showSuccess('Department Added Successfully');</script>";
}

// DELETE
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    pg_query_params($conn, "DELETE FROM department WHERE dept_id = $1", array($id));

    echo "<script>showSuccess('Department Deleted');</script>";
}

// UPDATE
if (isset($_POST['update_department'])) {
    $id = $_POST['dept_id'];
    $name = $_POST['dept_name'];

    pg_query_params($conn, "UPDATE department SET dept_name=$1 WHERE dept_id=$2", array($name, $id));

    echo "<script>showSuccess('Department Updated');</script>";
}

// Fetch all
$result = pg_query($conn, "SELECT * FROM department ORDER BY dept_id DESC");

// Fetch single (for edit)
$editData = null;
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $res = pg_query_params($conn, "SELECT * FROM department WHERE dept_id=$1", array($id));
    $editData = pg_fetch_assoc($res);
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Departments</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

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

        .main-content {
            width: calc(100% - 220px);
            margin-left: 220px;
            padding: 20px;
            background: #f4f6f9;
            min-height: 100vh;
        }
    </style>
</head>

<body>

    <div class="container-fluid">
        <div class="row">

            <!-- Sidebar -->
            <!-- <div class="col-md-2 sidebar">
             <h4>Admin Panel</h4>
            <a href="dashboard.php">Dashboard</a>
            <a href="departments.php">Departments</a>
             <a href="../logout.php">Logout</a>
            </div> -->

            <?php include("../admin/sidebar.php"); ?>

            <div class="main-content">

                <div class="container-fluid">

                    <!-- Add/Edit Department -->
                    <div class="card p-4 mb-4">
                        <h5><?php echo $editData ? "Edit Department" : "Add Department"; ?></h5>

                        <form method="POST">
                            <div class="row g-2">

                                <div class="col-md-4">
                                    <input type="text" name="dept_id"
                                        class="form-control"
                                        value="<?php echo $editData['dept_id'] ?? ''; ?>"
                                        placeholder="Enter Department ID" required>
                                </div>

                                <div class="col-md-4">
                                    <input type="text" name="dept_name"
                                        class="form-control"
                                        value="<?php echo $editData['dept_name'] ?? ''; ?>"
                                        placeholder="Enter Department Name" required>
                                </div>

                                <div class="col-md-4">
                                    <?php if ($editData) { ?>
                                        <button class="btn btn-warning w-100" name="update_department">Update</button>
                                    <?php } else { ?>
                                        <button class="btn btn-primary w-100" name="add_department">Add</button>
                                    <?php } ?>
                                </div>

                            </div>
                        </form>
                    </div>

                    <!-- Department Table -->
                    <div class="card p-4">
                        <h5>All Departments</h5>

                        <table class="table table-bordered mt-3">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Department Name</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php while ($row = pg_fetch_assoc($result)) { ?>
                                    <tr>
                                        <td><?php echo $row['dept_id']; ?></td>
                                        <td><?php echo $row['dept_name']; ?></td>
                                        <td>
                                            <!-- <a href="?edit=<?php echo $row['dept_id']; ?>" class="btn btn-info btn-sm">Edit</a> -->

                                            <button onclick="confirmDelete('<?php echo $row['dept_id']; ?>')"
                                                class="btn btn-danger btn-sm">
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>

                        </table>
                    </div>

                </div>

            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/dept.js"></script>

</body>

</html>