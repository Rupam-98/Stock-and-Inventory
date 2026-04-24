<?php
session_start();
include("../Connection/conn.php");

// // Protect
// if (!isset($_SESSION['role']) || $_SESSION['role'] != "student") {
//     header("Location: ../login.php");
//     exit();
// }

$student_id = $_SESSION['student_id'];
$msg = "";

// -------- CANCEL REQUEST (only if Pending) --------
if (isset($_GET['cancel'])) {
    $req_id = $_GET['cancel'];

    // Ensure the request belongs to this student and is Pending
    $check = pg_query_params($conn,
        "SELECT status FROM requests WHERE request_id=$1 AND student_id=$2",
        array($req_id, $student_id)
    );

    if ($row = pg_fetch_assoc($check)) {
        if ($row['status'] === 'Pending') {
            pg_query_params($conn,
                "UPDATE requests SET status='Cancelled' WHERE request_id=$1",
                array($req_id)
            );
            $msg = "Request cancelled.";
        } else {
            $msg = "Only pending requests can be cancelled.";
        }
    } else {
        $msg = "Invalid request.";
    }
}

// -------- FETCH HISTORY --------
$history = pg_query_params($conn,
    "SELECT r.request_id, r.quantity_requested, r.status, r.requested_at,
            i.item_name, i.category
     FROM requests r
     JOIN inventory i ON r.item_id = i.item_id
     WHERE r.student_id = $1
     ORDER BY r.requested_at DESC",
    array($student_id)
);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Request History</title>

<style>
body {
    font-family: Arial;
    background:#eef2f7;
    margin:0;
}

header {

    background:#1f2937;
    color:white;
    padding:15px;
}

.container {
    padding:20px;
}

.card {
    background:white;
    padding:20px;
    border-radius:10px;
    box-shadow:0 2px 6px rgba(0,0,0,0.1);
}

h2, h6 {
    margin-bottom:15px;
}

table {
    width:100%;
    border-collapse: collapse;
}

th, td {
    padding:10px;
    border-bottom:1px solid #ddd;
    text-align:center;
}

th {
    background:#1f2937;
    color:white;
}

.status {
    padding:5px 10px;
    border-radius:6px;
    font-size:13px;
    font-weight:bold;
}

/* Status colors */
.pending { background:#fff3cd; color:#856404; }
.approved { background:#d4edda; color:#155724; }
.rejected { background:#f8d7da; color:#721c24; }
.cancelled { background:#e2e3e5; color:#383d41; }

/* Button */
.btn {
    padding:6px 10px;
    border:none;
    border-radius:5px;
    cursor:pointer;
}

.btn-cancel {
    background:#dc3545;
    color:white;
}

.msg {
    background:#d4edda;
    padding:10px;
    margin-bottom:10px;
    border-radius:5px;
}
</style>
</head>

<body>

<header>
<h2>📄 Request History</h2>
<h6><a href="studentdashboard.php">HOME</a></h6>
</header>

<div class="container">

<?php if($msg != ""){ ?>
<div class="msg"><?php echo $msg; ?></div>
<?php } ?>

<div class="card">

<table>
<tr>
    <th>ID</th>
    <th>Item</th>
    <th>Category</th>
    <th>Qty</th>
    <th>Status</th>
    <th>Date</th>
    <th>Action</th>
</tr>

<?php if(pg_num_rows($history) == 0){ ?>
<tr>
    <td colspan="7">No requests yet</td>
</tr>
<?php } ?>

<?php while($row = pg_fetch_assoc($history)) { ?>
<tr>
    <td><?php echo $row['request_id']; ?></td>
    <td><?php echo $row['item_name']; ?></td>
    <td><?php echo $row['category']; ?></td>
    <td><?php echo $row['quantity_requested']; ?></td>

    <td>
        <span class="status <?php echo strtolower($row['status']); ?>">
            <?php echo $row['status']; ?>
        </span>
    </td>

    <td><?php echo $row['requested_at']; ?></td>

    <td>
        <?php if($row['status'] == 'Pending'){ ?>
            <a href="?cancel=<?php echo $row['request_id']; ?>">
                <button class="btn btn-cancel">Cancel</button>
            </a>
        <?php } ?>
    </td>
</tr>
<?php } ?>

</table>

</div>
</div>

</body>
</html>