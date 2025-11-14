<?php
session_start();
require __DIR__ . '/vendor/autoload.php';

if (!isset($_SESSION['employee_id'])) {
    header("Location: login.php");
    exit;
}

// Prevent cached pages after logout
header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 


//$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
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

// Fetch payslip data from Supabase
$url = $projectUrl . '/rest/v1/payslip_content?employee_id=eq.' . urlencode($employee_id) . '&order=cutoff_date.desc';
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

// Determine which payslip to show
$selectedPayslip = null;
if (!empty($payslips)) {
    if (isset($_POST['payroll_date'])) {
        foreach ($payslips as $p) {
            if ($p['payroll_date'] === $_POST['payroll_date']) {
                $selectedPayslip = $p;
                break;
            }
        }
    } else {
        $selectedPayslip = $payslips[0]; // latest by default
    }
}

$position = $selectedPayslip['position'] ?? ($_SESSION['position'] ?? '-');
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($subsidiary) ?> Payslip</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($logoPath) ?>?v=1" />
  <link rel="icon" type="image/png" sizes="64x64" href="<?= htmlspecialchars($logoPath) ?>?v=1" />
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($logoPath) ?>?v=1" />
  <meta name="theme-color" content="<?= htmlspecialchars($themeColor) ?>" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" />
  <style>
  /* Full background gradient (same as employeedashboard) */
  body {
    min-height: 100vh;
    background: linear-gradient(to bottom right, #e5e7eb, #d1d5db, #9ca3af);
    background-attachment: fixed;
    color: #111;
    font-family: sans-serif;
  }

  /* Sidebar base styling */
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

    header h2 {
      font-size: 1rem;
    }

    main {
      padding: 1rem !important;
    }

    .bg-white.text-black.rounded-xl.shadow-lg {
      padding: 1.25rem !important;
    }

    .grid {
      grid-template-columns: 1fr !important;
    }

    table {
      font-size: 0.85rem !important;
    }
  }

  /* 📲 Small Mobile Adjustments */
  @media (max-width: 480px) {
    select,
    button {
      font-size: 0.9rem !important;
      padding: 0.6rem 0.8rem !important;
    }
    header h2 {
      font-size: 0.95rem;
    }
  }

  /* Desktop Sidebar Behavior */
  @media (min-width: 1024px) {
    #sidebar {
      position: fixed;
      top: 0;
      left: 0;
      height: 100%;
      width: 18rem; /* slightly wider like employeedashboard */
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

    /* When sidebar visible, push main content */
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
<body class="bg-gradient-to-br from-gray-200 via-gray-300 to-gray-400 text-white font-sans">
  <div class="flex flex-col md:flex-row h-screen overflow-hidden">
    <!-- Sidebar -->
<div id="sidebar" class="bg-black text-white flex flex-col transition-all duration-300 ease-in-out z-50">
  <div class="p-6 border-b border-gray-700 flex items-center justify-between">
    <h1 id="sidebarTitle" class="text-xl font-bold whitespace-nowrap overflow-hidden md:whitespace-normal md:overflow-visible">
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
    <a href="employeedashboard.php" class="w-full block text-left px-4 py-3 rounded-lg text-gray-300 hover:bg-gray-800 flex items-center space-x-3">
      <i class="bi bi-speedometer2"></i>
      <span class="nav-text">Dashboard</span>
    </a>
    <a href="index.php" class="w-full block text-left px-4 py-3 rounded-lg bg-white text-black flex items-center space-x-3">
      <i class="bi bi-cash-coin"></i>
      <span class="nav-text">My Payslips</span>
    </a>
    <a href="loan_employee.php" class="w-full block text-left px-4 py-3 rounded-lg text-gray-300 hover:bg-gray-800 flex items-center space-x-3">
      <i class="bi bi-cash-stack"></i>
      <span class="nav-text">Loans</span>
    </a>
  </nav>
</div>


     <!-- Main Content -->
  <div class="flex-1 flex flex-col overflow-y-auto">
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
      <div id="accountMenu" class="hidden absolute right-0 mt-2 w-40 bg-white text-black rounded-md shadow-lg z-50">
        <a href="logout.php" class="block px-4 py-2 hover:bg-gray-100 text-sm text-red-600">
          <i class="bi bi-box-arrow-right me-2"></i>Logout
        </a>
      </div>
    </div>
  </div>
</header>
      <main class="flex-1 flex flex-col items-center justify-start py-10 px-4">
        <?php if (empty($payslips)): ?>
        <p class="text-black text-lg">No payslips available.</p>
        <?php else: ?>
        <!-- Dropdown + Download -->
        <div
          class="flex flex-col sm:flex-row items-center justify-center sm:space-x-3 space-y-3 sm:space-y-0 mb-6 w-full max-w-3xl">
          <form
            method="post"
            id="payslipForm"
            class="flex items-center space-x-3 w-full sm:w-auto"
          >
            <select
              name="payroll_date"
              onchange="showLoadingAndSubmit()"
              class="bg-black text-white px-4 py-2 rounded-md w-full sm:w-auto"
            >
              <?php foreach ($payslips as $p): ?>
              <?php 
                $isSelected = isset($selectedPayslip) && isset($selectedPayslip['payroll_date']) && $selectedPayslip['payroll_date'] === $p['payroll_date'];
              ?>
              <option
                value="<?= htmlspecialchars($p['payroll_date']) ?>"
                <?= $isSelected ? 'selected' : '' ?>
              >
                <?= date('F j, Y', strtotime($p['payroll_date'])) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </form>

          <button
            id="downloadPdfBtn"
            class="bg-black text-white px-4 py-2 rounded-md flex items-center justify-center space-x-2 w-full sm:w-auto"
          >
            <span>Download PDF</span>
          </button>
        </div>

        <!-- Payslip Card -->
        <div
          class="bg-white text-black rounded-xl shadow-lg w-full max-w-3xl p-8 overflow-x-auto">
          <div class="text-center border-b border-gray-300 pb-4 mb-4">
  <div class="flex flex-col items-center justify-center leading-none">
<img
  src="<?= htmlspecialchars($logoPath) ?>"
  width="70"
  height="35"
  class="mx-auto mb-0"
  alt="Logo"
/>
<h2 class="text-lg font-bold text-black mt-0 mb-1 leading-tight">
  <?= htmlspecialchars($subsidiaryDisplayName) ?>
</h2>
  </div>
  <p class="text-sm text-gray-600">
    Huervana St., Burgos-Mabini, La Paz, Iloilo City, 5000
  </p>
  <p class="text-sm text-gray-600">management@quiraogroup.com</p>
</div>


          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
            <div class="bg-gray-100 p-3 rounded-md">
              <p class="text-xs text-gray-600">Employee Name:</p>
              <p class="font-semibold">
                <?= htmlspecialchars($_SESSION['complete_name'] ?? '-') ?>
              </p>
            </div>
            <div class="bg-gray-100 p-3 rounded-md">
              <p class="text-xs text-gray-600">Position:</p>
              <p class="font-semibold"><?= htmlspecialchars($position) ?></p>
            </div>
            <div class="bg-gray-100 p-3 rounded-md">
              <p class="text-xs text-gray-600">Employee ID:</p>
              <p class="font-semibold"><?= htmlspecialchars($employee_id) ?></p>
            </div>
            <div class="bg-gray-100 p-3 rounded-md">
              <p class="text-xs text-gray-600">Payroll Period:</p>
              <p class="font-semibold">
                <?= htmlspecialchars($selectedPayslip['cutoff_date'] ?? '-') ?>
              </p>
            </div>
          </div>

   <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 items-stretch">
  <!-- Earnings -->
  <div class="overflow-x-auto flex flex-col justify-between bg-white rounded-md">
    <div>
      <h3 class="font-semibold mb-2">Earnings</h3>
      <table class="w-full text-sm border-t border-gray-300">
        <tr class="border-b border-gray-200">
          <td>Description</td>
          <td class="text-right">Hours</td>
          <td class="text-right">Amount</td>
        </tr>
        <?php
        $earnings = [
          'basic_pay' => ['label' => 'Basic Pay', 'hours' => 'no_of_hours'],
          'ot_pay' => ['label' => 'Overtime', 'hours' => 'ot_hours'],
          'rdot_pay' => ['label' => 'Rest Day OT', 'hours' => 'rdot_hours'],
          'night_dif_pay' => ['label' => 'Night Differential', 'hours' => 'nd_hours'],
          'special_holiday_pay' => ['label' => 'Special Holiday Pay', 'hours' => 'special_hol_hours'],
          'regular_holiday_pay' => ['label' => 'Regular Holiday Pay', 'hours' => 'reg_hol_hours'],
          'special_holiday_ot_pay' => ['label' => 'Special Holiday OT Pay', 'hours' => 'special_hol_ot_hours'],
          'regular_holiday_ot_pay' => ['label' => 'Regular Holiday OT Pay', 'hours' => 'reg_hol_ot_hours'],
          'leave_w_pay' => ['label' => 'Leave with Pay'],
          'allowance' => ['label' => 'Allowance'],
          'sign_in_bonus' => ['label' => 'Sign-in Bonus'],
          'other_adjustment' => ['label' => 'Other Adjustment']
        ];
        $hasEarnings = false;
        foreach ($earnings as $key => $meta) {
          $amount = floatval($selectedPayslip[$key] ?? 0);
          if ($amount > 0) {
            $hasEarnings = true;
            echo "<tr><td>{$meta['label']}</td>";
            echo "<td class='text-right'>" . (!empty($meta['hours']) && !empty($selectedPayslip[$meta['hours']])
                  ? htmlspecialchars($selectedPayslip[$meta['hours']]) . ' hrs' : '') . "</td>";
            echo "<td class='text-right'>₱" . number_format($amount, 2) . "</td></tr>";
          }
        }
        if (!$hasEarnings) {
          echo "<tr><td colspan='3' class='text-center text-gray-500 italic py-2'>No earnings available</td></tr>";
        }
        ?>
      </table>
    </div>
    <p class="font-semibold mt-2 text-right border-t border-gray-300 pt-2 mb-4">
      Total Compensation: ₱<?= number_format($selectedPayslip['total_compensation'] ?? 0, 2) ?>
    </p>
  </div>

  <!-- Deductions -->
  <div class="overflow-x-auto flex flex-col justify-between bg-white rounded-md">
    <div>
      <h3 class="font-semibold mb-2">Deductions</h3>
      <table class="w-full text-sm border-t border-gray-300">
        <tr class="border-b border-gray-200">
          <td>Description</td>
          <td class="text-right">Amount</td>
        </tr>
        <?php
        $deductions = [
          'less_late' => 'Late',
          'less_absent' => 'Absent',
          'less_sss' => 'SSS',
          'less_phic' => 'PHIC',
          'less_hdmf' => 'HDMF',
          'less_whtax' => 'Withholding Tax',
          'less_sss_loan' => 'SSS Loan',
          'less_sss_sloan' => 'SSS Salary Loan',
          'less_pagibig_loan' => 'Pag-IBIG Loan',
          'less_comp_cash_advance' => 'Cash Advance',
          'less_company_loan' => 'Company Loan',
          'less_product_equip_loan' => 'Product/Equipment Loan',
          'less_uniform' => 'Uniform Deduction',
          'less_accountability' => 'Accountability',
          'salary_overpaid_deduction' => 'Salary Overpaid Deduction'
        ];
        $hasDeduction = false;
        foreach ($deductions as $key => $label) {
          $amount = floatval($selectedPayslip[$key] ?? 0);
          if ($amount > 0) {
            $hasDeduction = true;
            echo "<tr><td>{$label}</td><td class='text-right'>₱" . number_format($amount, 2) . "</td></tr>";
          }
        }
        if (!$hasDeduction) {
          echo "<tr><td colspan='2' class='text-center text-gray-500 italic py-2'>No deductions</td></tr>";
        }
        ?>
      </table>
    </div>
    <p class="font-semibold mt-2 text-right border-t border-gray-300 pt-2 mb-4">
      Total Deduction: ₱<?= number_format($selectedPayslip['total_deduction'] ?? 0, 2) ?>
    </p>
  </div>
</div>
<div class="bg-gray-100 rounded-md p-3 text-center mt-6">
  <p class="font-bold text-lg sm:text-xl">
    NET PAY:
    ₱<?= number_format((float)str_replace([',', ' '], '', $selectedPayslip['net_pay'] ?? 0), 2) ?>
  </p>
</div>

        </div>
        <?php endif; ?>
        <div
          id="loadingOverlay"
          class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50"
          >
          <div class="flex flex-col items-center">
            <svg
              class="animate-spin h-10 w-10 text-white mb-3"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
              ></circle>
              <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
              ></path>
            </svg>
            <p class="text-white text-sm font-medium">Loading payslip...</p>
          </div>
        </div>
      </main>
    </div>
  </div>
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
    function showLoadingAndSubmit() {
      document.getElementById("loadingOverlay").classList.remove("hidden");
      document.getElementById("payslipForm").submit();
    }
</script>    
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script> 
   
<script>
document.getElementById("downloadPdfBtn")?.addEventListener("click", async () => {
  const { jsPDF } = window.jspdf;
  const src = document.querySelector(".bg-white.text-black.rounded-xl.shadow-lg");
  if (!src) {
    alert("No payslip available to download.");
    return;
  }

  // ----- Force Desktop Layout -----
  const originalWidth = src.style.width;
  const originalTransform = src.style.transform;
  const originalScale = src.style.scale;
  const originalMargin = src.style.margin;

  src.style.width = "1024px"; // force desktop width
  src.style.transform = "scale(1)";
  src.style.margin = "0 auto";

  // Ensure all content is visible
  const originalOverflow = document.body.style.overflow;
  document.body.style.overflow = "visible";

  // Temporarily detach flex constraints (for full height rendering)
  const originalDisplay = src.style.display;
  src.style.display = "block";

  // Small delay for layout stabilization
  await new Promise(r => setTimeout(r, 200));

  // ----- Capture with html2canvas -----
  const SCALE = 3; // higher scale = sharper image
  let canvas;
  try {
    canvas = await html2canvas(src, {
      scale: SCALE,
      useCORS: true,
      backgroundColor: "#ffffff",
      scrollY: 0,
      windowWidth: 1024, // force desktop width
      windowHeight: src.scrollHeight,
      logging: false
    });
  } catch (err) {
    console.error("Canvas generation failed:", err);
    alert("Error generating PDF. Please try again.");
    // Restore styles before exiting
    src.style.width = originalWidth;
    src.style.transform = originalTransform;
    src.style.scale = originalScale;
    src.style.margin = originalMargin;
    document.body.style.overflow = originalOverflow;
    src.style.display = originalDisplay;
    return;
  }

  // Restore DOM state
  src.style.width = originalWidth;
  src.style.transform = originalTransform;
  src.style.scale = originalScale;
  src.style.margin = originalMargin;
  document.body.style.overflow = originalOverflow;
  src.style.display = originalDisplay;

  // ----- Prepare PDF setup -----
  const pdf = new jsPDF("p", "mm", "a4");
  const pageWidth = pdf.internal.pageSize.getWidth();
  const pageHeight = pdf.internal.pageSize.getHeight();

  const marginLeft = 10;
  const marginTop = 1.5; // tight header
  const imgWidth = pageWidth - marginLeft * 2;
  const pageHeightUsable = pageHeight - marginLeft * 2;

  const imgHeight = (canvas.height * imgWidth) / canvas.width;
  const pageCanvasHeight = (pageHeightUsable * canvas.width) / imgWidth;

  let yOffset = 0;
  let pageIndex = 0;

  // ----- Slice the canvas into multiple pages if needed -----
  while (yOffset < canvas.height) {
    const pageCanvas = document.createElement("canvas");
    pageCanvas.width = canvas.width;
    pageCanvas.height = Math.min(pageCanvasHeight, canvas.height - yOffset);

    const ctx = pageCanvas.getContext("2d");
    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, pageCanvas.width, pageCanvas.height);
    ctx.drawImage(
      canvas,
      0, yOffset, canvas.width, pageCanvas.height,
      0, 0, canvas.width, pageCanvas.height
    );

    const imgData = pageCanvas.toDataURL("image/png");

    if (pageIndex > 0) pdf.addPage();
    pdf.addImage(
      imgData,
      "PNG",
      marginLeft,
      marginTop,
      imgWidth,
      (pageCanvas.height * imgWidth) / canvas.width
    );

    yOffset += pageCanvasHeight;
    pageIndex++;
  }

  // -----Footer Section -----
  pdf.setTextColor(120, 120, 120); 
  pdf.setFont("helvetica", "bold");
  pdf.setFontSize(9.5);
  const footerTop = pageHeight - 40;
  pdf.text("Disclaimer:", 10, footerTop);
  pdf.setFont("helvetica", "normal");
  pdf.setFontSize(8.5);
  const lines = [
    "This payslip can be used for any valid purposes you may require, including but not limited to employment verification, loan applications, visa or",
    "travel documentation, and proof of income for financial institutions. Should you need further assistance or additional documentation,",
    "feel free to reach out."
  ];
  let y = footerTop + 5;
  lines.forEach(line => {
    pdf.text(line, 10, y);
    y += 4;
  });
  pdf.setFontSize(9);
  pdf.text("Managed by QUIRAO GROUP OF COMPANIES, OPC", pageWidth / 2, pageHeight - 15, { align: "center" });
  pdf.text("© 2025 All rights reserved.", pageWidth / 2, pageHeight - 10, { align: "center" });

  // ----- Save the file -----
  const employeeName = <?= json_encode($_SESSION['complete_name'] ?? 'Employee') ?>;
  try {
    pdf.save(`PAYSLIP_${employeeName.replace(/\s+/g, "_")}.pdf`);
  } catch (err) {
    alert("Unable to save PDF. Please make sure your browser allows downloads.");
    console.error(err);
  }
});
</script>
<script>

function updateDateTime() {
  const now = new Date();
  const formatted = now.toLocaleString("en-US", { 
    year: "numeric", month: "2-digit", day: "2-digit", 
    hour: "2-digit", minute: "2-digit", second: "2-digit", 
    hour12: true 
  });
  document.getElementById("datetime").textContent = formatted;
}
setInterval(updateDateTime, 1000);
updateDateTime();
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

