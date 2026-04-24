<?php
session_start();
include("../Connection/conn.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] != "dept_admin") {
    header("Location: ../login.php");
    exit();
}

$admin_id = $_SESSION['dept_admin_id'];

// Get dept
$res = pg_query_params(
    $conn,
    "SELECT dept_id FROM dept_admin WHERE dept_admin_id=$1",
    array($admin_id)
);
$admin = pg_fetch_assoc($res);
$dept_id = $admin['dept_id'];

// Fetch requests
$requests = pg_query_params(
    $conn,
    "SELECT r.*, i.item_name, s.username 
     FROM requests r
     JOIN inventory i ON r.item_id = i.item_id
     JOIN students s ON r.student_id = s.student_id
     WHERE i.dept_id=$1",
    array($dept_id)
);

// Approve
if (isset($_GET['approve'])) {
    $id = $_GET['approve'];

    // Reduce stock
    pg_query($conn, "
        UPDATE inventory SET quantity = quantity - (
            SELECT quantity_requested FROM requests WHERE request_id = $id
        )
        WHERE item_id = (SELECT item_id FROM requests WHERE request_id = $id)
    ");

    // Update request
    pg_query($conn, "UPDATE requests SET status='Approved' WHERE request_id=$id");
}

// Reject
if (isset($_GET['reject'])) {
    $id = $_GET['reject'];
    pg_query($conn, "UPDATE requests SET status='Rejected' WHERE request_id=$id");
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/requests.css">
</head>
<?php include("dept_sidebar.php"); ?>

<body>
    <div class="main-content">
    <div class="container">

        <h3>Item Requests</h3>

        <div class="card">

            <table class="table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if(pg_num_rows($requests) == 0){ ?>
                    <tr>
                        <td colspan="5" class="no-data">No requests found</td>
                    </tr>
                <?php } ?>

                <?php while($row = pg_fetch_assoc($requests)) { ?>
                    <tr>
                        <td><?php echo $row['username']; ?></td>
                        <td><?php echo $row['item_name']; ?></td>
                        <td><?php echo $row['quantity_requested']; ?></td>

                        <td>
                            <span class="status <?php echo strtolower($row['status']); ?>">
                                <?php echo $row['status']; ?>
                            </span>
                        </td>

                        <td>
                            <?php if($row['status'] == 'Pending'){ ?>
                                <a href="?approve=<?php echo $row['request_id']; ?>">
                                    <button class="btn btn-approve">Approve</button>
                                </a>

                                <a href="?reject=<?php echo $row['request_id']; ?>">
                                    <button class="btn btn-reject">Reject</button>
                                </a>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>

                </tbody>
            </table>

        </div>

    </div>
</div>
</body>

</html>