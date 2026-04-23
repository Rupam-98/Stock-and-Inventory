<?php
session_start();
include("../Connection/conn.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != "dept_admin"){
    header("Location: ../login.php");
    exit();
}

$admin_id = $_SESSION['dept_admin_id'];

// Get dept
$res = pg_query_params($conn,
    "SELECT dept_id FROM dept_admin WHERE dept_admin_id=$1",
    array($admin_id)
);
$admin = pg_fetch_assoc($res);
$dept_id = $admin['dept_id'];

// Fetch requests
$requests = pg_query_params($conn,
    "SELECT r.*, i.item_name, s.username 
     FROM requests r
     JOIN inventory i ON r.item_id = i.item_id
     JOIN students s ON r.student_id = s.student_id
     WHERE i.dept_id=$1",
    array($dept_id)
);

// Approve
if(isset($_GET['approve'])){
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
if(isset($_GET['reject'])){
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
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>

<div class="container mt-5">
    <h3>Item Requests</h3>

    <table class="table table-bordered mt-3">
        <thead class="table-dark">
            <tr>
                <th>Student</th>
                <th>Item</th>
                <th>Qty</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>

        <tbody>
        <?php while($row = pg_fetch_assoc($requests)) { ?>
            <tr>
                <td><?php echo $row['username']; ?></td>
                <td><?php echo $row['item_name']; ?></td>
                <td><?php echo $row['quantity_requested']; ?></td>
                <td><?php echo $row['status']; ?></td>

                <td>
                    <?php if($row['status'] == 'Pending'){ ?>
                        <a href="?approve=<?php echo $row['request_id']; ?>" class="btn btn-success btn-sm">Approve</a>
                        <a href="?reject=<?php echo $row['request_id']; ?>" class="btn btn-danger btn-sm">Reject</a>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>

    </table>

</div>

</body>
</html>