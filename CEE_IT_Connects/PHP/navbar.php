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

        .btn-update {
            background: #FFE7B3 !important;
            color: #7a5200 !important;
            border: none !important;
            transition: background-color .15s ease, color .15s ease;
            padding: 4px 18px;
            border-radius: 8px;
        }

        .btn-update i {
            color: #7a5200 !important;
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
        <?php if (!$hideAdviserNav): ?>
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
                <?php endif; ?>
                <?php if (!$hideStudentNav): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page == 'opportunity') ? 'active' : '' ?>"
                            href="/applied-Internship-programs.php">Internships</a>
                    </li>
                <?php endif; ?>
                <?php if (!$hideAdviserNav): ?>
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

                    } ?>">
                        <i class="fa-solid fa-desktop"></i>
                    </a>
                </div>
            <?php endif; ?>
            <!-- addtl s -->
            <a onclick="openHelpModal()" class="navbar-icon-btn" data-tip="Help &amp; Guide">
                <i class="fa-solid fa-circle-question"></i>
            </a>
            <!-- addtl e -->

            <!-- Bell + Notification popup -->
            <div class="position-relative">
                <i class="fa-regular fa-bell" id="notifBell" style="cursor:pointer;" data-tip="Notifications"></i>

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
            <div class="profile-wrapper">
                <button class="icon-btn" id="profileBtn" datatip="Profile">
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
                    <button class="btn-update" onclick="window.location.href='personal-information.php'">
                        <i class="bi bi-pencil-square"></i> Edit Profile
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
            style="background:#fff;border-radius:16px;width:min(720px,95vw);max-height:85vh;display:flex;flex-direction:column;overflow:hidden;">

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

                <?php if ($currentRole === 'student'): ?>
                    <!-- ======== STUDENT ======== -->
                    <div style="margin-bottom:18px;">
                        <div style="font-size:15px;font-weight:700;color:#272f54;margin-bottom:4px;">
                            <i class="bi bi-mortarboard-fill" style="margin-right:6px;"></i>Student User Guide
                        </div>
                    </div>
                    <?php
                    $sections = [
                        [
                            'bi-funnel-fill',
                            'View Internship Listings',
                            [
                                'Go to the <strong>Internships</strong> section from the navigation bar.',
                                'Use the filter options to narrow results by preferred criteria (company classification, location, department).',
                                'Browse the available internship opportunities.',
                            ]
                        ],
                        [
                            'bi-info-circle-fill',
                            'View Opportunity Details',
                            [
                                'Click on any internship listing<strong> Read More</strong> button to open its full details.',
                                'Review the complete, structured information including company, requirements, and schedule.',
                                'Use the provided contact information to inquire directly with the company if needed.',
                            ]
                        ],
                        [
                            'bi-hand-thumbs-up-fill',
                            'Express Interest in an Internship',
                            [
                                'Open the internship listing you are interested in.',
                                'Click the <strong>Interest</strong> button.',
                                'Confirm your application when prompted.',
                            ]
                        ],
                        [
                            'bi-file-earmark-text-fill',
                            'View Internship Documentation',
                            [
                                'Go to the <strong>Applications</strong> section from the Monitor section to track documents.',
                                'View all internship-related paperwork uploaded for your reference.',
                            ]
                        ],
                        [
                            'bi-file-earmark-arrow-down-fill',
                            'Generate Automated Documents',
                            [
                                'Navigate to the <strong>Applications</strong> section.',
                                'Click <strong>Preview</strong> or <strong>Download</strong> on the document type you need.',
                            ]
                        ],
                        [
                            'bi-geo-alt-fill',
                            'View Map & Locate Partner Institutions',
                            [
                                'Open the <strong>Home</strong> or search in <strong>Internships</strong> section for company location.',
                                'Browse partner company locations on the interactive map.',
                                'Get navigation directions.',
                            ]
                        ],
                        [
                            'bi-person-lines-fill',
                            'Submit Academic & Personal Information',
                            [
                                'Navigate to <strong>Profile</strong> or <strong>Edit Profile</strong>.',
                                'Fill in or update your academic and personal details.',
                                'Click <strong>Apply Changes</strong> to submit your information.',
                            ]
                        ],
                        [
                            'bi-card-checklist',
                            'View Application Status',
                            [
                                'Go to <strong>Applications</strong> from the Monitor section.',
                                'View the current status of each internship application.',
                            ]
                        ],
                        [
                            'bi-clipboard-check-fill',
                            'View and Input Status',
                            [
                                'Go to the <strong>Hours</strong> section on the Monitor section.',
                                'View and log hours rendered.',
                            ]
                        ],
                        [
                            'bi-door-open-fill',
                            'View OJT Room & Content',
                            [
                                'Open your assigned <strong>OJT Room</strong> from the Monitor section.',
                                'Read announcements posted by your OJT Adviser.',
                            ]
                        ],
                        [
                            'bi-bell-fill',
                            'Receive Real-Time Notifications',
                            [
                                'Notifications appear automatically on the platform.',
                                'You will be alerted for changes and postings.',
                                'Click a notification to go directly to the relevant page.',
                            ]
                        ],
                        [
                            'bi-chat-dots-fill',
                            'Message a User',
                            [
                                'Go to the <strong>Chats</strong> section from the sidebar of Monitor section.',
                                'Select the user you want to contact.',
                                'Type your message and click <strong>Send</strong>.',
                            ]
                        ],
                    ];
                    foreach ($sections as [$icon, $title, $steps]): ?>
                        <div style="margin-bottom:16px;border:1px solid #e8eaf0;border-radius:12px;overflow:hidden;">
                            <div
                                style="background:#f4f6ff;padding:11px 16px;display:flex;align-items:center;gap:9px;border-bottom:1px solid #e8eaf0;">
                                <i class="bi <?= $icon ?>" style="color:#272f54;font-size:15px;flex-shrink:0;"></i>
                                <span style="font-weight:700;font-size:13.5px;color:#272f54;">
                                    <?= $title ?>
                                </span>
                            </div>
                            <div style="padding:12px 16px;">
                                <ol style="margin:0;padding-left:18px;">
                                    <?php foreach ($steps as $step): ?>
                                        <li style="font-size:13px;color:#444;line-height:1.7;margin-bottom:2px;">
                                            <?= $step ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </div>
                    <?php endforeach; ?>

                <?php elseif ($currentRole === 'ojt_adviser'): ?>
                    <!-- ======== OJT ADVISER ======== -->
                    <div style="margin-bottom:18px;">
                        <div style="font-size:15px;font-weight:700;color:#272f54;margin-bottom:4px;">
                            <i class="bi bi-person-badge-fill" style="margin-right:6px;"></i>OJT Adviser User Guide
                        </div>
                    </div>
                    <?php
                    $sections = [
                        [
                            'bi-people-fill',
                            'Add Participants to a Room',
                            [
                                'Open an existing room from the Rooms list.',
                                'Click <strong>Add Participants</strong>.',
                                'Search for and select the students to add manually.',
                                'Confirm to join them in the room.',
                            ]
                        ],
                        [
                            'bi-clock-history',
                            'Monitor Rendered OJT Hours & Remarks',
                            [
                                'Go to the <strong>Status</strong> tab in the sidebar.',
                                'View each student\'s total rendered OJT hours and progress bar.',
                                'Check HTE Adviser remarks submitted for each student.',
                            ]
                        ],
                        [
                            'bi-megaphone-fill',
                            'Post Announcements',
                            [
                                'Open the room where you want to post and type in the text box.',
                                'Enter a title and compose your message using the text editor.',
                                'Click <strong>Post Announcement</strong> to publish to all room participants.',
                            ]
                        ],
                        [
                            'bi-chat-left-text-fill',
                            'Communicate with HTE Advisers and Students',
                            [
                                'Go to the <strong>Chats</strong> section.',
                                'Select an HTE Adviser or student from the contact list.',
                                'Type your message and click <strong>Send</strong> for real-time messaging.',
                            ]
                        ],
                    ];
                    foreach ($sections as [$icon, $title, $steps]): ?>
                        <div style="margin-bottom:16px;border:1px solid #e8eaf0;border-radius:12px;overflow:hidden;">
                            <div
                                style="background:#f4f6ff;padding:11px 16px;display:flex;align-items:center;gap:9px;border-bottom:1px solid #e8eaf0;">
                                <i class="bi <?= $icon ?>" style="color:#272f54;font-size:15px;flex-shrink:0;"></i>
                                <span style="font-weight:700;font-size:13.5px;color:#272f54;">
                                    <?= $title ?>
                                </span>
                            </div>
                            <div style="padding:12px 16px;">
                                <ol style="margin:0;padding-left:18px;">
                                    <?php foreach ($steps as $step): ?>
                                        <li style="font-size:13px;color:#444;line-height:1.7;margin-bottom:2px;">
                                            <?= $step ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </div>
                    <?php endforeach; ?>

                <?php elseif ($currentRole === 'hte_adviser'): ?>
                    <!-- ======== HTE ADVISER ======== -->
                    <div style="margin-bottom:18px;">
                        <div style="font-size:15px;font-weight:700;color:#272f54;margin-bottom:4px;">
                            <i class="bi bi-building" style="margin-right:6px;"></i>HTE Adviser User
                            Guide
                        </div>
                        <p style="font-size:13px;color:#666;margin:0;line-height:1.6;">
                            As an HTE Adviser, you are the on-site supervisor at the Host Training
                            Establishment. Your main
                            responsibilities involve evaluating students and updating their progress.
                        </p>
                    </div>
                    <?php
                    $sections = [
                        [
                            'bi-pencil-square',
                            'Submit Remarks & Feedback on Students',
                            [
                                'From your dashboard, go to the <strong>Remarks</strong> section.',
                                'Select or search the student you want to evaluate.',
                                'Click <strong>Save</strong> to submit your evaluation.',
                            ]
                        ],
                        [
                            'bi-stopwatch-fill',
                            'Confirm Student Rendered OJT Hours',
                            [
                                'Open the <strong>Status</strong> section from your dashboard.',
                                'Select the student whose hours you want to update.',
                                'Click <strong>Confirm</strong> to save the changes.',
                            ]
                        ],
                        [
                            'bi-chat-dots-fill',
                            'Communicate with Students & OJT Advisers',
                            [
                                'Go to the <strong>Chats</strong> section.',
                                'Select a student or an OJT Adviser from your contact list.',
                                'Type your message and click <strong>Send</strong>.',
                            ]
                        ],
                    ];
                    foreach ($sections as [$icon, $title, $steps]): ?>
                        <div style="margin-bottom:16px;border:1px solid #e8eaf0;border-radius:12px;overflow:hidden;">
                            <div
                                style="background:#f4f6ff;padding:11px 16px;display:flex;align-items:center;gap:9px;border-bottom:1px solid #e8eaf0;">
                                <i class="bi <?= $icon ?>" style="color:#272f54;font-size:15px;flex-shrink:0;"></i>
                                <span style="font-weight:700;font-size:13.5px;color:#272f54;">
                                    <?= $title ?>
                                </span>
                            </div>
                            <div style="padding:12px 16px;">
                                <ol style="margin:0;padding-left:18px;">
                                    <?php foreach ($steps as $step): ?>
                                        <li style="font-size:13px;color:#444;line-height:1.7;margin-bottom:2px;">
                                            <?= $step ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </div>
                    <?php endforeach; ?>

                <?php elseif ($currentRole === 'intern_admin'): ?>
                    <!-- ======== INTERNSHIP ADMIN ======== -->
                    <div style="margin-bottom:18px;">
                        <div style="font-size:15px;font-weight:700;color:#272f54;margin-bottom:4px;">
                            <i class="bi bi-clipboard-data-fill" style="margin-right:6px;"></i>Internship Admin User Guide
                        </div>
                    </div>
                    <?php
                    $sections = [
                        [
                            'bi-briefcase-fill',
                            'Post Internship Opportunities',
                            [
                                'Navigate to <strong>Postings</strong>.',
                                'Click <strong>Add Internship Post</strong> and fill in all required details.',
                                'Ensure complete and validated information.',
                                'Click <strong>Create Posting</strong> to make it visible to students.',
                            ]
                        ],
                        [
                            'bi-eye-fill',
                            'Monitor Students Who Expressed Interest',
                            [
                                'Open an internship posting.',
                                'Go to the <strong>Interested Students</strong> tab.',
                                'View the list of students who expressed interest in a company.',
                                'Return with feedback if needed.'
                            ]
                        ],
                        [
                            'bi-megaphone-fill',
                            'Update System Announcements',
                            [
                                'Navigate to the <strong>Announcements</strong> section.',
                                'Compose your message and click <strong>Post Announcement</strong>.',
                            ]
                        ],
                        [
                            'bi-newspaper',
                            'Manage Informational Content',
                            [
                                'Go to <strong>Management Announcements</strong> from the sidebar.',
                                'Edit or delete informational articles and announcements.',
                                'Click <strong>Save</strong> icon to make changes visible to users.',
                            ]
                        ],
                        [
                            'bi-card-checklist',
                            'Track Student Applications & Documentation',
                            [
                                'Go to <strong>Documents</strong> from the sidebar.',
                                'View each student\'s application and submitted documents.',
                            ]
                        ],
                    ];
                    foreach ($sections as [$icon, $title, $steps]): ?>
                        <div style="margin-bottom:16px;border:1px solid #e8eaf0;border-radius:12px;overflow:hidden;">
                            <div
                                style="background:#f4f6ff;padding:11px 16px;display:flex;align-items:center;gap:9px;border-bottom:1px solid #e8eaf0;">
                                <i class="bi <?= $icon ?>" style="color:#272f54;font-size:15px;flex-shrink:0;"></i>
                                <span style="font-weight:700;font-size:13.5px;color:#272f54;">
                                    <?= $title ?>
                                </span>
                            </div>
                            <div style="padding:12px 16px;">
                                <ol style="margin:0;padding-left:18px;">
                                    <?php foreach ($steps as $step): ?>
                                        <li style="font-size:13px;color:#444;line-height:1.7;margin-bottom:2px;">
                                            <?= $step ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </div>
                    <?php endforeach; ?>

                <?php elseif ($currentRole === 'sys_admin'): ?>
                    <!-- ======== SYSTEM ADMIN ======== -->
                    <div style="margin-bottom:18px;">
                        <div style="font-size:15px;font-weight:700;color:#272f54;margin-bottom:4px;">
                            <i class="bi bi-shield-lock-fill" style="margin-right:6px;"></i>System Admin User
                            Guide
                        </div>
                        <p style="font-size:13px;color:#666;margin:0;line-height:1.6;">
                            As a System Admin, you have full platform oversight —
                            managing users, monitoring all activity,
                            and ensuring system integrity and compliance.
                        </p>
                    </div>
                    <?php
                    $sections = [
                        [
                            'bi-bar-chart-line-fill',
                            'Monitor Overall Internship Activities',
                            [
                                'Go to the <strong>Dashboard</strong> for a system-wide overview.',
                                'View internship postings, students interested, and announcements.',
                            ]
                        ],
                        [
                            'bi-person-gear',
                            'Create Admin, Adviser, and Student Accounts',
                            [
                                'Go to <strong>Add (user role)</strong> from the sidebar.',
                                'Click <strong>Create Account</strong> and select the <strong>Internship Admin</strong> role.',
                                'Fill in the required details and click <strong>Save</strong>.',
                            ]
                        ],
                        [
                            'bi-people-fill',
                            'Change Roles of All Admin Accounts',
                            [
                                'Go to <strong>Change Roles</strong>.',
                                'Edit role of any admin user account if they are an Internship Admin or a System Admin.',
                                'Assign or update roles and access permissions per user.',
                            ]
                        ],
                        [
                            'bi-layout-text-sidebar-reverse',
                            'Access All User Activities and Actions',
                            [
                                'Use the sidebar to navigate between all activities in the system.',
                                'Review user actions, internship postings, and announcements.',
                            ]
                        ]
                    ];
                    foreach ($sections as [$icon, $title, $steps]): ?>
                        <div style="margin-bottom:16px;border:1px solid #e8eaf0;border-radius:12px;overflow:hidden;">
                            <div
                                style="background:#f4f6ff;padding:11px 16px;display:flex;align-items:center;gap:9px;border-bottom:1px solid #e8eaf0;">
                                <i class="bi <?= $icon ?>" style="color:#272f54;font-size:15px;flex-shrink:0;"></i>
                                <span style="font-weight:700;font-size:13.5px;color:#272f54;">
                                    <?= $title ?>
                                </span>
                            </div>
                            <div style="padding:12px 16px;">
                                <ol style="margin:0;padding-left:18px;">
                                    <?php foreach ($steps as $step): ?>
                                        <li style="font-size:13px;color:#444;line-height:1.7;margin-bottom:2px;">
                                            <?= $step ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

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
    <?php if (!$hideStudentNav): ?>
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
    <?php endif; ?>
</nav>

<script>
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
</script>