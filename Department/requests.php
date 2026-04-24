<?php
session_start();
include("../Connection/conn.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != "dept_admin"){
    header("Location: ../login.php");
    exit();
}

$admin_id = $_SESSION['dept_admin_id'];
$msg = "";

// Get admin dept
$res = pg_query_params($conn,
    "SELECT dept_id FROM dept_admin WHERE dept_admin_id=$1",
    array($admin_id)
);
$admin = pg_fetch_assoc($res);
$dept_id = $admin['dept_id'];

/* =========================
   APPROVE (PROCUREMENT)
   ========================= */
if(isset($_GET['approve'])){
    $req_id = $_GET['approve'];

    pg_query($conn, "BEGIN");

    // Lock request row
    $req_res = pg_query_params($conn,
        "SELECT * FROM requests WHERE request_id=$1 FOR UPDATE",
        array($req_id)
    );
    $req = pg_fetch_assoc($req_res);

    if(!$req){
        pg_query($conn, "ROLLBACK");
        $msg = "Request not found.";
    }
    else if($req['status'] !== 'Pending'){
        pg_query($conn, "ROLLBACK");
        $msg = "Already processed.";
    }
    else {
        // CASE A: Existing inventory item → INCREASE stock
        if(!empty($req['item_id'])){
            // Lock inventory row
            $inv_res = pg_query_params($conn,
                "SELECT quantity FROM inventory WHERE item_id=$1 FOR UPDATE",
                array($req['item_id'])
            );
            $inv = pg_fetch_assoc($inv_res);

            if(!$inv){
                pg_query($conn, "ROLLBACK");
                $msg = "Inventory item not found.";
            } else {
                pg_query_params($conn,
                    "UPDATE inventory
                     SET quantity = quantity + $1
                     WHERE item_id = $2",
                    array($req['quantity_requested'], $req['item_id'])
                );

                pg_query_params($conn,
                    "UPDATE requests SET status='Approved' WHERE request_id=$1",
                    array($req_id)
                );

                pg_query($conn, "COMMIT");
                $msg = "Approved: stock increased.";
            }
        }
        // CASE B: Custom request → CREATE new inventory item
        else if(!empty($req['custom_item_name'])){
            pg_query_params($conn,
                "INSERT INTO inventory (item_name, category, quantity, status, dept_id)
                 VALUES ($1, 'General', $2, 'Available', $3)",
                array($req['custom_item_name'], $req['quantity_requested'], $dept_id)
            );

            pg_query_params($conn,
                "UPDATE requests SET status='Approved' WHERE request_id=$1",
                array($req_id)
            );

            pg_query($conn, "COMMIT");
            $msg = "Approved: new item added to inventory.";
        }
        else{
            pg_query($conn, "ROLLBACK");
            $msg = "Invalid request data.";
        }
    }
}

/* =========================
   REJECT
   ========================= */
if(isset($_GET['reject'])){
    $req_id = $_GET['reject'];

    pg_query_params($conn,
        "UPDATE requests
         SET status='Rejected'
         WHERE request_id=$1 AND status='Pending'",
        array($req_id)
    );

    $msg = "Request rejected.";
}

/* =========================
   OPTIONAL: CONVERT (create item first)
   ========================= */
if(isset($_GET['convert'])){
    $req_id = $_GET['convert'];

    $res = pg_query_params($conn,
        "SELECT * FROM requests WHERE request_id=$1",
        array($req_id)
    );
    $req = pg_fetch_assoc($res);

    if($req && empty($req['item_id']) && !empty($req['custom_item_name'])){
        // Create item with 0 qty (planning)
        $ins = pg_query_params($conn,
            "INSERT INTO inventory (item_name, category, quantity, status, dept_id)
             VALUES ($1, 'General', 0, 'Available', $2)
             RETURNING item_id",
            array($req['custom_item_name'], $dept_id)
        );
        $new = pg_fetch_assoc($ins);

        // Link request to this item
        pg_query_params($conn,
            "UPDATE requests SET item_id=$1 WHERE request_id=$2",
            array($new['item_id'], $req_id)
        );

        $msg = "Converted to inventory item. Now approve to add quantity.";
    } else {
        $msg = "Conversion not applicable.";
    }
}

/* =========================
   FETCH LIST
   ========================= */
$requests = pg_query_params($conn,
    "SELECT r.*, i.item_name, s.username
     FROM requests r
     LEFT JOIN inventory i ON r.item_id = i.item_id
     JOIN students s ON r.student_id = s.student_id
     WHERE s.dept_id=$1
     ORDER BY r.requested_at DESC",
    array($dept_id)
);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Dept Admin - Requests (Procurement)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet      ">
<link rel="stylesheet" href="../assets/css/main.css">
<style>
.badge{padding:4px 8px;border-radius:6px;font-size:12px}
.pending{background:#fff3cd;color:#856404}
.approved{background:#d4edda;color:#155724}
.rejected{background:#f8d7da;color:#721c24}
.actions a{margin:0 4px;text-decoration:none}
</style>
</head>
<body>

<?php include("dept_sidebar.php"); ?>

<div class="main-content">
  <h2>📥 Department Requests (Procurement)</h2>

  <?php if($msg){ echo "<div class='card'>$msg</div>"; } ?>

  <div class="card">
    <table>
      <tr>
        <th>Student</th>
        <th>Item</th>
        <th>Qty</th>
        <th>Status</th>
        <th>Action</th>
      </tr>

      <?php if(pg_num_rows($requests)==0){ ?>
        <tr><td colspan="5">No requests</td></tr>
      <?php } ?>

      <?php while($r = pg_fetch_assoc($requests)){ ?>
      <tr>
        <td><?php echo $r['username']; ?></td>

        <td>
          <?php
            echo $r['item_name']
              ? $r['item_name']
              : "<span style='color:#c0392b'>".$r['custom_item_name']."</span>";
          ?>
        </td>

        <td><?php echo $r['quantity_requested']; ?></td>

        <td>
          <span class="badge <?php echo strtolower($r['status']); ?>">
            <?php echo $r['status']; ?>
          </span>
        </td>

        <td class="actions">
          <?php if($r['status']==='Pending'){ ?>

            <!-- Convert only for custom requests -->
            <?php if(empty($r['item_id'])){ ?>
              <a href="?convert=<?php echo $r['request_id']; ?>">Convert</a>
            <?php } ?>

            <a href="?approve=<?php echo $r['request_id']; ?>">Approve</a>
            <a href="?reject=<?php echo $r['request_id']; ?>">Reject</a>

          <?php } ?>
        </td>
      </tr>
      <?php } ?>
    </table>
  </div>
</div>

<script src="../assets/js/sidebar.js"></script>
</body>
</html>