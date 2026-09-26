<?php
require 'db.php';
// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';
require __DIR__ . '/PHPMailer-master/src/Exception.php';


// Code
if (isset($_POST['send_code'])) {
    $email = trim($_POST['email']);

    $stmt = $pdo->prepare("SELECT * FROM students WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

        $stmt = $pdo->prepare("
            UPDATE students 
            SET reset_code = :code, reset_expiry = :expiry 
            WHERE email = :email
        ");

        $stmt->execute([
            'code' => $code,
            'expiry' => $expiry,
            'email' => $email
        ]);

        // email via Brevo API
        $payload = json_encode([
            'sender' => [
                'name' => 'CEE IT Connects',
                'email' => 'jamesherold25@gmail.com', // must match the sender you verified in Brevo
            ],
            'to' => [
                ['email' => $email],
            ],
            'subject' => 'Password Reset Code',
            'htmlContent' => "
                <h3>Your verification code is:</h3>
                <h1>$code</h1>
                <p>This code expires in 10 minutes.</p>
            ",
        ]);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'api-key: ' . getenv('BREVO_API_KEY'),
                'Content-Type: application/json',
                'accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("Brevo cURL error: " . $curlError);
            $error = "Email failed: " . $curlError;
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            header("Location: forgot-password.php?step=code&email=" . urlencode($email) . "&msg=Code sent!");
            exit;
        } else {
            error_log("Brevo API error ({$httpCode}): " . $response);
            $error = "Email failed: " . $response;
        }

    } else {
        $error = "No student found with that email.";
    }
}


// VERIFY CODE 
if (isset($_POST['verify_code'])) {
    $entered_code = trim($_POST['code']);
    $email = $_POST['email'];

    $stmt = $pdo->prepare("
        SELECT * FROM students 
        WHERE email = :email 
        AND reset_code = :code 
        AND reset_expiry >= :now
    ");

    $stmt->execute([
        'email' => $email,
        'code' => $entered_code,
        'now' => date("Y-m-d H:i:s")
    ]);

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        header("Location: forgot-password.php?step=reset&email=" . urlencode($email));
        exit;
    } else {
        $error = "Invalid or expired verification code.";
    }
}


// RESET PASSWORD 
if (isset($_POST['reset_password'])) {
    $email = $_POST['email'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $passwordRegex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>_\-+=~`\[\];\'\/\\\\]).{8,16}$/';

    if (!preg_match($passwordRegex, $new_password)) {
        $error = "Password must be 8-16 characters and include an uppercase letter, a lowercase letter, a number, and a special character.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            UPDATE students 
            SET password_hash = :password, 
                reset_code = NULL, 
                reset_expiry = NULL 
            WHERE email = :email
        ");

        $stmt->execute([
            'password' => $hashed,
            'email' => $email
        ]);

        header("Location: login-ui.php?reset=success");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f4f5f9; }
        .fp-card { width: 400px; border: none; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); padding: 2rem; }
        .fp-title { font-weight: 700; color: #1b1c39; margin-bottom: 1.25rem; }
        .fp-alert { display: flex; align-items: flex-start; gap: 10px; background: #fdecea; color: #b3261e;
                    border-radius: 10px; padding: 12px 14px; font-size: 0.9rem; margin-bottom: 1rem; }
        .fp-alert i { margin-top: 2px; }
        .fp-msg { background: #eaf7ee; color: #1e7d3c; border-radius: 10px; padding: 12px 14px;
                  font-size: 0.9rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 10px; }
        .btn-fp { background: #e05834; border: none; font-weight: 600; border-radius: 10px; }
        .btn-fp:hover { background: #c94a29; }
        .fp-input { border-radius: 10px; padding: 10px 14px; }
    </style>
</head>

<body class="d-flex justify-content-center align-items-center vh-100">

    <div class="card fp-card">

        <?php if (isset($_GET['step']) && $_GET['step'] == 'code'): ?>

            <h3 class="fp-title">Enter Code</h3>
            <div class="fp-msg"><i class="fa-solid fa-circle-check"></i><span><?php echo htmlspecialchars($_GET['msg']); ?></span></div>
            <?php if (isset($error)) echo "<div class='fp-alert'><i class='fa-solid fa-circle-exclamation'></i><span>$error</span></div>"; ?>

            <form method="POST">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($_GET['email']); ?>">
                <input type="text" name="code" class="form-control fp-input mb-3" placeholder="Enter Code" required>
                <button name="verify_code" class="btn btn-primary btn-fp w-100">Verify</button>
            </form>

        <?php elseif (isset($_GET['step']) && $_GET['step'] == 'reset'): ?>

            <h3 class="fp-title">Reset Password</h3>
            <?php if (isset($error))
                echo "<div class='alert alert-danger'>$error</div>"; ?>

            <form method="POST" id="resetForm">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($_GET['email']); ?>">
                <input type="password" name="new_password" id="newPassword" pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&amp;*(),.?&quot;:{}|&lt;&gt;_\-+=~`\[\];'/\\]).{8,16}$"
                    class="form-control fp-input mb-1" placeholder="New Password" required oninput="this.setCustomValidity('')"
                    oninvalid="this.setCustomValidity('Password must be 8–16 characters and include an uppercase letter, a lowercase letter, a number, and a special character.')">
                <div class="text-muted mb-2" style="font-size:12px;">
                    Must be 8–16 characters, with at least 1 uppercase, 1 lowercase, 1 number, and 1 special character.
                </div>

                <input type="password" name="confirm_password" id="confirmPassword" class="form-control fp-input mb-1"
                    placeholder="Confirm Password" required oninput="this.setCustomValidity('')">
                <div id="confirmError" class="text-danger mb-2" style="font-size:12px; display:none;">
                    Passwords do not match.
                </div>

            <button name="reset_password" class="btn btn-success btn-fp w-100">Reset Password</button>            </form>

            <script>
                document.getElementById('resetForm').addEventListener('submit', function (e) {
                    const pass = document.getElementById('newPassword');
                    const confirm = document.getElementById('confirmPassword');
                    const confirmError = document.getElementById('confirmError');

                    confirmError.style.display = 'none';
                    confirm.setCustomValidity('');

                    if (pass.value !== confirm.value) {
                        e.preventDefault();
                        confirm.setCustomValidity('Passwords do not match.');
                        confirmError.style.display = 'block';
                        confirm.reportValidity();
                    }
                });
            </script>

        <?php else: ?>

            <h3 class="fp-title">Forgot Password</h3>
            <?php if (isset($error))
                echo "<div class='alert alert-danger'>$error</div>"; ?>

            <form method="POST">
                <input type="email" name="email" class="form-control fp-input mb-3" placeholder="Enter Email" required>
                <button name="send_code" class="btn btn-primary btn-fp w-100">Send Code</button>
            </form>

        <?php endif; ?>

    </div>

</body>

</html>