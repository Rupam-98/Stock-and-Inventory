<?php
session_start();
include("../Connection/conn.php");

// Protect
if(!isset($_SESSION['role']) || $_SESSION['role'] != "admin"){
    header("Location: ../login.php");
    exit();
}

$msg = "";

// Fetch departments
$depts = pg_query($conn, "SELECT * FROM department");

// ADD
if(isset($_POST['add_item'])){
    $name = $_POST['item_name'];
    $cat = $_POST['category'];
    $qty = $_POST['quantity'];
    $status = $_POST['status'];
    $dept = $_POST['dept_id'];

    pg_query_params($conn,
        "INSERT INTO inventory (item_name, category, quantity, status, dept_id)
         VALUES ($1,$2,$3,$4,$5)",
        array($name,$cat,$qty,$status,$dept)
    );

    $msg = "Item Added!";
}

// DELETE
if(isset($_GET['delete'])){
    $id = $_GET['delete'];

    pg_query_params($conn,
        "DELETE FROM inventory WHERE item_id=$1",
        array($id)
    );

    $msg = "Item Deleted!";
}

// UPDATE
if(isset($_POST['update_item'])){
    $id = $_POST['item_id'];
    $name = $_POST['item_name'];
    $cat = $_POST['category'];
    $qty = $_POST['quantity'];
    $status = $_POST['status'];
    $dept = $_POST['dept_id'];

    pg_query_params($conn,
        "UPDATE inventory 
         SET item_name=$1, category=$2, quantity=$3, status=$4, dept_id=$5
         WHERE item_id=$6",
        array($name,$cat,$qty,$status,$dept,$id)
    );

    $msg = "Item Updated!";
}

// FETCH
$result = pg_query($conn,
    "SELECT i.*, d.dept_name
     FROM inventory i
     JOIN department d ON i.dept_id = d.dept_id
     ORDER BY item_id DESC"
);

// EDIT
$editData = null;
if(isset($_GET['edit'])){
    $id = $_GET['edit'];
    $res = pg_query_params($conn,
        "SELECT * FROM inventory WHERE item_id=$1",
        array($id)
    );
    $editData = pg_fetch_assoc($res);
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Admin Inventory</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/inv.css">
</head>

<body>

<?php include("sidebar.php"); ?>

<div class="main-content">

<h2>📦 Inventory Panel</h2>

<?php if($msg){ echo "<div class='card'>$msg</div>"; } ?>

<!-- FORM -->
<div class="card">

<form method="POST">

<input type="hidden" name="item_id" value="<?php echo $editData['item_id'] ?? ''; ?>">

<input type="text" name="item_name" placeholder="Item Name"
value="<?php echo $editData['item_name'] ?? ''; ?>" required>

<input type="text" name="category" placeholder="Category"
value="<?php echo $editData['category'] ?? ''; ?>">

<input type="number" name="quantity" placeholder="Quantity"
value="<?php echo $editData['quantity'] ?? ''; ?>" required>

<select name="status">
<option>Available</option>
<option>Damaged</option>
<option>Out of Stock</option>
</select>

<select name="dept_id" required>
<option value="">Select Dept</option>
<?php while($d = pg_fetch_assoc($depts)){ ?>
<option value="<?php echo $d['dept_id']; ?>"
<?php if(($editData['dept_id'] ?? '')==$d['dept_id']) echo "selected"; ?>>
<?php echo $d['dept_name']; ?>
</option>
<?php } ?>
</select>

<?php if($editData){ ?>
<button name="update_item">Update</button>
<?php } else { ?>
<button name="add_item">Add</button>
<?php } ?>

</form>
</div>

<!-- TABLE -->
<div class="card">

<table>
<tr>
<th>ID</th>
<th>Name</th>
<th>Category</th>
<th>Qty</th>
<th>Status</th>
<th>Dept</th>
<th>Action</th>
</tr>

<?php while($row = pg_fetch_assoc($result)){ ?>
<tr>
<td><?php echo $row['item_id']; ?></td>
<td><?php echo $row['item_name']; ?></td>
<td><?php echo $row['category']; ?></td>
<td><?php echo $row['quantity']; ?></td>
<td><?php echo $row['status']; ?></td>
<td><?php echo $row['dept_name']; ?></td>

<td>
<a href="?edit=<?php echo $row['item_id']; ?>">Edit</a>
<a href="?delete=<?php echo $row['item_id']; ?>" onclick="return confirm('Delete?')">Delete</a>
</td>
</tr>
<?php } ?>

</table>

</div>

</div>

<script src="../assets/js/sidebar.js"></script>

</body>
</html>