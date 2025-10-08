<?php
session_start();
require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

// 🚨 Protect this page
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: admindashboard.php");
    exit();
}

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];
$table      = "payslip_content";

$valid_columns = [
    "payroll_date","cutoff_date","employee_id","name","position","subsidiary","salary_type",
    "late_minutes","days_absent","no_of_hours","basic_rate","basic_pay","ot_hours","ot_rate","ot_pay",
    "rdot_hours","rdot_rate","rdot_pay","nd_hours","nd_rate","night_dif_pay","leave_w_pay",
    "special_hol_hours","special_hol_rate","special_holiday_pay","reg_hol_hours","reg_hol_rate","regular_holiday_pay",
    "special_hol_ot_hours","special_hol_ot_rate","special_holiday_ot_pay","reg_hol_ot_hours",
    "reg_hol_ot_rate","regular_holiday_ot_pay","allowance","sign_in_bonus","other_adjustment",
    "total_compensation","less_late","less_absent","less_sss","less_phic","less_hdmf","less_whtax",
    "less_sss_loan","less_sss_sloan","less_pagibig_loan","less_comp_cash_advance","less_company_loan",
    "less_product_equip_loan","less_uniform","less_accountability","salary_overpaid_deduction",
    "total_deduction","net_pay"
];

function clean_utf8($value) {
    return is_string($value) ? mb_convert_encoding($value, 'UTF-8', 'UTF-8') : $value;
}

$uploadSuccess = false;

// ✅ Fixed: Add Employee (no password) to employee_credentials
if (isset($_POST['action']) && $_POST['action'] === 'add_employee') {
    $employeeData = [
        "employee_id" => $_POST['employee_id'] ?? null,
        "complete_name" => $_POST['complete_name'] ?? null,
        "position" => $_POST['position'] ?? null,
        "email" => $_POST['email'] ?? null,
        "subsidiary" => $_POST['subsidiary'] ?? null
    ];

    // Prepare JSON payload for Supabase
    $payload = json_encode([$employeeData], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$projectUrl/rest/v1/employees_credentials");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: $apiKey",
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json",
        "Prefer: return=representation"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 201) {
        header("Location: admindashboard.php?added=success");
        exit();
    } else {
        echo "<script>alert('Employee Already Exists');</script>";
    }
}
// ✅ NEW CODE END

// 📤 Existing CSV upload handler (untouched)
if (isset($_POST['submit'])) {
    if (is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $csv_file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $headers = fgetcsv($csv_file);
        $headers = array_map(function($h) {
            $h = strtolower(trim($h));
            $h = preg_replace('/\s+/', '_', $h);
            $h = preg_replace('/[^a-z0-9_]/', '', $h);
            return $h;
        }, $headers);

        $header_map = [
            "netpay" => "net_pay",
            "net_pay_" => "net_pay",
            "salaryoverpaiddeduction" => "salary_overpaid_deduction",
            "salary_overpaid" => "salary_overpaid_deduction",
            "salary_overpaid_deduction" => "salary_overpaid_deduction",
            "payrolldate" => "payroll_date",
            "cutoffdate" => "cutoff_date"
        ];

        foreach ($headers as &$h) {
            if (isset($header_map[$h])) {
                $h = $header_map[$h];
            }
        }
        unset($h);

        $headers = array_values(array_intersect($headers, $valid_columns));
        $rows = [];

        while (($data = fgetcsv($csv_file)) !== FALSE) {
            $data = array_slice($data, 0, count($headers));
            if (count($data) == count($headers)) {
                $row = array_combine($headers, $data);
                foreach ($row as $key => $value) {
                    if ($value === "" || $value === null) {
                        $row[$key] = null;
                    } elseif ($key === "payroll_date") {
                        $ts = strtotime($value);
                        $row[$key] = $ts ? date("Y-m-d", $ts) : null;
                    } else {
                        $row[$key] = clean_utf8($value);
                    }
                }
                $rows[] = $row;
            }
        }
        fclose($csv_file);

        if (!empty($rows)) {
            $deduped = [];
            foreach ($rows as $row) {
                $deduped[$row['employee_id'] . '_' . $row['payroll_date']] = $row;
            }
            $rows = array_values($deduped);
            $payload = json_encode($rows, JSON_UNESCAPED_UNICODE);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "$projectUrl/rest/v1/$table");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "apikey: $apiKey",
                "Authorization: Bearer $apiKey",
                "Content-Type: application/json",
                "Prefer: resolution=merge-duplicates"
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpcode == 201) {
                $uploadSuccess = true;
            }
        }
    }
}

// 📥 Existing fetch employees logic
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$projectUrl/rest/v1/$table?select=employee_id,name,position,net_pay,payroll_date");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $apiKey",
    "Authorization: Bearer $apiKey",
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
$employees = json_decode($response, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" />
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css" />
  <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
</head>

<body class="bg-gray-100 font-sans">
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
          <button onclick="showSection('upload')" class="w-full text-left px-4 py-3 rounded-lg bg-white text-black hover:bg-gray-200 flex items-center space-x-3">
              <i class="bi bi-people"></i>
              <span class="nav-text">Payslip Management</span>
          </button>
      </nav>

      <div class="p-4 border-t border-gray-700">
          <a href="logout.php" class="w-full block px-4 py-3 rounded-lg bg-gray-800 hover:bg-gray-700 flex items-center space-x-3">
              <i class="bi bi-box-arrow-right"></i>
              <span class="nav-text">Log Out</span>
          </a>
      </div>
  </div>

  <!-- Main -->
  <div class="flex-1 flex flex-col">
    <header class="bg-white shadow-sm border-b px-6 py-4 flex justify-between items-center">
      <span class="text-sm text-gray-600">Welcome, <?= htmlspecialchars($_SESSION['complete_name']) ?></span>
    </header>

    <main class="flex-1 p-6 overflow-y-auto">
      <section id="uploadSection" class="section">
        <div class="max-w-5xl mx-auto bg-white rounded-lg shadow p-6">
<div class="flex justify-end mb-6">
  <button onclick="openUploadModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2">
    <span>Upload Payslip</span>
  </button>
</div>

          <!-- ✅ NEW Add Employee Button -->
          <div class="flex justify-end mb-4">
            <button onclick="openAddModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center space-x-2">
            <span>Add Employee</span>
            </button>
          </div>

          <!-- Payroll Date Filter -->
          <div class="flex items-center space-x-2 mb-4">
            <label for="payrollFilter" class="text-gray-700 font-medium">Filter by Payroll Date:</label>
            <input type="date" id="payrollFilter" class="border border-gray-300 rounded-md p-2" />
            <button id="clearFilter" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-2 rounded-md">Clear</button>
          </div>

          <table id="employeeTable" class="display min-w-full border border-gray-200 mt-6">
            <thead class="bg-black text-white">
              <tr>
                <th>Payroll Date</th>
                <th>Employee ID</th>
                <th>Employee Name</th>
                <th>Position</th>
                <th>Net Pay</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($employees)): ?>
                <?php foreach ($employees as $emp): ?>
                  <tr>
                    <td><?= htmlspecialchars($emp['payroll_date'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($emp['employee_id'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($emp['name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($emp['position'] ?? '-') ?></td>
                    <td>₱<?= htmlspecialchars($emp['net_pay'] ?? '0.00') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  <?php if ($uploadSuccess): ?>
<div id="successModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg p-6 max-w-sm text-center">
    <i class="bi bi-check-circle text-green-600 text-4xl mb-3"></i>
    <h3 class="text-lg font-semibold mb-2">Upload Successful</h3>
    <p class="text-gray-600 mb-4">Payslip data has been successfully uploaded.</p>
    <button onclick="closeSuccessModal()" class="bg-black text-white px-4 py-2 rounded-lg hover:bg-gray-800">OK</button>
  </div>
</div>
<?php endif; ?>

<!-- ✅ Upload CSV Modal -->
<div id="uploadModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6">
    <h3 class="text-lg font-semibold mb-4 text-gray-800">Upload Payslip</h3>
    <form method="post" enctype="multipart/form-data" id="csvUploadModalForm" onsubmit="showUploadSpinner()" class="flex flex-col gap-4">
      <input type="file" name="csv_file" accept=".csv" required class="border border-gray-300 rounded-md p-2" />
      <div class="flex justify-end gap-3">
        <button type="button" onclick="closeUploadModal()" class="bg-gray-300 hover:bg-gray-400 text-black px-4 py-2 rounded-lg">Cancel</button>
        <button type="submit" name="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2">
          <span>Upload</span>
        </button>
      </div>
    </form>
  </div>
</div>
  </div>
</div>
<!-- Add Employee Success Modal -->
<?php if (isset($_GET['added']) && $_GET['added'] === 'success'): ?>
<div id="employeeAddedModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg p-6 max-w-sm text-center">
    <i class="bi bi-check-circle text-green-600 text-4xl mb-3"></i>
    <h3 class="text-lg font-semibold mb-2">Employee Added</h3>
    <p class="text-gray-600 mb-4">The new employee has been added successfully.</p>
    <button onclick="closeEmployeeModal()" class="bg-black text-white px-4 py-2 rounded-lg hover:bg-gray-800">OK</button>
  </div>
</div>
<?php endif; ?>
<!-- Add Employee Modal -->
<div id="employeeModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-3xl p-6 max-h-[90vh] overflow-y-auto">
    <h3 class="text-lg font-semibold mb-4">Add Employee</h3>
    <form method="post" id="employeeForm" class="grid grid-cols-2 gap-4">
      <input type="hidden" name="action" value="add_employee">
      <?php
      foreach (["employee_id","complete_name","position","email","subsidiary"] as $field) {
        // Custom label formatting for Employee ID
        $label = ucwords(str_replace('_', ' ', $field));
        if ($field === "employee_id") {
          $label = "Employee ID";
        }
        echo "<div>
                <label class='block text-sm font-medium text-gray-700 mb-1'>{$label}</label>
                <input type='text' name='{$field}' class='border border-gray-300 rounded-md w-full p-2'>
              </div>";
      }
      ?>
      <div class="col-span-2 flex justify-end space-x-3 mt-4">
        <button type="button" onclick="closeModal()" class="bg-gray-300 px-4 py-2 rounded-lg hover:bg-gray-400">Cancel</button>
        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">Save</button>
      </div>
    </form>
  </div>
</div>
<script>

  function openUploadModal() {
  document.getElementById('uploadModal').classList.remove('hidden');
}

function closeUploadModal() {
  document.getElementById('uploadModal').classList.add('hidden');
}

  function closeSuccessModal() {
  const modal = document.getElementById('successModal');
  if (modal) modal.classList.add('hidden');
}

  function closeEmployeeModal() {
  const modal = document.getElementById('employeeAddedModal');
  if (modal) modal.classList.add('hidden');
  // remove the query string so it doesn’t reopen on refresh
  const url = new URL(window.location);
  url.searchParams.delete('added');
  window.history.replaceState({}, document.title, url);
}

  function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const sidebarTitle = document.getElementById('sidebarTitle');
  const toggleIcon = document.getElementById('toggleIcon');
  const navTexts = document.querySelectorAll('.nav-text');
  const navButtons = document.querySelectorAll('nav button, nav a');

  if (sidebar.classList.contains('w-64')) {
    // Collapse
    sidebar.classList.remove('w-64');
    sidebar.classList.add('w-16');
    sidebarTitle.style.display = 'none';
    navTexts.forEach(t => t.style.display = 'none');
    navButtons.forEach(b => {
      b.classList.add('justify-center');
      b.classList.remove('space-x-3');
    });
    toggleIcon.innerHTML =
      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>';
  } else {
    // Expand
    sidebar.classList.remove('w-16');
    sidebar.classList.add('w-64');
    sidebarTitle.style.display = 'block';
    navTexts.forEach(t => t.style.display = 'block');
    navButtons.forEach(b => {
      b.classList.remove('justify-center');
      b.classList.add('space-x-3');
    });
    toggleIcon.innerHTML =
      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>';
  }
}
  function showUploadSpinner() {
  const spinner = document.getElementById('uploadSpinner');
  if (spinner) spinner.classList.remove('hidden');
}

function hideUploadSpinner() {
  const spinner = document.getElementById('uploadSpinner');
  if (spinner) spinner.classList.add('hidden');
}

function openAddModal() {
  document.getElementById('employeeForm').reset();
  document.getElementById('employeeModal').classList.remove('hidden');
}

function closeModal() {
  // Close Add Employee Modal (if open)
  const employeeModal = document.getElementById('employeeModal');
  if (employeeModal && !employeeModal.classList.contains('hidden')) {
    employeeModal.classList.add('hidden');
  }

  // Close Upload Success Modal (if open)
  const successModal = document.getElementById('successModal');
  if (successModal && !successModal.classList.contains('hidden')) {
    successModal.classList.add('hidden');
  }
}

$(document).ready(function(){
  var table=$('#employeeTable').DataTable({pageLength:10,lengthMenu:[5,10,25,50],order:[[0,'asc']],language:{search:"_INPUT_",searchPlaceholder:"Search employee"}});
  $('#payrollFilter').on('change',function(){var d=this.value.trim();if(d){table.column(0).search(d,true,false).draw();}else{table.column(0).search('').draw();}});
  $('#clearFilter').on('click',function(){$('#payrollFilter').val('');table.column(0).search('').draw();});
});
</script>
<!-- Uploading Spinner Overlay -->
<div id="uploadSpinner" class="hidden fixed inset-0 bg-gray-900 bg-opacity-60 flex items-center justify-center z-[9999]">
  <div class="flex flex-col items-center text-center text-white">
    <svg class="animate-spin h-10 w-10 text-white mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
    </svg>
    <p class="text-lg font-semibold">Uploading CSV...</p>
  </div>
</div>
</body>
</html>
