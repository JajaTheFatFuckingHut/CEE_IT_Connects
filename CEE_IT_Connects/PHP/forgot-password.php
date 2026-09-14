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

        // email
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = getenv('GMAIL_USERNAME');
            $mail->Password = getenv('GMAIL_APP_PASSWORD');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = 465;

            $mail->Timeout = 15;
            $mail->setFrom(getenv('GMAIL_USERNAME'), 'CEE IT Connects');
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Code';
            $mail->Body = "
                <h3>Your verification code is:</h3>
                <h1>$code</h1>
                <p>This code expires in 10 minutes.</p>
            ";

            $mail->send();

            header("Location: forgot-password.php?step=code&email=" . urlencode($email) . "&msg=Code sent!");
            exit;

        } catch (Exception $e) {
            $error = "Email failed: {$mail->ErrorInfo}";
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

    if ($new_password !== $confirm_password) {
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
</head>

<body class="d-flex justify-content-center align-items-center vh-100">

    <div class="card p-4" style="width: 400px;">

        <?php if (isset($_GET['step']) && $_GET['step'] == 'code'): ?>

            <h3>Enter Code</h3>
            <p><?php echo htmlspecialchars($_GET['msg']); ?></p>
            <?php if (isset($error))
                echo "<div class='alert alert-danger'>$error</div>"; ?>

            <form method="POST">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($_GET['email']); ?>">
                <input type="text" name="code" class="form-control mb-3" placeholder="Enter Code" required>
                <button name="verify_code" class="btn btn-primary w-100">Verify</button>
            </form>

        <?php elseif (isset($_GET['step']) && $_GET['step'] == 'reset'): ?>

            <h3>Reset Password</h3>
            <?php if (isset($error))
                echo "<div class='alert alert-danger'>$error</div>"; ?>

            <form method="POST" id="resetForm">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($_GET['email']); ?>">
                <input type="password" name="new_password" id="newPassword" pattern="^(?=.*[a-z])(?=.*[A-Z]).{8,16}$"
                    class="form-control mb-1" placeholder="New Password" required oninput="this.setCustomValidity('')"
                    oninvalid="this.setCustomValidity('Password must be 8–16 characters and include at least one uppercase and one lowercase letter.')">
                <div class="text-muted mb-2" style="font-size:12px;">
                    Must be 8–16 characters, with at least 1 uppercase and 1 lowercase letter.
                </div>

                <input type="password" name="confirm_password" id="confirmPassword" class="form-control mb-1"
                    placeholder="Confirm Password" required oninput="this.setCustomValidity('')">
                <div id="confirmError" class="text-danger mb-2" style="font-size:12px; display:none;">
                    Passwords do not match.
                </div>

                <button name="reset_password" class="btn btn-success w-100">Reset Password</button>
            </form>

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

            <h3>Forgot Password</h3>
            <?php if (isset($error))
                echo "<div class='alert alert-danger'>$error</div>"; ?>

            <form method="POST">
                <input type="email" name="email" class="form-control mb-3" placeholder="Enter Email" required>
                <button name="send_code" class="btn btn-primary w-100">Send Code</button>
            </form>

        <?php endif; ?>

    </div>

</body>

</html>