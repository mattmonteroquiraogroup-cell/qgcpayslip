<?php
session_start();
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];

$message = "";
$step    = 1; // Step control

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id  = $_POST['employee_id'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($user_id && !$password) {
        // 🔹 Step 1: First, check Admin table
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
            // ✅ Found in admin table
            $user = $adminData[0];
            $_SESSION['login_role'] = 'admin';
            $_SESSION['temp_user']  = $user_id;
            $step = 2; // ask for password
        } else {
            // 🔹 Step 1: Check Employees table
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

              if (empty($user['password'])) {

    // 🕒 Set timezone to Philippine Time
    date_default_timezone_set('Asia/Manila');

    // Send reset email
    $token   = bin2hex(random_bytes(16));

    // ⏱ Changed: expires in 1 minute (PH time)
    $expires = date("c", strtotime("+1 minute"));

    $url = $projectUrl . "/rest/v1/employees_credentials?employee_id=eq." . urlencode($user_id);
    $payload = json_encode([
        "reset_token" => $token,
        "reset_token_expires" => $expires
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: $apiKey",
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json",
        "Prefer: return=representation"
    ]);
    curl_exec($ch);
    curl_close($ch);

    $resetLink = "http://localhost/qgcpayslip/set_password.php?token=$token";

    // 🕒 Added: readable expiration time for PH timezone
    $expiresPH = new DateTime($expires);
    $expiresDisplay = $expiresPH->format('F j, Y '); // ex: October 6, 2025 10:55:47 AM

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'];
        $mail->Password   = $_ENV['SMTP_PASS'];
        $mail->SMTPSecure = $_ENV['SMTP_SECURE'];
        $mail->Port       = $_ENV['SMTP_PORT'];

        $mail->setFrom($_ENV['SMTP_USER'], $_ENV['SMTP_FROM_NAME']);
        $mail->addAddress($user['email'], $user['complete_name']);

        $mail->isHTML(true);
        $mail->Subject = 'Set Your Password';
        $mail->Body    = "Hello " . htmlspecialchars($user['complete_name']) . ",<br><br>
            Please click the link below to set your password:<br>
            <a href='$resetLink'>$resetLink</a><br><br>
            This link expires in <b>1 minute<br>
            Expiration time: <b>$expiresDisplay<br><br>
            Thank you.";

        $mail->send();
        $message = "A password setup link has been sent to your email.";
    } catch (Exception $e) {
        $message = "Email error: " . $mail->ErrorInfo;
    }
                } else {
                    $_SESSION['login_role'] = 'employee';
                    $_SESSION['temp_user']  = $user_id;
                    $step = 2; // ask for password
                }
            }
        }
    } elseif ($password && isset($_SESSION['temp_user'])) {
        // 🔹 Step 2: Verify login
        $user_id = $_SESSION['temp_user'];

        if ($_SESSION['login_role'] === 'admin') {
            $url = $projectUrl . "/rest/v1/admin_credentials?admin_id=eq." . urlencode($user_id);
        } else {
            $url = $projectUrl . "/rest/v1/employees_credentials?employee_id=eq." . urlencode($user_id);
        }

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
        $user = $data[0];

        if ($_SESSION['login_role'] === 'admin') {
            // ✅ Admin check (if password stored hashed, switch to password_verify)
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
            // ✅ Employee check
            if (password_verify($password, $user['password'])) {
                $_SESSION['employee_id']   = $user['employee_id'];
                $_SESSION['complete_name'] = $user['complete_name'];
                $_SESSION['subsidiary'] = $user['subsidiary'];  // save subsidiary info
                $_SESSION['role']          = 'employee';
                unset($_SESSION['temp_user'], $_SESSION['login_role']);
                header("Location: employeedashboard.php");
                exit;
            } else {
                $message = "Invalid password.";
                $step = 2;
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
  <title>Payslip Login</title>
  <style>
    body {
      margin: 0; padding: 0;
      font-family: 'Segoe UI', sans-serif;
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      height: 100vh;
      display: flex; align-items: center; justify-content: center;
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
      margin-bottom: 1.5rem;
      
    }
    .login-card h1 {
      font-size: 1.8rem;
      margin-bottom: .5rem;
      color: #212529;
    }
    .login-card p {
      font-size: 0.95rem;
      color: #6c757d;
      margin-bottom: 2rem;
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
  padding: 0.9rem 1rem;   /* increase padding a little */
  border: 1.8px solid #dee2e6;
  border-radius: 10px;
  font-size: 1rem;
  line-height: 1.4rem;    /* ensures text is vertically centered */
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
    .error {
      color: #dc3545;
      margin-bottom: 1rem;
      font-weight: 500;
    }
  </style>
</head>
<body>
  <div class="login-card">
    <img src="qgc.png" alt="QGC Logo" width="90" height="45">
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
         placeholder="Enter your ID" <?= ($step==2) ? "readonly" : "" ?> required>
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
