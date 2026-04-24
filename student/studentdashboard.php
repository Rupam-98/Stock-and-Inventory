<?php
session_start();
include("../Connection/conn.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] != "student") {
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['student_id'];

// Get student dept
$res = pg_query_params($conn,
    "SELECT dept_id FROM students WHERE student_id=$1",
    array($student_id)
);
$student = pg_fetch_assoc($res);
$dept_id = $student['dept_id'];

$msg = "";

// ---------------- REQUEST ITEM ----------------
if (isset($_POST['request'])) {
    $item_id = $_POST['item_id'];
    $qty = $_POST['req_qty'];

    // Check available stock
    $check = pg_query_params($conn,
        "SELECT quantity FROM inventory WHERE item_id=$1",
        array($item_id)
    );
    $item = pg_fetch_assoc($check);

    if ($item && $qty <= $item['quantity']) {

        pg_query_params($conn,
            "INSERT INTO requests (student_id, item_id, quantity_requested)
             VALUES ($1, $2, $3)",
            array($student_id, $item_id, $qty)
        );

        $msg = "Request Sent Successfully";

    } else {
        $msg = "Invalid quantity or item not available";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Student Dashboard</title>

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
    margin-bottom:20px;
    box-shadow:0 2px 6px rgba(0,0,0,0.1);
}

h2 {
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

.btn {
    padding:6px 10px;
    border:none;
    border-radius:5px;
    cursor:pointer;
}

.btn-request {
    background:#3498db;
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
<h2>🎓 Student Dashboard</h2>
</header>

<div class="container">

<!-- MESSAGE -->
<?php if($msg != ""){ ?>
<div class="msg"><?php echo $msg; ?></div>
<?php } ?>

<!-- INVENTORY -->
<div class="card">
<h2>📦 Available Inventory</h2>

<table>
<tr>
    <th>ID</th>
    <th>Item</th>
    <th>Category</th>
    <th>Available</th>
    <th>Request</th>
</tr>

<?php
$items = pg_query_params($conn,
    "SELECT * FROM inventory WHERE dept_id=$1 AND quantity > 0",
    array($dept_id)
);

while ($row = pg_fetch_assoc($items)) {
?>
<tr>
    <td><?php echo $row['item_id']; ?></td>
    <td><?php echo $row['item_name']; ?></td>
    <td><?php echo $row['category']; ?></td>
    <td><?php echo $row['quantity']; ?></td>

    <td>
        <form method="POST" style="display:flex; gap:5px; justify-content:center;">
            <input type="hidden" name="item_id" value="<?php echo $row['item_id']; ?>">
            <input type="number" name="req_qty" min="1" max="<?php echo $row['quantity']; ?>" required>
            <button class="btn btn-request" name="request">Request</button>
        </form>
    </td>
</tr>
<?php } ?>

</table>
</div>

</div>

</body>
</html>