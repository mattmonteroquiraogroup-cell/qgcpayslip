<?php
session_start();
require __DIR__ . '/vendor/autoload.php';

//$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
//$dotenv->load();

// Supabase config
$projectUrl = $_ENV['SUPABASE_URL'];
$apiKey     = $_ENV['SUPABASE_KEY'];

// Flags for modals
$showSuccess = false;
$showWarning = false;
$warningMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token        = $_POST['access_token'] ?? '';
    $new_password = $_POST['password'] ?? '';

    if (!$token) {
        $showWarning = true;
        $warningMessage = "Invalid or missing token.";
    } elseif (!$new_password) {
        $showWarning = true;
        $warningMessage = "Please enter a new password.";
    } else {
        // 🔹 Check if token exists in Supabase and is still valid
        $url = $projectUrl . "/rest/v1/employees_credentials?reset_token=eq." . urlencode($token);
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

        if (empty($data)) {
            $showWarning = true;
            $warningMessage = "Invalid or missing token.";
        } else {
            $user = $data[0];

            // ✅ Make sure both are compared in UTC
            date_default_timezone_set('UTC');
            $currentTime = time();
            $expiryTime  = strtotime($user['reset_token_expires']);

            if ($currentTime > $expiryTime) {
                $showWarning = true;
                $warningMessage = "Reset link has expired. Please request a new one.";
            } else {
                // 🔹 Hash the new password
                $hashedPassword = password_hash($new_password, PASSWORD_BCRYPT);

                // 🔹 Update password in Supabase
                $updateUrl = $projectUrl . "/rest/v1/employees_credentials?employee_id=eq." . urlencode($user['employee_id']);
                $payload = json_encode([
                    "password" => $hashedPassword,
                    "reset_token" => null,
                    "reset_token_expires" => null
                ]);

                $ch = curl_init($updateUrl);
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
                $updateResponse = curl_exec($ch);
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
      color: #000;
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
      <input type="hidden" name="access_token" id="tokenField">
      <div class="form-group">
        <label for="password">New Password</label>
        <input type="password" id="password" name="password" placeholder="Enter new password" required>
      </div>
      <button type="submit">Save Password</button>
    </form>
  </div>

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

  <?php if ($showWarning): ?>
  <div class="modal-overlay">
    <div class="modal-box warning">
      <h3>Oops!</h3>
      <p><?php echo htmlspecialchars($warningMessage); ?></p>
      <a href="login.php">Back to Login</a>
    </div>
  </div>
  <?php endif; ?>

  <script>
    // Automatically fill token from URL (?token=xxxx)
    const params = new URLSearchParams(window.location.search);
    const token = params.get("token");
    if (token) document.getElementById("tokenField").value = token;
  </script>

</body>
</html>

