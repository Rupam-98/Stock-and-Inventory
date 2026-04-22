<?php
$host = "localhost";
$port = "5432";
$dbname = "six_sem";
$user = "postgres";
$password = "1035";

$conn = pg_connect("host=$host port=$port dbname=$dbname user=$user password=$password");

if(!$conn){
    die("Connection failed");
}
?>
