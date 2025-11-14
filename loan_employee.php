<?php
session_start();
require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

// Restrict access to employees only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'employee') {
    header("Location: login.php");
    exit();
}

// Prevent cached pages after logout
header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

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

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

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

$showSuccessModal = false;
if ($httpCode >= 200 && $httpCode < 300) {
    $showSuccessModal = true;
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
    if (($loan['status'] ?? 'pending') === 'pending') {
    $loan['balance'] = 0; // Pending loans should show 0 balance
} else {
    $loan['balance'] = max(0, ((float)$loan['loan_amount']) - $totalPaid);
}

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
/* Full background gradient for consistency with dashboard */
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
}

/* Desktop Sidebar Collapse Behavior */
@media (min-width: 1024px) {
  #sidebar {
    position: fixed;
    top: 0;
    left: 0;
    height: 100%;
    width: 18rem;
    transition: width 0.3s ease-in-out;
  }

  #sidebar.collapsed {
    width: 4rem !important; 
    transition: width 0.3s ease-in-out;
  }

  /* Hide texts when collapsed */
  #sidebar.collapsed .nav-text,
  #sidebar.collapsed #sidebarTitle {
    display: none !important;
  }

  /* Center links and ensure square buttons */
  #sidebar.collapsed nav a {
    padding: 0.85rem !important;
    width: 100% !important;
    justify-content: center !important;
  }

  /* Center icon */
  #sidebar.collapsed nav a i {
    margin: 0 auto;
  }

  /* Shift main content area when sidebar expanded */
  #main-content {
    margin-left: 18rem;
    transition: margin-left 0.3s ease-in-out;
  }

  /* When sidebar collapsed, shift content closer */
  #sidebar.collapsed ~ #main-content {
    margin-left: 4rem;
  }
}


</style>
</head>
<body class="bg-gradient-to-br from-gray-200 via-gray-300 to-gray-400 text-gray-900 font-sans">
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
    <a href="index.php" class="w-full block text-left px-4 py-3 rounded-lg text-gray-300 hover:bg-gray-800 flex items-center space-x-3">
      <i class="bi bi-cash-coin"></i>
      <span class="nav-text">My Payslips</span>
    </a>
    <a href="loan_employee.php" class="w-full block text-left px-4 py-3 rounded-lg bg-white text-black flex items-center space-x-3">
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
<div class="dropdown ms-auto">
  <button
    class="dropdown-toggle d-flex align-items-center border-0 bg-transparent text-white fw-semibold"
    type="button"
    id="accountDropdown"
    data-bs-toggle="dropdown"
    aria-expanded="false"
    style="box-shadow: none;">
    <i class="bi bi-person-circle me-2"></i>
    <span class="text-sm font-small">
      <?= htmlspecialchars($_SESSION['complete_name'] ?? 'Account') ?>
    </span>
  </button>
  <ul class="dropdown-menu dropdown-menu-end mt-2 shadow" aria-labelledby="accountDropdown">
    <li><a class="dropdown-item text-danger" href="logout.php"> <i class="bi bi-box-arrow-right me-2"></i>Logout</a>
    </li>
  </ul>
</div>
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
                        <th>Release Date</th>
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

                              <!-- Release Date + Notes -->
                              <td title="<?= htmlspecialchars($loan['release_notes'] ?? '') ?>">
                                <?= !empty($loan['release_date']) ? date('M d, Y', strtotime($loan['release_date'])) : '—' ?>
                              </td>
                              <!-- Existing Status Column -->
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

<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-black text-white">
        <h5 class="modal-title" id="successModalLabel">
          <i class="bi bi-check-circle-fill me-2 text-white"></i> Loan Request Submitted
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center py-4">
        <p class="fs-5 mb-0">Your loan request has been successfully submitted!<br>
        Please wait for HR approval.</p>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-dark px-4" data-bs-dismiss="modal"
          onclick="window.location.href='loan_employee.php'">
          OK
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($showSuccessModal) && $showSuccessModal === true): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var successModal = new bootstrap.Modal(document.getElementById('successModal'));
  successModal.show();
});
</script>
<?php endif; ?>
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
document.addEventListener("DOMContentLoaded", () => {
  const toggle = document.getElementById("accountToggle");
  const menu = document.getElementById("accountMenu");

  toggle.addEventListener("click", (e) => {
    e.stopPropagation(); // prevents immediate close
    menu.classList.toggle("hidden");
  });

  document.addEventListener("click", (e) => {
    if (!toggle.contains(e.target) && !menu.contains(e.target)) {
      menu.classList.add("hidden");
    }
  });
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
