<?php
session_start();
include("../Connection/conn.php");

// Protect
if(!isset($_SESSION['role']) || $_SESSION['role'] != "dept_admin"){
    header("Location: ../login.php");
    exit();
}

$msg = "";

// Fetch departments
$depts = pg_query($conn, "SELECT * FROM department ORDER BY dept_name");

// Add student
if(isset($_POST['add_student'])){

    $name = $_POST['name'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    $roll = $_POST['roll_no'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $dept_id = $_POST['dept_id'];

    // Check duplicate username/email/roll
    $check = pg_query_params($conn,
        "SELECT * FROM students WHERE username=$1 OR email=$2 OR roll_no=$3",
        array($username, $email, $roll)
    );

    if(pg_num_rows($check) > 0){
        $msg = "Username / Email / Roll already exists!";
    } else {

        pg_query_params($conn,
            "INSERT INTO students (name, username, email, roll_no, password, dept_id)
             VALUES ($1, $2, $3, $4, $5, $6)",
            array($name, $username, $email, $roll, $password, $dept_id)
        );

        $msg = "Student added successfully!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Add Student</title>

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
    width:500px;
    margin:auto;
    box-shadow:0 2px 6px rgba(0,0,0,0.1);
}

h2 {
    margin-bottom:15px;
}

input, select {
    width:100%;
    padding:10px;
    margin:8px 0;
    border-radius:5px;
    border:1px solid #ccc;
}

button {
    width:100%;
    padding:10px;
    background:#3498db;
    color:white;
    border:none;
    border-radius:5px;
    cursor:pointer;
}

button:hover {
    background:#2980b9;
}

.msg {
    padding:10px;
    margin-bottom:10px;
    border-radius:5px;
    background:#d4edda;
}
.error {
    background:#f8d7da;
}
</style>

</head>

<body>

<header>
<h2>➕ Add Student</h2>
</header>

<div class="container">

<div class="card">

<?php if($msg != ""){ ?>
<div class="msg"><?php echo $msg; ?></div>
<?php } ?>

<form method="POST">

<input type="text" name="name" placeholder="Full Name" required>

<input type="text" name="username" placeholder="Username" required>

<input type="email" name="email" placeholder="Email" required>

<input type="text" name="roll_no" placeholder="Roll Number" required>

<input type="password" name="password" placeholder="Password" required>

<select name="dept_id" required>
<option value="">Select Department</option>

<?php while($row = pg_fetch_assoc($depts)) { ?>
<option value="<?php echo $row['dept_id']; ?>">
<?php echo $row['dept_name']; ?>
</option>
<?php } ?>

</select>

<button name="add_student">Add Student</button>

</form>

</div>

</div>

</body>
</html>