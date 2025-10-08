<?php
session_start();

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Supabase config from .env
$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];

// Flags to trigger modals
$showSuccess = false;
$showWarning = false;
$warningMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token        = $_POST['token'] ?? '';
    $new_password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    if (!$token) {
        $showWarning = true;
        $warningMessage = "Invalid request. No token provided.";
    } else {
        // 1. Fetch employee by reset_token
        $url = $projectUrl . "/rest/v1/employees_credentials"
             . "?reset_token=eq." . urlencode($token)
             . "&select=employee_id,reset_token_expires";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "apikey: $apiKey",
            "Authorization: Bearer $apiKey"
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);

        if (empty($data)) {
            $showWarning = true;
            $warningMessage = "Invalid or expired reset link.";
        } else {
            $user = $data[0];

            // 2. Check if token expired
            if (strtotime($user['reset_token_expires']) < time()) {
                $showWarning = true;
                $warningMessage = "Reset link expired. Please request a new one.";
            } else {
                // 3. Update password and clear token
                $url = $projectUrl . "/rest/v1/employees_credentials"
                     . "?employee_id=eq." . urlencode($user['employee_id']);

                $payload = json_encode([
                    "password" => $new_password,
                    "password_set_timestamp" => date("c"),
                    "reset_token" => null,
                    "reset_token_expires" => null
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
                $result = curl_exec($ch);
                curl_close($ch);

                $showSuccess = true;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Set New Password</title>
  <style>
    body {
      margin: 0;
      font-family: Arial, sans-serif;
      background: #f5f6f8;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
    }

    .container {
      background: #fff;
      padding: 40px 30px;
      border-radius: 12px;
      box-shadow: 0 6px 20px rgba(0,0,0,0.1);
      width: 100%;
      max-width: 400px;
      text-align: center;
    }

    .logo {
      margin-bottom: 15px;
    }

    .container h2 {
      margin: 10px 0;
      font-weight: bold;
      color: #000;
    }

    .container p {
      font-size: 14px;
      color: #666;
      margin-bottom: 25px;
    }

    .form-group {
      margin-bottom: 20px;
      text-align: left;
    }

    label {
      display: block;
      margin-bottom: 6px;
      font-size: 13px;
      font-weight: bold;
      color: #000;
    }

    input[type="password"] {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid #ccc;
      border-radius: 8px;
      outline: none;
      font-size: 14px;
      line-height: 1.4;
      box-sizing: border-box;
    }

    input[type="password"]:focus {
      border-color: #000;
      background: #fafafa;
    }

    input[type="password"]::placeholder {
      color: #999;
      font-size: 14px;
    }

    button {
      width: 100%;
      padding: 12px;
      background: #000;
      border: none;
      color: #fff;
      font-size: 15px;
      font-weight: bold;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.3s;
    }

    button:hover {
      background: #333;
    }

    /* Modal styling */
    .modal-overlay {
      position: fixed;
      top: 0; left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.5);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 1000;
    }

    .modal-box {
      background: #fff;
      padding: 30px 25px;
      border-radius: 10px;
      text-align: center;
      width: 90%;
      max-width: 380px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.2);
      animation: fadeIn 0.3s ease;
    }

    .modal-box.success h3 {
      color: #28a745;
    }

    .modal-box.warning h3 {
      color: #000000ff;
    }

    .modal-box p {
      font-size: 14px;
      color: #555;
      margin: 15px 0;
    }

    .modal-box a {
      display: inline-block;
      margin-top: 10px;
      background: #000;
      color: #fff;
      padding: 10px 18px;
      border-radius: 6px;
      text-decoration: none;
      font-weight: bold;
      transition: background 0.3s;
    }

    .modal-box a:hover {
      background: #333;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: scale(0.95); }
      to { opacity: 1; transform: scale(1); }
    }
  </style>
</head>
<body>

  <div class="container">
    <div class="logo">
      <img src="qgc.png" alt="Logo" width="80">
    </div>

    <h2>Set New Password</h2>
    <p>Please enter your new password to continue</p>

    <form method="POST">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>">
      <div class="form-group">
        <label for="password">New Password</label>
        <input type="password" id="password" name="password" placeholder="Enter new password" required>
      </div>
      <button type="submit">Save Password</button>
    </form>
  </div>

  <!-- SUCCESS MODAL -->
  <?php if ($showSuccess): ?>
  <div class="modal-overlay">
    <div class="modal-box success">
      <h3>Password Updated Successfully!</h3>
      <p>Your password has been reset. You can now log in using your new password.</p>
      <a href="login.php">Go to Login</a>
    </div>
  </div>
  <script>
    if (window.history.replaceState) {
      window.history.replaceState(null, null, window.location.href);
    }
  </script>
  <?php endif; ?>

  <!-- WARNING MODAL -->
  <?php if ($showWarning): ?>
  <div class="modal-overlay">
    <div class="modal-box warning">
      <h3>Oops! Link Expired</h3>
      <p><?php echo htmlspecialchars($warningMessage); ?></p>
      <a href="login.php">Log in Again</a>
    </div>
  </div>
  <?php endif; ?>

</body>
</html>
