<?php
session_start();
require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

// Restrict access to employees only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'employee') {
    header("Location: login.php");
    exit();
}

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
    'QGC'              => 'QUIRAO GROUP OF COMPANIES',
    'BUILDMASTER'      => 'BUILDMASTER',
    'BRIGHTLINE'       => 'BRIGHTLINE TRUCKING CORPORATION',
    'WATERGATE'        => 'WATERGATE',
    'SARI-SARI MANOKAN'=> 'PIGGLY FOODS CORPORATION',
    'PALUTO'           => 'PIGGLY FOODS CORPORATION',
    'COMMISSARY'       => 'PIGGLY FOODS CORPORATION',

];

// Define display name safely
$subsidiaryDisplayName = $subsidiaryFullNames[$subsidiary] ?? strtoupper($subsidiary);

// fallback if not found
$logoPath = $subsidiaryStyles[$subsidiary]['logo'] ?? 'qgc.png';
$themeColor = $subsidiaryStyles[$subsidiary]['color'] ?? '#949494ff';

//$dotenv = Dotenv::createImmutable(__DIR__);
//$dotenv->load();

$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];

$employee_id = $_SESSION['employee_id'];
$name = $_SESSION['complete_name'];
$subsidiary = $_SESSION['subsidiary'] ?? '';

//  HANDLE LOAN SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loan_amount'])) {
    $loan_type = trim($_POST['loan_type']);
    $loan_amount = floatval($_POST['loan_amount']);
    $payment_terms = strval($_POST['payment_terms']);
    // Calculation: Loan Amount / (Terms in Months * 2 cutoffs/month)
    $payment_amount = $loan_amount / ((int)$payment_terms * 2);
    $purpose = trim($_POST['purpose']);

    $payload = json_encode([
        "employee_id" => $employee_id,
        "name" => $name,
        "subsidiary" => $subsidiary,
        "loan_type" => $loan_type,
        "loan_amount" => $loan_amount,
        "payment_terms" => $payment_terms,
        "payment_amount" => $payment_amount,
        "purpose" => $purpose,
        "status" => "pending"
    ]);

    $ch = curl_init("$projectUrl/rest/v1/loans");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Prefer: return=representation"
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        echo "<script>alert('Loan request submitted successfully!'); window.location.href='loan_employee.php';</script>";
        exit;
    } else {
        $errorMsg = isset($result['message']) ? $result['message'] : 'Unknown error';
        echo "<script>alert('Failed to submit loan: " . addslashes($errorMsg) . "');</script>";
    }
}


//  FETCH EMPLOYEE'S LOANS WITH BALANCES
$ch = curl_init("$projectUrl/rest/v1/loans?select=*&employee_id=eq.$employee_id&order=inserted_at.desc");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "apikey: $apiKey",
        "Authorization: Bearer $apiKey"
    ]
]);
$response = curl_exec($ch);
curl_close($ch);

$loans = json_decode($response, true);
if (!is_array($loans) || isset($loans['message'])) $loans = [];

// For each loan, calculate total paid and balance
foreach ($loans as &$loan) {
    $loanId = $loan['id'] ?? null;
    if (!$loanId) continue;

    // Get total payments for this loan
    $ch = curl_init("$projectUrl/rest/v1/loan_payments?loan_id=eq.$loanId&select=payment_amount");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ]
    ]);
    $paymentsResponse = curl_exec($ch);
    curl_close($ch);

    $payments = json_decode($paymentsResponse, true);
    $totalPaid = array_sum(array_column($payments, 'payment_amount'));

    $loan['total_paid'] = $totalPaid;
    $loan['balance'] = max(0, ((float)$loan['loan_amount']) - $totalPaid);
}
unset($loan);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Loan Request</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($logoPath) ?>?v=1" />
  <link rel="icon" type="image/png" sizes="64x64" href="<?= htmlspecialchars($logoPath) ?>?v=1" />
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($logoPath) ?>?v=1" />
  <meta name="theme-color" content="<?= htmlspecialchars($themeColor) ?>" />
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
/* Unified Sidebar Styles for Mobile and Desktop Collapse */
@media (max-width: 1023px) {
    #sidebar {
        position: fixed;
        top: 0;
        left: -16rem;
        height: 100%;
        z-index: 50;
        transition: left 0.3s ease-in-out;
    }
    #sidebar.active {
        left: 0;
    }
}

/* Desktop collapse style (width: 4rem) - Ensures square active state */
@media (min-width: 1024px) {
    #sidebar.collapsed {
        width: 4rem !important; 
        transition: width 0.3s ease-in-out;
    }

    #sidebar.collapsed .nav-text,
    #sidebar.collapsed #sidebarTitle {
        display: none !important;
    }

    /* FIX: Set uniform padding (0.85rem) for the square look and center the links */
    /* Padding is applied to the link container, now the margin from the nav container handles the outer spacing */
    #sidebar.collapsed nav a {
        /* This ensures the active square is perfectly centered */
        padding: 0.85rem !important; 
        width: 100% !important; 
        justify-content: center !important;
    }
    
    /* Ensure the icon itself is centered */
    #sidebar.collapsed nav a i {
        margin: 0 auto; 
    }
}
</style>
</head>

<body class="bg-gradient-to-br from-gray-200 via-gray-300 to-gray-400 text-gray-900 font-sans">
<div class="flex flex-col md:flex-row h-screen overflow-hidden">

  <div id="sidebar" class="w-64 bg-black text-white flex flex-col transition-all duration-300 ease-in-out relative z-50">
      
      <div class="p-6 border-b border-gray-700 flex items-center justify-between relative">
          <h1 id="sidebarTitle" class="text-xl font-bold">Payslip Portal</h1>
          <button onclick="toggleSidebar()" class="p-2 hover:bg-gray-800 rounded-lg transition-colors absolute right-3 top-5 md:static md:ml-auto">
              <svg id="toggleIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
              </svg>
          </button>
      </div>

      <nav class="flex-1 space-y-2 mx-2">
          <a href="employeedashboard.php" class="w-full block text-left py-3 px-5 rounded-lg text-gray-300 hover:bg-gray-800 flex items-center space-x-3">
              <i class="bi bi-speedometer2"></i>
              <span class="nav-text">Dashboard</span>
          </a>
          <a href="index.php" class="w-full block text-left py-3 px-5 rounded-lg text-gray-300 hover:bg-gray-800 flex items-center space-x-3">
              <i class="bi bi-cash-coin"></i>
              <span class="nav-text">View Payslips</span>
          </a>
          <a href="loan_employee.php" class="w-full block text-left py-3 px-5 rounded-lg bg-white text-black flex items-center space-x-3">
              <i class="bi bi-cash-stack"></i>
              <span class="nav-text">My Loans</span>
          </a>
      </nav>

      <div class="p-4 border-t border-gray-700 mx-2">
          <a href="logout.php" class="w-full block py-3 px-5 rounded-lg bg-gray-800 hover:bg-gray-700 flex items-center space-x-3">
              <i class="bi bi-box-arrow-right"></i>
              <span class="nav-text">Log Out</span>
          </a>
      </div>
  </div>
  <div class="flex-1 flex flex-col overflow-y-auto">
    <header class="bg-black shadow-sm border-b border-gray-800 px-4 md:px-6 py-4 flex justify-between items-center text-white">
      <h2 class="text-xl font-semibold text-white">Welcome, <?= htmlspecialchars($name) ?></h2>
      <button onclick="toggleSidebar()" class="md:hidden p-2 rounded-lg hover:bg-gray-800 transition-colors">
        <i class="bi bi-list text-xl"></i>
      </button>
    </header>
    <main class="flex-1 py-8 px-6">
      <div class="bg-white rounded-2xl shadow-lg p-6 max-w-6xl mx-auto">
          <div class="flex justify-between items-center mb-4">
              <h2 class="text-2xl font-bold">My Loan Requests</h2>
              <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#loanModal">Request Loan</button>
          </div>
          <div class="table-responsive">
              <table class="table table-bordered align-middle text-sm mb-0 shadow-sm">
                  <thead class="table-dark text-center align-middle">
                      <tr>
                        <th>Loan Type</th>
                        <th>Amount</th>
                        <th>Terms (Months)</th>
                        <th>Payment per Cutoff</th>
                        <th>Balance</th>
                        <th>Purpose</th>
                        <th>Status</th>
                    </tr>
                  </thead>
                  <tbody class="text-center">
                      <?php if (empty($loans)): ?>
                          <tr><td colspan="6" class="text-muted py-3">No loan requests found.</td></tr>
                      <?php else: ?>
                          <?php foreach ($loans as $loan): ?>
                              <tr>
                                  <td><?= htmlspecialchars($loan['loan_type'] ?? '—') ?></td>
                                  <td>₱<?= number_format($loan['loan_amount'] ?? 0, 2) ?></td>
                                  <td><?= htmlspecialchars($loan['payment_terms'] ?? '—') ?></td>
                                  <td>₱<?= number_format($loan['payment_amount'] ?? 0, 2) ?></td>
                                  <td>₱<?= number_format($loan['balance'] ?? 0, 2) ?></td>
                                  <td class="text-start"><?= htmlspecialchars($loan['purpose'] ?? '—') ?></td>
                                  <td>
                                      <?php
                                          $status = $loan['status'] ?? 'pending';
                                          if ($status === 'paid') echo '<span class="badge bg-success px-2 py-1">Paid</span>';
                                          elseif ($status === 'active') echo '<span class="badge bg-primary px-2 py-1">Active</span>';
                                          else echo '<span class="badge bg-warning text-dark px-2 py-1">Pending</span>';
                                      ?>
                                  </td>
                              </tr>
                          <?php endforeach; ?>
                      <?php endif; ?>
                  </tbody>
              </table>
          </div>
      </div>
    </main>
  </div>
</div>

<div class="modal fade" id="loanModal" tabindex="-1" aria-labelledby="loanModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="loanModalLabel">Request a Loan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Name</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($name) ?>" readonly>
          </div>
          <div class="col-md-6">
            <label class="form-label">Employee ID</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($employee_id) ?>" readonly>
          </div>
          <div class="col-md-6">
            <label class="form-label">Subsidiary</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($subsidiary) ?>" readonly>
          </div>
          <div class="col-md-6">
            <label class="form-label">Loan Type</label>
            <select name="loan_type" class="form-select" required>
              <option value="">Select</option>
              <option value="Salary Loan">Salary Loan</option>
              <option value="Cash Advance">Cash Advance</option>
              <option value="Product Loan">Product Loan</option>
              <option value="Equipment/Gadget">Equipment/Gadget</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Loan Amount</label>
            <input type="number" name="loan_amount" id="loanAmount" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Payment Terms (Months)</label>
            <input type="number" name="payment_terms" id="paymentTerms" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Payment per Cutoff</label>
            <input type="text" id="paymentAmount" class="form-control" readonly>
          </div>
          <div class="col-md-12">
            <label class="form-label">Purpose of Loan</label>
            <textarea name="purpose" class="form-control" rows="2" required></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success">Submit Request</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Auto-calculate loan payment - logic maintained for modal functionality
const loanAmount = document.getElementById('loanAmount');
const paymentTerms = document.getElementById('paymentTerms');
const paymentAmount = document.getElementById('paymentAmount');

function calculatePayment() {
  const loan = parseFloat(loanAmount.value) || 0;
  const terms = parseInt(paymentTerms.value) || 0;
  // Calculation: Loan Amount / (Terms in Months * 2 cutoffs/month)
  paymentAmount.value = loan > 0 && terms > 0 ? (loan / (terms * 2)).toFixed(2) : '';
}
loanAmount.addEventListener('input', calculatePayment);
paymentTerms.addEventListener('input', calculatePayment);

// Unified Sidebar Toggle Logic 
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const isMobile = window.innerWidth < 1024;

  if (isMobile) {
    // Mobile: slide in/out
    sidebar.classList.toggle('active');
  } else {
    // Desktop: collapse/expand
    sidebar.classList.toggle('collapsed');

    const toggleIcon = document.getElementById('toggleIcon');
    if (sidebar.classList.contains('collapsed')) {
      // Toggle to a "show" icon (pointing right)
      toggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>';
    } else {
      // Toggle to a "hide" icon (pointing left)
      toggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>';
    }
  }
}
</script>
</body>

</html>
