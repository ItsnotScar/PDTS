<?php
require_once 'config.php';

$name = "ADMIN";
$email = "admin@gmail.com";
$password = password_hash("admin123", PASSWORD_DEFAULT);
$role = "admin";

$sql = "INSERT INTO profile (name, email, password, role) VALUES ('$name', '$email', '$password', '$role')";
if ($conn->query($sql)) {
    echo "Admin created successfully!";
} else {
    echo "Error: " . $conn->error;
}
?>
