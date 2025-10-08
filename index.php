<?php
session_start();
require __DIR__ . '/vendor/autoload.php';

if (!isset($_SESSION['employee_id'])) {
    header("Location: login.php");
    exit;
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];

$employee_id = $_SESSION['employee_id'];
$subsidiary  = strtoupper($_SESSION['subsidiary'] ?? 'QGC');

// 🖼️ Map each subsidiary to its logo and color
$subsidiaryStyles = [
    'QGC'              => ['logo' => 'qgc.png', 'color' => '#aaaaaaff'],
    'WATERGATE'        => ['logo' => 'WTG.png', 'color' => '#0284c7'],
    'SARI-SARI MANOKAN'=> ['logo' => 'PFC.png', 'color' => '#00973fff'],
    'PALUTO'           => ['logo' => 'PFC.png', 'color' => '#cc1800ff'],
    'COMMISSARY'       => ['logo' => 'PFC.png', 'color' => '#cc1800ff'],
    'BRIGHTLINE'       => ['logo' => 'BL.png', 'color' => '#df6808ff'],
    'BMMI-WAREHOUSE'   => ['logo' => 'BMMI.png', 'color' => '#df6808ff'],
    'BMMI-DROPSHIPPING'=> ['logo' => 'BMMI.png', 'color' => '#df6808ff'],
];

// fallback if not found
$logoPath = $subsidiaryStyles[$subsidiary]['logo'] ?? 'qgc.png';
$themeColor = $subsidiaryStyles[$subsidiary]['color'] ?? '#949494ff';

// 🔹 Fetch payslip data from Supabase
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

// 🔹 Determine which payslip to show
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($subsidiary) ?> Payslip</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($logoPath) ?>?v=1">
  <link rel="icon" type="image/png" sizes="64x64" href="<?= htmlspecialchars($logoPath) ?>?v=1">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($logoPath) ?>?v=1">
  <meta name="theme-color" content="<?= htmlspecialchars($themeColor) ?>">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="bg-gradient-to-br from-gray-200 via-gray-300 to-gray-400 text-white font-sans">
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
          <a href="employeedashboard.php" class="w-full block text-left px-4 py-3 rounded-lg text-gray-300 hover:bg-gray-800 flex items-center space-x-3">
              <i class="bi bi-speedometer2"></i>
              <span class="nav-text">Dashboard</span>
          </a>
          <a href="index.php" class="w-full block text-left px-4 py-3 rounded-lg bg-white text-black flex items-center space-x-3">
              <i class="bi bi-cash-coin"></i>
              <span class="nav-text">View Payslips</span>
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
      <h2 class="text-xl font-semibold">Welcome, <?= htmlspecialchars($_SESSION['complete_name'] ?? '') ?></h2>
    </header>

    <main class="flex-1 flex flex-col items-center justify-start py-10 px-4">
      <?php if (empty($payslips)): ?>
        <p class="text-black text-lg">No payslips available.</p>
      <?php else: ?>
      
      <!-- Dropdown + Download Button -->
      <div class="flex items-center justify-center space-x-3 mb-6">
        <form method="post" id="payslipForm" class="flex items-center space-x-3">
          <select name="payroll_date" onchange="showLoadingAndSubmit()" class="bg-black text-white px-4 py-2 rounded-md">
            <?php foreach ($payslips as $p): ?>
              <?php 
                $isSelected = isset($selectedPayslip) && isset($selectedPayslip['payroll_date']) && $selectedPayslip['payroll_date'] === $p['payroll_date'];
              ?>
              <option value="<?= htmlspecialchars($p['payroll_date']) ?>" <?= $isSelected ? 'selected' : '' ?>>
                <?= date('F j, Y', strtotime($p['payroll_date'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <button id="downloadPdfBtn" class="bg-black text-white px-4 py-2 rounded-md flex items-center space-x-2">
          <span>Download PDF</span>
        </button>
      </div>

      <!-- Payslip Card -->
      <div class="bg-white text-black rounded-xl shadow-lg w-full max-w-3xl p-8">
        <div class="text-center border-b border-gray-300 pb-4 mb-4">
          <img src="<?= htmlspecialchars($logoPath) ?>" width="90" height="45" class="mx-auto mb-2" alt="Logo">
          <p class="text-sm text-gray-600">Huervana St., Burgos-Mabini, La Paz, Iloilo City, 5000</p>
          <p class="text-sm text-gray-600">management@quiraogroup.com</p>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-6">
          <div class="bg-gray-100 p-3 rounded-md">
            <p class="text-xs text-gray-600">Employee Name:</p>
            <p class="font-semibold"><?= htmlspecialchars($_SESSION['complete_name'] ?? '-') ?></p>
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
            <p class="font-semibold"><?= htmlspecialchars($selectedPayslip['cutoff_date'] ?? '-') ?></p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-6 mb-6">
          <div>
            <h3 class="font-semibold mb-2">Earnings</h3>
            <table class="w-full text-sm border-t border-gray-300">
              <tr class="border-b border-gray-200">
                <td>Description</td>
                <td class="text-right">Hours</td>
                <td class="text-right">Amount</td>
              </tr>

              <!-- Basic Pay -->
<tr>
  <td>Total Basic</td>
  <td class="text-right"><?= htmlspecialchars($selectedPayslip['no_of_hours'] ?? '0') ?> hrs</td>
  <td class="text-right">₱<?= number_format($selectedPayslip['basic_pay'] ?? 0, 2) ?></td>
</tr>

<?php
// ✅ Conditional earnings mapping
$earnings = [
  'ot_pay' => ['label' => 'Overtime', 'hours' => 'ot_hours'],
  'rdot_pay' => ['label' => 'Rest Day OT', 'hours' => 'rdot_hours', 'rate' => 'rdot_rate'],
  'night_dif_pay' => ['label' => 'Night Differential', 'hours' => 'nd_hours', 'rate' => 'nd_rate'],
  'leave_w_pay' => ['label' => 'Leave with Pay'],
  'special_holiday_pay' => ['label' => 'Special Holiday Pay', 'hours' => 'special_hol_hours', 'rate' => 'special_hol_rate'],
  'regular_holiday_pay' => ['label' => 'Regular Holiday Pay', 'hours' => 'reg_hol_hours', 'rate' => 'reg_hol_rate'],
  'special_holiday_ot_pay' => ['label' => 'Special Holiday OT Pay', 'hours' => 'special_hol_ot_hours', 'rate' => 'special_hol_ot_rate'],
  'regular_holiday_ot_pay' => ['label' => 'Regular Holiday OT Pay', 'hours' => 'reg_hol_ot_hours', 'rate' => 'reg_hol_ot_rate'],
  'allowance' => ['label' => 'Allowance'],
  'sign_in_bonus' => ['label' => 'Sign-in Bonus'],
  'other_adjustment' => ['label' => 'Other Adjustment']
];

// ✅ Loop through and display only if value > 0
foreach ($earnings as $key => $meta) {
  $amount = floatval($selectedPayslip[$key] ?? 0);
  if ($amount > 0) {
    echo "<tr>";
    echo "<td>{$meta['label']}</td>";

    // Optional Hours Column
    if (isset($meta['hours']) && !empty($selectedPayslip[$meta['hours']])) {
      echo "<td class='text-right'>" . htmlspecialchars($selectedPayslip[$meta['hours']]) . " hrs</td>";
    } else {
      echo "<td></td>";
    }

    // Amount Column
    echo "<td class='text-right'>₱" . number_format($amount, 2) . "</td>";
    echo "</tr>";
  }
}
?>

            </table>
            <p class="font-semibold mt-2 text-right">Total Compensation: ₱<?= number_format($selectedPayslip['total_compensation'] ?? 0, 2) ?></p>
          </div>

          <!-- ✅ CONDITIONAL DEDUCTIONS -->
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
                'less_uniform' => 'Uniform',
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
            <p class="font-semibold mt-2 text-right">Total Deduction: ₱<?= number_format($selectedPayslip['total_deduction'] ?? 0, 2) ?></p>
          </div>
        </div>

        <div class="bg-gray-100 rounded-md p-3 text-center">
          <p class="font-bold text-lg">NET PAY: ₱<?= number_format((float)str_replace([',', ' '], '', $selectedPayslip['net_pay'] ?? 0), 2) ?></p>
        </div>
      </div>
      <?php endif; ?>

      <!-- Loading Spinner -->
      <div id="loadingOverlay" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50">
        <div class="flex flex-col items-center">
          <svg class="animate-spin h-10 w-10 text-white mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
          </svg>
          <p class="text-white text-sm font-medium">Loading payslip...</p>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- JS: Sidebar + Loading + PDF Export -->
<script>
function showLoadingAndSubmit() {
  document.getElementById('loadingOverlay').classList.remove('hidden');
  document.getElementById('payslipForm').submit();
}

function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const sidebarTitle = document.getElementById('sidebarTitle');
  const toggleIcon = document.getElementById('toggleIcon');
  const navTexts = document.querySelectorAll('.nav-text');
  const navButtons = document.querySelectorAll('nav a');
  if (sidebar.classList.contains('w-64')) {
    sidebar.classList.replace('w-64', 'w-16');
    sidebarTitle.style.display = 'none';
    navTexts.forEach(t => t.style.display = 'none');
    navButtons.forEach(b => { b.classList.add('justify-center'); b.classList.remove('space-x-3'); });
    toggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>';
  } else {
    sidebar.classList.replace('w-16', 'w-64');
    sidebarTitle.style.display = 'block';
    navTexts.forEach(t => t.style.display = 'block');
    navButtons.forEach(b => { b.classList.remove('justify-center'); b.classList.add('space-x-3'); });
    toggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>';
  }
}
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
// PDF Export with Disclaimer
document.getElementById('downloadPdfBtn')?.addEventListener('click', async () => {
  const { jsPDF } = window.jspdf;
  const payslipCard = document.querySelector('.bg-white.text-black.rounded-xl.shadow-lg');
  if (!payslipCard) { alert('No payslip available to download.'); return; }

  const canvas = await html2canvas(payslipCard, { scale: 2, useCORS: true, backgroundColor: "#ffffff" });
  const imgData = canvas.toDataURL('image/png');
  const pdf = new jsPDF('p', 'mm', 'a4');
  const pageWidth = 210;
  const imgWidth = pageWidth - 20;
  const imgHeight = (canvas.height * imgWidth) / canvas.width;
  pdf.addImage(imgData, 'PNG', 10, 10, imgWidth, imgHeight);

  // Footer / Disclaimer
  const footerStartY = Math.min(imgHeight + 10, 260);
  pdf.setFont('helvetica', 'bold');
  pdf.setFontSize(9.5);
  pdf.text('Disclaimer:', 10, footerStartY);
  pdf.setFont('helvetica', 'normal');
  pdf.setFontSize(8.5);
  const lines = [
    'This payslip can be used for any valid purposes you may require, including but not limited to employment verification, loan applications, visa or',
    'travel documentation, and proof of income for financial institutions. Should you need further assistance or additional documentation',
    'feel free to reach out.'
  ];
  let y = footerStartY + 5;
  lines.forEach(line => { pdf.text(line, 10, y); y += 4; });
  pdf.setFontSize(9);
  pdf.text('Managed by QUIRAO GROUP OF COMPANIES, OPC', pageWidth / 2, y + 8, { align: 'center' });
  pdf.text('© 2025 All rights reserved.', pageWidth / 2, y + 13, { align: 'center' });

  pdf.save(`PAYSLIP_${(<?=$_SESSION['complete_name'] ? json_encode($_SESSION['complete_name']) : "'Employee'"?>).replace(/\s+/g,'_')}.pdf`);
});
</script>

</body>
</html>
