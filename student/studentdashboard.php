<?php
// ---------------- DATABASE CONNECTION ----------------
$host = "localhost";
$port = "5432";
$db   = "six_sem";
$user = "postgres";
$pass = "1035"; // change this

$msg = "";

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// ---------------- CREATE TABLE ----------------
$pdo->exec("CREATE TABLE IF NOT EXISTS items (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100),
    quantity INT
)");

// ---------------- ADD ITEM ----------------
if (isset($_POST['add'])) {
    $name = $_POST['name'];
    $qty  = $_POST['quantity'];

    if ($name != "" && $qty >= 0) {
        $stmt = $pdo->prepare("INSERT INTO items(name, quantity) VALUES(?, ?)");
        $stmt->execute([$name, $qty]);
        $msg = "Item Added Successfully";
    } else {
        $msg = "Invalid Input";
    }
}

// ---------------- DELETE ITEM ----------------
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $pdo->prepare("DELETE FROM items WHERE id=?")->execute([$id]);
    $msg = "Item Deleted";
}

// ---------------- DASHBOARD DATA ----------------
$totalItems = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
$totalQty   = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM items")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard</title>

<!-- ICONS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
body { font-family: Arial; background:#eef2f7; margin:0; }
header { background:#2c3e50; color:white; padding:15px; text-align:left; }
.container { padding:20px; }

.card {
    background:white;
    padding:20px;
    border-radius:10px;
    margin-bottom:20px;
    box-shadow:0 2px 6px rgba(0,0,0,0.1);
}

.dashboard {
    display:flex;
    gap:20px;
}

.box {
    flex:1;
    background:white;
    padding:20px;
    border-radius:10px;
    text-align:center;
}

input, button {
    padding:10px;
    margin:5px;
}

button {
    background:#3498db;
    color:white;
    border:none;
    border-radius:5px;
    cursor:pointer;
}

.delete { background:red; }

table {
    width:100%;
    border-collapse: collapse;
}

th, td {
    padding:10px;
    border-bottom:1px solid #ddd;
    text-align:center;
}

.msg {
    padding:10px;
    margin-bottom:10px;
    background:#d4edda;
}
</style>
</head>

<body>

<header>
<h1><i class="fa-solid fa-boxes-stacked"></i> Student Dashboard</h1>
</header>

<div class="container">

<!-- DASHBOARD -->
<div class="dashboard">

<!-- STOCK MANAGEMENT -->
<div class="card">
<h2><i class="fa-solid fa-warehouse"></i> Stock Management</h2>

<?php if($msg): ?>
    <div class="msg"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>


<!-- REQUEST STOCK -->
<h3><i class="fa-solid fa-arrow-down"></i> Request Stock</h3>
<form method="POST" class="form">
    <input type="number" name="item_id" placeholder="Item ID" required>
    <input type="number" name="req_qty" placeholder="Request Quantity" min="1" required>
    <button name="request"><i class="fa-solid fa-hand"></i> Request</button>
</form>

</div>

<!-- TABLE -->
<div class="card">
<h2><i class="fa-solid fa-table"></i> Inventory List</h2>

<table>
<tr>
    <th>ID</th>
    <th>Item</th>
    <th>Quantity</th>
    <th>Action</th>
</tr>

<?php
$result = $pdo->query("SELECT * FROM items ORDER BY id DESC");
foreach ($result as $row) {
    echo "<tr>
        <td>{$row['id']}</td>
        <td>{$row['name']}</td>
        <td>{$row['quantity']}</td>
        <td>
            <a href='?delete={$row['id']}' onclick='return confirm(\"Delete?\")'>
                <button class='delete'><i class=\"fa-solid fa-trash\"></i></button>
            </a>
        </td>
    </tr>";
}
?>

</table>
</div>

</div>

</body>
</html>