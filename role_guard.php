<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$ROLE = $_SESSION['role'] ?? null;
$current_page = basename($_SERVER['PHP_SELF']);

// Pages Finance can access
$FINANCE_ALLOWED = ['admin_loan.php', 'activity_logs.php'];

// Only show overlay if finance tries to access restricted page
$SHOW_RESTRICT_OVERLAY = ($ROLE === 'finance' && !in_array($current_page, $FINANCE_ALLOWED));
