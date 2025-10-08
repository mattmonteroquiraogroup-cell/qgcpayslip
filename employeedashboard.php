<?php
session_start();
require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

if (!isset($_SESSION['employee_id'])) {
    header("Location: login.php");
    exit;
}

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];

$employee_id = $_SESSION['employee_id'];

// 🔹 Fetch all payslips for this employee
$url = $projectUrl . '/rest/v1/payslip_content?employee_id=eq.' . urlencode($employee_id) . '&order=cutoff_date.desc,payroll_date.desc';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $apiKey",
    "Authorization: Bearer $apiKey",
    "Content-Type: application/json"
]);
$response = curl_exec($ch);
curl_close($ch);
$payslips = json_decode($response, true) ?? [];

// Derive Position from the most recent payslip row
$position = '-';
if (!empty($payslips)) {
    // latest is index 0 because of the ORDER in the URL
    $latest = $payslips[0];
    $position = $latest['position'] ?? '-';
}


// 🔹 Compute totals
$totalNetPay = 0;
$totalSSS = 0;
$totalPHIC = 0;
$totalHDMF = 0;

foreach ($payslips as $p) {
    $netPay  = floatval(str_replace(['₱', ',', ' '], '', $p['net_pay'] ?? 0));
    $sss     = floatval(str_replace(['₱', ',', ' '], '', $p['less_sss'] ?? 0));
    $phic    = floatval(str_replace(['₱', ',', ' '], '', $p['less_phic'] ?? 0));
    $hdmf    = floatval(str_replace(['₱', ',', ' '], '', $p['less_hdmf'] ?? 0));

    $totalNetPay += $netPay;
    $totalSSS    += $sss;
    $totalPHIC   += $phic;
    $totalHDMF   += $hdmf;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Employee Compensation Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="bg-gradient-to-br from-gray-200 via-gray-300 to-gray-400 text-gray-900 font-sans">
<div class="flex h-screen">

  <!-- Sidebar -->
  <div id="sidebar" class="w-64 bg-black text-white flex flex-col transition-all duration-300 ease-in-out">
      <div class="p-6 border-b border-gray-700 flex items-center justify-between">
          <h1 id="sidebarTitle" class="text-xl font-bold">Payslip Portal</h1>
          <button onclick="toggleSidebar()" class="p-2 hover:bg-gray-800 rounded-lg transition-colors">
              <svg id="toggleIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
              </svg>
          </button>
      </div>

      <nav class="flex-1 p-4 space-y-2">
          <a href="employeedashboard.php" class="w-full block text-left px-4 py-3 rounded-lg bg-white text-black flex items-center space-x-3">
              <i class="bi bi-speedometer2"></i>
              <span class="nav-text">Dashboard</span>
          </a>
          <a href="index.php" class="w-full block text-left px-4 py-3 rounded-lg text-gray-300 hover:bg-gray-800 flex items-center space-x-3">
              <i class="bi bi-cash-coin"></i>
              <span class="nav-text">My Payslips</span>
          </a>
      </nav>

      <div class="p-4 border-t border-gray-700">
          <a href="logout.php" class="w-full block px-4 py-3 rounded-lg bg-gray-800 hover:bg-gray-700 flex items-center space-x-3">
              <i class="bi bi-box-arrow-right"></i>
              <span class="nav-text">Log Out</span>
          </a>
      </div>
  </div>

  <!-- Main Content -->
  <div class="flex-1 flex flex-col overflow-y-auto">
    <header class="bg-black shadow-sm border-b border-gray-800 px-6 py-4 flex justify-between items-center">
      <h2 class="text-xl font-semibold text-white">Welcome, <?= htmlspecialchars($_SESSION['complete_name'] ?? '') ?></h2>
    </header>

    <main class="flex-1 flex flex-col items-center justify-start py-10 px-4">

      <div class="bg-white rounded-2xl shadow-2xl p-10 w-full max-w-4xl">
        <h1 class="text-center text-2xl font-bold text-black mb-6 tracking-wide">EMPLOYEE COMPENSATION DASHBOARD</h1>

        <!-- Employee Info -->
        <div class="grid grid-cols-3 gap-4 mb-6">
          <div>
            <p class="text-xs font-semibold text-gray-600">Name</p>
            <p class="border rounded-md px-3 py-2"><?= htmlspecialchars($_SESSION['complete_name'] ?? '-') ?></p>
          </div>
          <div>
            <p class="text-xs font-semibold text-gray-600">Position</p>
            <p class="border rounded-md px-3 py-2"><?= htmlspecialchars($position) ?></p>

          </div>
          <div>
            <p class="text-xs font-semibold text-gray-600">Subsidiary</p>
            <p class="border rounded-md px-3 py-2"><?= htmlspecialchars($_SESSION['subsidiary'] ?? '-') ?></p>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-4 mb-8">
          <div>
            <p class="text-xs font-semibold text-gray-600">Social Security System</p>
            <p class="border rounded-md px-3 py-2">-</p>
          </div>
          <div>
            <p class="text-xs font-semibold text-gray-600">PhilHealth (PHIC)</p>
            <p class="border rounded-md px-3 py-2">-</p>
          </div>
          <div>
            <p class="text-xs font-semibold text-gray-600">Pag-IBIG (HDMF)</p>
            <p class="border rounded-md px-3 py-2">-</p>
          </div>
        </div>

        <!-- Compensation Overview -->
        <h2 class="text-center text-lg font-bold mb-4">COMPENSATION OVERVIEW</h2>

        <div class="bg-gray-100 rounded-lg text-center p-6 mb-6 shadow-inner">
          <p class="text-gray-600 text-sm font-medium">Total Compensation</p>
          <p class="text-3xl font-bold text-black mt-1">₱<?= number_format($totalNetPay, 2) ?></p>
        </div>

        <div class="grid grid-cols-3 gap-4 mb-8">
          <div class="bg-gray-100 rounded-lg p-4 text-center shadow-sm">
            <p class="text-gray-600 text-sm font-semibold">Total EE SSS</p>
            <p class="text-lg font-bold text-black mt-1">₱<?= number_format($totalSSS, 2) ?></p>
          </div>
          <div class="bg-gray-100 rounded-lg p-4 text-center shadow-sm">
            <p class="text-gray-600 text-sm font-semibold">Total EE PHIC</p>
            <p class="text-lg font-bold text-black mt-1">₱<?= number_format($totalPHIC, 2) ?></p>
          </div>
          <div class="bg-gray-100 rounded-lg p-4 text-center shadow-sm">
            <p class="text-gray-600 text-sm font-semibold">Total EE PAGIBIG</p>
            <p class="text-lg font-bold text-black mt-1">₱<?= number_format($totalHDMF, 2) ?></p>
          </div>
        </div>
      </div>

    </main>
  </div>
</div>

<script>
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const sidebarTitle = document.getElementById('sidebarTitle');
  const toggleIcon = document.getElementById('toggleIcon');
  const navTexts = document.querySelectorAll('.nav-text');
  const navButtons = document.querySelectorAll('nav a');

  if (sidebar.classList.contains('w-64')) {
      sidebar.classList.remove('w-64');
      sidebar.classList.add('w-16');
      sidebarTitle.style.display = 'none';
      navTexts.forEach(t => t.style.display = 'none');
      navButtons.forEach(b => { b.classList.add('justify-center'); b.classList.remove('space-x-3'); });
      toggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>';
  } else {
      sidebar.classList.remove('w-16');
      sidebar.classList.add('w-64');
      sidebarTitle.style.display = 'block';
      navTexts.forEach(t => t.style.display = 'block');
      navButtons.forEach(b => { b.classList.remove('justify-center'); b.classList.add('space-x-3'); });
      toggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>';
  }
}
</script>

</body>
</html>
