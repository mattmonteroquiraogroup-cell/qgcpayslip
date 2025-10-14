<?php
session_start();
require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

// Protect this page
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: admindashboard.php");
    exit();
}

// Load .env for Supabase
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];
$table      = "loans";

// Log activity
function logActivity($action, $description) {
    global $projectUrl, $apiKey;
    $admin_id = $_SESSION['employee_id'] ?? 'ADMIN';
    $admin_name = $_SESSION['complete_name'] ?? 'Administrator';
    $payload = json_encode([[
        'admin_id' => $admin_id,
        'admin_name' => $admin_name,
        'action' => $action,
        'description' => $description
    ]]);
    $ch = curl_init("$projectUrl/rest/v1/activity_logs");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json",
            "Prefer: return=minimal"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// --- Fetch loans ---
function getLoans() {
    global $projectUrl, $apiKey, $table;
    $ch = curl_init("$projectUrl/rest/v1/$table?select=*");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        echo "<pre> Error fetching loans. HTTP $httpCode<br>Response:<br>$response</pre>";
    }
    return json_decode($response, true);
}

// --- Update loan ---
if (isset($_POST['action']) && $_POST['action'] === 'update_loan') {
    $loan_id       = $_POST['loan_id'];
    $employee_id   = $_POST['employee_id'];
    $loan_amount   = $_POST['loan_amount'];
    $payment_terms = $_POST['payment_terms'];
    $payment_amount= $_POST['payment_amount'] ?: 0;
    $start_date    = $_POST['start_date'];
    $end_date      = $_POST['end_date'];
    $status        = $_POST['status'] ?: 'pending';
    $loan_type     = $_POST['loan_type'];
    $purpose       = $_POST['purpose'];

$updateData = [
    "loan_amount"    => (float)$loan_amount,
    "payment_terms"  => $payment_terms,
    // Keep per-cutoff payment value — don’t zero it out
    "payment_amount" => (float)$payment_amount,
    "start_date"     => $start_date ?: null,
    "end_date"       => $end_date ?: null,
    "status"         => $status,
    "loan_type"      => $loan_type,
    "purpose"        => $purpose
];

// If marking as active, auto-fill date_approved
if ($status === 'active' && empty($loan['date_approved'])) {
    $updateData["date_approved"] = date("m-d-Y");
} else if ($status === 'pending') {
    $updateData["date_approved"] = null;
}


    $payload = json_encode($updateData);

    $ch = curl_init("$projectUrl/rest/v1/$table?id=eq.$loan_id");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => "PATCH",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json",
            "Prefer: return=representation"
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200 || $httpCode == 204) {
        logActivity("Update Loan", "Updated loan for employee $employee_id (Status: $status)");
        header("Location: admin_loan.php?updated=success");
        exit();
    } else {
        echo "<script>alert('Failed to update loan. Please try again.');</script>";
    }
}

// --- Add Payment ---
if (isset($_POST['action']) && $_POST['action'] === 'add_payment') {
    $loan_id        = $_POST['loan_id']; // UUID string from form
    $employee_id    = $_POST['employee_id'];
    $payment_amount = (float)$_POST['payment_amount'];
    $payment_date   = $_POST['payment_date'];
    $notes          = $_POST['notes'] ?? '';

    // Insert payment record (each one saved separately)
    $paymentData = [[
        'loan_id' => $loan_id,
        'employee_id' => $employee_id,
        'payment_amount' => $payment_amount,
        'payment_date' => $payment_date,
        'notes' => $notes
    ]];
    $payload = json_encode($paymentData);

    $ch = curl_init("$projectUrl/rest/v1/loan_payments");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json",
            "Prefer: return=representation"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 201 && $httpCode !== 200) {
        echo "<pre>Failed to insert payment (HTTP $httpCode): $response</pre>";
        exit();
    }

    // Compute total paid for this loan
    $ch = curl_init("$projectUrl/rest/v1/loan_payments?loan_id=eq.$loan_id&select=payment_amount");
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
    $totalPaid = array_sum(array_column($payments, 'payment_amount')); // Correctly sums the paid amounts

    // Fetch loan details
    $ch = curl_init("$projectUrl/rest/v1/loans?id=eq.$loan_id&select=id,loan_amount,balance,status");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ]
    ]);
    $loanResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "<pre> Failed to fetch loan (HTTP $httpCode): $loanResponse</pre>";
        exit();
    }

    $loanData = json_decode($loanResponse, true);
    if (empty($loanData)) {
        echo "<pre> Loan not found for ID $loan_id</pre>";
        exit();
    }
    $loan = $loanData[0];

    // Compute new balance & status
    $new_balance = max(0, ((float)$loan['loan_amount']) - $totalPaid);
    $new_status = $new_balance <= 0 ? 'paid' : 'active';

    // Update the loan record with new balance and status
    $updateData = json_encode(['balance' => $new_balance, 'status' => $new_status]);
    $ch = curl_init("$projectUrl/rest/v1/loans?id=eq.$loan_id");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => "PATCH",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json",
            "Prefer: return=representation"
        ],
        CURLOPT_POSTFIELDS => $updateData
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 && $httpCode !== 204) {
        echo "<pre> Failed to update balance (HTTP $httpCode): $response</pre>";
        exit();
    }

    logActivity("Payment Recorded", "₱$payment_amount recorded for employee $employee_id (Loan ID: $loan_id)");
    header("Location: admin_loan.php?payment=success");
    exit();
}


// --- Fetch all loans for display ---
$loans = getLoans();
$subsidiaryFilter = $_GET['subsidiary'] ?? '';
if ($subsidiaryFilter !== '') {
    $loans = array_filter($loans, fn($l) => $l['subsidiary'] === $subsidiaryFilter);
}

$activeLoansData = array_filter($loans, fn($l) => $l['status'] === 'active');
$totalLoanAmount = array_sum(array_column($activeLoansData, 'loan_amount'));

// Fetch actual total paid from loan_payments
$totalAmountPaid = 0;
foreach ($activeLoansData as $loan) {
    $loanId = $loan['id'];
    $ch = curl_init("$projectUrl/rest/v1/loan_payments?loan_id=eq.$loanId&select=payment_amount");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ]
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $payments = json_decode($res, true);
    $loanPaid = array_sum(array_column($payments, 'payment_amount'));
    $totalAmountPaid += $loanPaid;
}

$activeLoans     = count($activeLoansData);

$current_page = basename($_SERVER['PHP_SELF']);
function navButtonClass($page, $current_page) {
    return $page === $current_page ? "bg-gray-800 text-white font-semibold" : "bg-transparent text-white";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Track Loans</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" />
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>

<style>
#loanTable td { position: relative; z-index: 1; }
#loanTable button { position: relative; z-index: 5; margin-right: 4px; }
#loanTable tbody tr:hover { background-color: #f9fafb; z-index: 0; }
@media (max-width: 640px) {
  #loanTable td button { display: block; width: 100%; margin-bottom: 4px; }
}

@media (max-width: 640px) {
  #loanTable td button {
    padding: 0.5rem;
    margin: 0;
  }
  #loanTable td {
    text-align: center;
  }
}
</style>
</head>
<body class="bg-gray-100 font-sans">
<div class="flex h-screen overflow-hidden">
<!-- Sidebar -->
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
      class="w-full text-left px-4 py-3 rounded-lg flex items-center space-x-3 <?= navButtonClass('admindashboard.php', $current_page) ?>">
      <i class="bi bi-people"></i><span class="nav-text">Payslip Management</span>
    </button>
    <button onclick="window.location.href='admin_loan.php'"
      class="w-full text-left px-4 py-3 rounded-lg flex items-center space-x-3 <?= navButtonClass('admin_loan.php', $current_page) ?>">
      <i class="bi bi-cash-stack"></i><span class="nav-text">Track Loans</span>
    </button>
    <button onclick="window.location.href='activity_logs.php'"
      class="w-full text-left px-4 py-3 rounded-lg flex items-center space-x-3 <?= navButtonClass('activity_logs.php', $current_page) ?>">
      <i class="bi bi-clock-history"></i><span class="nav-text">Recent Activities</span>
    </button>
  </nav>
  <div class="p-4 border-t border-gray-700">
    <a href="logout.php" class="w-full block px-4 py-3 rounded-lg bg-gray-800 hover:bg-gray-700 flex items-center space-x-3">
      <i class="bi bi-box-arrow-right"></i><span class="nav-text">Log Out</span>
    </a>
  </div>
</div>
<!-- Main -->
<main id="mainContent" class="flex-1 p-6 overflow-y-auto transition-all duration-300">
  <div class="max-w-7xl mx-auto bg-white rounded-lg shadow p-6">
    <h2 class="text-2xl font-semibold mb-6">Loan Overview</h2>

    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mb-6">
      <div class="border p-4 rounded-lg"><p class="text-sm">Total Loan Amount</p><p class="text-2xl font-bold">₱<?= number_format($totalLoanAmount, 2) ?></p></div>
      <div class="border p-4 rounded-lg"><p class="text-sm">Total Amount Paid</p><p class="text-2xl font-bold">₱<?= number_format($totalAmountPaid, 2) ?></p></div>
      <div class="border p-4 rounded-lg"><p class="text-sm">Balance</p><p class="text-2xl font-bold">₱<?= number_format($totalLoanAmount - $totalAmountPaid, 2) ?></p></div>
      <div class="border p-4 rounded-lg"><p class="text-sm">Active Loans</p><p class="text-2xl font-bold"><?= $activeLoans ?></p></div>
    </div>

    <div class="overflow-x-auto">
      <table id="loanTable" class="display w-full text-center border">
        <thead class="bg-black text-white">
          <tr>
            <th>Name</th><th>Employee ID</th><th>Subsidiary</th><th>Loan Type</th>
            <th>Loan Amount</th><th>Payment Terms</th><th>Payment per Cutoff</th><th>Balance</th>
            <th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($loans)): foreach ($loans as $loan): ?>
          <tr>
            <td><?= htmlspecialchars($loan['name']) ?></td>
            <td><?= htmlspecialchars($loan['employee_id']) ?></td>
            <td><?= htmlspecialchars($loan['subsidiary']) ?></td>
            <td><?= htmlspecialchars($loan['loan_type']) ?></td>
            <td>₱<?= number_format($loan['loan_amount'], 2) ?></td>
            <td><?= htmlspecialchars($loan['payment_terms']) ?></td>
            <td>₱<?= number_format($loan['payment_amount'], 2) ?></td>
            <td>₱<?= number_format($loan['balance'] ?? 0, 2) ?></td>
            <td>
              <span class="px-3 py-1 rounded-full text-white 
                <?= $loan['status']==='active'?'bg-blue-600':($loan['status']==='paid'?'bg-green-600':'bg-yellow-500') ?>">
                <?= ucfirst($loan['status']) ?>
              </span>
            </td>
                  <td class="flex justify-center gap-2">
          <button title="Edit Loan" 
            class="edit-btn bg-red-500 hover:bg-red-600 text-white p-2 rounded transition-all duration-200"
            data-loan='<?= json_encode($loan) ?>'>
            <i class="bi bi-pencil-square"></i>
          </button>
          <button title="Add Payment" 
            class="payment-btn bg-green-600 hover:bg-green-700 text-white p-2 rounded transition-all duration-200"
            data-loan='<?= json_encode($loan) ?>'>
            <i class="bi bi-cash-stack"></i>
          </button>
          <button title="View History" 
            class="history-btn bg-gray-700 hover:bg-gray-800 text-white p-2 rounded transition-all duration-200"
            data-loan-id="<?= $loan['id'] ?>">
            <i class="bi bi-clock-history"></i>
          </button>
        </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="10" class="text-gray-500 py-4">No records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 hidden bg-black bg-opacity-40 z-50 flex items-center justify-center">
  <div class="bg-white p-6 rounded-lg shadow-lg max-w-lg w-full mx-4">
    <h3 class="text-xl font-bold mb-4">Update Loan</h3>
    <form method="POST">
      <input type="hidden" name="action" value="update_loan">
      <input type="hidden" name="loan_id" id="edit_loan_id">
      <input type="hidden" name="employee_id" id="edit_employee_id">
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-sm">Loan Type</label><input type="text" id="edit_loan_type" name="loan_type" class="w-full border rounded p-2"></div>
        <div><label class="text-sm">Loan Amount</label><input type="number" step="0.01" id="edit_loan_amount" name="loan_amount" class="w-full border rounded p-2"></div>
        <div><label class="text-sm">Payment Terms</label><input type="text" id="edit_payment_terms" name="payment_terms" class="w-full border rounded p-2"></div>
        <div><label class="text-sm">Payment per Cutoff</label><input type="number" step="0.01" id="edit_payment_amount" name="payment_amount" class="w-full border rounded p-2"></div>
        <div><label class="text-sm">Start Date</label><input type="date" id="edit_start_date" name="start_date" class="w-full border rounded p-2"></div>
        <div><label class="text-sm">End Date</label><input type="date" id="edit_end_date" name="end_date" class="w-full border rounded p-2"></div>
        <div class="col-span-2"><label class="text-sm">Purpose</label><textarea id="edit_purpose" name="purpose" class="w-full border rounded p-2"></textarea></div>
        <div class="col-span-2"><label class="text-sm">Status</label>
          <select id="edit_status" name="status" class="w-full border rounded p-2">
            <option value="pending">Pending</option>
            <option value="active">Active</option>
            <option value="paid">Paid</option>
          </select>
        </div>
      </div>
      <div class="flex justify-end mt-4 space-x-2">
        <button type="button" id="cancelEdit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded">Cancel</button>
        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="fixed inset-0 hidden bg-black bg-opacity-40 z-50 flex items-center justify-center">
  <div class="bg-white p-6 rounded-lg shadow-lg max-w-lg w-full mx-4">
    <h3 class="text-xl font-bold mb-4">Record Payment</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add_payment">
      <input type="hidden" name="loan_id" id="payment_loan_id">
      <input type="hidden" name="employee_id" id="payment_employee_id">
      <div class="mb-3"><label class="text-sm">Payment Amount</label><input type="number" step="0.01" name="payment_amount" class="w-full border rounded p-2" required></div>
      <div class="mb-3"><label class="text-sm">Payment Date</label><input type="date" name="payment_date" class="w-full border rounded p-2" required></div>
      <div class="mb-3"><label class="text-sm">Notes</label><textarea name="notes" class="w-full border rounded p-2"></textarea></div>
      <div class="flex justify-end space-x-2">
        <button type="button" id="cancelPayment" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded">Cancel</button>
        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- History Modal -->
<div id="historyModal" class="fixed inset-0 hidden bg-black bg-opacity-40 z-50 flex items-center justify-center">
  <div class="bg-white p-6 rounded-lg shadow-lg max-w-lg w-full mx-4">
    <h3 class="text-xl font-bold mb-4">Payment History</h3>
    <div class="overflow-x-auto">
      <table class="w-full border">
        <thead class="bg-gray-800 text-white">
          <tr><th class="p-2">Date</th><th class="p-2">Amount</th><th class="p-2">Notes</th></tr>
        </thead>
        <tbody id="historyTableBody" class="text-center"></tbody>
      </table>
    </div>
    <div class="flex justify-end mt-4">
      <button id="closeHistory" class="bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded">Close</button>
    </div>
  </div>
</div>

<script>
$(document).ready(function() {
  $('#loanTable').DataTable({ pageLength: 10, order: [[0,'asc']], language: { search: "_INPUT_", searchPlaceholder: "Search loans..." } });
});

// Modal handling
$(document).on('click', '.edit-btn', function() {
  const loan = JSON.parse($(this).attr('data-loan'));
  $('#editModal').removeClass('hidden').addClass('flex');
  $('#edit_loan_id').val(loan.id);
  $('#edit_employee_id').val(loan.employee_id);
  $('#edit_loan_type').val(loan.loan_type || '');
  $('#edit_loan_amount').val(loan.loan_amount || '');
  $('#edit_payment_terms').val(loan.payment_terms || '');
  $('#edit_payment_amount').val(loan.payment_amount || '');
  $('#edit_start_date').val(loan.start_date || '');
  $('#edit_end_date').val(loan.end_date || '');
  $('#edit_purpose').val(loan.purpose || '');
  $('#edit_status').val(loan.status || 'pending');
});
$('#cancelEdit').on('click', () => $('#editModal').addClass('hidden').removeClass('flex'));

$(document).on('click', '.payment-btn', function() {
  const loan = JSON.parse($(this).attr('data-loan'));
  $('#paymentModal').removeClass('hidden').addClass('flex');
  $('#payment_loan_id').val(loan.id);
  $('#payment_employee_id').val(loan.employee_id);
});
$('#cancelPayment').on('click', () => $('#paymentModal').addClass('hidden').removeClass('flex'));

$(document).on('click', '.history-btn', async function() {
  const loanId = $(this).data('loan-id');
  const apiKey = "<?= $_ENV['SUPABASE_KEY'] ?>";
  const projectUrl = "<?= $_ENV['SUPABASE_URL'] ?>";
  const res = await fetch(`${projectUrl}/rest/v1/loan_payments?loan_id=eq.${loanId}&select=*`, {
    headers: { apikey: apiKey, Authorization: `Bearer ${apiKey}` }
  });
  const data = await res.json();
  let rows = data.length ? data.map(p =>
    `<tr><td>${p.payment_date}</td><td>₱${parseFloat(p.payment_amount).toFixed(2)}</td><td>${p.notes || ''}</td></tr>`
  ).join('') : '<tr><td colspan="3" class="text-center py-2 text-gray-500">No payment history found</td></tr>';
  $('#historyTableBody').html(rows);
  $('#historyModal').removeClass('hidden').addClass('flex');
});
$('#closeHistory').on('click', () => $('#historyModal').addClass('hidden').removeClass('flex'));

function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const sidebarTitle = document.getElementById('sidebarTitle');
  const toggleIcon = document.getElementById('toggleIcon');
  const navTexts = document.querySelectorAll('.nav-text');
  const navButtons = document.querySelectorAll('nav button, nav a');
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
</body>
</html>
