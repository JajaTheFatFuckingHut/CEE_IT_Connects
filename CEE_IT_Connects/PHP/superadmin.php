<?php
require 'db.php';
require 'auth.php';

$role = strtolower(trim($_SESSION['role'] ?? ''));

if ($role !== 'superadmin') {
    header("Location: index.php");
    exit();
}
$statePath = __DIR__ . '/register_toggle.txt';
$registerVisible = file_exists($statePath) ? trim(file_get_contents($statePath)) : 'show';

if (isset($_POST['toggle_register'])) {
    $newState = $registerVisible === 'show' ? 'hide' : 'show';
    file_put_contents($statePath, $newState);
    $registerVisible = $newState;
}

$internshipStmt = $pdo->query("
    SELECT id, company, title
    FROM internships
    ORDER BY company ASC, title ASC
");
$internships = $internshipStmt->fetchAll(PDO::FETCH_ASSOC);


// Stats for dashboard
$statsStmt = $pdo->query("SELECT COUNT(*) AS total FROM internships");
$totalInternships = $statsStmt->fetchColumn();

$interestedStmt = $pdo->query("SELECT COUNT(*) AS total FROM internship_bookmarks");
$totalInterested = $interestedStmt->fetchColumn();

$announcementsStmt = $pdo->query("SELECT COUNT(*) AS total FROM announcements");
$totalAnnouncements = $announcementsStmt->fetchColumn();

// added
$accountsStmt = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM students) +
        (SELECT COUNT(*) FROM advisers) +
        (SELECT COUNT(*) FROM admins)
    AS total
");

$totalAccounts = $accountsStmt->fetchColumn();


$programsStmt = $pdo->query("
        SELECT COUNT(*) FROM role_department_access
");

$totalPrograms = $programsStmt->fetchColumn();

$recentInternshipsStmt = $pdo->query("
    SELECT title, company, location, created_at 
    FROM internships 
    ORDER BY created_at DESC 
    LIMIT 5
");
$recentInternships = $recentInternshipsStmt->fetchAll(PDO::FETCH_ASSOC);

$recentInterestedStmt = $pdo->query("
    SELECT s.full_name, s.email, i.title AS internship_title, i.company, ib.created_at
    FROM internship_bookmarks ib
    JOIN students s ON s.id = ib.student_id
    JOIN internships i ON i.id = ib.internship_id
    ORDER BY ib.created_at DESC
    LIMIT 5
");
$recentInterested = $recentInterestedStmt->fetchAll(PDO::FETCH_ASSOC);

$recentAnnouncementsStmt = $pdo->query("
    SELECT title, category, created_at 
    FROM announcements 
    ORDER BY created_at DESC 
    LIMIT 5
");
$recentAnnouncements = $recentAnnouncementsStmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT id, name, email, role FROM admins ORDER BY id ASC");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    $admin_ids = $_POST['admin_id'];
    $roles = $_POST['role'];

    if (!is_array($roles))
        $roles = [$roles];
    if (!is_array($admin_ids))
        $admin_ids = [$admin_ids];

    $superadmincount = 0;
    foreach ($roles as $role) {
        if ($role === 'superadmin')
            $superadmincount++;
    }

    if ($superadmincount > 3) {
        $_SESSION['error'] = "Only three system administrators are allowed.";
        header("Location: superadmin.php");
        exit;
    }

    $fetchOldRole = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
    $updateStmt = $pdo->prepare("UPDATE admins SET role = ? WHERE id = ?");
    $notifStmt = $pdo->prepare("
        INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
        VALUES (?, 'admin', ?, ?, ?, FALSE, NOW())
    ");

    $changedCount = 0;
    for ($i = 0; $i < count($admin_ids); $i++) {
        $fetchOldRole->execute([$admin_ids[$i]]);
        $oldRole = $fetchOldRole->fetchColumn();
        $updateStmt->execute([$roles[$i], $admin_ids[$i]]);
        if ($oldRole !== $roles[$i]) {
            $changedCount++;
            $notifStmt->execute([
                $admin_ids[$i],
                'Role Updated',
                'Your role has been changed from ' . $oldRole . ' to ' . $roles[$i] . ' by a system administrator.',
                $roles[$i] === 'superadmin' ? 'superadmin.php' : 'internship-ui.php'
            ]);
        }
    }

    $stmtActivity = $pdo->prepare("INSERT INTO audits (user_id, roles, activity, activity_date) VALUES (:user_id, :roles, :activity, NOW())");
    $stmtActivity->execute([
        ':user_id' => $_SESSION['user_id'],
        ':roles' => 'superadmin',
        ':activity' => 'Updated admin roles'
    ]);

    if ($changedCount > 0) {
        $_SESSION['success'] = "Successfully updated {$changedCount} role(s).";
    } else {
        $_SESSION['info'] = "No roles were changed.";
    }

    header("Location: superadmin.php?section=roles");
    exit;
}

// Assign adviser POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_adviser'])) {
    $student_id = (int) $_POST['student_id'];
    $adviser_id = (int) $_POST['adviser_id'];
    $current_room_id = (int) ($_POST['current_room_id'] ?? 0);

    if (!$student_id || !$adviser_id) {
        $_SESSION['error'] = "Please select a valid adviser.";
        header("Location: superadmin.php");
        exit;
    }

    // Get the adviser's room
    $roomStmt = $pdo->prepare("
        SELECT id FROM rooms 
        WHERE adviser_id = ? AND is_archived = FALSE 
        LIMIT 1
    ");
    $roomStmt->execute([$adviser_id]);
    $adviserRoom = $roomStmt->fetch(PDO::FETCH_ASSOC);

    if (!$adviserRoom) {
        $_SESSION['error'] = "This adviser has no active room yet.";
        header("Location: superadmin.php");
        exit;
    }

    $new_room_id = $adviserRoom['id'];

    // Remove student from current room if they're already in one
    if ($current_room_id && $current_room_id !== $new_room_id) {
        $removeStmt = $pdo->prepare("
            DELETE FROM room_members 
            WHERE room_id = ? AND user_id = ? AND user_type = 'student'
        ");
        $removeStmt->execute([$current_room_id, $student_id]);
    }

    // Check if already in the new room
    $checkStmt = $pdo->prepare("
        SELECT id FROM room_members 
        WHERE room_id = ? AND user_id = ? AND user_type = 'student'
    ");
    $checkStmt->execute([$new_room_id, $student_id]);

    if (!$checkStmt->fetch()) {
        $insertStmt = $pdo->prepare("
            INSERT INTO room_members (room_id, user_id, user_type)
            VALUES (?, ?, 'student')
        ");
        $insertStmt->execute([$new_room_id, $student_id]);
    }

    // Audit log
    $stmtActivity = $pdo->prepare("
        INSERT INTO audits (user_id, roles, activity, activity_date) 
        VALUES (:user_id, :roles, :activity, NOW())
    ");
    $stmtActivity->execute([
        ':user_id' => $_SESSION['user_id'],
        ':roles' => 'superadmin',
        ':activity' => "Assigned student ID {$student_id} to adviser ID {$adviser_id}"
    ]);

    $_SESSION['success'] = "Student successfully assigned to adviser's room.";
    header("Location: superadmin.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_assign_csv'])) {
    $headers = array_map('strtolower', array_map('trim', $_POST['headers'] ?? []));
    $rows = $_POST['csv'] ?? [];
    $sidIdx = array_search('student_id', $headers);
    $aidIdx = array_search('adviser_id', $headers);

    if ($sidIdx === false || $aidIdx === false) {
        $_SESSION['error'] = "CSV must contain student_id and adviser_id columns.";
        header("Location: superadmin.php?section=assign_adviser");
        exit;
    }

    $assigned = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $student_id = (int) ($row[$sidIdx] ?? 0);
        $adviser_id = (int) ($row[$aidIdx] ?? 0);
        if (!$student_id || !$adviser_id) {
            $skipped++;
            continue;
        }

        // Skip if student already has an adviser
        $checkAssigned = $pdo->prepare("
            SELECT r.id FROM rooms r
            JOIN room_members rm ON rm.room_id = r.id
            WHERE rm.user_id = ? AND rm.user_type = 'student' AND r.is_archived = FALSE
            LIMIT 1
        ");
        $checkAssigned->execute([$student_id]);
        if ($checkAssigned->fetch()) {
            $skipped++;
            continue;
        }

        // Get adviser's room
        $roomStmt = $pdo->prepare("
            SELECT id FROM rooms WHERE adviser_id = ? AND is_archived = FALSE LIMIT 1
        ");
        $roomStmt->execute([$adviser_id]);
        $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            $skipped++;
            continue;
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO room_members (room_id, user_id, user_type) VALUES (?, ?, 'student')
        ");
        $insertStmt->execute([$room['id'], $student_id]);
        $assigned++;
    }

    $pdo->prepare("INSERT INTO audits (user_id, roles, activity, activity_date) VALUES (?, 'superadmin', ?, NOW())")
        ->execute([$_SESSION['user_id'], "Bulk assigned {$assigned} student(s) via CSV, skipped {$skipped}"]);

    $_SESSION['success'] = "Assigned {$assigned} student(s). Skipped {$skipped} (already assigned or invalid).";
    header("Location: superadmin.php?section=assign_adviser");
    exit;
}

$stmt = $pdo->query("
    SELECT id, full_name AS name, email, 'student' AS role, 'students' AS source FROM students
    UNION ALL
    SELECT id, name, email, role, 'admins' AS source FROM admins WHERE role != 'superadmin'
    UNION ALL
    SELECT id, full_name AS name, email, role::text AS role, 'advisers' AS source FROM advisers
    ORDER BY name ASC
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("
    SELECT 
        a.id, a.user_id, a.roles, a.activity, a.activity_date,
        COALESCE(u.name, 'Unknown (#' || a.user_id || ')') AS name
    FROM audits a
    LEFT JOIN (
        SELECT id, full_name AS name, 'student' AS role FROM students
        UNION ALL
        SELECT id, name, role FROM admins
        UNION ALL
        SELECT id, full_name AS name, role::text AS role FROM advisers
    ) u ON a.user_id = u.id AND LOWER(a.roles) = LOWER(u.role)
    ORDER BY a.activity_date DESC
    LIMIT 30
");
$activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Adviser list with rooms
$adviserListStmt = $pdo->query("
    SELECT a.id, a.full_name, a.email, r.id AS room_id, r.room_name
    FROM advisers a
    LEFT JOIN rooms r ON r.adviser_id = a.id AND r.is_archived = FALSE
    WHERE a.role = 'internship_adviser'
    ORDER BY a.full_name ASC
");
$adviserList = $adviserListStmt->fetchAll(PDO::FETCH_ASSOC);

// Student list with current adviser/room
$studentListStmt = $pdo->query("
    SELECT 
        s.id,
        s.full_name,
        s.email,
        a.full_name AS adviser_name,
        r.room_name,
        r.id AS room_id
    FROM students s
    LEFT JOIN room_members rm ON s.id = rm.user_id AND rm.user_type = 'student'
    LEFT JOIN rooms r ON rm.room_id = r.id AND r.is_archived = FALSE
    LEFT JOIN advisers a ON r.adviser_id = a.id
    ORDER BY s.full_name ASC
");
$studentList = $studentListStmt->fetchAll(PDO::FETCH_ASSOC);

$supReqStmt = $pdo->query("
    SELECT
        shss.*,
        s.full_name AS student_name,
        s.student_id AS student_no,
        s.program
    FROM student_hte_supervisor_submissions shss
    JOIN students s ON shss.student_id = s.id
    ORDER BY
        CASE shss.status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
        shss.submitted_at DESC
");
$supervisorRequests = $supReqStmt->fetchAll(PDO::FETCH_ASSOC);

$programHoursStmt = $pdo->query("
    SELECT program, required_hours
    FROM internships
    WHERE program IS NOT NULL AND program <> ''
    GROUP BY program, required_hours
    ORDER BY program ASC
");
$programHoursList = $programHoursStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>System Admin | CEE IT Connects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --gradient-start: #FFB62F;
            --gradient-end: #E4572E;
            --primary-dark-blue: #272f54;
        }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif !important;
            background: linear-gradient(135deg, #f5f7ff, #eef1ff);
            min-height: 100vh;
            padding-top: 70px;
        }

        .dashboard-container {
            width: 100%;
            max-width: 600px;
            background: #fff;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
            animation: fadeIn 0.8s ease-in-out;
        }

        .admin-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .admin-form input,
        .admin-form select {
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #ddd;
            font-size: 14px;
            outline: none;
            transition: 0.3s;
        }

        .admin-form input:focus,
        .admin-form select:focus {
            border-color: var(--gradient-end);
            box-shadow: 0 0 8px rgba(228, 87, 46, 0.3);
        }

        .layout {
            display: flex;
            height: 100vh;
            width: 100vw;
        }

        .sidebar {
            width: 220px;
            margin-top: 10px;
            /* background: #2c3e67; */
            background: #272f54;
            color: white;
            padding: 25px;
            display: flex;
            flex-direction: column;
            height: 100%;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
        }

        .sidebar h3 {
            margin-bottom: 20px;
        }

        .sidebar a {
            text-decoration: none;
            color: white;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: 0.3s;
        }

        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #FFB62F;
        }

        .sidebar a:hover i {
            color: #FFB62F;
        }

        .sidebar a.active {
            background: #e35f36;
            color: #fff;
            font-weight: 600;
        }

        .main-content {
            flex: 1;
            padding: 40px;
            background: #f5f7ff;
            margin-left: 220px;
            min-width: 0;
            overflow-y: auto;
        }

        .section {
            display: none;
        }

        .section.active {
            display: block;
        }

        .sysAdm-section {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            width: 100%;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            /* overflow-x: auto; */

            /* puts content inside the table */
            overflow: hidden;
        }


        /* sysAdm-header--danger addition for delete account feature */
        .sysAdm-header--danger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, #f7dddd 7%, #fbdad9 50%, #f1cecd 100%);
            border-radius: 14px 14px 0 0;
            padding: 22px 28px;
            /* margin-bottom: 20px; */
            margin: -24px -24px 20px -24px;
            width: calc(100% + 48px);

        }

        .sysAdm-header--danger h2 {
            color: var(--primary-dark-blue);
            font-size: 26px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .sysAdm-header--danger p {
            margin-bottom: 0 !important;
        }

        .sysAdm-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .sysAdm-header-icon {
            background-color: #f6c3bd;
            color: var(--gradient-end);
            width: 64px;
            height: 64px;
            min-width: 64px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        .sysAdm-header-deco {
            width: 100px;
            height: 100px;
            /* height: auto; */
        }

        .sysAdm-header--blue {
            background: linear-gradient(135deg, #dce2ef 0%, #dde3f0 50%, #c0cfef 100%);
        }

        .sysAdm-header--blue .sysAdm-header-icon {
            background-color: #c7d2e8;
            color: var(--primary-dark-blue);
        }

        .sysAdm-header-text {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* end of added section for delete account feature */

        .sysAdm-header h2 {
            font-size: 26px;
            font-weight: 700;
            color: var(--primary-dark-blue);
            margin-bottom: 4px !important;
            overflow-x: hidden;
        }

        .sysAdm-header p {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 0px !important;
            overflow-x: hidden;
        }

        .sysAdm-table {
            width: 100%;
            /* added */
            min-width: 720px;
            border-collapse: collapse;
            /* overflow: hidden; */
            /* overflow-x: auto; */
        }

        .sysAdm-table-wrapper {
            overflow-x: auto;
            width: 100%;
            -webkit-overflow-scrolling: touch;
        }

        .sysAdm-table thead {
            background: #eaedef;
        }

        .sysAdm-table th {
            text-align: left;
            padding: 14px 18px;
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            border-bottom: 2px solid #dbe1ea;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .sysAdm-table td {
            padding: 14px 18px;
            font-size: 14px;
            color: #334155;
            border-bottom: 1px solid #edf2f7;
        }

        .sysAdm-table tbody tr {
            transition: background 0.2s ease;
        }

        .sysAdm-table tbody tr:hover {
            background: #f8fafc;
        }

        /* commented out */

        /* .btn-update {
            margin-top: 18px;
            padding: 11px 24px;
            font-size: 15px;
            font-weight: 600;
            border-radius: 8px;
            border: none;
            background: #4f51a8;
            color: #fff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s;
        }

        .btn-update:hover {
            background: #3A3B7B;
        } */

        .btn-create {
            padding: 14px;
            border-radius: 12px;
            border: none;
            background: linear-gradient(135deg, var(--gradient-start), var(--gradient-end));
            color: white;
            font-weight: 700;
            cursor: pointer;
            font-size: 15px;
            transition: box-shadow 0.2s;
        }

        .btn-create:hover {
            box-shadow: 0 8px 20px rgba(228, 87, 46, 0.3);
        }

        .btn-delete {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 8px;
            border: 1px solid #f7c1c1;
            background: #fcebeb;
            color: #a32d2d;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-delete:hover {
            background: #f09595;
            color: #791f1f;
        }

        .form-card {
            background: #fff;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 8px 20px rgba(67, 67, 67, 0.08);
        }

        .btn-button {
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            border: none;
            background: #4f51a8;
            color: #fff;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-button:hover {
            background: #3A3B7B;
        }

        .submit-btn {
            background: linear-gradient(135deg, #FFB62F, #E4572E);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 10px;
            font-weight: bold;
            cursor: pointer;
            font-size: 16px;
        }

        .submit-btn:hover {
            opacity: 0.9;
        }

        .role-select {
            padding: 8px 32px 8px 14px;
            border: 1.5px solid #cacfd5;
            border-radius: 10px;
            font-size: 13px;
            /* font-weight: 600; */
            color: var(--primary-dark-blue);
            background-color: #f1f5f9;
            cursor: pointer;
            /* outline: none;
            appearance: none;
            -webkit-appearance: none; */
        }

        /* Assign adviser select */
        .assign-select {
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid #ddd;
            font-size: 13px;
            min-width: 200px;
        }

        .assign-btn {
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            border: none;
            background: #4f51a8;
            color: #fff;
            cursor: pointer;
            transition: background 0.15s;
            white-space: nowrap;
        }

        .assign-btn:hover {
            background: #3A3B7B;
        }

        .assign-select:disabled {
            appearance: none;
            -webkit-appearance: none;
            width: 100%;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /*susu*/

        @media (max-width: 768px) {
            .layout {
                flex-direction: row;
            }

            /* added ulit dahil sa top header cardZ */
            .sysAdm-section {
                padding: 20px !important;
            }

            .sysAdm-header--danger {
                margin: -20px -20px 20px -20px;
                width: calc(100% + 40px);
                border-radius: 0;
                /* optional: square off entirely on mobile if you want */
            }

            .sidebar {
                top: 60px;
                width: 60px !important;
                padding: 10px 0 !important;
                align-items: center;
                overflow: visible !important;
                z-index: 1050;
            }

            .sidebar h3 {
                display: none !important;
            }

            .sidebar a {
                width: 44px !important;
                height: 44px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                border-radius: 12px !important;
                margin: 0 auto 8px auto !important;
                padding: 0 !important;
                position: relative;
            }

            .sidebar a i {
                margin: 0 !important;
            }

            /* Hide text labels */
            .sidebar a .nav-label {
                display: none !important;
            }

            /* Tooltip */
            .sidebar a::after {
                content: attr(data-tooltip);
                position: absolute;
                left: 56px;
                top: 50%;
                transform: translateY(-50%);
                background: #1a1a2e;
                color: #fff;
                font-size: 12px;
                font-weight: 500;
                padding: 5px 10px;
                border-radius: 6px;
                white-space: nowrap;
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.2s ease;
            }

            .sidebar a:hover::after {
                opacity: 1;
            }

            .main-content {
                margin-left: 60px !important;
                padding: 10px !important;
            }

            /* STAT CARDS — 1 row, 3 columns */
            .row.g-3.mb-4 {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 4px !important;
            }

            .row.g-3.mb-4 .col-md-4 {
                width: 100% !important;
                padding: 0 !important;
            }

            .row.g-3.mb-4 .card {
                border-radius: 10px !important;
            }

            .row.g-3.mb-4 .card-body {
                padding: 8px !important;
                font-size: 0.8rem !important;
            }

            .row.g-3.mb-4 .card-body p {
                font-size: 10px !important;
                margin-bottom: 4px !important;
                letter-spacing: 0 !important;
                font-size: 0.5rem !important;
            }

            .row.g-3.mb-4 .card-body .d-flex {
                flex-wrap: nowrap !important;
                align-items: flex-start !important;
                justify-content: space-between !important;
                gap: 4px !important;
            }

            .row.g-3.mb-4 .card-body .d-flex>div:first-child:not(.rounded-3) {
                flex: 1 !important;
            }

            .row.g-3.mb-4 .card-body h2 {
                font-size: 0.7rem !important;
                margin-bottom: 0 !important;
            }

            /* Hide icon box to save space */
            .row.g-3.mb-4 .card-body .rounded-3 {
                width: 24px !important;
                height: 24px !important;
                flex-shrink: 0;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                align-self: flex-start !important;
                margin-top: 4px !important;
            }

            .row.g-3.mb-4 .card-body .rounded-3 i {
                font-size: 10px !important;
            }

            /* TABLES — allow horizontal scroll */
            .sysAdm-table {
                font-size: 12px !important;
            }

            .sysAdm-table th,
            .sysAdm-table td {
                padding: 10px 10px !important;
                font-size: 12px !important;
            }

            /* DASHBOARD CONTAINER (forms) */
            .dashboard-container {
                padding: 20px !important;
                max-width: 100% !important;
            }

            /* SECTION HEADERS */
            .sysAdm-header h2 {
                font-size: 20px !important;
            }

            /* DOWNLOAD BUTTONS */
            .d-flex.justify-content-end.gap-2.mt-4 {
                flex-direction: row !important;
                align-items: center !important;
                justify-content: center;
            }

            .d-flex.justify-content-end.gap-2.mt-4 button {
                flex: 1;
                font-size: 12px;
                padding: 8px;
                justify-content: center !important;
            }

            .stat-card-title {
                font-size: 9px !important;
                letter-spacing: 0 !important;
                margin-bottom: 4px !important;
                white-space: normal !important;
                line-height: 1.1 !important;
                word-break: break-word !important;
            }

            .stat-card-number {
                font-size: 0.7rem !important;
            }
        }

        @media (max-width: 480px) {
            .row.g-3.mb-4 {
                gap: 3px !important;
            }

            .row.g-3.mb-4 .card-body {
                padding: 6px !important;
            }

            .row.g-3.mb-4 .card-body p {
                font-size: 8px !important;
                letter-spacing: 0 !important;
                margin-bottom: 2px !important;
                line-height: 1.2 !important;
                word-break: break-word !important;
            }

            .row.g-3.mb-4 .card-body h2 {
                font-size: 1rem !important;
                margin-bottom: 0 !important;
            }

            .row.g-3.mb-4 .card-body .rounded-3 {
                width: 24px !important;
                height: 24px !important;
            }

            .row.g-3.mb-4 .card-body .rounded-3 i {
                font-size: 10px !important;
            }

            .stat-card-title {
                font-size: 7px !important;
                line-height: 1.1 !important;
                word-break: break-word !important;
            }

            .stat-card-number {
                font-size: 1rem !important;
            }
        }
    </style>
</head>

<script>
    function showSection(event, sectionId) {
        document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
        document.getElementById(sectionId).classList.add('active');
        document.querySelectorAll('.sidebar a').forEach(l => l.classList.remove('active'));
        event.currentTarget.classList.add('active');
    }

    function confirmSuperadminGlobal() {
        const roles = document.querySelectorAll("select[name='role[]']");
        let superadminCount = 0, hasChangeToSuperadmin = false, hasRemovalFromSuperadmin = false;
        roles.forEach(select => {
            const original = select.dataset.original;
            const current = select.value;
            if (current === 'superadmin') superadminCount++;
            if (current === 'superadmin' && original !== 'superadmin') hasChangeToSuperadmin = true;
            if (original === 'superadmin' && current !== 'superadmin') hasRemovalFromSuperadmin = true;
        });
        if (superadminCount > 3) { alert("Only 3 system administrators are allowed."); return false; }
        if (hasRemovalFromSuperadmin) return confirm("You are removing a System administrator. Continue?");
        if (hasChangeToSuperadmin) return confirm("You are assigning a System administrator. Are you sure?");
        return true;
    }
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

<body>
    <?php include 'navbar.php'; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['info'])): ?>
        <div class="alert alert-info alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="bi bi-info-circle-fill me-2"></i><?= $_SESSION['info'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['info']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="bi bi-x-circle-fill me-2"></i><?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="layout">

        <div class="sidebar">
            <h3> . </h3>
            <a href="#" onclick="showSection(event, 'dashboard')" class="active" data-tooltip="Home">
                <i class="bi bi-person-fill-lock me-2"></i>
                <span class="nav-label">Home</span>
            </a>
            <a href="#" onclick="showSection(event, 'roles')" data-tooltip="Account Management">
                <i class="bi bi-person-gear"></i>
                <span class="nav-label">Account Management</span>
            </a>
            <a href="#" onclick="showSection(event, 'delete')" data-tooltip="Account Deletion">
                <i class="bi bi-person-x me-2"></i>
                <span class="nav-label">Account Deletion</span>
            </a>
            <a href="#" onclick="showSection(event, 'monitor')" data-tooltip="Monitor">
                <i class="bi bi-binoculars me-2"></i>
                <span class="nav-label">Monitor</span>
            </a>
            <a href="#" onclick="showSection(event, 'student_register')" data-tooltip="Student Register">
                <i class="bi bi-file-earmark-person me-2"></i>
                <span class="nav-label">Student Register</span>
            </a>
            <a href="#" onclick="showSection(event, 'assign_adviser')" data-tooltip="Assign Adviser">
                <i class="bi bi-person-lines-fill me-2"></i>
                <span class="nav-label">Assign Adviser</span>
            </a>
            <a href="#" onclick="showSection(event, 'supervisor_requests')" data-tooltip="Supervisor Requests">
                <i class="bi bi-person-badge me-2"></i>
                <span class="nav-label">Supervisor Requests</span>
            </a>
            <a href="#" onclick="showSection(event, 'ojt_hours')" data-tooltip="OJT Hours">
                <i class="bi bi-clock-history me-2"></i>
                <span class="nav-label">OJT Hours</span>
            </a>
        </div>

        <div class="main-content">

            <!-- DASHBOARD -->
            <div id="dashboard" class="section active sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue mb-4">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>System Admin Overview</h2>
                            <p>Live summary from the internship admin panel</p>
                        </div>
                    </div>
                </div>

                <!-- ADDED: scoped styles for this dashboard section only. Nothing below overrides
                     other pages; classes are prefixed ojtc- to avoid collisions with existing CSS. -->
                <style>
                    /* ADDED: subtle blue "tab" indicator on table column headers */
                    .ojtc-th-tab {
                        background: rgba(39, 111, 255, 0.08) !important;
                        color: #272f54 !important;
                        padding: 10px 12px !important;
                        border-radius: 6px 6px 0 0;
                    }

                    /* ADDED: hover state for Recent Account Activity rows */
                    .ojtc-activity-row {
                        padding: 8px;
                        border-radius: 10px;
                        transition: background-color .15s ease;
                    }

                    .ojtc-activity-row:hover {
                        background: #f5f7ff;
                    }

                    /* ADDED: hover state for OJT Hours by Program rows */
                    .ojtc-hours-row {
                        padding: 6px 8px;
                        border-radius: 10px;
                        transition: background-color .15s ease;
                    }

                    .ojtc-hours-row:hover {
                        background: #f7f8fb;
                    }

                    /* ADDED*/
                    .ojtc-badge-it {
                        background: #fff1e0;
                        color: #b85c00;
                    }

                    .ojtc-badge-ce {
                        background: #e7effe;
                        color: #1b4fce;
                    }

                    .ojtc-badge-ee {
                        background: #fff8dc;
                        color: #8a6d00;
                    }

                    .ojtc-badge-default {
                        background: #f0f4ff;
                        color: #272f54;
                    }

                    /* ADDED: stat card hover lift for the redesigned cards below */
                    .ojtc-stat-card {
                        transition: transform .15s ease, box-shadow .15s ease;
                    }

                    .ojtc-stat-card:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
                    }

                    /* ADDED: subtle yellow buttons by default, solid orange on hover */
                    .btn-update {
                        background: #FFE7B3 !important;
                        color: #7a5200 !important;
                        border: none !important;
                        transition: background-color .15s ease, color .15s ease;
                    }

                    .btn-update:hover {
                        background: #E4572E !important;
                        color: #fff !important;
                    }
                </style>

                <div class="row g-3 mb-4">
                    <!-- CHANGED LAYOUT: Internship Postings card restyled to match new design —
                         light tint background, colored icon box on the left -->
                    <div class="col-md-4">
                        <div class="card border-0 rounded-4 h-100 ojtc-stat-card" style="background:#EEF3FF;">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                                        style="width:44px;height:44px;background:#272f54;">
                                        <i class="bi bi-briefcase-fill text-white fs-5"></i>
                                    </div>
                                    <div class="flex-grow-1" style="min-width:0; overflow: hidden;">
                                        <p class="small mb-1 fw-semibold text-uppercase"
                                            style="letter-spacing:.05em; font-size:11px; color:#272f54;">Internships</p>
                                        <h2 class="fw-bold mb-0" style="color:#272f54;"><?= (int) $totalInternships ?>
                                        </h2>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CHANGED LAYOUT: Active Accounts card restyled to match new design (same pattern as above). -->
                    <div class="col-md-4">
                        <div class="card border-0 rounded-4 h-100 ojtc-stat-card" style="background:#FFF6E3;">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                                        style="width:44px;height:44px;background:#FFB62F;">
                                        <i class="bi bi-person-badge-fill fs-5" style="color:#3b2600;"></i>
                                    </div>
                                    <div class="flex-grow-1" style="min-width:0; overflow: hidden;">
                                        <p class="small mb-1 fw-semibold text-uppercase"
                                            style="letter-spacing:.05em;font-size:11px;color:#7a5200;">Accounts
                                        </p>
                                        <h2 class="fw-bold mb-0" style="color:#3b2600;"><?= (int) $totalAccounts ?></h2>

                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CHANGED LAYOUT: Programs Tracked card restyled to match new design (same pattern as above). -->
                    <div class="col-md-4">
                        <div class="card border-0 rounded-4 h-100 ojtc-stat-card" style="background:#FDEEE8;">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                                        style="width:44px;height:44px;background:#E4572E;">
                                        <i class="bi bi-clock-history text-white fs-5"></i>
                                    </div>
                                    <div class="flex-grow-1" style="min-width:0; overflow: hidden;">
                                        <p class="small mb-1 fw-semibold text-uppercase"
                                            style="letter-spacing:.05em;font-size:11px;color:#a13d1f;">Programs</p>
                                        <h2 class="fw-bold mb-0" style="color:#a13d1f;"><?= (int) $totalPrograms ?></h2>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Internship Postings -->
                <div class="card border-0 rounded-4 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                        <i class="bi bi-briefcase" style="color:#272f54;"></i>
                        <h6 class="fw-bold mb-0" style="color:#272f54;">Recent Internship Postings</h6>
                    </div>
                    <div class="card-body px-4 pb-4 pt-2">
                        <?php if (empty($recentInternships)): ?>
                            <p class="text-muted small mb-0">No internships posted yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table id="table-internships" class="table table-hover align-middle mb-0"
                                    style="font-size:14px;">
                                    <thead>
                                        <!-- CHANGED: added ojtc-th-tab class for the subtle blue column-header
                                             indicator. No data/columns removed, inline styles kept. -->
                                        <tr
                                            style="color:#aaa;font-size:12px;text-transform:uppercase;letter-spacing:.04em;">
                                            <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Title</th>
                                            <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Company</th>
                                            <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Location</th>
                                            <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Posted</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentInternships as $ri): ?>
                                            <tr>
                                                <td class="fw-semibold" style="color:#272f54;">
                                                    <?= htmlspecialchars($ri['title']) ?>
                                                </td>
                                                <td><?= htmlspecialchars($ri['company']) ?></td>
                                                <td class="text-muted"><i
                                                        class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($ri['location']) ?>
                                                </td>
                                                <!-- CHANGED: removed the highlighted pill background on the
                                                     Posted date, now plain muted text as requested. -->
                                                <td class="text-muted" style="font-size:13px;">
                                                    <?= date("M d, Y", strtotime($ri['created_at'])) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Bottom Row -->
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="card border-0 rounded-4 shadow-sm h-100">
                            <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                                <i class="bi bi-person-plus" style="color:#272f54;"></i>
                                <h6 class="fw-bold mb-0" style="color:#272f54;">Recent Account Activity</h6>
                            </div>
                            <div id="table-account-activity" class="card-body px-4 pb-4 pt-2">
                                <?php if (empty($recentAccounts)): ?>
                                    <p class="text-muted small mb-0">No accounts created recently.</p>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach ($recentAccounts as $ra): ?>
                                            <!-- CHANGED: added ojtc-activity-row class for a subtle hover
                                                 background, matching the "List Item (Hover)" style. -->
                                            <div class="d-flex align-items-center gap-3 ojtc-activity-row">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 fw-bold"
                                                    style="width:38px;height:38px;background:#eef1ff;color:#272f54;font-size:13px;">
                                                    <?= strtoupper(substr($ra['full_name'], 0, 1)) ?>
                                                </div>
                                                <div class="flex-grow-1 overflow-hidden">
                                                    <p class="fw-semibold mb-0 text-truncate"
                                                        style="color:#272f54;font-size:14px;">
                                                        <?= htmlspecialchars($ra['full_name']) ?>
                                                    </p>
                                                    <p class="text-muted mb-0 text-truncate" style="font-size:12px;">
                                                        <?= htmlspecialchars($ra['email']) ?>
                                                    </p>
                                                </div>
                                                <div class="text-end flex-shrink-0">
                                                    <span class="badge rounded-pill px-2"
                                                        style="background:#f5f5f5;color:#555;font-size:11px;font-weight:500;">
                                                        <?= htmlspecialchars($ra['role']) ?>
                                                    </span>
                                                    <p class="text-muted mb-0 mt-1" style="font-size:11px;">
                                                        <?= date("M d", strtotime($ra['created_at'])) ?>
                                                    </p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card border-0 rounded-4 shadow-sm h-100">
                            <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                                <i class="bi bi-clock-history" style="color:#272f54;"></i>
                                <h6 class="fw-bold mb-0" style="color:#272f54;">OJT Hours by Program</h6>
                            </div>
                            <div id="table-hours-summary" class="card-body px-4 pb-4 pt-2">
                                <?php if (empty($programHoursList)): ?>
                                    <p class="text-muted small mb-0">No programs configured yet.</p>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach ($programHoursList as $ph): ?>
                                            <?php
                                            // ADDED: presentation-only color mapping for the hours badge.
                                            // Does not change $ph['program'] or how it is stored/echoed —
                                            // only picks which existing badge class to apply below.
                                            $ojtcProgramName = strtoupper((string) $ph['program']);
                                            if (strpos($ojtcProgramName, 'IT') !== false) {
                                                $ojtcBadgeClass = 'ojtc-badge-it';
                                            } elseif (strpos($ojtcProgramName, 'CE') !== false) {
                                                $ojtcBadgeClass = 'ojtc-badge-ce';
                                            } elseif (strpos($ojtcProgramName, 'EE') !== false) {
                                                $ojtcBadgeClass = 'ojtc-badge-ee';
                                            } else {
                                                $ojtcBadgeClass = 'ojtc-badge-default';
                                            }
                                            ?>
                                            <!-- CHANGED: added ojtc-hours-row class for a subtle row hover. -->
                                            <div class="d-flex align-items-center justify-content-between ojtc-hours-row">
                                                <p class="fw-semibold mb-0" style="color:#272f54;font-size:14px;">
                                                    <?= htmlspecialchars($ph['program']) ?>
                                                </p>
                                                <!-- CHANGED: badge now uses the color-coded class above instead
                                                     of a single fixed blue background. -->
                                                <span class="badge rounded-pill px-3 <?= $ojtcBadgeClass ?>"
                                                    style="font-weight:500;font-size:12px;">
                                                    <?= (int) $ph['required_hours'] ?> hrs
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4 mb-3">
                        <button onclick="downloadDashboardCSV()" class="btn-update">
                            <i class="bi bi-filetype-csv me-1"></i> Download CSV
                        </button>
                        <button onclick="downloadDashboardPDF()" class="btn-update">
                            <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF
                        </button>
                    </div>
                </div>

                <!-- Registration Toggle -->
                <div id="register-toggle" class="card border-0 rounded-4 shadow-sm mt-2">
                    <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                        <i class="bi bi-toggle2-on" style="color:#272f54;"></i>
                        <h6 class="fw-bold mb-0" style="color:#272f54;">Registration Link</h6>
                    </div>
                    <div class="card-body px-4 pb-4 pt-2">
                        <p class="text-muted small">Control whether the registration link is visible on the login page.
                        </p>
                        <form method="POST" class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <!-- CHANGED: status now shown as an icon pill instead of plain colored text,
                                 same $registerVisible conditional and copy, just restyled. -->
                            <p class="mb-0 d-flex align-items-center gap-2">Current Status:
                                <span class="badge rounded-pill px-3 py-2 d-inline-flex align-items-center gap-1"
                                    style="background: <?= $registerVisible === 'show' ? '#e6f4ea' : '#fdeaea' ?>;">
                                    <i class="bi <?= $registerVisible === 'show' ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"
                                        style="font-size:11px; color: <?= $registerVisible === 'show' ? 'green' : 'red' ?>;"></i>
                                    <strong style="color: <?= $registerVisible === 'show' ? 'green' : 'red' ?>">
                                        <?= $registerVisible === 'show' ? 'Visible' : 'Hidden' ?>
                                    </strong>
                                </span>
                            </p>
                            <button type="submit" name="toggle_register" class="btn-update">
                                <?= $registerVisible === 'show' ? 'Hide Registration Link' : 'Show Registration Link' ?>
                            </button>
                        </form>
                    </div>
                </div>

            </div>

            <!-- DELETE USER -->
            <div id="delete" class="section sysAdm-section">
                <div class="sysAdm-header--danger">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="fa-solid fa-trash"></i>
                        </div>
                        <h2>Account Deletion</h2>
                        <p>Permanently remove users from the system.</p>
                    </div>

                    <!-- <div class="sysAdm-header-deco">
                        <img src="../Sources/Inbox%20cleanup-pana.svg" alt="Delete account illustration">
                    </div> -->
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="d-flex gap-2 flex-wrap">
                        <input type="text" id="search-delete" oninput="filterDelete()"
                            placeholder="Search for a student"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:220px;">
                        <select id="filter-role" onchange="filterDelete()"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:200px;">
                            <option value="">All Roles</option>
                            <option value="student">Student</option>
                            <option value="adviser">Adviser</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>


                <div class="sysAdm-table-wrapper">
                    <table class="sysAdm-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="delete-tbody">
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?= htmlspecialchars($u['name']) ?></td>
                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                    <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $u['role']))) ?></td>
                                    <td>
                                        <form method="POST" action="superadmin-db.php"
                                            onsubmit="return confirm('Are you sure you want to delete this user?')">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="source" value="<?= $u['source'] ?>">
                                            <button type="submit" name="delete" class="btn-delete">
                                                <i class="bi bi-trash-fill"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- add updated delete account with danger top card -->
            <!-- CHANGE ROLES / Admin Management -->
            <div id="roles" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-person-gear"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Account Management</h2>
                            <p>Create accounts and assign roles for admins and advisers.</p>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="d-flex gap-2 flex-wrap">
                        <input type="text" id="search-roles" oninput="filterRoles()" placeholder="Search for an admin"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:220px;">
                        <select id="filter-role-admin" onchange="filterRoles()"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:200px;">
                            <option value="">All Roles</option>
                            <option value="superadmin">System Admin</option>
                            <option value="internship_admin">Internship Admin</option>
                        </select>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn-update" onclick="showSection(event, 'add-admin')">
                            <i class="bi bi-person-plus me-1"></i>Add Admin
                        </button>
                        <button type="button" class="btn-update" onclick="showSection(event, 'add-adviser')">
                            <i class="bi bi-person-plus me-1"></i>Add Adviser
                        </button>
                    </div>
                </div>

                <form method="POST" onsubmit="return confirmSuperadminGlobal()">
                    <div class="sysAdm-table-wrapper">
                        <table class="sysAdm-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                </tr>
                            </thead>
                            <tbody id="roles-tbody">
                                <?php foreach ($admins as $admin): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($admin['name']) ?></td>
                                        <td><?= htmlspecialchars($admin['email']) ?></td>
                                        <td>
                                            <input type="hidden" name="admin_id[]" value="<?= $admin['id'] ?>">
                                            <select name="role[]" data-original="<?= $admin['role'] ?>" class="role-select"
                                                required>
                                                <option value="superadmin" <?= $admin['role'] == 'superadmin' ? 'selected' : '' ?>>
                                                    System admin</option>
                                                <option value="internship_admin" <?= $admin['role'] == 'internship_admin' ? 'selected' : '' ?>>Internship Admin</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-end" style="margin-top: 16px;">
                        <button type="submit" name="update_role" class="btn-update">
                            <i class="bi bi-floppy2"></i> Update Roles
                        </button>
                    </div>
                </form>
            </div>

            <!-- ADD ADMIN -->
            <div id="add-admin" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-person-plus"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Create New Admin Account</h2>
                            <p>Admins can be assigned to an internship administration role.</p>
                        </div>
                    </div>
                </div>
                <form method="POST" action="superadmin-db.php" class="admin-form">
                    <input type="text" name="name" placeholder="Full Name" required>
                    <input type="email" name="email" placeholder="Email Address" required>
                    <input type="password" name="password" placeholder="Password" required>
                    <div style="color: #888; font-size: 10px;">Password must be at least 8 characters long, contains an
                        uppercase and lowercase letter, a number, and a special character.</div>
                    <select name="role" required>
                        <option value="internship_admin">Internship Admin</option>
                    </select>
                    <div style="display:flex;width:100%;justify-content:space-between;margin-top:12px;">
                        <button type="button" class="btn-update" onclick="showSection(event, 'roles')"
                            style="background:#eee;color:#555;">
                            <i class="bi bi-arrow-left me-1"></i>Back
                        </button>
                        <button type="submit" name="create-admin" class="btn-update">Create Admin</button>
                    </div>
                </form>
            </div>

            <!-- ADD ADVISER -->
            <div id="add-adviser" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-person-plus"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Create New Adviser Account</h2>
                            <p>Advisers can be assigned to either HTE or Internship advising roles.</p>
                        </div>
                    </div>
                </div>
                <form method="POST" action="superadmin-db.php" class="admin-form">
                    <input type="text" name="name" placeholder="Full Name" required>
                    <input type="email" name="email" placeholder="Email Address" required>
                    <input type="password" name="password" placeholder="Password" required>
                    <div style="color: #888; font-size: 10px;">Password must be at least 8 characters long, contains an
                        uppercase and lowercase letter, a number, and a special character.</div>
                    <label>Department</label>
                    <select name="department" required style="width:100%;">
                        <option value="" disabled selected>Select Department</option>
                        <option value="information technology">Information Technology</option>
                        <option value="electrical engineering">Electrical Engineering</option>
                        <option value="civil engineering">Civil Engineering</option>
                    </select>
                    <select name="role" id="adviserRole" required>
                        <option value="" disabled selected>Select Role</option>
                        <option value="HTE_adviser">HTE Adviser</option>
                        <option value="internship_adviser">Internship Adviser</option>
                    </select>
                    <div id="internshipWrapper" style="display:none;">
                        <select name="internship_id" id="internshipSelect">
                            <option value="" selected>Select Internship</option>
                            <?php foreach ($internships as $internship): ?>
                                <option value="<?= $internship['id'] ?>">
                                    <?= htmlspecialchars($internship['company']) ?> —
                                    <?= htmlspecialchars($internship['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex;width:100%;justify-content:space-between;margin-top:12px;">
                        <button type="button" class="btn-update" onclick="showSection(event, 'roles')"
                            style="background:#eee;color:#555;">
                            <i class="bi bi-arrow-left me-1"></i>Back
                        </button>
                        <button type="submit" name="create-adviser" class="btn-update">Create Adviser</button>
                    </div>
                </form>
            </div>

            <!-- MONITOR -->
            <div id="monitor" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-activity"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>System Monitoring</h2>
                            <p>Recent user activities and actions.</p>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="d-flex gap-2 flex-wrap">
                        <input type="text" id="search-monitor" oninput="filterMonitor()"
                            placeholder="Search for a student"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:220px;">
                        <select id="filter-role-monitor" onchange="filterMonitor()"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:200px;">
                            <option value="">All Roles</option>
                            <option value="student">Student</option>
                            <option value="hte_adviser">HTE Adviser</option>
                            <option value="internship_adviser">Internship Adviser</option>
                            <option value="internship_admin">Internship Admin</option>
                            <option value="superadmin">System Admin</option>
                        </select>
                    </div>
                </div>

                <div class="sysAdm-table-wrapper">
                    <table class="sysAdm-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Activity</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody id="monitor-tbody">
                            <?php foreach ($activityLogs as $log): ?>
                                <tr data-role="<?= htmlspecialchars(strtolower($log['roles'])) ?>">
                                    <td><?= htmlspecialchars($log['name']) ?></td>
                                    <td><?php if ($log['roles'] === 'superadmin') {
                                        echo htmlspecialchars('System Admin');
                                    } else {
                                        echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['roles'])));
                                    } ?></td>
                                    <td><?= htmlspecialchars($log['activity']) ?></td>
                                    <td><?= date('M j, Y • g:i A', strtotime($log['activity_date'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- STUDENT REGISTER -->
            <div id="student_register" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-person-vcard"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Student Register</h2>
                            <p>The master list for keeping tabs on every student in the system.</p>
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h5 class="fw-semibold mb-1" style="color:#272f54;">Excel Sheet for Student Registration
                            </h5>
                            <p class="text-muted small mb-0">View and edit the current student CSV file.</p>
                        </div>
                        <button class="btn-update" onclick="showSection(event, 'edit_csv')">
                            <i class="bi bi-pencil-square me-1"></i>Edit Current CSV
                        </button>
                    </div>
                </div>

                <hr style="border-color:#eee;">

                <div class="form-card">
                    <div class="mb-3">
                        <h5 class="fw-semibold mb-1" style="color:#272f54;">Import New CSV File</h5>
                        <p class="text-muted small mb-0">Replaces the existing CSV with the uploaded file.</p>
                    </div>
                    <form action="../auto-register-csv.php" method="POST" enctype="multipart/form-data">
                        <div class="d-flex gap-2 align-items-center">
                            <input type="file" name="students_csv" accept=".csv" required class="form-control"
                                style="flex:1;font-size:13px;">
                            <button class="btn-update" type="submit">
                                <i class="bi bi-upload me-1"></i>Replace CSV
                            </button>
                        </div>
                    </form>
                </div>

                <hr style="border-color:#eee;">

                <div class="form-card">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h5 class="fw-semibold mb-1" style="color:#272f54;">Download Current CSV Template</h5>
                            <p class="text-muted small mb-0">Download the current student registration template.</p>
                        </div>
                        <a href="download-csv-temp.php?type=student_register" style="text-decoration: none;"
                            class="btn-update">
                            <i class="bi bi-download me-1"></i> Download Template
                        </a>
                    </div>
                </div>
            </div>

            <!-- EDIT CSV -->
            <div id="edit_csv" class="section sysAdm-header">

                <!-- ADDED: scoped styles for this section only (prefixed ojtc-csv- to avoid collisions). -->
                <style>
                    /* ADDED: card wrapper polish to match the rest of the dashboard's card style */
                    #edit_csv .form-card {
                        border-radius: 16px;
                        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
                        padding: 24px;
                    }

                    /* ADDED: section title + "Editing: file.csv" line spacing */
                    #edit_csv h2 {
                        color: #272f54;
                        margin-bottom: 16px;
                    }

                    #edit_csv .ojtc-csv-filename {
                        background: #eef1ff;
                        color: #272f54;
                        display: inline-block;
                        padding: 2px 10px;
                        border-radius: 999px;
                        font-weight: 600;
                    }

                    #edit_csv #csv-table thead th {
                        background: rgba(39, 111, 255, 0.08);
                        color: #272f54;
                        font-size: 12px;
                        text-transform: uppercase;
                        letter-spacing: .04em;
                        padding: 12px 10px;
                        border-bottom: 2px solid rgba(39, 111, 255, 0.15);
                        white-space: nowrap;
                    }

                    /* ADDED: row hover + tidier cell padding for readability */
                    #edit_csv #csv-table tbody tr:hover {
                        background: #f8f9ff;
                    }

                    #edit_csv #csv-table td {
                        padding: 6px 8px;
                        vertical-align: middle;
                    }

                    /* ADDED: input fields inside the table — softer border, focus highlight */
                    #edit_csv #csv-table .form-control {
                        border-radius: 8px;
                        border: 1px solid #e0e3ec;
                        font-size: 13px;
                        padding: 8px 10px;
                    }

                    #edit_csv #csv-table .form-control:focus {
                        border-color: #272f54;
                        box-shadow: 0 0 0 3px rgba(39, 47, 84, 0.1);
                    }

                    /* ADDED: Add Row / Save / Back buttons — consistent rounded shape and spacing */
                    #edit_csv .btn-success,
                    #edit_csv .submit-btn {
                        border-radius: 10px !important;
                        padding: 8px 18px !important;
                        font-weight: 600 !important;
                        border: none !important;
                    }
                </style>

                <h2>Edit Student CSV</h2>
                <br>
                <div class="form-card">
                    <?php
                    $sourceDir = __DIR__ . '/Sources/';
                    $activeFile = file_exists($sourceDir . 'active_csv.txt')
                        ? trim(file_get_contents($sourceDir . 'active_csv.txt'))
                        : 'students.csv';
                    $csvPath = $sourceDir . $activeFile;
                    $csvRows = [];
                    if (file_exists($csvPath)) {
                        $handle = fopen($csvPath, 'r');
                        if ($handle !== false) {
                            while (($row = fgetcsv($handle, 0, ',')) !== false) {
                                $csvRows[] = $row;
                            }
                            fclose($handle);
                        }
                        if (count($csvRows) > 1 && $csvRows[0] === $csvRows[1]) {
                            array_shift($csvRows);
                        }
                    }

                    // ADDED: presentation-only formatter for the column header labels shown in <th>.
                    // Turns "student_id" into "Student Id", "full_name" into "Full Name", etc.
                    // This ONLY changes what is displayed in the <th> below — the original
                    // $headerCell value (used in the hidden "headers[]" inputs for form submission)
                    // is left completely untouched.
                    if (!function_exists('ojtc_format_header_label')) {
                        function ojtc_format_header_label($raw)
                        {
                            $label = str_replace(['_', '-'], ' ', $raw);
                            return ucwords(strtolower($label));
                        }
                    }
                    ?>
                    <?php if (empty($csvRows)): ?>
                        <p class="text-muted">No CSV file found. Please upload one first.</p>
                    <?php else: ?>
                        <p class="text-muted small mb-3">Editing:
                            <span class="ojtc-csv-filename"><?= htmlspecialchars($activeFile) ?></span>
                        </p><br>
                        <form method="POST" action="auto-register-save-csv.php">
                            <input type="hidden" name="edit_csv">
                            <?php foreach ($csvRows[0] as $colIndex => $headerCell): ?>
                                <input type="hidden" name="headers[<?= $colIndex ?>]"
                                    value="<?= htmlspecialchars($headerCell) ?>">
                            <?php endforeach; ?>
                            <div class="table-responsive">
                                <table class="table table-bordered" id="csv-table">
                                    <thead>
                                        <tr>
                                            <?php foreach ($csvRows[0] as $headerCell): ?>
                                                <!-- CHANGED: display uses the formatted label (ojtc_format_header_label)
                                                     instead of the raw db-style field name. Underlying data/name
                                                     attributes elsewhere are unaffected. -->
                                                <th><?= htmlspecialchars(ojtc_format_header_label($headerCell)) ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody id="csv-tbody">
                                        <?php foreach ($csvRows as $rowIndex => $row): ?>
                                            <?php if ($rowIndex === 0)
                                                continue; ?>
                                            <tr>
                                                <?php foreach ($row as $colIndex => $cell): ?>
                                                    <td>
                                                        <input type="text" name="csv[<?= $rowIndex ?>][<?= $colIndex ?>]"
                                                            value="<?= htmlspecialchars($cell) ?>" class="form-control">
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <input type="hidden" id="col-count" value="<?= count($csvRows[0]) ?>">
                            <input type="hidden" id="row-count" value="<?= count($csvRows) ?>">
                            <!-- CHANGED: buttons now reuse the existing .btn-update class (subtle yellow
                                 by default, solid orange on hover) instead of bootstrap's btn-success/
                                 btn-danger/submit-btn, for the same look as the dashboard buttons. -->
                            <div class="d-flex gap-2 mt-3">
                                <button type="button" class="btn-update" onclick="addRow()">
                                    <i class="bi bi-plus-circle me-1"></i> Add Row
                                </button>

                                <div style="flex:1; text-align:right;">

                                    <!-- SAVE CSV -->
                                    <button type="submit" class="btn-update">
                                        <i class="bi bi-save me-1"></i> Save CSV
                                    </button>

                                    <!-- ADD CSV DATA TO DATABASE -->
                                    <button type="submit" name="import_to_database" value="1" class="btn-update"
                                        onclick="return confirm('Add all valid students from this CSV to the database?');">
                                        <i class="bi bi-database-add me-1"></i> Add to Database
                                    </button>

                                    <!-- BACK -->
                                    <button type="button" class="btn-update"
                                        onclick="showSection(event, 'student_register')">
                                        Back
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ASSIGN ADVISER -->
            <div id="assign_adviser" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-person-check-fill"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Assign Adviser to Student</h2>
                            <p>Assign an internship adviser to a student. The student will automatically be added to
                                that
                                adviser's room.</p>
                        </div>
                    </div>
                </div>

                <!-- Search + filter -->
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="d-flex gap-2 flex-wrap">
                        <input type="text" id="assign-search" oninput="filterAssignTable()"
                            placeholder="Search for a student"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:220px;">
                        <select id="assign-filter-adviser" onchange="filterAssignTable()"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:200px;">
                            <option value="">All Advisers</option>
                            <option value="unassigned">Unassigned</option>
                            <?php foreach ($adviserList as $adv): ?>
                                <option value="<?= htmlspecialchars($adv['full_name']) ?>">
                                    <?= htmlspecialchars($adv['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn-update" data-bs-toggle="modal" data-bs-target="#csvAssignModal">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Import via CSV
                    </button>
                </div>
                <div class="sysAdm-table-wrapper">
                    <table class="sysAdm-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Email</th>
                                <th>Current Adviser</th>
                                <th>Current Room</th>
                                <th>Assign To</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="assign-tbody">
                            <?php foreach ($studentList as $st): ?>
                                <tr data-adviser="<?= htmlspecialchars(strtolower($st['adviser_name'] ?? '')) ?>"
                                    data-name="<?= htmlspecialchars(strtolower($st['full_name'])) ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                                                style="width:34px;height:34px;background:#eef1ff;color:#272f54;font-size:12px;">
                                                <?= strtoupper(substr($st['full_name'], 0, 1)) ?>
                                            </div>
                                            <?= htmlspecialchars($st['full_name']) ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($st['email']) ?></td>
                                    <td>
                                        <?php if ($st['adviser_name']): ?>
                                            <span style="color:#272f54;font-size:12px; font-weight:550;">
                                                <?= htmlspecialchars($st['adviser_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill px-3"
                                                style="background:#f8d7da;color:#721c24;font-size:12px;">
                                                Unassigned
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $st['room_name'] ? htmlspecialchars($st['room_name']) : '—' ?></td>
                                    <td>
                                        <form method="POST" id="assign-form-<?= $st['id'] ?>">
                                            <input type="hidden" name="student_id" value="<?= $st['id'] ?>">
                                            <input type="hidden" name="current_room_id" value="<?= $st['room_id'] ?? '' ?>">
                                            <select name="adviser_id" class="assign-select" <?= $st['adviser_name'] ? 'disabled' : '' ?>>
                                                <option value="">— Select Adviser —</option>
                                                <?php foreach ($adviserList as $adv): ?>
                                                    <?php if (!$adv['room_id'])
                                                        continue; ?>
                                                    <option value="<?= $adv['id'] ?>" <?= ($st['room_id'] && $adv['room_id'] == $st['room_id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($adv['full_name']) ?>
                                                        (<?= htmlspecialchars($adv['room_name']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ($st['adviser_name']): ?>
                                            <span class="badge rounded-pill px-3"
                                                style="background:#eaf3de;color:#27500a;font-size:12px;font-weight:500;">
                                                <i class="bi bi-check-circle-fill me-1"></i> Assigned
                                            </span>
                                        <?php else: ?>
                                            <button type="submit" form="assign-form-<?= $st['id'] ?>" name="assign_adviser"
                                                class="btn-update" style="padding:8px; important;">
                                                <i class="bi bi-person-check me-1"></i> Assign
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SUPERVISOR REQUESTS -->
            <div id="supervisor_requests" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-person"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>HTE Supervisor Requests</h2>
                            <p>Review and approve HTE supervisor account requests from students across all rooms.</p>
                        </div>
                    </div>
                </div>

                <?php if (empty($supervisorRequests)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-person-tie fs-1 d-block mb-2"></i>
                        No supervisor requests yet.
                    </div>
                <?php else: ?>
                    <div style="display:flex; flex-direction:column; gap:12px;">
                        <?php foreach ($supervisorRequests as $req):
                            $avatarColors = ['#ff2c8f', '#2c6fff', '#1abc9c', '#9b59b6', '#e67e22'];
                            $avatarColor = $avatarColors[crc32($req['student_name']) % count($avatarColors)];
                            $statusStyles = [
                                'pending' => ['bg' => '#fffbeb', 'border' => '#fde68a', 'color' => '#92400e', 'label' => 'Pending Review'],
                                'approved' => ['bg' => '#f0fdf4', 'border' => '#bbf7d0', 'color' => '#065f46', 'label' => 'Approved'],
                                'rejected' => ['bg' => '#fef2f2', 'border' => '#fecaca', 'color' => '#dc2626', 'label' => 'Returned'],
                            ];
                            $st = $statusStyles[$req['status']] ?? $statusStyles['pending'];
                            ?>
                            <div style="background:white; border:1px solid #eee; border-radius:12px;
                            padding:18px 20px; box-shadow:0 1px 4px rgba(0,0,0,.04);">

                                <!-- Top row -->
                                <div style="display:flex; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                                    <div style="display:flex; align-items:center; gap:10px; flex:1; min-width:200px;">
                                        <div style="width:40px;height:40px;border-radius:50%;background:<?= $avatarColor ?>;
                                        color:white;display:flex;align-items:center;justify-content:center;
                                        font-weight:700;font-size:16px;flex-shrink:0;">
                                            <?= strtoupper(substr($req['student_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div style="font-weight:600;font-size:14px;">
                                                <?= htmlspecialchars($req['student_name']) ?>
                                            </div>
                                            <div style="font-size:12px;color:#888;">
                                                <?= htmlspecialchars($req['student_no']) ?> &middot;
                                                <?= htmlspecialchars($req['program']) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span style="padding:4px 12px; border-radius:99px; font-size:11px; font-weight:600;
                                     background:<?= $st['bg'] ?>; color:<?= $st['color'] ?>;
                                     border:1px solid <?= $st['border'] ?>; white-space:nowrap; align-self:center;">
                                        <?= $st['label'] ?>
                                    </span>
                                </div>

                                <!-- Supervisor details -->
                                <div
                                    style="margin-top:14px; display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
                                gap:10px; background:#f9fafb; border-radius:8px; padding:12px 14px; border:1px solid #f0f0f0;">
                                    <div>
                                        <div
                                            style="font-size:11px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">
                                            Supervisor Name</div>
                                        <div style="font-size:13px;font-weight:600;"><?= htmlspecialchars($req['full_name']) ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div
                                            style="font-size:11px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">
                                            Email</div>
                                        <div style="font-size:13px;"><?= htmlspecialchars($req['email'] ?? '—') ?></div>
                                    </div>
                                    <div>
                                        <div
                                            style="font-size:11px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">
                                            Contact</div>
                                        <div style="font-size:13px;"><?= htmlspecialchars($req['contact_number'] ?? '—') ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div
                                            style="font-size:11px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">
                                            Submitted</div>
                                        <div style="font-size:13px;"><?= date('M d, Y', strtotime($req['submitted_at'])) ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <?php if ($req['status'] === 'pending'): ?>
                                    <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                        <form action="superadmin-db.php" method="POST" style="margin:0;">
                                            <input type="hidden" name="submission_id" value="<?= $req['id'] ?>">
                                            <input type="hidden" name="student_id" value="<?= $req['student_id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" style="display:inline-flex;align-items:center;gap:6px;
                                    padding:8px 16px; border-radius:8px; border:none; cursor:pointer;
                                    background:#16a34a; color:white; font-size:13px; font-weight:500;
                                    font-family:inherit; transition:filter .15s;"
                                                onmouseover="this.style.filter='brightness(.9)'" onmouseout="this.style.filter=''">
                                                <i class="bi bi-check-circle"></i> Approve & Create Account
                                            </button>
                                        </form>
                                        <button onclick="toggleRejectSupAdmin(<?= $req['id'] ?>)" style="display:inline-flex;align-items:center;gap:6px;
                                       padding:8px 16px; border-radius:8px; cursor:pointer;
                                       background:white; color:#dc2626; font-size:13px; font-weight:500;
                                       border:1.5px solid #fca5a5; font-family:inherit; transition:filter .15s;"
                                            onmouseover="this.style.filter='brightness(.95)'" onmouseout="this.style.filter=''">
                                            <i class="bi bi-x-circle"></i> Return to Student
                                        </button>
                                    </div>

                                    <div id="reject-sup-admin-<?= $req['id'] ?>" style="display:none; margin-top:10px;">
                                        <form action="adviser-supervisor-action.php" method="POST">
                                            <input type="hidden" name="submission_id" value="<?= $req['id'] ?>">
                                            <input type="hidden" name="student_id" value="<?= $req['student_id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <textarea name="rejection_note" rows="2"
                                                placeholder="Reason for returning (optional)..." style="width:100%; padding:8px 12px; border:1.5px solid #fca5a5;
                                           border-radius:8px; font-size:13px; font-family:inherit;
                                           outline:none; resize:vertical; margin-bottom:6px;"></textarea>
                                            <button type="submit" style="padding:7px 14px; border-radius:8px; border:none;
                                    background:#dc2626; color:white; font-size:13px; font-weight:500;
                                    font-family:inherit; cursor:pointer;">
                                                <i class="bi bi-send me-1"></i> Confirm Return
                                            </button>
                                        </form>
                                    </div>

                                <?php elseif ($req['status'] === 'approved'): ?>
                                    <div
                                        style="margin-top:10px; font-size:12px; color:#16a34a; display:flex; align-items:center; gap:6px;">
                                        <i class="bi bi-check-circle-fill"></i>
                                        Account created &middot; Approved <?= date('M d, Y', strtotime($req['reviewed_at'])) ?>
                                    </div>

                                <?php elseif ($req['status'] === 'rejected'): ?>
                                    <div
                                        style="margin-top:10px; font-size:12px; color:#dc2626; display:flex; align-items:center; gap:6px;">
                                        <i class="bi bi-x-circle-fill"></i>
                                        Returned to student &middot; <?= date('M d, Y', strtotime($req['reviewed_at'])) ?>
                                        <?php if (!empty($req['rejection_note'])): ?>
                                            &middot; <em><?= htmlspecialchars($req['rejection_note']) ?></em>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- CSV ASSIGN ADVISER MODAL -->
            <div class="modal fade" id="csvAssignModal" tabindex="-1">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content rounded-4">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-file-earmark-spreadsheet me-2"></i>
                                <strong>Bulk Assign Advisers via CSV</strong>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div id="csv-assign-step-1">
                                <p style="font-size:13px;color:#888;">
                                    Upload a <code>.csv</code> file with two columns:
                                    <strong>student_id</strong> and <strong>adviser_id</strong><br>
                                    Only <strong>unassigned</strong> students will be processed. Already-assigned
                                    students are skipped.
                                </p>
                                <a href="download-csv-temp.php?type=assign_adviser"
                                    class="btn btn-sm btn-outline-secondary mb-3">
                                    <i class="bi bi-download me-1"></i> Download Template
                                </a>
                                <input type="file" id="csvAssignFileInput" accept=".csv,.tsv,.txt"
                                    class="form-control mb-3" onchange="previewAssignCSV(this)">
                                <div id="csv-assign-error" class="alert alert-danger d-none"></div>
                            </div>

                            <div id="csv-assign-step-2" class="d-none">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span style="font-size:13px;color:#555;" id="csv-assign-preview-count"></span>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="resetAssignCSV()">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Choose different file
                                    </button>
                                </div>
                                <div style="overflow-x:auto;max-height:380px;border:1px solid #eee;border-radius:8px;">
                                    <table class="table table-sm table-bordered mb-0" id="csv-assign-preview-table"
                                        style="font-size:12px;min-width:300px;"></table>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-button" id="csv-assign-confirm-btn" onclick="submitAssignCSV()"
                                disabled>
                                <i class="bi bi-upload me-1"></i> Confirm & Import
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- OJT HOURS -->
            <div id="ojt_hours" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Required OJT Hours per Program</h2>
                            <p>
                                Set the required number of OJT hours per program. Updates apply to
                                all internships sharing that program name. Advisers cannot override these values.
                            </p>
                        </div>
                    </div>
                </div>

                <?php if (empty($programHoursList)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        No programs found. Make sure internships have a program field set.
                    </div>
                <?php else: ?>

                    <form method="POST" action="superadmin-db.php">
                        <?php
                        $countStmt = $pdo->query("
                                SELECT program, COUNT(*) AS total
                                FROM internships
                                WHERE program IS NOT NULL AND program <> ''
                                GROUP BY program
                            ");
                        $programCounts = [];
                        foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            $programCounts[$row['program']] = $row['total'];
                        }

                        $hoursStmt = $pdo->prepare("
                            SELECT programs
                            FROM intenrships
                        ");

                        $cardStyles = [
                            'Civil Engineering' => ['color' => '#4f51a8', 'icon' => 'bi-pc-display'],
                            'Information Technology' => ['color' => '#e0483e', 'icon' => 'bi-code-slash'],
                            'Electrical Engineering' => ['color' => '#f2b705', 'icon' => 'bi-lightning-charge'],
                        ];
                        ?>

                        <div style="display:flex; flex-wrap:wrap; gap:20px; margin-bottom:24px;">
                            <?php foreach ($programHoursList as $ph):

                                $program = $ph['program'];

                                $style = $cardStyles[$program] ?? [
                                    'color' => '#6b7280',
                                    'icon' => 'bi-mortarboard'
                                ];

                                $count = (int) ($programCounts[$program] ?? 0);
                                ?>
                                <div style="flex:1; min-width:260px; background:#fff;
                                        border:1.5px solid #e5e7eb;
                                        border-left:5px solid <?= $style['color'] ?>;
                                        border-radius:10px; padding:18px 20px;">

                                    <!-- Header row: program name + icon -->
                                    <div style="display:flex; justify-content:space-between;
                                        align-items:flex-start; margin-bottom:16px;">

                                        <div>
                                            <div style="font-weight:700; font-size:16px; color:#272f54;">
                                                <?= htmlspecialchars($program) ?>
                                            </div>

                                            <div style="font-size:12.5px; color:#9ca3af;">
                                                <?= $count ?> internship(s) will be updated
                                            </div>
                                        </div>

                                        <div style="
                                                width:40px;
                                                height:40px;
                                                border-radius:10px;
                                                background:<?= $style['color'] ?>15;
                                                color:<?= $style['color'] ?>;
                                                display:flex;
                                                align-items:center;
                                                justify-content:center;
                                                font-size:20px;
                                            ">
                                            <i class="bi <?= $style['icon'] ?>"></i>
                                        </div>

                                    </div>
                                    <div style="display:flex; align-items:baseline; gap:6px;">
                                        <input type="hidden" name="program[]" value="<?= htmlspecialchars($program) ?>">

                                        <input type="number" name="required_hours[]" value="<?= (int) $ph['required_hours'] ?>"
                                            min="1" max="9999" required style="width:100%; border:none; outline:none; background:transparent;
                                                   font-size:28px; font-weight:700; color:#272f54;
                                                   font-family:inherit; padding:0;">
                                        <span style="font-size:13px; color:#9ca3af; white-space:nowrap;">hrs</span>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        </div>



                        <div style="display:flex; justify-content:flex-end;">
                            <button type="submit" name="save_program_hours" class="btn-update">
                                <i class="bi bi-floppy2 me-1"></i> Save Changes
                            </button>
                        </div>
                    </form>

                <?php endif; ?>
            </div>
        </div><!-- /.main-content -->
    </div><!-- /.layout -->

    <script>
        setTimeout(() => {
            const alert = document.getElementById('flashAlert');
            if (alert) {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 300);
            }
        }, 3000);

        document.addEventListener('DOMContentLoaded', function () {
            const role = document.getElementById('adviserRole');
            const wrapper = document.getElementById('internshipWrapper');
            const internship = document.getElementById('internshipSelect');

            function toggleInternshipDropdown() {
                if (role.value === 'HTE_adviser') {
                    wrapper.style.display = 'block';
                    internship.required = true;
                } else {
                    wrapper.style.display = 'none';
                    internship.required = false;
                    internship.value = '';
                }
            }

            role.addEventListener('change', toggleInternshipDropdown);
            toggleInternshipDropdown();
        });

        function filterAssignTable() {
            const search = document.getElementById('assign-search').value.toLowerCase();
            const adviser = document.getElementById('assign-filter-adviser').value.toLowerCase();

            document.querySelectorAll('#assign-tbody tr').forEach(row => {
                const name = row.dataset.name ?? '';
                const rowAdviser = row.dataset.adviser ?? '';

                const matchName = name.includes(search);
                const matchAdviser = adviser === ''
                    ? true
                    : adviser === 'unassigned'
                        ? rowAdviser === ''
                        : rowAdviser === adviser;

                row.style.display = (matchName && matchAdviser) ? '' : 'none';
            });
        }

        // function filterMonitor() {
        //     const search = document.getElementById('search-monitor').value.toLowerCase();
        //     document.querySelectorAll('#monitor-tbody tr').forEach(row => {
        //         row.style.display = row.innerText.toLowerCase().includes(search) ? '' : 'none';
        //     });
        // }

        // function filterDelete() {
        //     const search = document.getElementById('search-delete').value.toLowerCase();
        //     document.querySelectorAll('#delete-tbody tr').forEach(row => {
        //         row.style.display = row.innerText.toLowerCase().includes(search) ? '' : 'none';
        //     });
        // }

        function filterDelete() {
            const search = document.getElementById('search-delete').value.toLowerCase();
            const role = document.getElementById('filter-role').value.toLowerCase();

            document.querySelectorAll('#delete-tbody tr').forEach(row => {
                const rowText = row.innerText.toLowerCase();
                const matchesSearch = rowText.includes(search);
                const matchesRole = role === '' || rowText.includes(role);

                row.style.display = (matchesSearch && matchesRole) ? '' : 'none';
            });
        }

        function filterMonitor() {
            const search = document.getElementById('search-monitor').value.toLowerCase();
            const roleValue = document.getElementById('filter-role-monitor').value.toLowerCase();

            document.querySelectorAll('#monitor-tbody tr').forEach(row => {
                const rowText = row.innerText.toLowerCase();
                const matchesSearch = rowText.includes(search);
                const matchesRole = roleValue === '' || row.dataset.role === roleValue;

                row.style.display = (matchesSearch && matchesRole) ? '' : 'none';
            });
        }

        function filterRoles() {
            const search = document.getElementById('search-roles').value.toLowerCase();
            const role = document.getElementById('filter-role-admin').value.toLowerCase();

            document.querySelectorAll('#roles-tbody tr').forEach(row => {
                const rowText = row.innerText.toLowerCase();
                const matchesSearch = rowText.includes(search);
                const matchesRole = role === '' || rowText.includes(role.replace('_', ' '));

                row.style.display = (matchesSearch && matchesRole) ? '' : 'none';
            });
        }

        function downloadDashboardCSV() {
            let csv = '';
            csv += 'Recent Internship Postings\n"Title","Company","Location","Posted"\n';
            document.querySelectorAll('#table-internships tbody tr').forEach(row => {
                const cells = [...row.querySelectorAll('td')].map(td => `"${td.innerText.trim().replace(/\n/g, ' ').replace(/"/g, '""')}"`);
                if (cells.length) csv += cells.join(',') + '\n';
            });
            csv += '\nRecently Interested Students\n"Name","Email","Company","Date"\n';
            document.querySelectorAll('#table-interested .d-flex.align-items-center').forEach(row => {
                const name = row.querySelector('.fw-semibold')?.innerText.trim() ?? '';
                const email = row.querySelectorAll('p')[1]?.innerText.trim() ?? '';
                const company = row.querySelector('.badge')?.innerText.trim() ?? '';
                const date = row.querySelector('.text-muted.mb-0.mt-1')?.innerText.trim() ?? '';
                csv += `"${name}","${email}","${company}","${date}"\n`;
            });
            csv += '\nRecent Announcements\n"Category","Title","Date"\n';
            document.querySelectorAll('#table-announcements .d-flex.align-items-start').forEach(row => {
                const category = row.querySelector('.badge')?.innerText.trim() ?? '';
                const title = row.querySelector('.fw-semibold')?.innerText.trim() ?? '';
                const date = row.querySelector('.text-muted')?.innerText.trim() ?? '';
                csv += `"${category}","${title}","${date}"\n`;
            });
            const blob = new Blob([csv], { type: 'text/csv' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'dashboard_summary.csv';
            a.click();
        }

        function downloadDashboardPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            let y = 15;
            doc.setFontSize(16); doc.setTextColor(39, 47, 84);
            doc.text('Dashboard Summary', 14, y); y += 10;
            doc.setFontSize(12); doc.setTextColor(0, 0, 0);
            doc.text('Recent Internship Postings', 14, y); y += 2;
            const internshipRows = [];
            document.querySelectorAll('#table-internships tbody tr').forEach(row => {
                const cells = [...row.querySelectorAll('td')].map(td => td.innerText.trim().replace(/\n/g, ' '));
                if (cells.length) internshipRows.push(cells);
            });
            doc.autoTable({ head: [['Title', 'Company', 'Location', 'Posted']], body: internshipRows, startY: y, styles: { fontSize: 10 }, headStyles: { fillColor: [39, 47, 84], textColor: [255, 255, 255] } });
            y = doc.lastAutoTable.finalY + 10;
            doc.text('Recently Interested Students', 14, y); y += 2;
            const studentRows = [];
            document.querySelectorAll('#table-interested .d-flex.align-items-center').forEach(row => {
                studentRows.push([
                    row.querySelector('.fw-semibold')?.innerText.trim() ?? '',
                    row.querySelectorAll('p')[1]?.innerText.trim() ?? '',
                    row.querySelector('.badge')?.innerText.trim() ?? '',
                    row.querySelector('.text-muted.mb-0.mt-1')?.innerText.trim() ?? ''
                ]);
            });
            doc.autoTable({ head: [['Name', 'Email', 'Company', 'Date']], body: studentRows, startY: y, styles: { fontSize: 10 }, headStyles: { fillColor: [255, 182, 47], textColor: [39, 47, 84] } });
            y = doc.lastAutoTable.finalY + 10;
            doc.text('Recent Announcements', 14, y); y += 2;
            const announcementRows = [];
            document.querySelectorAll('#table-announcements .d-flex.align-items-start').forEach(row => {
                const title = row.querySelector('.fw-semibold')?.innerText.trim() ?? '';
                if (title) announcementRows.push([
                    row.querySelector('.badge')?.innerText.trim() ?? '',
                    title,
                    row.querySelector('.text-muted')?.innerText.trim() ?? ''
                ]);
            });
            doc.autoTable({ head: [['Category', 'Title', 'Date']], body: announcementRows, startY: y, styles: { fontSize: 10 }, headStyles: { fillColor: [228, 87, 46], textColor: [255, 255, 255] } });
            doc.save('dashboard_summary.pdf');
        }

        function addRow() {
            const tbody = document.getElementById('csv-tbody');
            const colCount = parseInt(document.getElementById('col-count').value);
            let rowCount = parseInt(document.getElementById('row-count').value);
            rowCount++;
            document.getElementById('row-count').value = rowCount;
            const tr = document.createElement('tr');
            for (let i = 0; i < colCount; i++) {
                const td = document.createElement('td');
                td.innerHTML = `<input type="text" name="csv[${rowCount}][${i}]" value="" class="form-control">`;
                tr.appendChild(td);
            }
            tbody.appendChild(tr);
        }
        let assignParsedHeaders = [];
        let assignParsedRows = [];

        function previewAssignCSV(input) {
            const file = input.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function (e) {
                const text = e.target.result.trim();
                const lines = text.split(/\r?\n/).filter(l => l.trim() !== '');
                if (lines.length < 2) {
                    showAssignCSVError('File must have at least a header row and one data row.');
                    return;
                }
                function parseCSVLine(line) {
                    const result = []; let cur = '', inQuotes = false;
                    for (let i = 0; i < line.length; i++) {
                        const ch = line[i];
                        if (ch === '"') { if (inQuotes && line[i + 1] === '"') { cur += '"'; i++; } else inQuotes = !inQuotes; }
                        else if (ch === ',' && !inQuotes) { result.push(cur.trim()); cur = ''; }
                        else { cur += ch; }
                    }
                    result.push(cur.trim());
                    return result;
                }
                assignParsedHeaders = parseCSVLine(lines[0]);
                assignParsedRows = lines.slice(1).map(l => parseCSVLine(l));
                const normalized = assignParsedHeaders.map(h => h.toLowerCase().trim());
                if (!normalized.includes('student_id') || !normalized.includes('adviser_id')) {
                    showAssignCSVError('Missing required columns: student_id and adviser_id');
                    return;
                }
                hideAssignCSVError();
                const table = document.getElementById('csv-assign-preview-table');
                let html = '<thead style="position:sticky;top:0;background:#f8f9fa;"><tr>';
                assignParsedHeaders.forEach(h => { html += `<th>${h}</th>`; });
                html += '</tr></thead><tbody>';
                assignParsedRows.forEach(row => { html += '<tr>' + row.map(cell => `<td>${cell}</td>`).join('') + '</tr>'; });
                html += '</tbody>';
                table.innerHTML = html;
                document.getElementById('csv-assign-preview-count').innerHTML =
                    `<strong>${assignParsedRows.length}</strong> row(s) to process`;
                document.getElementById('csv-assign-step-1').classList.add('d-none');
                document.getElementById('csv-assign-step-2').classList.remove('d-none');
                document.getElementById('csv-assign-confirm-btn').disabled = false;
            };
            reader.readAsText(file);
        }

        function resetAssignCSV() {
            assignParsedHeaders = []; assignParsedRows = [];
            document.getElementById('csvAssignFileInput').value = '';
            document.getElementById('csv-assign-step-1').classList.remove('d-none');
            document.getElementById('csv-assign-step-2').classList.add('d-none');
            document.getElementById('csv-assign-confirm-btn').disabled = true;
            hideAssignCSVError();
        }

        function showAssignCSVError(msg) {
            const el = document.getElementById('csv-assign-error');
            el.textContent = msg; el.classList.remove('d-none');
            document.getElementById('csv-assign-confirm-btn').disabled = true;
        }

        function hideAssignCSVError() {
            document.getElementById('csv-assign-error').classList.add('d-none');
        }

        function submitAssignCSV() {
            if (!assignParsedHeaders.length || !assignParsedRows.length) {
                showAssignCSVError('No data to submit.');
                return;
            }
            const btn = document.getElementById('csv-assign-confirm-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Processing...';

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'superadmin.php'; // posts back to itself

            const sourceInput = document.createElement('input');
            sourceInput.type = 'hidden'; sourceInput.name = 'bulk_assign_csv'; sourceInput.value = '1';
            form.appendChild(sourceInput);

            assignParsedHeaders.forEach((h, i) => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = `headers[${i}]`; input.value = h;
                form.appendChild(input);
            });
            assignParsedRows.forEach((row, rowIdx) => {
                row.forEach((cell, colIdx) => {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = `csv[${rowIdx}][${colIdx}]`; input.value = cell;
                    form.appendChild(input);
                });
            });
            document.body.appendChild(form);
            form.submit();
        }
        function toggleRejectSupAdmin(id) {
            const el = document.getElementById('reject-sup-admin-' + id);
            el.style.display = el.style.display === 'none' ? 'block' : 'none';
        }
        document.addEventListener('DOMContentLoaded', function () {
            const params = new URLSearchParams(window.location.search);
            if (params.get('section') === 'ojt_hours') {
                document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
                const sec = document.getElementById('ojt_hours');
                if (sec) sec.classList.add('active');
                document.querySelectorAll('.sidebar a').forEach(l => l.classList.remove('active'));
                const link = document.querySelector('.sidebar a[onclick*="ojt_hours"]');
                if (link) link.classList.add('active');
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>