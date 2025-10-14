<?php
session_start();
require __DIR__ . '/vendor/autoload.php';

use Brevo\Client\Configuration;
use Brevo\Client\Api\TransactionalEmailsApi;
use Brevo\Client\Model\SendSmtpEmail;
use GuzzleHttp\Client;

//$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
//$dotenv->load();

$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];

$message = "";
$step    = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id  = $_POST['employee_id'] ?? '';
    $password = $_POST['password'] ?? '';

    // User ID entered
    if ($user_id && !$password) {
        // Check Admin table first
        $url = $projectUrl . "/rest/v1/admin_credentials?admin_id=eq." . urlencode($user_id);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "apikey: $apiKey",
                "Authorization: Bearer $apiKey",
                "Content-Type: application/json"
            ]
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $adminData = json_decode($response, true);

        if (!empty($adminData)) {
            $_SESSION['login_role'] = 'admin';
            $_SESSION['temp_user']  = $user_id;
            $step = 2;
        } else {
            // Check Employee table
            $url = $projectUrl . "/rest/v1/employees_credentials?employee_id=eq." . urlencode($user_id);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "apikey: $apiKey",
                    "Authorization: Bearer $apiKey",
                    "Content-Type: application/json"
                ]
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $empData = json_decode($response, true);

            if (empty($empData)) {
                $message = "ID not found. Please contact HR.";
            } else {
                $user = $empData[0];

                // No password set — send password setup email
                if (empty($user['password'])) {
                    $token   = bin2hex(random_bytes(16));

                    // Always use UTC for expiration time (Supabase standard)
                    date_default_timezone_set('UTC');
                    $expires = gmdate("Y-m-d\TH:i:s\Z", strtotime("+3 hours"));

                    // Display local time (Philippines)
                    $expiresDisplay = new DateTime($expires, new DateTimeZone('UTC'));
                    $expiresDisplay->setTimezone(new DateTimeZone('Asia/Manila'));
                    $expiresDisplayFormatted = $expiresDisplay->format('F j, Y g:i A');

                    // Store token in Supabase
                    $url = $projectUrl . "/rest/v1/employees_credentials?employee_id=eq." . urlencode($user_id);
                    $payload = json_encode([
                        "reset_token" => $token,
                        "reset_token_expires" => $expires
                    ]);
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CUSTOMREQUEST => "PATCH",
                        CURLOPT_POSTFIELDS => $payload,
                        CURLOPT_HTTPHEADER => [
                            "apikey: $apiKey",
                            "Authorization: Bearer $apiKey",
                            "Content-Type: application/json",
                            "Prefer: return=representation"
                        ]
                    ]);
                    curl_exec($ch);
                    curl_close($ch);

                    // Build reset link
                    $resetLink = "https://qgcpayslip.onrender.com/set_password.php?token=$token";

                    // Send email via Brevo
                    try {
                        $config = Configuration::getDefaultConfiguration()
                            ->setApiKey('api-key', $_ENV['BREVO_API_KEY']);
                        $apiInstance = new TransactionalEmailsApi(new Client(), $config);

                        $sendSmtpEmail = new SendSmtpEmail([
                            'subject' => 'Set Your Password',
                            'sender' => [
                                'name' => $_ENV['BREVO_SENDER_NAME'],
                                'email' => $_ENV['BREVO_SENDER_EMAIL']
                            ],
                            'to' => [[
                                'email' => $user['email'],
                                'name' => $user['complete_name']
                            ]],
                            'htmlContent' => "
                                <p>Hello <b>{$user['complete_name']}</b>,</p>
                                <p>Please click the link below to set your password:</p>
                                <p><a href='$resetLink'>$resetLink</a></p>
                                <p>This link expires on <b>$expiresDisplayFormatted</b>.</p>
                                <p>Thank you,<br>Payslip Portal Team</p>"
                        ]);

                        $apiInstance->sendTransacEmail($sendSmtpEmail);
                        $message = "A password setup link has been sent to your email.";
                    } catch (Exception $e) {
                        $message = "Email sending failed: " . $e->getMessage();
                    }
                } else {
                    $_SESSION['login_role'] = 'employee';
                    $_SESSION['temp_user']  = $user_id;
                    $step = 2;
                }
            }
        }

    // Password entered
    } elseif ($password && isset($_SESSION['temp_user'])) {
        $user_id = $_SESSION['temp_user'];
        $role = $_SESSION['login_role'];

        $url = ($role === 'admin')
            ? "$projectUrl/rest/v1/admin_credentials?admin_id=eq." . urlencode($user_id)
            : "$projectUrl/rest/v1/employees_credentials?employee_id=eq." . urlencode($user_id);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "apikey: $apiKey",
                "Authorization: Bearer $apiKey",
                "Content-Type: application/json"
            ]
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
                    $_SESSION['admin_id'] = $user['admin_id'];
                    $_SESSION['complete_name'] = $user['complete_name'];
                    $_SESSION['role'] = 'admin';
                    unset($_SESSION['temp_user'], $_SESSION['login_role']);
                    header("Location: admindashboard.php");
                    exit;
                } else {
                    $message = "Invalid admin password.";
                    $step = 2;
                }
            } else {
                if (password_verify($password, $user['password'])) {
                    $_SESSION['employee_id'] = $user['employee_id'];
                    $_SESSION['complete_name'] = $user['complete_name'];
                    $_SESSION['subsidiary'] = $user['subsidiary'];
                    $_SESSION['role'] = 'employee';
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
  <title>QGC Payslip & Loan Portal</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <style>
    :root {
      --bg-light: #f8f9fa;
      --bg-lighter: #e9ecef;
      --text-dark: #212529;
      --text-muted: #6c757d;
      --border-color: #dee2e6;
      --btn-bg: #212529;
      --btn-hover: #495057;
      --error-color: #dc3545;
    }

    body {
      margin: 0;
      padding: 0;
      font-family: 'Segoe UI', sans-serif;
      background: linear-gradient(135deg, var(--bg-light), var(--bg-lighter));
      min-height: 100vh;
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
      box-sizing: border-box;
    }

    .login-card img {
      display: block;
      margin: 0 auto 0.25rem;
      width: 150px;
      height: auto;
    }

    .login-card h1 {
      font-size: 1.8rem;
      margin-top: 0;
      margin-bottom: 0.4rem;
      color: var(--text-dark);
    }

    .login-card p {
      font-size: 0.95rem;
      color: var(--text-muted);
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
      color: var(--text-dark);
    }

    .form-input {
      width: 100%;
      padding: 0.9rem 1rem;
      border: 1.8px solid var(--border-color);
      border-radius: 10px;
      font-size: 1rem;
      line-height: 1.4rem;
      box-sizing: border-box;
      transition: all 0.2s ease;
    }

    .form-input:focus {
      border-color: var(--btn-hover);
      box-shadow: 0 0 0 3px rgba(33,37,41,0.1);
      outline: none;
    }

    .login-button {
      width: 100%;
      padding: 0.9rem;
      background: var(--btn-bg);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-size: 1rem;
      font-weight: 500;
      cursor: pointer;
      transition: background .2s, transform .2s;
    }

    .login-button:hover {
      background: var(--btn-hover);
      transform: translateY(-1px);
    }

    .forgot {
      display: block;
      margin-top: 1rem;
      font-size: 0.9rem;
      color: #0d6efd;
      text-decoration: none;
    }

    .forgot:hover {
      text-decoration: underline;
    }

    .error {
      color: var(--error-color);
      margin-bottom: 1rem;
      font-weight: 500;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
      body {
        padding: 1.5rem;
        align-items: flex-start;
        background: var(--bg-lighter);
      }

      .login-card {
        padding: 2rem;
        max-width: 100%;
        margin-top: 5vh;
      }

      .login-card h1 {
        font-size: 1.6rem;
      }

      .login-card p {
        font-size: 0.9rem;
      }
    }

    @media (max-width: 480px) {
      .login-card {
        padding: 1.5rem;
        border-radius: 12px;
      }

      .form-input {
        padding: 0.75rem;
        font-size: 0.95rem;
      }

      .login-button {
        padding: 0.8rem;
        font-size: 0.95rem;
      }

      .login-card img {
        width: 120px;
      }
    }
    
  </style>
</head>
<body>
  <div class="login-card">
    <img src="favicon.svg" alt="QGC Logo">
    <h1>Payslip & Loan Portal</h1>
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
  <script>
  document.addEventListener("DOMContentLoaded", function() {
    const userIdInput = document.getElementById("user_id");
    userIdInput.addEventListener("input", function() {
      this.value = this.value.toUpperCase();
    });
  });
</script>

</body>
</html>

