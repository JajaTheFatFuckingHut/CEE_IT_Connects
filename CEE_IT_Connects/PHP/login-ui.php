<?php
$role = $_GET['role'] ?? 'student';
$statePath = __DIR__ . '/register_toggle.txt';
$registerVisible = file_exists($statePath) ? trim(file_get_contents($statePath)) : 'show';


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CEE IT Connects - <?= htmlspecialchars(ucfirst($role)) ?> Login</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../CSS/student-login.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">
</head>

<body>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- LOADING SCREEN -->
    <!-- <div id="loading-screen">
        <img src="../Sources/CEE IT Connects Logo.png" alt="Logo" class="loading-logo">
    </div> -->

    <div class="container-fluid login-container">
        <div class="row h-100">

            <div class="col-md-5 p-0 left-panel"></div>

            <div class="col-md-7 right-panel">
                <img src="../Sources/CEE IT Connects Logo.png" alt="CEE IT Logo" class="logo-img">

                <h2 class="login-title">CEE IT CONNECTS</h2>
                <h3 class="login-subtitle">
                    <?= ucfirst($role) ?> Login
                </h3>

                <!-- ROLE TOGGLE -->
                <div class="role-toggle">
                    <a href="login-ui.php?role=student"
                        class="btn-role <?= $role === 'student' ? 'active' : 'inactive' ?>">
                        Students
                    </a>
                    <a href="login-ui.php?role=adviser"
                        class="btn-role <?= $role === 'adviser' ? 'active' : 'inactive' ?>">
                        Adviser
                    </a>
                    <a href="login-ui.php?role=admin" class="btn-role <?= $role === 'admin' ? 'active' : 'inactive' ?>">
                        Admin
                    </a>
                </div>

                <!-- LOGIN FORM -->
                <form class="form-wrapper" method="POST" action="login.php">

                    <div class="mb-3">
                        <input type="email" class="form-control" name="email" placeholder="<?= ucfirst($role) ?> Email"
                            required>
                    </div>

                    <div class="mb-3 password-group">
                        <input type="password" class="form-control" name="password" id="password" placeholder="Password"
                            required>

                        <i class="fa fa-eye-slash toggle-password" id="togglePasswordIcon"></i>
                    </div>

                    <!-- ROLE IDENTIFIER -->
                    <input type="hidden" name="role" value="<?= $role ?>">

                    <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
                    <button type="submit" class="btn-login">Login</button>
                </form>
                <?php if ($registerVisible === 'show'): ?>
                    <div class="register-text mt-3" style="text-align: center; margin-top: 20px; 
                        font-size: 0.9rem; color: #333;">Click <a href="student-register.php"
                            style="color: #e05834;">here</a>
                        to register</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/JS/login.js"></script>

</body>

</html>