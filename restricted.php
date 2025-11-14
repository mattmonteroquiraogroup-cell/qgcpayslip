<?php
session_start();
$message = $_SESSION['restricted_message'] ?? "You do not have permission to access this page.";
unset($_SESSION['restricted_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Access Restricted</title>
<style>
body {
  background-color: #f0f0f0;
  color: #333;
  font-family: 'Segoe UI', sans-serif;
  text-align: center;
  padding: 50px;
}
.card {
  display: inline-block;
  background: #fff;
  border: 2px solid #ccc;
  padding: 2rem;
  border-radius: 10px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  color: #888;
}
</style>
</head>
<body>
  <div class="card">
    <h2>Access Restricted</h2>
    <p><?= htmlspecialchars($message) ?></p>
    <a href="admin_loan.php">Go to Loan Management</a>
  </div>
</body>
</html>
