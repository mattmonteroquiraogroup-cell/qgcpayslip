<?php
session_start();
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/temporary_block_page.php';
use Dotenv\Dotenv;

if (!isset($_SESSION['employee_id'])) {
    header("Location: login.php");
    exit;
}

// Prevent cached pages after logout
header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 


//$dotenv = Dotenv::createImmutable(__DIR__);
//$dotenv->load();

$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];

$employee_id = $_SESSION['employee_id'];
$subsidiary  = strtoupper($_SESSION['subsidiary'] ?? 'QGC');

// Map each subsidiary to its logo and color
$subsidiaryStyles = [
    'QGC'              => ['logo' => 'QGC.png', 'color' => '#aaaaaaff'],
    'WATERGATE'        => ['logo' => 'WTG.png', 'color' => '#0284c7'],
    'SARI-SARI MANOKAN'=> ['logo' => 'PFC.png', 'color' => '#00973fff'],
    'PALUTO'           => ['logo' => 'PFC.png', 'color' => '#cc1800ff'],
    'COMMISSARY'       => ['logo' => 'PFC.png', 'color' => '#cc1800ff'],
    'BRIGHTLINE'       => ['logo' => 'BL.png', 'color' => '#df6808ff'],
    'BMMI-WAREHOUSE'   => ['logo' => 'BMMI.png', 'color' => '#df6808ff'],
    'BMMI-DROPSHIPPING'=> ['logo' => 'BMMI.png', 'color' => '#df6808ff'],
];

// Map subsidiary codes to full names
$subsidiaryFullNames = [
    'QGC'               => 'QUIRAO GROUP OF COMPANIES',
    'BMMI-WAREHOUSE'    => 'BUILDMASTER',
    'BMMI-DROPSHIPPING' => 'BUILDMASTER',
    'BRIGHTLINE'        => 'BRIGHTLINE TRUCKING CORPORATION',
    'WATERGATE'         => 'WATERGATE',
    'SARI-SARI MANOKAN' => 'PIGGLY FOODS CORPORATION',
    'PALUTO'            => 'PIGGLY FOODS CORPORATION',
    'COMMISSARY'        => 'PIGGLY FOODS CORPORATION',

];

// Define display name safely
$subsidiaryDisplayName = $subsidiaryFullNames[$subsidiary] ?? strtoupper($subsidiary);

// fallback if not found
$logoPath = $subsidiaryStyles[$subsidiary]['logo'] ?? 'qgc.png';
$themeColor = $subsidiaryStyles[$subsidiary]['color'] ?? '#949494ff';


$employee_id = $_SESSION['employee_id'];

// Fetch all payslips for this employee
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

// Compute totals
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
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Compensation Dashboard</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($logoPath) ?>?v=1" />
  <link rel="icon" type="image/png" sizes="64x64" href="<?= htmlspecialchars($logoPath) ?>?v=1" />
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($logoPath) ?>?v=1" />
  <meta name="theme-color" content="<?= htmlspecialchars($themeColor) ?>" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    /* Full background gradient across entire screen */
    body {
      min-height: 100vh;
      background: linear-gradient(to bottom right, #e5e7eb, #d1d5db, #9ca3af);
      background-attachment: fixed;
      color: #111;
      font-family: sans-serif;
    }

    /* Sidebar styling */
    #sidebar {
      background-color: rgba(0, 0, 0, 1);
      box-shadow: 4px 0 10px rgba(0, 0, 0, 0.4);
    }

    /* Mobile Sidebar Behavior */
    @media (max-width: 1023px) {
      #sidebar {
        position: fixed;
        top: 0;
        left: -18rem;
        height: 100%;
        z-index: 50;
        transition: left 0.3s ease-in-out;
      }
      #sidebar.active {
        left: 0;
      }
    }

    /* Desktop Sidebar Behavior */
    @media (min-width: 1024px) {
      #sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100%;
        width: 18rem; /* Slightly wider so title fits fully */
      }
      #sidebar.collapsed {
        width: 4rem !important;
        transition: width 0.3s ease-in-out;
      }

      #sidebar.collapsed .nav-text,
      #sidebar.collapsed #sidebarTitle {
        display: none !important;
      }

      #sidebar.collapsed nav a {
        justify-content: center !important;
      }

      /* Shift main content when sidebar is visible */
      #main-content {
        margin-left: 18rem;
        transition: margin-left 0.3s ease-in-out;
      }
      #sidebar.collapsed ~ #main-content {
        margin-left: 4rem;
      }
    }
  </style>
</head>
<body>
  <!-- Sidebar -->
  <div id="sidebar" class="bg-black text-white flex flex-col transition-all duration-300 ease-in-out z-50">
    <div class="p-6 border-b border-gray-700 flex items-center justify-between">
      <h1 id="sidebarTitle"
          class="text-xl font-bold whitespace-nowrap overflow-hidden md:whitespace-normal md:overflow-visible">
        Payslip & Loan Portal
      </h1>
      <button onclick="toggleSidebar()" class="p-2 hover:bg-gray-800 rounded-lg transition-colors ml-2 flex-shrink-0">
        <svg id="toggleIcon" class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
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
     <!----<a href="loan_employee.php" class="w-full block text-left px-4 py-3 rounded-lg text-gray-300 hover:bg-gray-800 flex items-center space-x-3">
        <i class="bi bi-cash-stack"></i>
        <span class="nav-text">Loans</span>
      </a> temporary block, restore after. ---->
<?php if ($isLoanFeatureActive): ?>
  <a href="loan_employee.php"
     class="w-full block text-left px-4 py-3 rounded-lg text-gray-300 hover:bg-gray-800 flex items-center space-x-3">
    <i class="bi bi-cash-stack"></i>
    <span class="nav-text">Loans</span>
  </a>
<?php else: ?>
  <a href="#"
     onclick="event.preventDefault(); alert('Loan requests are temporarily unavailable. Please check back later.');"
     class="w-full block text-left px-4 py-3 rounded-lg text-gray-500 flex items-center space-x-3 opacity-50 cursor-not-allowed"
     title="Loan requests are temporarily unavailable">
    <i class="bi bi-cash-stack"></i>
    <span class="nav-text">Loans</span>
  </a>
<?php endif; ?>
    </nav>
  </div>

  <!-- Main Content Area -->
  <div id="main-content" class="flex-1 flex flex-col overflow-y-auto transition-all duration-300">

    <!-- Header -->
    <header class="bg-black shadow-sm border-b border-gray-800 px-4 md:px-6 py-4 flex items-center justify-between text-white">
      <!-- Burger (mobile only) -->
      <button onclick="toggleSidebar()" class="md:hidden p-2 rounded-lg hover:bg-gray-800 transition-colors text-white">
        <i class="bi bi-list text-xl"></i>
      </button>
      <!-- Right side (account dropdown) -->
      <div class="flex items-center space-x-4 ml-auto">
        <div class="relative group">
          <button id="accountToggle" class="flex items-center space-x-2 focus:outline-none">
            <i class="bi bi-person-circle text-lg"></i>
            <span class="text-sm font-small"><?= htmlspecialchars($_SESSION['complete_name'] ?? 'Account') ?></span>
            <i class="bi bi-caret-down-fill text-xs"></i>
          </button>
          <div id="accountMenu"
               class="hidden absolute right-0 mt-2 w-40 bg-white text-black rounded-md shadow-lg z-50">
            <a href="logout.php" class="block px-4 py-2 hover:bg-gray-100 text-sm text-red-600">
              <i class="bi bi-box-arrow-right me-2"></i>Logout
            </a>
          </div>
        </div>
      </div>
    </header> 
    <!-- Main -->
    <main class="flex-1 flex flex-col items-center justify-start py-10 px-4">
      <div class="bg-white rounded-2xl shadow-lg p-10 w-full max-w-4xl">
        <h1 class="text-center text-2xl font-bold text-black mb-6 tracking-wide">EMPLOYEE COMPENSATION DASHBOARD</h1>

        <!-- Employee Info -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
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

        <div class="border shadow-md p-4 rounded-lg text-center p-6 mb-6">
          <p class="text-gray-600 text-xl font-semibold">Total Compensation</p>
          <p class="text-3xl font-bold text-black mt-1">₱<?= number_format($totalNetPay, 2) ?></p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
          <div class="border shadow-md p-4 rounded-lg text-center">
            <p class="text-gray-600 text-sm font-semibold">Total EE SSS</p>
            <p class="text-lg font-bold text-black mt-1">₱<?= number_format($totalSSS, 2) ?></p>
          </div>
          <div class="border shadow-md p-4 rounded-lg text-center">
            <p class="text-gray-600 text-sm font-semibold">Total EE PHIC</p>
            <p class="text-lg font-bold text-black mt-1">₱<?= number_format($totalPHIC, 2) ?></p>
          </div>
          <div class="border shadow-md p-4 rounded-lg text-center">
            <p class="text-gray-600 text-sm font-semibold">Total EE PAGIBIG</p>
            <p class="text-lg font-bold text-black mt-1">₱<?= number_format($totalHDMF, 2) ?></p>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- Scripts -->
  <script>
  function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const isMobile = window.innerWidth < 1024;

    if (isMobile) {
      sidebar.classList.toggle('active');
    } else {
      sidebar.classList.toggle('collapsed');
      const toggleIcon = document.getElementById('toggleIcon');
      toggleIcon.innerHTML = sidebar.classList.contains('collapsed')
        ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>'
        : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>';
    }
  }
  // Account dropdown toggle
document.getElementById('accountToggle').addEventListener('click', function() {
  document.getElementById('accountMenu').classList.toggle('hidden');
});
document.addEventListener('click', function(e) {
  const toggle = document.getElementById('accountToggle');
  const menu = document.getElementById('accountMenu');
  if (!toggle.contains(e.target) && !menu.contains(e.target)) {
    menu.classList.add('hidden');
  }
});
  </script>
  <script>
window.addEventListener("pageshow", function (event) {
  if (event.persisted) {
    // Force reload if coming from browser cache (like pressing Back)
    window.location.reload();
  }
});
</script>

</body>
</html>


