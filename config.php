<?php

$host = "localhost";           
$dbname = "kejasmart";         
$username = "root";            
$password = "";                

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    // Enable error reporting
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // If connection fails
    die("Database connection failed: " . $e->getMessage());
}
?>
