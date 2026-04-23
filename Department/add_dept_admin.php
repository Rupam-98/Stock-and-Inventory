<?php
session_start();
include("../Connection/conn.php");

// Protect
if (!isset($_SESSION['role']) || $_SESSION['role'] != "admin") {
    header("Location: ../login.php");
    exit();
}

// Fetch Departments (only those without admin)
$dept_query = pg_query($conn, "
    SELECT * FROM department 
    WHERE dept_id NOT IN (SELECT dept_id FROM dept_admin WHERE dept_id IS NOT NULL)
");

if (isset($_POST['add_admin'])) {
    $dept_admin_id = $_POST['dept_admin_id'];
    $name = $_POST['name'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $dept_id = $_POST['dept_id'];

    pg_query_params(
        $conn,
        "INSERT INTO dept_admin (dept_admin_id, name, username, email, password, dept_id)
         VALUES ($1, $2, $3, $4, $5, $6)",
        array($dept_admin_id, $name, $username, $email, $password, $dept_id)
    );

    $success = "Department Admin Created!";
}

?>

<!DOCTYPE html>
<html>

<head>
    <title>Add Department Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/main.css">
</head>

<body>

    <?php include("../admin/sidebar.php"); ?>

    <div class="main-content">

        <div class="container-fluid">

            <div class="card p-4">
                <h4>Create Department Admin</h4>

                <form method="POST">
                    
                    <div class="row g-3">
                        <div class="col-md-5">
                            <input type="text" name="dept_admin_id" class="form-control" placeholder="Admin ID" required>   
                        </div>

                        <div class="col-md-5">
                            <input type="text" name="name" class="form-control" placeholder="Admin Name" required>
                        </div>

                        <div class="col-md-5">
                            <input type="text" name="username" class="form-control" placeholder="Username" required>
                        </div>

                        <div class="col-md-5">
                            <input type="email" name="email" class="form-control" placeholder="Email" required>
                        </div>

                        <div class="col-md-5">
                            <input type="password" name="password" class="form-control" placeholder="Password" required>
                        </div>

                        <div class="col-md-5">
                            <select name="dept_id" class="form-control" required>
                                <option value="">Select Department</option>

                                <?php while ($dept = pg_fetch_assoc($dept_query)) { ?>
                                    <option value="<?php echo $dept['dept_id']; ?>">
                                        <?php echo $dept['dept_name']; ?> (<?php echo $dept['dept_id']; ?>)
                                    </option>
                                <?php } ?>

                            </select>
                        </div>

                        <div class="col-md-9">
                            <button class="btn btn-success w-100" name="add_admin">
                                Create Admin
                            </button>
                        </div>

                    </div>
                </form>

            </div>

        </div>

    </div>

    <!-- SweetAlert -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


    <?php if (!empty($success)) { ?>
        <script>
            Swal.fire({
                icon: 'success',
                title: '<?php echo $success; ?>',
                timer: 1500,
                showConfirmButton: false
            });
        </script>
    <?php } ?>

</body>

</html>