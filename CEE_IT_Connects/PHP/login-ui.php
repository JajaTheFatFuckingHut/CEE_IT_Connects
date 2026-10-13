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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            <svg class="wave-bg" viewBox="0 0 580 1000" preserveAspectRatio="none" aria-hidden="true">
                <!-- outer band (yellow) -->
                <path fill="#f4a614" d="M0 0 L468 0
                    C510 65 565 155 558 290
                    C552 385 510 430 498 510
                    C486 600 480 690 446 790
                    C420 868 410 945 415 1000 L0 1000 Z"/>
                <!-- middle band (orange) -->
                <path fill="#e5572f" d="M0 0 L440 0
                    C482 60 538 150 532 285
                    C528 380 488 425 478 500
                    C468 590 462 680 428 780
                    C400 860 385 940 392 1000 L0 1000 Z"/>
                <!-- navy panel -->
                <path fill="#010e47" d="M0 0 L412 0
                    C450 60 505 140 500 270
                    C497 360 462 400 455 470
                    C448 560 440 640 400 740
                    C370 810 345 900 330 1000 L0 1000 Z"/>
            </svg>

        <div class="row g-0">

            <!-- LEFT BRAND PANEL (text is now real HTML so it scales instead of being cropped) -->
            <div class="col-lg-5 left-panel">
                

                <div class="left-inner">
                    <div class="brand">
                        <img src="../Sources/CEE IT Connects Logo.png" alt="" class="brand-logo">
                        <div>
                            <div class="brand-name">CEE IT CONNECTS</div>
                            <div class="brand-sub">Internship Coordination for CEIT PLV</div>
                        </div>
                    </div>

                    <div class="hero">
                        <h1 class="hero-title">Learn. <span class="c-orange">Experience.</span> <span class="c-yellow">Grow.</span></h1>
                        <p class="hero-text">Connect with your advisers, explore internship opportunities, and keep your OJT journey organized all in one platform.</p>
                    </div>

                    <ul class="school-info">
                        <li><i class="fa-solid fa-location-dot"></i>Tongco St., Maysan, Valenzuela City</li>
                        <li><i class="fa-regular fa-building"></i>Pamantasan ng Lungsod ng Valenzuela</li>
                    </ul>
                </div>
            </div>

            <!-- RIGHT PANEL with login card -->
            <div class="col-lg-7 right-panel">
                <div class="login-card">
                    <img src="../Sources/CEE IT Connects Logo.png" alt="CEE IT Logo" class="logo-img">

                    <h2 class="login-title">CEE IT CONNECTS</h2>
                    <h3 class="login-subtitle"><?= htmlspecialchars(ucfirst($role)) ?> Login</h3>

                    <!-- ROLE TOGGLE -->
                    <div class="role-toggle">
                        <a href="login-ui.php?role=student" class="btn-role <?= $role === 'student' ? 'active' : 'inactive' ?>">
                            <i class="fa-solid fa-graduation-cap"></i> Students
                        </a>
                        <a href="login-ui.php?role=adviser" class="btn-role <?= $role === 'adviser' ? 'active' : 'inactive' ?>">
                            <i class="fa-regular fa-user"></i> Adviser
                        </a>
                        <a href="login-ui.php?role=admin" class="btn-role <?= $role === 'admin' ? 'active' : 'inactive' ?>">
                            <i class="fa-solid fa-user-shield"></i> Admins
                        </a>
                    </div>

                    <!-- LOGIN FORM (logic untouched) -->
                    <form class="form-wrapper" method="POST" action="login.php">

                        <div class="mb-3">
                            <label class="field-label" for="email"><?= htmlspecialchars(ucfirst($role)) ?> Email</label>
                            <div class="input-icon">
                                <i class="fa-regular fa-envelope field-icon"></i>
                                <input type="email" class="form-control" name="email" id="email"
                                    placeholder="Enter your <?= htmlspecialchars(strtolower($role)) ?> email" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="field-label" for="password">Password</label>
                            <div class="input-icon password-group">
                                <i class="fa-solid fa-lock field-icon"></i>
                                <input type="password" class="form-control" name="password" id="password"
                                    placeholder="Enter your password" required>
                                <i class="fa fa-eye-slash toggle-password" id="togglePasswordIcon"></i>
                            </div>
                        </div>

                        <!-- ROLE IDENTIFIER -->
                        <input type="hidden" name="role" value="<?= $role ?>">

                        <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
                        <button type="submit" class="btn-login">Login <i class="fa-solid fa-arrow-right"></i></button>
                    </form>

                    <?php if ($registerVisible === 'show' && $role === 'student'): ?>
                        <div class="register-text">Click <a href="student-register.php" class="register-link">here</a> to register</div>
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