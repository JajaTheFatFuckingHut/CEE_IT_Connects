<?php
require 'db.php';
require_once 'auth.php';
$page = $page ?? "";
$user_id = $_SESSION['user_id'];
$role = strtolower(trim($_SESSION['role']));

$hideStudentNav = in_array($role, [
    'admin',
    'superadmin',
    'internship_admin',
    'adviser',
    'hte_adviser',
    'internship_adviser'
]);

$hideAdviserNav = in_array($role, [
    'admin',
    'superadmin',
    'internship_admin',
    'adviser',
]);

$hideFromAdviser = in_array($role, [
    'adviser',
    'hte_adviser',
    'internship_adviser'
]);

$roleMap = [
    'student' => 'student',
    'internship_adviser' => 'adviser',
    'HTE_adviser' => 'adviser',
    'hte_adviser' => 'adviser',
    'adviser' => 'adviser',
    'internship_admin' => 'admin',
    'superadmin' => 'admin',
    'admin' => 'admin'
];

$helpRoleMap = [
    'student' => 'student',
    'internship_adviser' => 'ojt_adviser',
    'hte_adviser' => 'hte_adviser',
    'adviser' => 'ojt_adviser',
    'internship_admin' => 'intern_admin',
    'superadmin' => 'sys_admin',
    'admin' => 'sys_admin',
];
$currentRole = $helpRoleMap[$role] ?? 'student';

$userType = $roleMap[$role] ?? 'student';

// Fetch notifications
$stmt = $pdo->prepare("
    SELECT * FROM notifications
    WHERE user_id = ? AND user_type = ?
    ORDER BY created_at DESC
");
$stmt->execute([$user_id, $userType]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = ? AND user_type = ? AND is_read = FALSE
");
$stmt->execute([$user_id, $userType]);
$unread_count = $stmt->fetchColumn();

// Fetch profile info
if ($userType === 'student') {
    $profileStmt = $pdo->prepare("SELECT full_name, email FROM students WHERE id = ?");
} elseif ($userType === 'admin') {
    $profileStmt = $pdo->prepare("SELECT name AS full_name, email FROM admins WHERE id = ?");
} else {
    $profileStmt = $pdo->prepare("SELECT full_name, email FROM advisers WHERE id = ?");
}
$profileStmt->execute([$user_id]);
$profileUser = $profileStmt->fetch(PDO::FETCH_ASSOC);
$displayName = $profileUser['full_name'] ?? 'User';
$displayEmail = $profileUser['email'] ?? '';
$parts = explode(' ', $displayName);
$initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));

// Time helper
function timeAgo($datetime)
{
    $time = time() - strtotime($datetime);
    if ($time < 60)
        return "Just now";
    if ($time < 3600)
        return floor($time / 60) . " min ago";
    if ($time < 86400)
        return floor($time / 3600) . " hr ago";
    return floor($time / 86400) . " day ago";
}
?>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CEE IT CONNECTS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../index-style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body,
        html {
            font-family: 'Poppins', sans-serif;
        }

        /* ── NAVBAR ── */
        .navbar-custom {
            /* background: #2c3e67; */
            background: #272f54;
            padding: 12px 0;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 70px;
            z-index: 1000;
            border-bottom: 1px solid #1b1f32;
            box-shadow: 0 8px 6px -6px rgba(0, 0, 0, 0.3);
            /* position: sticky; */
            align-items: center;
            justify-content: space-between;
            flex-wrap: nowrap;
            /* overflow: hidden; */
        }

        .nav-logo {
            height: 60px;
            width: auto;
        }

        .brand-text {
            color: #ff6b2c;
            font-weight: 700;
            letter-spacing: 1px;
            font-size: 26px;
            font-family: 'Franklin Gothic Medium', sans-serif !important;
        }

        .navbar-nav .nav-link {
            color: white;
            font-weight: 600;
            font-size: 16px;
            transition: 0.2s;
            letter-spacing: .5px;
        }

        .navbar-nav .nav-link:hover {
            color: #00cfff;
        }

        .navbar-nav .nav-link.active {
            background: #ff6b2c;
            color: white;
            border-radius: 10px;
            padding: 10px 18px;
        }

        /* ── THREE-COLUMN LAYOUT ── */
        .navbar-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding-left: 18px;
            padding-right: 18px;
        }

        .navbar-brand {
            flex: 1;
        }

        .navbar-center {
            flex: 1;
            display: flex;
            justify-content: center;
        }

        /* ── RIGHT ICONS ── */
        .navbar-icons {
            flex: 1;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 18px;
            padding-right: 10px;
        }

        .navbar-icons i {
            color: white;
            font-size: 20px;
        }

        .navbar-icons i:hover {
            color: #00cfff;
        }

        .icon-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            color: white;
            font-size: 20px;
            display: flex;
            align-items: center;
        }

        .icon-btn:hover {
            color: #00cfff;
        }

        /* ── NOTIF BADGE ── */
        .notif-badge {
            position: absolute;
            top: -5px;
            right: -8px;
            background: red;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 50%;
        }


        [data-tip] {
            position: relative;
        }

        [data-tip]::after {
            content: attr(data-tip);
            position: absolute;
            top: 120%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(39, 47, 84, 0.85);
            color: #fff;
            font-size: 12px;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 6px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: opacity .15s ease;
            pointer-events: none;
            z-index: 999;
        }

        [data-tip]:hover::after {
            opacity: 1;
            visibility: visible;
        }

        /* ── NOTIF POPUP ── */
        .notif-popup {
            display: none;
            position: absolute;
            right: 0;
            top: 35px;
            width: 320px;
            background: #faf7f7;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            z-index: 999;
            max-height: 400px;
            overflow-y: auto;
        }

        .notif-subtitle {
            color: #8a92a6;
            margin-bottom: 10px;
        }

        .notif-item {
            display: flex;
            gap: 10px;
            padding: 10px 8px;
        }

        .notif-item:hover {
            background: #ffd280;
            cursor: pointer;
            border-radius: 8px;
            margin-left: -15px;
            margin-right: -15px;
            padding-left: 15px;
            padding-right: 15px;
        }


        /* .notif-popup {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 12px 32px rgba(0,0,0,0.18);
            padding: 0 0 8px 0;
            width: 340px;
            max-height: 420px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .notif-popup-header {
            background: linear-gradient(135deg,#272f54,#1e2647);
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        .notif-popup-header-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .notif-popup-header-icon i {
            color: #ff9d5c;
            font-size: 16px;
        }

        .notif-popup-header h5 {
            margin: 0 0 2px 0;
            font-size: 15px;
            font-weight: 700;
            color: #fff;
        }

        .notif-popup-list {
            overflow-y: auto;
            flex: 1;
            padding-top: 10px;
        }
        .notif-item:first-child {
            margin-top: 10px;
        }

        .notif-subtitle {
            margin: 0;
            font-size: 12.5px;
            color: #9aa3c7;
        }

        .notif-subtitle {
            padding: 0 20px;
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 14px;
        }

        .notif-popup hr {
            margin: 0 0 4px 0;
            border-color: #f1f5f9;
        }

        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 20px;
            cursor: pointer;
            border-bottom: 1px solid #f8fafc;
            position: relative;
            transition: background 0.15s ease;
        }

        .notif-item:hover {
            background: #f8fafc;
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-item .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ff6b2c;
            flex-shrink: 0;
            margin-top: 6px;
        }

        .notif-item > div:last-child {
            flex: 1;
            min-width: 0;
        }

        .notif-item strong {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 2px;
        }

        .notif-item p {
            font-size: 12.5px;
            color: #64748b;
            line-height: 1.5;
            margin-bottom: 4px;
        }

        .notif-item small {
            font-size: 11px;
            color: #cbd5e1;
        }

        .notif-popup::-webkit-scrollbar {
            width: 6px;
        }

        .notif-popup::-webkit-scrollbar-thumb {
            background: #e2e8f0;
            border-radius: 10px;
        } */

        .dot {
            width: 8px;
            height: 8px;
            background: #ff3c00;
            border-radius: 50%;
            margin-top: 6px;
            flex-shrink: 0;
        }

        /* ── PROFILE DROPDOWN ── */
        .profile-wrapper {
            position: relative;
        }

        .profile-drop {
            display: none;
            position: absolute;
            right: 0;
            top: 40px;
            width: 220px;
            background: white;
            border-radius: 14px;
            padding: 20px 16px 14px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            z-index: 999;
            text-align: center;
            flex-direction: column;
            align-items: center;
        }

        .profile-drop.open {
            display: flex;
        }

        .p-name {
            font-size: 14px;
            font-weight: 700;
            color: #272f54;
            margin-bottom: 2px;
        }

        .p-email {
            font-size: 12px;
            color: #888;
            margin-bottom: 14px;
            word-break: break-all;
        }

        .profile-drop .p-name {
            color: #272f54 !important;
        }

        .profile-drop .p-email {
            color: #888 !important;
        }

        .btn-update {
            background: #FFE7B3 !important;
            color: #7a5200 !important;
            border: none !important;
            transition: background-color .15s ease, color .15s ease;
            padding: 4px 18px;
            border-radius: 8px;
            font-size: 0.6 rem;
        }

        .btn-update i {
            color: #7a5200 !important;
            width: 14px;
            height: 14px;
        }

        .btn-update:hover {
            background: #E4572E !important;
            color: #fff !important;
        }

        .btn-update:hover i {
            color: #fff !important;
        }

        .logout {
            font-size: 13px;
            color: #e74c3c;
            text-decoration: none;
            font-weight: 500;
        }

        .logout:hover {
            text-decoration: underline;
        }

        /* addtl s */
        /* ── MOBILE MENU ── */
        #mobileMenu {
            display: none;
            position: absolute;
            top: 78px;
            right: 12px;
            width: 230px;
            background: #272f54;
            border: 1px solid #1b1f32;
            border-radius: 14px;
            padding: 8px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.35);
            z-index: 999;
        }

        #mobileMenu.open {
            display: block;
        }

        #mobileMenu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            border-radius: 10px;
        }

        #mobileMenu a i {
            width: 18px;
            text-align: center;
        }

        #mobileMenu a:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        #mobileMenu a.active {
            background: #ff6b2c;
        }

        @media (min-width: 992px) {
            #mobileMenu {
                display: none !important;
            }
        }
        /* addtl e */

        @media (max-width: 768px) {
            .brand-text {
                font-size: 20px;
                font-family: 'Franklin Gothic Medium', sans-serif !important;
                ;
            }

            .nav-logo {
                height: 46px;
            }

            .navbar-icons {
                gap: 15px;
            }

            .navbar-icons i {
                font-size: 15px;
            }

            .notif-popup {
                width: 260px;
                right: -60px;
            }
        }

        .hg-hero{display:flex;align-items:center;gap:14px;background:linear-gradient(135deg,#272f54,#33448f);color:#fff;border-radius:14px;padding:18px 20px;border-left:4px solid #FFB62F;margin-bottom:18px}
        .hg-hero-icon{width:46px;height:46px;border-radius:12px;background:rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
        .hg-hero-title{font-size:17px;font-weight:700}
        .hg-hero-sub{font-size:12.5px;opacity:.85;margin-top:2px}
        .hg-label{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8b93ab;margin:0 0 10px}
        .hg-flow{display:flex;flex-wrap:wrap;align-items:center;gap:8px 6px;margin-bottom:22px}
        .hg-flow-step{display:inline-flex;align-items:center;gap:8px;background:#f4f6ff;border:1px solid #e8eaf0;border-radius:999px;padding:5px 14px 5px 5px;font-size:12.5px;font-weight:600;color:#272f54}
        .hg-flow-num{width:22px;height:22px;border-radius:50%;background:#ff6b2c;color:#fff;font-size:11px;display:flex;align-items:center;justify-content:center}
        .hg-flow-arrow{color:#cbd5e1;font-size:12px}
        .hg-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .hg-card{border:1px solid #e8eaf0;border-radius:12px;padding:14px 16px;background:#fff}
        .hg-card-head{display:flex;align-items:center;gap:12px;margin-bottom:10px}
        .hg-card-ic{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
        .hg-card-title{font-weight:700;font-size:13.5px;color:#272f54}
        .hg-where{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:#64748b;background:#f1f3f8;border-radius:6px;padding:2px 8px;margin-top:3px}
        .hg-steps{margin:0;padding-left:18px}
        .hg-steps li{font-size:12.5px;color:#444;line-height:1.65}
        .hg-tip{margin-top:16px;background:#fff8f0;border:1px solid #ffd8a8;border-radius:12px;padding:12px 16px;font-size:12.5px;color:#7a3f00;display:flex;gap:10px;align-items:flex-start}
        .hg-tip i{color:#c46a00;font-size:16px;margin-top:1px}
        @media (max-width:640px){.hg-grid{grid-template-columns:1fr}}
    </style>
</head>

<nav class="navbar navbar-expand-lg navbar-custom fixed-top">
    <div class="container-fluid px-3">
        <a class="navbar-brand d-flex align-items-center" href="<?php if ($role == 'students') {
            echo 'index.php';
        } else if ($role == 'superadmin' || $role == 'sys_admin' || $role == 'admin') {
            echo 'superadmin.php';
        } else if ($role == 'internship_admin') {
            echo 'internship-ui.php';
        } else if ($role == 'hte_adviser') {
            echo 'hte-ui.php';
        } else if ($role == 'internship_adviser') {
            echo 'ojt-rooms.php';
        } else {
            echo 'index.php';
        }
        ?>">
            <img src="/Sources/CEE IT Connects Logo.png" class="nav-logo">
            <span class="brand-text ms-1">CEE IT CONNECTS</span>
        </a>

        <!-- Center Menu (students only) -->
        <?php if (!$hideStudentNav): ?>
            <div class="navbar-center d-none d-lg-flex">
                <ul class="navbar-nav gap-5">
                    <li class="nav-item">
                        <a class="nav-link <?= ($page == 'home') ? 'active' : '' ?>" href="<?php
                                if ($role == 'students') {
                                    echo 'index.php';
                                } else if ($role == 'hte_adviser') {
                                    echo 'hte-ui.php';
                                } else if ($role == 'internship_adviser') {
                                    echo 'ojt-rooms.php';
                                } else {
                                    echo 'index.php';

                                } ?>">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page == 'opportunity') ? 'active' : '' ?>"
                            href="/applied-Internship-programs.php">Internships</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page == 'announcements') ? 'active' : '' ?>"
                            href="announcement.php">Announcements</a>
                    </li>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Right Icons -->
        <div class="navbar-icons d-flex align-items-center position-relative">

            <!-- Messages (students only) -->
            <?php if (!$hideFromAdviser): ?>
                <div>
                    <a href="<?php
                    if ($role == 'student') {
                        echo 'message.php';
                    } else if ($role == 'superadmin' || $role == 'admin') {
                        echo 'systemadmin-rooms.php';
                    } else if ($role == 'internship_admin') {
                        echo 'internadmin-rooms.php';
                    } else {
                        echo 'message.php';

                    } ?>" class="navbar-icon-btn" data-tip="Rooms">
                        <i class="fa-solid fa-users"></i>
                    </a>
                </div>
            <?php endif; ?>
            <!-- addtl s -->
            <a onclick="openHelpModal()" class="navbar-icon-btn" data-tip="Help &amp; Guide">
                <i class="fa-regular fa-circle-question"></i>
            </a>
            <!-- addtl e -->

            <!-- Bell + Notification popup -->
            <div class="position-relative" data-tip="Notifications">
                <i class="fa-regular fa-bell" id="notifBell" style="cursor:pointer;"></i>

                <?php if ($unread_count > 0): ?>
                    <span class="notif-badge"><?= $unread_count ?></span>
                <?php endif; ?>

                <div id="notifPopup" class="notif-popup">
                    <h5><strong>Notifications</strong></h5>
                    <p class="notif-subtitle">You have <?= $unread_count ?> new notifications</p>
                    <hr>

                    <?php foreach ($notifications as $notif): ?>
                        <div class="notif-item"
                            onclick="window.location.href='<?= htmlspecialchars($notif['link'] ?? 'index.php') ?>'">
                            <?php if (!$notif['is_read']): ?>
                                <div class="dot"></div><?php endif; ?>
                            <div>
                                <strong><?= htmlspecialchars($notif['title']) ?></strong>
                                <p class="mb-0 small text-muted"><?= htmlspecialchars($notif['message']) ?></p>
                                <small><?= timeAgo($notif['created_at']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- <div id="notifPopup" class="notif-popup">
                    <div class="notif-popup-header">
                        <div class="notif-popup-header-icon"><i class="fa fa-bell"></i></div>
                        <div>
                            <h5><strong>Notifications</strong></h5>
                            <p class="notif-subtitle">You have <?= $unread_count ?> new notifications</p>
                        </div>
                    </div>

                    <div class="notif-popup-list">
                        <?php foreach ($notifications as $notif): ?>
                            <div class="notif-item"
                                onclick="window.location.href='<?= htmlspecialchars($notif['link'] ?? 'index.php') ?>'">
                                <?php if (!$notif['is_read']): ?>
                                    <div class="dot"></div><?php endif; ?>
                                <div>
                                    <strong><?= htmlspecialchars($notif['title']) ?></strong>
                                    <p class="mb-0 small text-muted"><?= htmlspecialchars($notif['message']) ?></p>
                                    <small><?= timeAgo($notif['created_at']) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div> -->
            </div>

            <!-- Profile dropdown -->
            <div class="profile-wrapper" data-tip="Profile">
                <button class="icon-btn" id="profileBtn">
                    <i class="fa-<?= ($page == 'profile') ? 'solid' : 'regular' ?> fa-user"></i>
                </button>

                <div class="profile-drop" id="profileDrop">
                    <div style="width:52px;height:52px;border-radius:50%;background: #d3dbfb;color:#272f54;
                                display:flex;align-items:center;justify-content:center;
                                font-size:18px;font-weight:700;margin-bottom:8px;">
                        <?= htmlspecialchars($initials) ?>
                    </div>
                    <div class="p-name"><?= htmlspecialchars($displayName) ?></div>
                    <div class="p-email"><?= htmlspecialchars($displayEmail) ?></div>
                    <button class="btn-update" style="padding: 2px 12px !important;"
                        onclick="window.location.href='personal-information.php'">
                        <i class="bi bi-pencil-square" style="width: 18px; height: 18px;"></i> Edit Profile
                    </button>
                    <a href="logout.php" class="logout">Log Out?</a>
                </div>
            </div>

            <!-- Mobile menu toggle (students only) -->
            <?php if (!$hideStudentNav): ?>
                <button id="mobileMenuToggle" class="d-lg-none"
                    style="border:none; background:transparent; cursor:pointer;">
                    <i class="bi bi-list" style="color:white; font-size:24px;"></i>
                </button>
            <?php endif; ?>

        </div>
    </div>

    <!-- ===================== HELP MODAL ===================== -->
    <div id="helpModal"
        style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.45);align-items:flex-start;justify-content:center;padding-top:40px;">
        <div
            style="background:#fff;border-radius:16px;width:min(900px,95vw);max-height:85vh;display:flex;flex-direction:column;overflow:hidden;">

            <!-- Header -->
            <div
                style="display:flex;align-items:center;justify-content:space-between;padding:22px 28px 18px;border-bottom:1.5px solid #e8eaf0;flex-shrink:0;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div
                        style="width:40px;height:40px;border-radius:10px;background:#eef1ff;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-question-circle-fill" style="color:#272f54;font-size:20px;"></i>
                    </div>
                    <div>
                        <div style="font-size:17px;font-weight:700;color:#272f54;">Help &amp; User Guide</div>
                        <div style="font-size:12px;color:#888;margin-top:1px;"> <?php
                        $roleLabels = [
                            'student' => 'Logged in as: Student',
                            'ojt_adviser' => 'Logged in as: OJT / Internship Adviser',
                            'hte_adviser' => 'Logged in as: HTE Adviser',
                            'intern_admin' => 'Logged in as: Internship Admin',
                            'sys_admin' => 'Logged in as: System Admin',
                        ];
                        echo htmlspecialchars($roleLabels[$currentRole] ?? 'User Guide');
                        ?>
                        </div>
                    </div>
                </div>
                <button onclick="closeHelpModal()"
                    style="border:none;background:none;cursor:pointer;font-size:22px;color:#aaa;line-height:1;padding:4px 8px;">&times;</button>
            </div>

            <!-- Body -->
            <div style="overflow-y:auto;padding:24px 28px;flex:1;">

                <?php
                $helpGuides = [
                    'student' => [
                        'title' => 'Student Guide',
                        'icon' => 'bi-mortarboard-fill',
                        'tagline' => 'From finding a company to finishing your OJT.',
                        'flow' => ['Pick company', 'Requirements', 'Join room', 'Log hours', 'Progress report', 'Evaluation'],
                        'cards' => [
                            ['bi-funnel-fill', 'Find an internship', 'Top bar › Internships', [
                                'Use the filters (program, classification, location) to narrow the list.',
                                'Click <strong>Read More</strong> for requirements, schedule and contact details.',
                                'Open the map on <strong>Home</strong> to see company locations and get directions.',
                            ]],
                            ['bi-building', 'Pick your host company', 'Home › Checklist', [
                                'Choose the company where you will do your OJT.',
                                'The Checklist marks this step <strong>Done</strong> once it is saved.',
                            ]],
                            ['bi-list-check', 'Complete your requirements', 'Sidebar › Checklist', [
                                'Upload proof for each requirement (MOU, Recommendation Letter, Waiver and the other forms).',
                                'Preview or download the generated documents where available.',
                                'Once the MOU, Recommendation Letter and Waiver are done, <strong>Hours</strong> and <strong>Progress Report</strong> unlock.',
                            ]],
                            ['bi-clock-history', 'Log your hours', 'Sidebar › Hours', [
                                'Enter your morning and afternoon time in/out for each day.',
                                'Your adviser and HTE supervisor can see them on the Timesheet.',
                                'Late or early time-outs are flagged against the company office hours.',
                            ]],
                            ['bi-file-earmark-text-fill', 'Submit progress reports', 'Sidebar › Progress Report', [
                                'Upload your weekly report as a file.',
                                'Your OJT adviser can view every week you submit.',
                            ]],
                            ['bi-clipboard-check', 'Evaluation', 'Sidebar › Evaluation', [
                                'Open it once you have finished your required hours.',
                                'Complete the evaluation form shown there.',
                            ]],
                            ['bi-door-open-fill', 'Your OJT room', 'Sidebar › Rooms', [
                                'Open your room to read updates posted by your adviser.',
                                'Check classmates and advisers under the <strong>Members</strong> tab.',
                            ]],
                            ['bi-chat-dots-fill', 'Chats and notifications', 'Sidebar › Chats · top bar bell', [
                                'Pick your adviser or supervisor and click <strong>Send</strong>.',
                                'The bell shows new updates. Click one to jump to the page.',
                            ]],
                        ],
                        'tip' => 'Hours and Progress Report stay locked until your MOU, Recommendation Letter and Waiver are marked done.',
                    ],

                    'ojt_adviser' => [
                        'title' => 'OJT Adviser Guide',
                        'icon' => 'bi-person-badge-fill',
                        'tagline' => 'Run your section room and track every intern.',
                        'flow' => ['Open room', 'Post updates', 'Check requirements', 'Monitor hours', 'Review reports'],
                        'cards' => [
                            ['bi-speedometer2', 'Home dashboard', 'Sidebar › Home', [
                                'See your student count, incomplete requirements, weekly reports and available documents.',
                                'Charts show attendance, hours rendered and requirement completion.',
                            ]],
                            ['bi-megaphone-fill', 'Post updates', 'Sidebar › ROOMS › Updates tab', [
                                'Pick your room under <strong>ROOMS</strong>.',
                                'Type in the box and click <strong>Post Announcement</strong>. Everyone in the room sees it.',
                            ]],
                            ['bi-people-fill', 'Manage members', 'Room › Members tab', [
                                'Click <strong>Add Participant</strong>, tick the people and confirm.',
                                'Hover a member card and click the <strong>✕</strong> to remove them.',
                            ]],
                            ['bi-list-ul', 'Requirements', 'Sidebar › Requirements', [
                                'Search by name, student no. or company, or filter by progress.',
                                'Click <strong>View</strong> to preview or download each uploaded document.',
                            ]],
                            ['bi-calendar-check-fill', 'OJT Status', 'Sidebar › OJT Status', [
                                'View each intern\'s hours and progress bar.',
                                'The <strong>Supervisor Eval</strong> button turns active once the supervisor submits.',
                            ]],
                            ['bi-file-earmark-text-fill', 'Progress Reports', 'Sidebar › Progress Reports', [
                                'Click <strong>View</strong> on a student, then pick a week.',
                                'Use <strong>Open</strong> or <strong>Download</strong> on the preview.',
                            ]],
                            ['bi-folder2-open', 'Documents Available', 'Sidebar › Documents Available', [
                                'Check which partner companies have the MOU, Recommendation Letter and Waiver ready.',
                            ]],
                            ['bi-chat-left-text-fill', 'Chats', 'Sidebar › Chats', [
                                'Message your students and the HTE supervisors linked to them.',
                            ]],
                        ],
                        'tip' => 'Required OJT hours are set per program by the System Administrator. Advisers cannot change them.',
                    ],

                    'hte_adviser' => [
                        'title' => 'HTE Adviser Guide',
                        'icon' => 'bi-building',
                        'tagline' => 'Supervise interns at your company and complete their evaluation.',
                        'flow' => ['Assign supervisor', 'Monitor hours', 'Review timesheet', 'Evaluation'],
                        'cards' => [
                            ['bi-speedometer2', 'Home dashboard', 'Sidebar › Home', [
                                'See interns, who is present today and who finished their hours.',
                                'Latest timesheet logs and attendance charts are shown below.',
                            ]],
                            ['bi-calendar-check-fill', 'OJT Status', 'Sidebar › OJT Status', [
                                'View each intern\'s rendered hours and progress.',
                                'Use the search box to find a student.',
                            ]],
                            ['bi-person-plus-fill', 'Assign a supervisor', 'OJT Status › Assign Supervisor', [
                                'Click <strong>Assign Supervisor</strong> beside the intern.',
                                'Enter the supervisor name and email, then <strong>Save Supervisor</strong>.',
                            ]],
                            ['bi-envelope-paper-fill', 'Supervisor evaluation', 'OJT Status › Actions', [
                                'When an intern reaches the required hours, the evaluation email is sent to the supervisor automatically.',
                                'Use <strong>Resend Email</strong> to send it again, or <strong>Fill Manually</strong> to answer it yourself.',
                                'Use <strong>Preview PDF</strong>, then <strong>Submit Evaluation</strong>. A <strong>Supervisor Eval</strong> PDF button then appears.',
                            ]],
                            ['bi-clock-fill', 'Timesheet', 'Sidebar › Timesheet › eye icon', [
                                'The popup shows progress, <strong>Office hours</strong>, and Late / Overtime / On-time counts.',
                                'The weekly tables show a status per day: <strong>On time</strong>, <strong>Late</strong>, <strong>Undertime</strong>, <strong>Late + Undertime</strong> or <strong>Incomplete</strong>.',
                            ]],
                            ['bi-inbox-fill', 'Intern information', 'Sidebar › Information', [
                                'View student number, program, year and section, contact, company and adviser.',
                            ]],
                            ['bi-door-open-fill', 'Your room', 'Sidebar › ROOMS', [
                                'Open a room to read updates and see its members.',
                            ]],
                            ['bi-chat-dots-fill', 'Chats', 'Sidebar › Chats', [
                                'Message your interns and their OJT advisers.',
                            ]],
                        ],
                        'tip' => 'The evaluation email goes out automatically the first time this page loads after an intern reaches the required hours and has a supervisor assigned.',
                    ],

                    'intern_admin' => [
                        'title' => 'Internship Admin Guide',
                        'icon' => 'bi-clipboard-data-fill',
                        'tagline' => 'Publish postings, manage documents and keep students informed.',
                        'flow' => ['Post internship', 'Upload MOU', 'Announce documents', 'Track interns', 'Announce'],
                        'cards' => [
                            ['bi-speedometer2', 'Overview', 'Sidebar › Home', [
                                'See applications, postings, announcements and document counts.',
                                'Charts show intern phases, requirements and applicants per program.',
                            ]],
                            ['bi-briefcase-fill', 'Post an internship', 'Sidebar › Postings', [
                                'Click <strong>Add Internship Post</strong> and fill in the details.',
                                'Set the <strong>OJT Schedule Time</strong> in/out, since Timesheets use it to mark late and undertime.',
                                'Search for the location or click the map to pin it, then <strong>Create Posting</strong>.',
                            ]],
                            ['bi-people-fill', 'Track interns', 'Sidebar › Interns', [
                                'See each student\'s phase: <strong>Internship Confirmed</strong>, <strong>In Progress</strong> or <strong>No Progress</strong>.',
                                'Filter by requirements (Complete / Incomplete) or program.',
                            ]],
                            ['bi-file-earmark-text-fill', 'Review documents', 'Sidebar › Documents', [
                                'Filter by student, document type or program.',
                                'Click the <strong>eye</strong> icon to open a submitted file.',
                            ]],
                            ['bi-file-text-fill', 'Upload an MOU', 'Sidebar › Upload MOU', [
                                'Click <strong>Upload MOU</strong>, choose the internship posting and attach a PDF (max 5 MB).',
                                'Use the <strong>eye</strong> or <strong>trash</strong> icon to view or delete it.',
                            ]],
                            ['bi-bookmark-fill', 'Document availability', 'Sidebar › Document Availability', [
                                'Click <strong>Post Document Availability</strong>.',
                                'Pick the internship and tick MOU, Recommendation Letter and/or Waiver.',
                            ]],
                            ['bi-bell-fill', 'Announcements', 'Sidebar › Manage Announcements', [
                                'Click <strong>Post Announcement</strong> and choose News, Updates or FAQs.',
                                'Edit a row directly, then click the <strong>save</strong> icon. Use the <strong>trash</strong> icon to delete.',
                            ]],
                            ['bi-door-open-fill', 'Department rooms', 'Top bar › Rooms icon', [
                                'Open a department room to post updates and add participants.',
                            ]],
                        ],
                        'tip' => 'Double-check the OJT schedule time on each posting. It is the basis for every Late and Undertime mark on the Timesheet.',
                    ],

                    'sys_admin' => [
                        'title' => 'System Admin Guide',
                        'icon' => 'bi-shield-lock-fill',
                        'tagline' => 'Set up the school year, accounts and rules for the whole platform.',
                        'flow' => ['Set sections', 'Import students', 'Create accounts', 'Assign advisers', 'Set OJT hours'],
                        'cards' => [
                            ['bi-speedometer2', 'Overview', 'Sidebar › Home', [
                                'See accounts, system admin seats and charts for roles, programs and activity.',
                                'Use <strong>Hide / Show Registration Link</strong> to control the login page link.',
                                'Download the dashboard as <strong>CSV</strong> or <strong>PDF</strong>.',
                            ]],
                            ['bi-ui-checks', 'Set up sections', 'Sidebar › Section', [
                                'Pick a school year (or <strong>+ Add new school year</strong>).',
                                'Enter how many sections each year level has, then <strong>Save</strong>.',
                            ]],
                            ['bi-person-vcard', 'Register students', 'Sidebar › Student Register', [
                                'Click <strong>Edit Current CSV</strong> to fix rows, then <strong>Save CSV</strong>.',
                                'Click <strong>Add to Database</strong> to create the student accounts.',
                                'Or <strong>Replace CSV</strong> with a new file. Use <strong>Download Template</strong> for the format.',
                            ]],
                            ['bi-person-gear', 'Create and manage accounts', 'Sidebar › Account Management', [
                                'Click <strong>Add Admin</strong> or <strong>Add Adviser</strong> and fill in the form.',
                                'HTE advisers need an internship. OJT advisers need a department.',
                                'Change an admin\'s role, then <strong>Update Roles</strong>. Only 3 System Admins are allowed.',
                            ]],
                            ['bi-person-lines-fill', 'Assign advisers', 'Sidebar › Assign Adviser', [
                                'Pick an open section for an OJT adviser and click <strong>Assign</strong>.',
                                'Click the <strong>✕</strong> on a section badge to unassign. This deletes the room and its members.',
                                'Use <strong>Import via CSV</strong> (student_id, adviser_id) to assign students in bulk.',
                            ]],
                            ['bi-clock-history', 'Required OJT hours', 'Sidebar › OJT Hours', [
                                'Enter the required hours for each program and click <strong>Save Changes</strong>.',
                                'It applies to every internship under that program.',
                            ]],
                            ['bi-calendar-event', 'Suspend dates', 'Sidebar › Calendar', [
                                'Pick a date, type the reason and click <strong>Add</strong>.',
                                'Click <strong>Remove</strong> to lift a suspension.',
                            ]],
                            ['bi-binoculars', 'Monitor, delete and restore', 'Sidebar › Monitor · Account Deletion · Archives', [
                                'Monitor lists recent actions. Filter by role or name.',
                                'Account Deletion removes a user. Archives lets you <strong>Restore</strong> archived accounts.',
                            ]],
                        ],
                        'tip' => 'Deleting an account is permanent. Archived accounts can be restored from Archives.',
                    ],
                ];

                $hg = $helpGuides[$currentRole] ?? $helpGuides['student'];
                $hgTones = [['#dbeafe', '#1e40af'], ['#ffe5d9', '#ff6b2c'], ['#ffe7b3', '#7a5200'], ['#d1fae5', '#065f46']];
                ?>

                <div class="hg-hero">
                    <div class="hg-hero-icon"><i class="bi <?= $hg['icon'] ?>"></i></div>
                    <div>
                        <div class="hg-hero-title"><?= htmlspecialchars($hg['title']) ?></div>
                        <div class="hg-hero-sub"><?= htmlspecialchars($hg['tagline']) ?></div>
                    </div>
                </div>

                <div class="hg-label">Your workflow</div>
                <div class="hg-flow">
                    <?php foreach ($hg['flow'] as $i => $f): ?>
                        <div class="hg-flow-step"><span class="hg-flow-num"><?= $i + 1 ?></span><?= htmlspecialchars($f) ?></div>
                        <?php if ($i < count($hg['flow']) - 1): ?><i class="bi bi-chevron-right hg-flow-arrow"></i><?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div class="hg-label">What you can do</div>
                <div class="hg-grid">
                    <?php foreach ($hg['cards'] as $i => [$icon, $title, $where, $steps]):
                        [$tbg, $tfg] = $hgTones[$i % count($hgTones)]; ?>
                        <div class="hg-card">
                            <div class="hg-card-head">
                                <div class="hg-card-ic" style="background:<?= $tbg ?>;color:<?= $tfg ?>;"><i class="bi <?= $icon ?>"></i></div>
                                <div>
                                    <div class="hg-card-title"><?= htmlspecialchars($title) ?></div>
                                    <span class="hg-where"><i class="bi bi-signpost-2"></i> <?= htmlspecialchars($where) ?></span>
                                </div>
                            </div>
                            <ol class="hg-steps">
                                <?php foreach ($steps as $s): ?>
                                    <li><?= $s ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="hg-tip">
                    <i class="bi bi-lightbulb-fill"></i>
                    <div><strong>Good to know:</strong> <?= htmlspecialchars($hg['tip']) ?></div>
                </div>
                <!-- Log Out — always shown for all roles -->
                <div
                    style="margin-top:10px;background:#fff8f0;border:1px solid #ffd8a8;border-radius:12px;padding:14px 18px;display:flex;align-items:flex-start;gap:12px;">
                    <i class="bi bi-box-arrow-right"
                        style="color:#c46a00;font-size:20px;margin-top:1px;flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700;font-size:13.5px;color:#7a3f00;margin-bottom:4px;">
                            Logging Out</div>
                        <ol style="margin:0;padding-left:18px;">
                            <li style="font-size:13px;color:#a05500;line-height:1.7;">
                                Click your <strong>profile
                                    icon</strong> at the top-right
                                corner of the page.</li>
                            <li style="font-size:13px;color:#a05500;line-height:1.7;">
                                The profile dropdown will appear
                                with your name and email.</li>
                            <li style="font-size:13px;color:#a05500;line-height:1.7;">
                                Click <strong>Log Out?</strong> at
                                the bottom of the dropdown.</li>
                            <li style="font-size:13px;color:#a05500;line-height:1.7;">
                                You will be redirected to the
                                login page. Always log out on shared
                                or public devices.</li>
                        </ol>
                    </div>
                </div>

            </div><!-- end body -->

            <!-- Footer -->
            <div
                style="padding:16px 28px;border-top:1.5px solid #e8eaf0;display:flex;justify-content:flex-end;flex-shrink:0;">
                <button onclick="closeHelpModal()"
                    style="padding:9px 24px;border-radius:8px;border:1px solid #d0d3e0;background:#fff;color:#272f54;font-size:13px;font-weight:700;cursor:pointer;">Close</button>
            </div>
        </div>
    </div>

    <!-- MOBILE DROPDOWN MENU -->
    <!-- <?php if (!$hideStudentNav): ?>
        <div id="mobileMenu"
            style="display: none;position: absolute;top: 70px; left: 0;width: 100%;background: #2c3e67;z-index: 999;padding: 10px 0;box-shadow: 0 8px 20px rgba(0,0,0,0.3);">
            <a href=" index.php" style="display:block;padding:14px 24px;color:white;text-decoration:none;font-weight:600;
            <?= ($page == 'home') ? 'background:#ff6b2c;border-radius:8px;margin:4px 12px;' : '' ?>">
                <i class="fa-solid fa-house me-2"></i> Home
            </a>
            <a href="applied-Internship-programs.php" style="display:block;padding:14px 24px;color:white;text-decoration:none;font-weight:600;
            <?= ($page == 'opportunity') ? 'background:#ff6b2c;border-radius:8px;margin:4px 12px;' : '' ?>">
                <i class="fa-solid fa-briefcase me-2"></i> Internships
            </a>
            <a href="announcement.php" style="display:block;padding:14px 24px;color:white;text-decoration:none;font-weight:600;
            <?= ($page == 'announcements') ? 'background:#ff6b2c;border-radius:8px;margin:4px 12px;' : '' ?>">
                <i class="fa-solid fa-bullhorn me-2"></i>
                Announcements
            </a>
        </div>
    <?php endif; ?> -->

    <!-- MOBILE DROPDOWN MENU -->
    <?php if (!$hideStudentNav): ?>
        <div id="mobileMenu">
            <a href="index.php" class="<?= ($page == 'home') ? 'active' : '' ?>">
                <i class="fa-solid fa-house"></i> Home
            </a>
            <a href="applied-Internship-programs.php" class="<?= ($page == 'opportunity') ? 'active' : '' ?>">
                <i class="fa-solid fa-briefcase"></i> Internships
            </a>
            <a href="announcement.php" class="<?= ($page == 'announcements') ? 'active' : '' ?>">
                <i class="fa-solid fa-bullhorn"></i> Announcements
            </a>
        </div>
    <?php endif; ?>
</nav>

<!-- <script>
    // Notification Elements
    const bell = document.getElementById("notifBell");
    const popup = document.getElementById("notifPopup");

    //Profile Elements
    const profileBtn = document.getElementById('profileBtn');
    const profileDrop = document.getElementById('profileDrop');

    // ── Notification bell ──
    bell.addEventListener("click", function (e) {
        e.stopPropagation();
        const isOpen = popup.style.display === "block";
        popup.style.display = isOpen ? "none" : "block";
        profileDrop.classList.remove('open');

        if (!isOpen) {
            fetch("mark-as-read.php")
                .then(res => res.text())
                .then(() => {
                    const badge = document.querySelector(".notif-badge");
                    if (badge) badge.remove();
                    document.querySelectorAll(".dot").forEach(d => d.remove());
                });
        }
    });

    document.addEventListener("click", function (e) {
        if (!popup.contains(e.target) && !bell.contains(e.target)) {
            popup.style.display = "none";
        }
    });

    // ── Profile dropdown ──
    profileBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        profileDrop.classList.toggle('open');
        popup.style.display = "none";
    });

    document.addEventListener('click', function (e) {
        if (!profileDrop.contains(e.target) && !profileBtn.contains(e.target)) {
            profileDrop.classList.remove('open');
        }
    });

    // ── Mobile menu toggle ──
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const mobileMenu = document.getElementById('mobileMenu');

    if (mobileToggle && mobileMenu) {
        mobileToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            mobileMenu.style.display = mobileMenu.style.display === 'block' ? 'none' : 'block';
        });

        document.addEventListener('click', function (e) {
            if (!mobileMenu.contains(e.target) && !mobileToggle.contains(e.target)) {
                mobileMenu.style.display = 'none';
            }
        });
    }
    function openHelpModal() {
        document.getElementById('helpModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
        var pd = document.getElementById('profileDrop');
        if (pd) pd.classList.remove('active');
    }
    function closeHelpModal() {
        document.getElementById('helpModal').style.display = 'none';
        document.body.style.overflow = '';
    }
    document.getElementById('helpModal').addEventListener('click', function (e) {
        if (e.target === this) closeHelpModal();
    });
</script> -->

<script>
    const bell = document.getElementById("notifBell");
    const popup = document.getElementById("notifPopup");
    const profileBtn = document.getElementById('profileBtn');
    const profileDrop = document.getElementById('profileDrop');
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const mobileMenu = document.getElementById('mobileMenu');

    function closeMobileMenu() {
        if (mobileMenu) mobileMenu.classList.remove('open');
    }

    // ── Notification bell ──
    bell.addEventListener("click", function (e) {
        e.stopPropagation();
        closeMobileMenu();
        const isOpen = popup.style.display === "block";
        popup.style.display = isOpen ? "none" : "block";
        profileDrop.classList.remove('open');

        if (!isOpen) {
            fetch("mark-as-read.php")
                .then(res => res.text())
                .then(() => {
                    const badge = document.querySelector(".notif-badge");
                    if (badge) badge.remove();
                    document.querySelectorAll(".dot").forEach(d => d.remove());
                });
        }
    });

    document.addEventListener("click", function (e) {
        if (!popup.contains(e.target) && !bell.contains(e.target)) {
            popup.style.display = "none";
        }
    });

    // ── Profile dropdown ──
    profileBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        closeMobileMenu();
        profileDrop.classList.toggle('open');
        popup.style.display = "none";
    });

    document.addEventListener('click', function (e) {
        if (!profileDrop.contains(e.target) && !profileBtn.contains(e.target)) {
            profileDrop.classList.remove('open');
        }
    });

    // ── Mobile menu toggle ──
    if (mobileToggle && mobileMenu) {
        mobileToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            popup.style.display = "none";
            profileDrop.classList.remove('open');
            mobileMenu.classList.toggle('open');
        });

        document.addEventListener('click', function (e) {
            if (!mobileMenu.contains(e.target) && !mobileToggle.contains(e.target)) {
                closeMobileMenu();
            }
        });
    }

    // ── Help modal ──
    function openHelpModal() {
        closeMobileMenu();
        popup.style.display = "none";
        profileDrop.classList.remove('open');
        document.getElementById('helpModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeHelpModal() {
        document.getElementById('helpModal').style.display = 'none';
        document.body.style.overflow = '';
    }
    document.getElementById('helpModal').addEventListener('click', function (e) {
        if (e.target === this) closeHelpModal();
    });
</script>