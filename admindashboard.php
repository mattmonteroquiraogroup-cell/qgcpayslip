<?php
session_start();
require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

include 'role_guard.php';

// Protect this page
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}


// Load environment variables
//$dotenv = Dotenv::createImmutable(__DIR__);
//$dotenv->load();

$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];
$table      = "payslip_content";

// Activity Logs function (added)
function logActivity($action, $description) {
    global $projectUrl, $apiKey;

    $admin_id = $_SESSION['employee_id'] ?? 'ADMIN';
    $admin_name = $_SESSION['complete_name'] ?? 'Administrator';

    $logData = [[
        'admin_id' => $admin_id,
        'admin_name' => $admin_name,
        'action' => $action,
        'description' => $description
    ]];

    $payload = json_encode($logData);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$projectUrl/rest/v1/activity_logs");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: $apiKey",
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json",
        "Prefer: return=minimal"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_exec($ch);
    curl_close($ch);
}


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

// Upload Employee CSV
$uploadEmployeeError = null;

if (isset($_POST['upload_employee_csv'])) {
    if (is_uploaded_file($_FILES['employee_csv']['tmp_name'])) {
        $csv_file = fopen($_FILES['employee_csv']['tmp_name'], 'r');
        $headers = fgetcsv($csv_file);

        if (!$headers) {
            $uploadEmployeeError = "Invalid CSV file — no headers found.";
        } else {
            // Normalize headers
            $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

            // Required columns
            $required_columns = ['employee_id', 'complete_name', 'position', 'email', 'subsidiary'];
            $missing = array_diff($required_columns, $headers);

            if (!empty($missing)) {
                $uploadEmployeeError = "Invalid CSV format — missing required columns: " . implode(', ', $missing);
            } else {
                $rows = [];
                $lineNumber = 1;

                while (($data = fgetcsv($csv_file)) !== FALSE) {
                    $lineNumber++;
                    if (count($data) != count($headers)) {
                        $uploadEmployeeError = "CSV format error on line $lineNumber — column count mismatch.";
                        break;
                    }

                    $row = array_combine($headers, $data);
                    if (empty($row['employee_id']) || empty($row['complete_name'])) continue;

                    $rows[] = [
                        "employee_id" => trim($row['employee_id']),
                        "complete_name" => trim($row['complete_name']),
                        "position" => trim($row['position'] ?? ''),
                        "email" => trim($row['email'] ?? ''),
                        "subsidiary" => trim($row['subsidiary'] ?? ''),
                        "role" => $row['role'] ?? 'employee',
                        "status" => 'active'
                    ];
                }
            }
        }

        fclose($csv_file);

        if (!$uploadEmployeeError && empty($rows)) {
            $uploadEmployeeError = "No valid employee data found in CSV.";
        }

        // ✅ Step 1: Check for duplicates in Supabase
        if (!$uploadEmployeeError && !empty($rows)) {
            $employee_ids = array_column($rows, 'employee_id');
            $unique_ids = array_unique($employee_ids);
            $duplicates_in_csv = array_diff_assoc($employee_ids, $unique_ids);

            if (!empty($duplicates_in_csv)) {
                $uploadEmployeeError = "Duplicate employee IDs found in CSV: " . implode(', ', array_unique($duplicates_in_csv));
            } else {
                // Check Supabase for existing IDs
                $id_list = implode('","', $unique_ids);
                $url = "$projectUrl/rest/v1/employees_credentials?employee_id=in.(\"$id_list\")";

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "apikey: $apiKey",
                    "Authorization: Bearer $apiKey",
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                curl_close($ch);

                $existing = json_decode($response, true);
                if (!empty($existing)) {
                    $existing_ids = array_column($existing, 'employee_id');
                    $uploadEmployeeError = "Duplicate employee IDs already exist in database: " . implode(', ', $existing_ids);
                }
            }
        }

        // Upload if no errors
        if (!$uploadEmployeeError) {
            $payload = json_encode($rows, JSON_UNESCAPED_UNICODE);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "$projectUrl/rest/v1/employees_credentials");
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
                $uploadEmployeeSuccess = true;
                logActivity("Upload Employees", "Uploaded employee CSV with " . count($rows) . " records.");
            } else {
                $uploadEmployeeError = "Error uploading employees — please check your CSV data.";
            }
        }
    } else {
        $uploadEmployeeError = "No file uploaded — please select a CSV file.";
    }
}

// Upload Payslip CSV (with validation + warning modal)
$uploadPayslipError = null;

if (isset($_POST['submit'])) {
    if (is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $csv_file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $headers = fgetcsv($csv_file);

        if (!$headers) {
            $uploadPayslipError = "Invalid CSV file — no headers found.";
        } else {
            // Normalize headers
            $headers = array_map(function($h) {
                $h = strtolower(trim($h));
                $h = preg_replace('/\s+/', '_', $h);
                $h = preg_replace('/[^a-z0-9_]/', '', $h);
                return $h;
            }, $headers);

            // Header mapping
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
                if (isset($header_map[$h])) $h = $header_map[$h];
            }
            unset($h);

            // Validate against valid columns
            global $valid_columns;
            $invalid_headers = array_diff($headers, $valid_columns);
            if (!empty($invalid_headers)) {
                $uploadPayslipError = "Invalid CSV format — unknown columns: " . implode(', ', $invalid_headers);
            }
        }

        if (!$uploadPayslipError) {
            $rows = [];
            $lineNumber = 1;

            while (($data = fgetcsv($csv_file)) !== FALSE) {
                $lineNumber++;

                if (count($data) != count($headers)) {
                    $uploadPayslipError = "CSV format error on line $lineNumber — column count does not match header.";
                    break;
                }

                $data = array_slice($data, 0, count($headers));
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
            fclose($csv_file);

            if (!$uploadPayslipError && empty($rows)) {
                $uploadPayslipError = "No valid data found in CSV.";
            }

            // Upload to Supabase only if valid
            if (!$uploadPayslipError && !empty($rows)) {
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
                    logActivity("Upload Payslip", "Uploaded payslip CSV with " . count($rows) . " records.");
                } else {
                    $uploadPayslipError = "Error uploading payslips — please check your CSV data.";
                }
            }
        } else {
            fclose($csv_file);
        }
    } else {
        $uploadPayslipError = "No file uploaded — please select a CSV file.";
    }
}

// Existing fetch employees logic
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

// Fetch Employee Credentials Data
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$projectUrl/rest/v1/employees_credentials?select=employee_id,complete_name,subsidiary,position,email,status");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $apiKey",
    "Authorization: Bearer $apiKey",
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
$employee_credentials = json_decode($response, true);



$current_page = basename($_SERVER['PHP_SELF']);

function navButtonClass($page, $current_page) {
    if ($page === $current_page) {
        // Active button: highlighted background
        return "bg-gray-800 text-white font-semibold";
    } else {
        // Inactive button: plain, no hover
        return "bg-transparent text-white";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Payslip Management</title>
   <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" />
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css" />
  <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
</head>
<body class="bg-gray-100 font-sans">

<?php if (!empty($SHOW_RESTRICT_OVERLAY)): ?>
<div class="rbac-overlay">
  <div class="rbac-card">
    <h2><strong>Access Restricted</strong></h2>
    <p>The Finance role only has access to Loan Management.</p>
    <a class="rbac-button" href="admin_loan.php">Go to Loan Management</a>
  </div>
</div>
<style>
  html, body { filter: grayscale(100%); }
  .rbac-overlay {
    position: fixed; inset: 0; background: rgba(255,255,255,0.9);
    z-index: 9999; display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(3px);
  }
  .rbac-card {
    background: white; border: 2px solid #ccc; border-radius: 12px;
    padding: 2rem; text-align: center; max-width: 400px;
  }
  .rbac-button {
    display: inline-block; margin-top: 1rem; padding: .75rem 1.25rem;
    border-radius: 8px; background: #212529; color: #fff; text-decoration: none;
  }
</style>
<script>
document.addEventListener('click', e => {
  if (!e.target.closest('.rbac-card')) e.preventDefault();
}, true);
</script>
<?php endif; ?>



<div class="flex h-screen">
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
  <?php
  function navButton($href, $icon, $label, $page, $ROLE, $ALLOWED, $current_page) {
      $disabled = ($ROLE === 'finance' && !in_array($page, $ALLOWED));
      $isActive = ($current_page === $page);
      $classes = "w-full text-left px-4 py-3 rounded-lg flex items-center space-x-3 ";
      $classes .= $isActive ? "bg-gray-800 text-white font-semibold " : "bg-transparent text-white ";
      if ($disabled) $classes .= "opacity-50 cursor-not-allowed pointer-events-none bg-gray-700 text-gray-400";

      $onclick = $disabled ? "" : "onclick=\"window.location.href='$href'\"";
      $tooltip = $disabled ? "title='Access restricted for Finance role'" : "";

      echo "<button $onclick $tooltip class='$classes'>
              <i class='bi $icon'></i><span class='nav-text'>$label</span>
            </button>";
  }

  // Define what Finance can access
  $FINANCE_ALLOWED = ['admin_loan.php'];

  // Render buttons
  navButton('admindashboard.php', 'bi-people', 'Payslip Management', 'admindashboard.php', $ROLE, $FINANCE_ALLOWED, $current_page);
  navButton('admin_loan.php', 'bi-cash-stack', 'Track Loans', 'admin_loan.php', $ROLE, $FINANCE_ALLOWED, $current_page);
  navButton('activity_logs.php', 'bi-clock-history', 'Recent Activities', 'activity_logs.php', $ROLE, $FINANCE_ALLOWED, $current_page);
  ?>
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
  <div class="max-w-6xl mx-auto bg-white rounded-lg shadow p-6">

    <!-- ✅ Bootstrap-Like Tabs -->
    <ul class="flex border-b border-gray-200 mb-4" id="dashboardTabs">
      <li class="mr-1">
        <button id="tabPayslip" onclick="showTab('payslip')" 
          class="inline-block bg-blue-600 hover:bg-blue-200 text-white font-semibold py-2 px-4 rounded-t">
          Payslip Management
        </button>
      </li>
      <li class="mr-1">
        <button id="tabEmployees" onclick="showTab('employees')" 
          class="inline-block bg-gray-100 hover:bg-blue-200 text-gray-700 font-semibold py-2 px-4 rounded-t">
          Employee Credentials
        </button>
      </li>
    </ul>

    <!-- Payslip Management Table -->
<div id="payslipTab" class="tab-content">
  <div class="flex justify-between items-center mb-6">
    <!--Payroll Filter -->
    <div class="flex items-center space-x-2">
      <label for="payrollFilter" class="text-gray-700 font-medium">Filter by Payroll Date:</label>
      <input type="date" id="payrollFilter" class="border border-gray-300 rounded-md p-2" />
      <button id="clearFilter" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-2 rounded-md">Clear</button>
    </div>

    <!-- Upload Button -->
    <button onclick="openUploadModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2">
      <span>Upload Payslip</span>
    </button>
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

    <!-- Employee Credentials Table -->
    <div id="employeesTab" class="tab-content hidden">
  <div class="flex justify-end mb-6">
    <button onclick="openEmployeeUploadModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center gap-2">
      <span>Upload Employees</span>
    </button>
  </div>
  <table id="employeeCredentialsTable" class="display min-w-full border border-gray-200 mt-6">
        <thead class="bg-black text-white">
          <tr>
            <th>Employee ID</th>
            <th>Complete Name</th>
            <th>Subsidiary</th>
            <th>Position</th>
            <th>Email</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($employee_credentials)): ?>
            <?php foreach ($employee_credentials as $emp): ?>
              <tr>
                <td><?= htmlspecialchars($emp['employee_id'] ?? '-') ?></td>
                <td><?= htmlspecialchars($emp['complete_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars($emp['subsidiary'] ?? '-') ?></td>
                <td><?= htmlspecialchars($emp['position'] ?? '-') ?></td>
                <td><?= htmlspecialchars($emp['email'] ?? '-') ?></td>
                <td><?= htmlspecialchars($emp['status'] ?? '-') ?></td>
                <td class="text-center">
                <div class="flex justify-center items-center space-x-2">
                  <button onclick='viewEmployeeDetails(<?= json_encode($emp) ?>)' 
                    class="flex items-center gap-1 bg-gray-200 hover:bg-gray-300 text-black px-2 py-1 rounded-md text-sm">
                    <i class="bi bi-eye"></i> View
                  </button>
                  <button onclick='openEditEmployeeModal(<?= json_encode($emp) ?>)' 
                    class="flex items-center gap-1 bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded-md text-sm">
                    <i class="bi bi-pencil-square"></i> Edit
                  </button>
                </div>
              </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
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

<?php if (isset($uploadPayslipError) && $uploadPayslipError): ?>
<div id="payslipUploadErrorModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg p-6 max-w-sm text-center">
    <i class="bi bi-exclamation-triangle text-yellow-500 text-4xl mb-3"></i>
    <h3 class="text-lg font-semibold mb-2 text-gray-800">Invalid Upload</h3>
    <p class="text-gray-600 mb-4"><?= htmlspecialchars($uploadPayslipError) ?></p>
    <button onclick="closePayslipUploadErrorModal()" class="bg-black text-white px-4 py-2 rounded-lg hover:bg-gray-800">OK</button>
  </div>
</div>
<?php endif; ?>

  <!-- NEW Employee Upload Success Modal -->
  <?php if (isset($uploadEmployeeSuccess) && $uploadEmployeeSuccess): ?>
  <div id="employeeUploadSuccessModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 max-w-sm text-center">
      <i class="bi bi-check-circle text-green-600 text-4xl mb-3"></i>
      <h3 class="text-lg font-semibold mb-2">Upload Successful</h3>
      <p class="text-gray-600 mb-4">Employee list has been uploaded successfully.</p>
      <button onclick="closeEmployeeUploadSuccessModal()" class="bg-black text-white px-4 py-2 rounded-lg hover:bg-gray-800">OK</button>
    </div>
  </div>
  <?php endif; ?>

<?php if (isset($uploadEmployeeError) && $uploadEmployeeError): ?>
<div id="employeeUploadErrorModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg p-6 max-w-sm text-center">
    <i class="bi bi-exclamation-triangle text-yellow-500 text-4xl mb-3"></i>
    <h3 class="text-lg font-semibold mb-2 text-gray-800">Invalid Upload</h3>
    <p class="text-gray-600 mb-4"><?= htmlspecialchars($uploadEmployeeError) ?></p>
    <button onclick="closeEmployeeUploadErrorModal()" class="bg-black text-white px-4 py-2 rounded-lg hover:bg-gray-800">
      OK
    </button>
  </div>
</div>
<?php endif; ?>


<!-- Upload CSV Modal -->
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
<!-- Upload Employee CSV Modal -->
<div id="employeeUploadModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6">
    <h3 class="text-lg font-semibold mb-4 text-gray-800">Upload Employee List</h3>
    <form method="post" enctype="multipart/form-data" onsubmit="showUploadSpinner()" class="flex flex-col gap-4">
      <input type="file" name="employee_csv" accept=".csv" required class="border border-gray-300 rounded-md p-2" />
      <div class="flex justify-end gap-3">
        <button type="button" onclick="closeEmployeeUploadModal()" class="bg-gray-300 hover:bg-gray-400 text-black px-4 py-2 rounded-lg">Cancel</button>
        <button type="submit" name="upload_employee_csv" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center gap-2">
          <span>Upload</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!---View Employee Data Modal --->
<div id="viewEmployeeModal" class="hidden fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-6">
    <h3 class="text-lg font-semibold mb-4">View Employee Details</h3>
    <div id="employeeViewContent" class="text-gray-700 space-y-2"></div>
    <div class="flex justify-end mt-4">
      <button onclick="closeViewModal()" class="bg-black text-white px-4 py-2 rounded-lg hover:bg-gray-800">Close</button>
    </div>
  </div>
</div>

<!---Edit Employee Data Modal --->
<div id="editEmployeeModal" class="hidden fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6">
    <h3 class="text-lg font-semibold mb-4">Edit Employee</h3>
    <form id="editEmployeeForm" method="post" class="space-y-4">
      <input type="hidden" id="edit_employee_id" name="employee_id">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Complete Name</label>
        <input type="text" id="edit_complete_name" name="complete_name" class="border border-gray-300 rounded-md w-full p-2">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
        <input type="email" id="edit_email" name="email" class="border border-gray-300 rounded-md w-full p-2">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
        <input type="text" id="edit_position" name="position" class="border border-gray-300 rounded-md w-full p-2">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Subsidiary</label>
        <input type="text" id="edit_subsidiary" name="subsidiary" class="border border-gray-300 rounded-md w-full p-2">
      </div>
      <div>
  <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
  <select id="edit_status" name="status" class="border border-gray-300 rounded-md w-full p-2 bg-white">
    <option value="active">Active</option>
    <option value="inactive">Inactive</option>
  </select>
</div>
      <div class="flex justify-end space-x-3">
        <button type="button" onclick="closeEditModal()" class="bg-gray-300 px-4 py-2 rounded-lg hover:bg-gray-400">Cancel</button>
        <button type="button" onclick="saveEmployeeChanges()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Save</button>
      </div>
    </form>
  </div>
</div>
<script>

  // ========== VIEW ==========
function viewEmployeeDetails(emp) {
  const container = document.getElementById('employeeViewContent');
  container.innerHTML = `
    <p><strong>Employee ID:</strong> ${emp.employee_id}</p>
    <p><strong>Name:</strong> ${emp.complete_name}</p>
    <p><strong>Subsidiary:</strong> ${emp.subsidiary}</p>
    <p><strong>Position:</strong> ${emp.position}</p>
    <p><strong>Email:</strong> ${emp.email}</p>
    <p><strong>Status:</strong> ${emp.status}</p>
  `;
  document.getElementById('viewEmployeeModal').classList.remove('hidden');
}

function closeViewModal() {
  document.getElementById('viewEmployeeModal').classList.add('hidden');
}

// ========== EDIT ==========
function openEditEmployeeModal(emp) {
  document.getElementById('edit_employee_id').value = emp.employee_id;
  document.getElementById('edit_complete_name').value = emp.complete_name;
  document.getElementById('edit_email').value = emp.email;
  document.getElementById('edit_position').value = emp.position;
  document.getElementById('edit_subsidiary').value = emp.subsidiary;
  document.getElementById('edit_status').value = emp.status || 'active';
  document.getElementById('editEmployeeModal').classList.remove('hidden');
}

function closeEditModal() {
  document.getElementById('editEmployeeModal').classList.add('hidden');
}

// ========== SAVE CHANGES ==========
function saveEmployeeChanges() {
  const employee_id = document.getElementById('edit_employee_id').value;
  const data = {
    complete_name: document.getElementById('edit_complete_name').value,
    email: document.getElementById('edit_email').value,
    position: document.getElementById('edit_position').value,
    subsidiary: document.getElementById('edit_subsidiary').value,
    status: document.getElementById('edit_status').value
  };

  fetch(`${"<?= $projectUrl ?>"}/rest/v1/employees_credentials?employee_id=eq.${employee_id}`, {
    method: 'PATCH',
    headers: {
      'apikey': "<?= $apiKey ?>",
      'Authorization': `Bearer <?= $apiKey ?>`,
      'Content-Type': 'application/json',
      'Prefer': 'return=minimal'
    },
    body: JSON.stringify(data)
  })
  .then(res => {
    if (res.ok) {
      document.getElementById('editEmployeeModal').classList.add('hidden');
      document.getElementById('employeeUpdateSuccessModal').classList.remove('hidden');
    } else {
      document.getElementById('employeeUpdateErrorModal').classList.remove('hidden');
    }
  })
  .catch(() => {
    document.getElementById('employeeUpdateErrorModal').classList.remove('hidden');
  });
}

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

function openEmployeeUploadModal() {
  document.getElementById('employeeUploadModal').classList.remove('hidden');
}

function closeEmployeeUploadModal() {
  document.getElementById('employeeUploadModal').classList.add('hidden');
}

function closeEmployeeUploadSuccessModal() {
  const modal = document.getElementById('employeeUploadSuccessModal');
  if (modal) modal.classList.add('hidden');

  // Also hide any background overlays
  const spinner = document.getElementById('uploadSpinner');
  if (spinner) spinner.classList.add('hidden');

  const uploadModal = document.getElementById('employeeUploadModal');
  if (uploadModal) uploadModal.classList.add('hidden');

  // Optional: reload page to refresh table
  window.location.href = window.location.pathname; 
}

function closeEmployeeUploadErrorModal() {
  const modal = document.getElementById('employeeUploadErrorModal');
  if (modal) modal.classList.add('hidden');
  const uploadModal = document.getElementById('employeeUploadModal');
  if (uploadModal) uploadModal.classList.add('hidden');
}

function closePayslipUploadErrorModal() {
  const modal = document.getElementById('payslipUploadErrorModal');
  if (modal) modal.classList.add('hidden');

  const uploadModal = document.getElementById('uploadModal');
  if (uploadModal) uploadModal.classList.add('hidden');

  // Optional refresh
  window.location.href = window.location.pathname;
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
  var table=$('#employeeTable').DataTable({
  pageLength:10,
  lengthMenu:[5,10,25,50],
  order:[[0,'asc']],
  language:{search:"_INPUT_",searchPlaceholder:"Search employees"}});

  $('#payrollFilter').on('change',function(){var d=this.value.trim();if(d){table.column(0).search(d,true,false).draw();}else{table.column(0).search('').draw();}});
  $('#clearFilter').on('click',function(){$('#payrollFilter').val('');table.column(0).search('').draw();});

  $('#employeeCredentialsTable').DataTable({
  pageLength: 10,
  lengthMenu: [5, 10, 25, 50],
  order: [[0, 'asc']],
  language: { search: "_INPUT_", searchPlaceholder: "Search employees" }
});

});


function showTab(tab) {
  const payslipTab = document.getElementById('payslipTab');
  const employeesTab = document.getElementById('employeesTab');
  const tabPayslip = document.getElementById('tabPayslip');
  const tabEmployees = document.getElementById('tabEmployees');

  if (tab === 'employees') {
    payslipTab.classList.add('hidden');
    employeesTab.classList.remove('hidden');

    tabPayslip.classList.remove('bg-blue-600', 'text-white');
    tabPayslip.classList.add('bg-gray-100', 'text-gray-700');
    tabEmployees.classList.remove('bg-gray-100', 'text-gray-700');
    tabEmployees.classList.add('bg-blue-600', 'text-white');
  } else {
    employeesTab.classList.add('hidden');
    payslipTab.classList.remove('hidden');

    tabEmployees.classList.remove('bg-blue-600', 'text-white');
    tabEmployees.classList.add('bg-gray-100', 'text-gray-700');
    tabPayslip.classList.remove('bg-gray-100', 'text-gray-700');
    tabPayslip.classList.add('bg-blue-600', 'text-white');
  }
}

function closeEmployeeUpdateSuccessModal() {
  document.getElementById('employeeUpdateSuccessModal').classList.add('hidden');
  window.location.reload();
}

function closeEmployeeUpdateErrorModal() {
  document.getElementById('employeeUpdateErrorModal').classList.add('hidden');
}
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

<!-- Employee Update Success Modal -->
<div id="employeeUpdateSuccessModal" class="hidden fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg p-6 max-w-sm text-center">
    <i class="bi bi-check-circle text-green-600 text-4xl mb-3"></i>
    <h3 class="text-lg font-semibold mb-2 text-gray-800">Update Successful</h3>
    <p class="text-gray-600 mb-4">Employee credentials have been updated successfully.</p>
    <button onclick="closeEmployeeUpdateSuccessModal()" class="bg-black text-white px-4 py-2 rounded-lg hover:bg-gray-800">
      OK
    </button>
  </div>
</div>

<!-- Employee Update Error Modal -->
<div id="employeeUpdateErrorModal" class="hidden fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg p-6 max-w-sm text-center">
    <i class="bi bi-exclamation-triangle text-yellow-500 text-4xl mb-3"></i>
    <h3 class="text-lg font-semibold mb-2 text-gray-800">Update Failed</h3>
    <p class="text-gray-600 mb-4">An error occurred while updating employee credentials.</p>
    <button onclick="closeEmployeeUpdateErrorModal()" class="bg-black text-white px-4 py-2 rounded-lg hover:bg-gray-800">
      OK
    </button>
  </div>
</div>

</body>
</html>

