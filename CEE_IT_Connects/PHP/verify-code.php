<?php
session_start();

require 'db.php';
require 'mailer.php';

$p = $_SESSION['pending_reg'] ?? null;
if (!$p) {
    $_SESSION['error'] = "Your registration session expired. Please register again.";
    header("Location: ../student-register.php");
    exit();
}

// ── Resend code (60s cooldown) ──
if (isset($_POST['resend'])) {
    if (time() - $p['last_sent'] < 60) {
        $_SESSION['warning'] = "Please wait a minute before requesting another code.";
    } else {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['pending_reg']['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
        $_SESSION['pending_reg']['expires'] = time() + 600;
        $_SESSION['pending_reg']['attempts'] = 0;
        $_SESSION['pending_reg']['last_sent'] = time();

        if (sendVerificationCode($p['email'], $p['full_name'], $code)) {
            $_SESSION['info'] = "A new code was sent.";
        } else {
            $_SESSION['error'] = "Couldn't send the code. Try again.";
        }
    }
    header("Location: verify-code.php");
    exit();
}

// ── Verify code ──
if (isset($_POST['verify'])) {
    $input = preg_replace('/\D/', '', $_POST['code'] ?? '');

    if (time() > $p['expires']) {
        $_SESSION['error'] = "That code expired. Request a new one.";
    } elseif ($p['attempts'] >= 5) {
        $_SESSION['error'] = "Too many attempts. Request a new code.";
    } elseif (!password_verify($input, $p['code_hash'])) {
        $_SESSION['pending_reg']['attempts']++;
        $_SESSION['error'] = "Incorrect code.";
    } else {
        try {
            // Re-check duplicates in case someone registered while this was pending
            $dup = $pdo->prepare("SELECT 1 FROM students WHERE student_id = ? OR LOWER(email) = ?");
            $dup->execute([$p['student_id'], $p['email']]);
            if ($dup->fetchColumn()) {
                unset($_SESSION['pending_reg']);
                $_SESSION['error'] = "Student ID or email is already registered.";
                header("Location: ../student-register.php");
                exit();
            }

            $year_level = 4; // always 4th year in this system

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO students
                (full_name, student_id, program, year_level, section, password_hash, contact_number, email, is_archived)
                VALUES
                (:full_name, :student_id, :program, :year_level, :section, :password_hash, :contact_number, :email, FALSE)
            ");
            $stmt->execute([
                ':full_name' => $p['full_name'],
                ':student_id' => $p['student_id'],
                ':program' => $p['program'],
                ':year_level' => $year_level,
                ':section' => $p['section'],
                ':password_hash' => $p['password_hash'],
                ':contact_number' => $p['contact_number'],
                ':email' => $p['email'],
            ]);
            $newStudentId = $pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO audits (user_id, roles, activity, activity_date)
                VALUES (:user_id, :roles, :activity, NOW())
            ")->execute([
                        ':user_id' => $newStudentId,
                        ':roles' => 'student',
                        ':activity' => 'Student registered an account with school ID ' . $p['student_id'],
                    ]);

            $pdo->commit();

            // Send credentials email (account already exists even if this fails)
            $sent = sendStudentCredentials($p['email'], $p['full_name'], $p['student_id']);

            // Log the student in, same as before
            $email = $p['email'];
            unset($_SESSION['pending_reg']);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $newStudentId;
            $_SESSION['role'] = 'student';
            $_SESSION['success'] = $sent
                ? "Email verified! Your account details were sent to $email."
                : "Email verified and your account is ready!";

            header("Location: ../index.php");
            exit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            error_log('Student registration failed: ' . $e->getMessage());
            $_SESSION['error'] = "Could not create your account. Please try again.";
        }
    }
    header("Location: verify-code.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CEE IT Connects - Verify Email</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/student-register.css">
</head>
<body>
<?php foreach (['success' => 'success', 'warning' => 'warning', 'info' => 'info', 'error' => 'danger'] as $k => $cls):
    if (isset($_SESSION[$k])): ?>
                <div class="alert alert-<?= $cls ?> alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
                     style="z-index:9999; min-width:350px;" role="alert">
                    <?= htmlspecialchars($_SESSION[$k]) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION[$k]);
    endif;
endforeach; ?>

<div class="container-fluid login-container">
    <div class="row h-100">
        <div class="col-md-5 p-0 left-panel"></div>
        <div class="col-md-7 right-panel">
            <img src="../Sources/CEE IT Connects Logo.png" alt="CEE IT Logo" class="logo-img">
            <h2 class="login-title">CEE IT CONNECTS</h2>
            <h3 class="login-subtitle">Verify your email</h3>

            <p class="text-center text-muted">
                Enter the 6-digit code sent to <strong><?= htmlspecialchars($p['email']) ?></strong>
            </p>

            <form class="form-wrapper" method="POST">
                <div class="mb-3">
                    <input type="text" name="code" class="form-control text-center"
                           style="font-size:24px; letter-spacing:8px;"
                           inputmode="numeric" maxlength="6" pattern="\d{6}"
                           placeholder="000000" autocomplete="one-time-code" required autofocus>
                </div>
                <button type="submit" name="verify" value="1" class="btn-login">Verify &amp; Create Account</button>
            </form>

            <form method="POST" class="text-center mt-3">
                <button type="submit" name="resend" value="1" class="btn btn-link">Resend code</button>
                <a href="student-register.php" class="btn btn-link">Use a different email</a>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>