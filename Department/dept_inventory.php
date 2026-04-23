<?php
session_start();
include("../Connection/conn.php");

// Protect
if(!isset($_SESSION['role']) || $_SESSION['role'] != "dept_admin"){
    header("Location: ../login.php");
    exit();
}

$admin_id = $_SESSION['dept_admin_id'];

// Get dept_id
$res = pg_query_params($conn,
    "SELECT dept_id FROM dept_admin WHERE dept_admin_id=$1",
    array($admin_id)
);
$admin = pg_fetch_assoc($res);
$dept_id = $admin['dept_id'];

// ADD
if(isset($_POST['add_item'])){
    $item_code = $_POST['item_code'];
    $name = $_POST['item_name'];
    $category = $_POST['category'];
    $qty = $_POST['quantity'];
    $status = $_POST['status'];

pg_query_params($conn,
    "INSERT INTO inventory (item_code, item_name, category, quantity, status, dept_id)
     VALUES ($1, $2, $3, $4, $5, $6)",
    array($item_code, $name, $category, $qty, $status, $dept_id)
);
}

// DELETE
if(isset($_GET['delete'])){
    $id = $_GET['delete'];
    pg_query_params($conn,
        "DELETE FROM inventory WHERE item_id=$1 AND dept_id=$2",
        array($id, $dept_id)
    );
}

// UPDATE
if(isset($_POST['update_item'])){
    $id = $_POST['item_id'];
    $item_code = $_POST['item_code'];
    $name = $_POST['item_name'];
    $category = $_POST['category'];
    $qty = $_POST['quantity'];
    $status = $_POST['status'];
    pg_query_params($conn,
        "UPDATE inventory 
         SET item_code=$1, item_name=$2, category=$3, quantity=$4, status=$5
         WHERE item_id=$6 AND dept_id=$7",
        array($item_code, $name, $category, $qty, $status, $id, $dept_id)
    );
}

// Fetch items
$result = pg_query_params($conn,
    "SELECT * FROM inventory WHERE dept_id=$1 ORDER BY item_id DESC",
    array($dept_id)
);

// Edit mode
$editData = null;
if(isset($_GET['edit'])){
    $id = $_GET['edit'];
    $res = pg_query_params($conn,
        "SELECT * FROM inventory WHERE item_id=$1 AND dept_id=$2",
        array($id, $dept_id)
    );
    $editData = pg_fetch_assoc($res);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Inventory</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include("dept_sidebar.php"); ?>

<div class="main-content">

    <h3 class="mb-4">Inventory Management</h3>

    <!-- Add / Edit -->
    <div class="card p-4 mb-4">
        <h5><?php echo $editData ? "Edit Item" : "Add Item"; ?></h5>

<form method="POST">
    <input type="hidden" name="item_id"
        value="<?php echo $editData['item_id'] ?? ''; ?>">

    <div class="row g-2">
        <div class="col-md-3">  
            <input type="text" name="item_code" class="form-control"
                value="<?php echo $editData['item_code'] ?? ''; ?>"
                placeholder="Item Code" required>   
        </div>

        <!-- Item Name -->
        <div class="col-md-3">
            <input type="text" name="item_name" class="form-control"
                value="<?php echo $editData['item_name'] ?? ''; ?>"
                placeholder="Item Name" required>
        </div>

        <!-- Category -->
        <div class="col-md-3">
                        <select name="category" class="form-control" placeholder ="Category" required>
                <option value="">Select Category</option>
                <option value="Electronics"
                    <?php if(($editData['category'] ?? '') == 'Electronics') echo 'selected'; ?>>
                    Electronics
                </option>
                <option value="Comsumables"
                     <?php if(($editData['category'] ?? '') == 'Comsumables') echo 'selected'; ?>>
                    Comsumables
                </option>
                <option value="Non-Consumables"
                    <?php if(($editData['category'] ?? '') == 'Non-Consumables') echo 'selected'; ?>>
                    Non-Consumables
                </option>
            </select>
        </div>

        <!-- Quantity -->
        <div class="col-md-2">
            <input type="number" name="quantity" class="form-control"
                value="<?php echo $editData['quantity'] ?? ''; ?>"
                placeholder="Quantity" required>
        </div>

        <!-- Status -->
        <div class="col-md-2">
            
            <select name="status" class="form-control">
                <option value="">Status</option>
                <option value="Available"
                    <?php if(($editData['status'] ?? '') == 'Available') echo 'selected'; ?>>
                    Available
                </option>
                <option value="Damaged"
                    <?php if(($editData['status'] ?? '') == 'Damaged') echo 'selected'; ?>>
                    Damaged
                </option>
                <option value="Out of Stock"
                    <?php if(($editData['status'] ?? '') == 'Out of Stock') echo 'selected'; ?>>
                    Out of Stock
                </option>
            </select>
        </div>


        <!-- Button -->
        <div class="col-md-2">
            <?php if($editData){ ?>
                <button class="btn btn-warning w-100" name="update_item">Update</button>
            <?php } else { ?>
                <button class="btn btn-primary w-100" name="add_item">Add</button>
            <?php } ?>
        </div>

    </div>
</form>
    </div>

    <!-- Table -->
    <div class="card p-4">
        <h5>All Items</h5>

        <table class="table table-bordered mt-3">
            <thead class="table-dark">
                <tr>
                    <th>Item Code</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Qty</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php while($row = pg_fetch_assoc($result)) { ?>
                    <tr>
                        <!-- <td><?php echo $row['item_id']; ?></td> -->
                        <td><?php echo $row['item_code']; ?></td>
                        <td><?php echo $row['item_name']; ?></td>
                        <td><?php echo $row['category']; ?></td>
                        <td><?php echo $row['quantity']; ?></td>
                        <td>
                            <a href="?edit=<?php echo $row['item_id']; ?>" class="btn btn-info btn-sm">Edit</a>
                            <a href="?delete=<?php echo $row['item_id']; ?>" class="btn btn-danger btn-sm"
                               onclick="return confirm('Delete this item?')">Delete</a>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>

        </table>
    </div>

</div>

</body>
</html>