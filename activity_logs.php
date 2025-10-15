<?php
session_start();
require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

// --- CONFIGURATION ---
const LOGS_PER_PAGE = 10;
// ---------------------

// Protect admin access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];

// --- PAGINATION LOGIC ---

// Get current page from URL, default to 1
$current_page = (int)($_GET['page'] ?? 1);
$current_page = max(1, $current_page); // Ensure it's not less than 1
$offset = ($current_page - 1) * LOGS_PER_PAGE;
$limit = LOGS_PER_PAGE;

// Fetch Total Count first
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$projectUrl/rest/v1/activity_logs?select=count");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $apiKey",
    "Authorization: Bearer $apiKey",
    "Range: 0-9", // Supabase requires a Range header even for count
    "Range-Unit: items",
    "Prefer: count=exact" // Crucial for getting the total count
]);
$count_response_headers = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$count_response_headers) {
    $len = strlen($header);
    $header_parts = explode(':', $header, 2);
    if (count($header_parts) < 2) {
        return $len;
    }
    $count_response_headers[strtolower(trim($header_parts[0]))] = trim($header_parts[1]);
    return $len;
});

curl_exec($ch);
curl_close($ch);

$total_logs = 0;
// The total count is typically returned in the Content-Range header from Supabase
if (isset($count_response_headers['content-range'])) {
    // Example format: '0-9/123' where 123 is the total count
    if (preg_match('/\/(\d+)$/', $count_response_headers['content-range'], $matches)) {
        $total_logs = (int)$matches[1];
    }
}

$total_pages = ceil($total_logs / LOGS_PER_PAGE);

// Fetch Logs for the current page
$ch = curl_init();
$range_end = $offset + $limit - 1; // Supabase range is inclusive
$log_range_header = "Range: $offset-$range_end";

curl_setopt($ch, CURLOPT_URL, "$projectUrl/rest/v1/activity_logs?select=*&order=created_at.desc");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $apiKey",
    "Authorization: Bearer $apiKey",
    $log_range_header, // Apply limit and offset via Range header
    "Range-Unit: items"
]);
$response = curl_exec($ch);
curl_close($ch);
$logs = json_decode($response, true);

// --- HELPER FUNCTIONS ---
$current_page_file = basename($_SERVER['PHP_SELF']); // Should be 'admin_logs.php'
function navButtonClass($page, $current_page_file) {
    return $page === $current_page_file
        ? "bg-gray-800 text-white font-semibold"
        : "bg-transparent text-white";
}

// Pagination link generator for Tailwind CSS class
function paginationLinkClass($page, $current) {
    return $page == $current
        ? 'bg-gray-800 text-white'
        : 'bg-white text-gray-700 hover:bg-gray-50';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Recent Activities</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="bg-gray-100 font-sans">
<div class="flex h-screen">

    <div id="sidebar" class="w-64 bg-black text-white flex flex-col transition-all duration-300 ease-in-out">
        <div class="p-6 border-b border-gray-700 flex items-center justify-between">
            <h1 id="sidebarTitle" class="text-xl font-bold">Payslip & Loan Portal</h1>
            <button onclick="toggleSidebar()" class="p-2 hover:bg-gray-800 rounded-lg transition-colors">
                <svg id="toggleIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                </svg>
            </button>
        </div>

        <nav class="flex-1 p-4 space-y-2">
            <button onclick="window.location.href='admindashboard.php'"
              class="w-full text-left px-4 py-3 rounded-lg flex items-center space-x-3 <?= navButtonClass('admindashboard.php', $current_page_file) ?>">
                <i class="bi bi-people"></i>
                <span class="nav-text">Payslip Management</span>
            </button>
            <button onclick="window.location.href='admin_loan.php'"
              class="w-full text-left px-4 py-3 rounded-lg flex items-center space-x-3 <?= navButtonClass('admin_loan.php', $current_page_file) ?>">
                <i class="bi bi-cash-stack"></i>
                <span class="nav-text">Track Loans</span>
            </button>
            <button onclick="window.location.href='admin_logs.php'"
              class="w-full text-left px-4 py-3 rounded-lg flex items-center space-x-3 <?= navButtonClass('activity_logs.php', $current_page_file) ?>">
                <i class="bi bi-clock-history"></i>
                <span class="nav-text">Recent Activities</span>
            </button>
        </nav>

        <div class="p-4 border-t border-gray-700">
            <a href="logout.php" class="w-full block px-4 py-3 rounded-lg bg-gray-800 hover:bg-gray-700 flex items-center space-x-3">
                <i class="bi bi-box-arrow-right"></i>
                <span class="nav-text">Log Out</span>
            </a>
        </div>
    </div>

    <main class="flex-1 p-6 overflow-y-auto">
        <div class="max-w-5xl mx-auto bg-white rounded-lg shadow p-6">
            <h2 class="text-2xl font-semibold mb-6 flex items-center gap-2">
                Recent Activities
            </h2>
            <?php if (!empty($logs)): ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($logs as $log): ?>
                        <div class="py-4">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="font-semibold text-gray-800">
                                        <?= htmlspecialchars($log['action']) ?>
                                    </p>
                                    <p class="text-gray-600 text-sm">
                                        <?= htmlspecialchars($log['description']) ?>
                                    </p>
                                    <p class="text-gray-400 text-xs mt-1">
                                        By <span class="font-medium text-gray-700"><?= htmlspecialchars($log['admin_name'] ?? 'N/A') ?></span>
                                        (ID: <?= htmlspecialchars($log['admin_id'] ?? 'N/A') ?>)
                                    </p>
                                </div>
                                <div class="text-right text-gray-500 text-sm">
                                    <?php
                                    $date = new DateTime($log['created_at'], new DateTimeZone('UTC'));
                                    $date->setTimezone(new DateTimeZone('Asia/Manila'));
                                    echo $date->format('M d, Y h:i A');
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex justify-between items-center mt-6">
                    <p class="text-sm text-gray-700">
                        Showing logs 
                        <span class="font-medium"><?= $offset + 1 ?></span> to 
                        <span class="font-medium"><?= min($offset + LOGS_PER_PAGE, $total_logs) ?></span> of 
                        <span class="font-medium"><?= $total_logs ?></span> results
                    </p>
                    <div class="flex space-x-1">
                        <a href="?page=<?= max(1, $current_page - 1) ?>" 
                           class="px-3 py-1 text-sm font-medium border rounded-md 
                           <?= $current_page <= 1 ? 'pointer-events-none opacity-50 bg-gray-100 text-gray-500' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                            Previous
                        </a>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i ?>" 
                               class="px-3 py-1 text-sm font-medium border rounded-md 
                               <?= paginationLinkClass($i, $current_page) ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <a href="?page=<?= min($total_pages, $current_page + 1) ?>" 
                           class="px-3 py-1 text-sm font-medium border rounded-md 
                           <?= $current_page >= $total_pages ? 'pointer-events-none opacity-50 bg-gray-100 text-gray-500' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                            Next
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <div class="text-center text-gray-500 py-10">
                    <i class="bi bi-info-circle text-4xl mb-2"></i>
                    <p>No activity recorded yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const sidebarTitle = document.getElementById('sidebarTitle');
    const toggleIcon = document.getElementById('toggleIcon');
    const navTexts = document.querySelectorAll('.nav-text');
    const navButtons = document.querySelectorAll('nav button');

    if (sidebar.classList.contains('w-64')) {
        sidebar.classList.replace('w-64', 'w-16');
        sidebarTitle.style.display = 'none';
        navTexts.forEach(t => t.style.display = 'none');
        navButtons.forEach(b => b.classList.add('justify-center'));
        toggleIcon.innerHTML =
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>';
    } else {
        sidebar.classList.replace('w-16', 'w-64');
        sidebarTitle.style.display = 'block';
        navTexts.forEach(t => t.style.display = 'block');
        navButtons.forEach(b => b.classList.remove('justify-center'));
        toggleIcon.innerHTML =
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>';
    }
}
</script>
</body>
</html>