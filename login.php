<?php
session_start();
require __DIR__ . '/vendor/autoload.php';

//$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
//$dotenv->load();

$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];

$message = "";
$step    = 1; // Step control

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id  = trim($_POST['employee_id'] ?? '');
    $password = $_POST['password'] ?? '';

    // If user clicked "Forgot Password"
    if (isset($_POST['forgot_password']) && $user_id) {
        $url = $projectUrl . "/rest/v1/employees_credentials?employee_id=eq." . urlencode($user_id);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $empData = json_decode($response, true);

        if (empty($empData)) {
            $message = "Employee ID not found.";
        } else {
            $email = $empData[0]['email'];
            // Trigger Supabase reset email
            $recoverUrl = $projectUrl . "/auth/v1/recover";
            $payload = json_encode([
                "email" => $email,
                "redirect_to" => "https://qgcpayslip.onrender.com/set_password.php"
            ]);

            $ch = curl_init($recoverUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "apikey: $apiKey",
                "Authorization: Bearer $apiKey",
                "Content-Type: application/json"
            ]);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $message = "Error sending reset email: $error";
            } else {
                $message = "A password reset link has been sent to your email.";
            }
        }
    }

    // Step 1: User enters ID (check Admin/Employee)
    elseif ($user_id && !$password) {
        // 1️⃣ Check Admin table
        $url = $projectUrl . "/rest/v1/admin_credentials?admin_id=eq." . urlencode($user_id);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $adminData = json_decode($response, true);

        if (!empty($adminData)) {
            $_SESSION['login_role'] = 'admin';
            $_SESSION['temp_user']  = $user_id;
            $step = 2;
        } else {
            // 2️⃣ Check Employee table
            $url = $projectUrl . "/rest/v1/employees_credentials?employee_id=eq." . urlencode($user_id);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "apikey: $apiKey",
                "Authorization: Bearer $apiKey",
                "Content-Type: application/json"
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $empData = json_decode($response, true);

            if (empty($empData)) {
                $message = "ID not found. Please contact HR.";
            } else {
                $user = $empData[0];

                // If password not yet set — trigger Supabase Auth email
                if (empty($user['password'])) {
                    $email = $user['email'];
                    $recoverUrl = $projectUrl . "/auth/v1/recover";

                    $payload = json_encode([
                        "email" => $email,
                        "redirect_to" => "https://qgcpayslip.onrender.com/set_password.php"
                    ]);

                    $ch = curl_init($recoverUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "apikey: $apiKey",
                        "Authorization: Bearer $apiKey",
                        "Content-Type: application/json"
                    ]);

                    $response = curl_exec($ch);
                    $error = curl_error($ch);
                    curl_close($ch);

                    if ($error) {
                        $message = "Error sending setup email: $error";
                    } else {
                        $message = "A password setup link has been sent to your email.";
                    }
                } else {
                    $_SESSION['login_role'] = 'employee';
                    $_SESSION['temp_user']  = $user_id;
                    $step = 2;
                }
            }
        }
    }

    // Step 2: User enters password
    elseif ($password && isset($_SESSION['temp_user'])) {
        $user_id = $_SESSION['temp_user'];
        $role = $_SESSION['login_role'];

        $url = ($role === 'admin')
            ? $projectUrl . "/rest/v1/admin_credentials?admin_id=eq." . urlencode($user_id)
            : $projectUrl . "/rest/v1/employees_credentials?employee_id=eq." . urlencode($user_id);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        $user = $data[0] ?? null;

        if (!$user) {
            $message = "User not found.";
            $step = 1;
        } else {
            if ($role === 'admin') {
                if ($password === $user['password']) {
                    $_SESSION['admin_id']      = $user['admin_id'];
                    $_SESSION['complete_name'] = $user['complete_name'];
                    $_SESSION['role']          = 'admin';
                    unset($_SESSION['temp_user'], $_SESSION['login_role']);
                    header("Location: admindashboard.php");
                    exit;
                } else {
                    $message = "Invalid admin password.";
                    $step = 2;
                }
            } else {
                if (password_verify($password, $user['password'])) {
                    $_SESSION['employee_id']   = $user['employee_id'];
                    $_SESSION['complete_name'] = $user['complete_name'];
                    $_SESSION['subsidiary']    = $user['subsidiary'];
                    $_SESSION['role']          = 'employee';
                    unset($_SESSION['temp_user'], $_SESSION['login_role']);
                    header("Location: employeedashboard.php");
                    exit;
                } else {
                    $message = "Invalid password.";
                    $step = 2;
                }
            }
        }
    } else {
        $message = "Please enter your ID.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QGC Payslip Portal</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: 'Segoe UI', sans-serif;
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .login-card {
      background: #fff;
      padding: 2.5rem;
      border-radius: 15px;
      box-shadow: 0 8px 25px rgba(0,0,0,0.1);
      width: 100%;
      max-width: 420px;
      text-align: center;
    }
    .login-card img {
      display: block;
      margin: 0 auto 0.25rem;
    }
    .login-card h1 {
      font-size: 1.8rem;
      margin-top: 0;
      margin-bottom: 0.4rem;
      color: #212529;
    }
    .login-card p {
      font-size: 0.95rem;
      color: #6c757d;
      margin-top: 0;
      margin-bottom: 1.8rem;
    }
    .form-group {
      text-align: left;
      margin-bottom: 1.5rem;
    }
    .form-label {
      font-weight: 500;
      margin-bottom: .5rem;
      display: block;
      color: #212529;
    }
    .form-input {
      width: 100%;
      padding: 0.9rem 1rem;
      border: 1.8px solid #dee2e6;
      border-radius: 10px;
      font-size: 1rem;
      line-height: 1.4rem;
      box-sizing: border-box;
      transition: all 0.2s ease;
    }
    .form-input:focus {
      border-color: #495057;
      box-shadow: 0 0 0 3px rgba(33,37,41,0.1);
      outline: none;
    }
    .login-button {
      width: 100%;
      padding: 0.9rem;
      background: #212529;
      color: #fff;
      border: none;
      border-radius: 10px;
      font-size: 1rem;
      font-weight: 500;
      cursor: pointer;
      transition: background .2s, transform .2s;
    }
    .login-button:hover {
      background: #495057;
      transform: translateY(-1px);
    }
    .forgot {
      display: block;
      margin-top: 1rem;
      font-size: 0.9rem;
      color: #0d6efd;
      text-decoration: none;
    }
    .forgot:hover { text-decoration: underline; }
    .error {
      color: #dc3545;
      margin-bottom: 1rem;
      font-weight: 500;
    }
  </style>
</head>
<body>
  <div class="login-card">
    <img src="favicon.svg" alt="QGC Logo" width="150" height="75">
    <h1>Payslip Portal</h1>
    <p>Sign in to access your account</p>

    <?php if ($message): ?>
      <div class="error"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label class="form-label" for="user_id">ID</label>
        <input type="text" name="employee_id" id="user_id" class="form-input"
               value="<?= ($step == 2) ? htmlspecialchars($_SESSION['temp_user']) : '' ?>"
               placeholder="Enter your ID"
               <?= ($step == 2) ? "readonly" : "" ?> required>
      </div>

      <?php if ($step == 2): ?>
      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <input type="password" name="password" id="password" class="form-input"
               placeholder="Enter your password" required>
      </div>
      <?php endif; ?>

      <button type="submit" class="login-button">
        <?= ($step == 1) ? "Next" : "Log In" ?>
      </button>

    </form>
  </div>
</body>
</html>
