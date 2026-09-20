<?php
session_start();
require 'db.php';
require 'auth.php';

$student_id = $_SESSION['user_id'];
$page = $page ?? "";
$isAdviser = isset($_SESSION['role']) && $_SESSION['role'] === 'internship_adviser';

$current_room_id = $_GET['room_id'] ?? null;
if ($current_room_id !== null && $current_room_id !== '' && ctype_digit((string) $current_room_id)) {
    $current_room_id = (int) $current_room_id;
} else {
    $current_room_id = null;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM ojt_evaluations_student
    WHERE student_id = ? LIMIT 1
");
$stmt->execute([$student_id]);
$studentEval = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT r.id, r.room_name, r.adviser_id
    FROM room_members rm
    JOIN rooms r ON r.id = rm.room_id
    WHERE rm.user_id = ? AND rm.user_type = 'student' AND r.is_archived = FALSE
    ORDER BY r.id
");
$stmt->execute([$student_id]);
$my_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Auto-load assigned room for students (no redirect, just set the variable)
if ($_SESSION['role'] === 'student') {
    $stmt = $pdo->prepare("
        SELECT a.id AS adviser_id
        FROM ojt_applications oa
        JOIN students s ON s.id = oa.student_id
        JOIN advisers a ON a.internship_id = oa.internship_id
                        AND a.department = s.program
                        AND a.role = 'HTE_adviser'
        WHERE oa.student_id = ?
        ORDER BY oa.submitted_at DESC
        LIMIT 1
    ");
    $stmt->execute([$student_id]);
    $placement = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($placement && $placement['adviser_id']) {
        $adviser_id = $placement['adviser_id'];

        $stmt = $pdo->prepare("
            SELECT id FROM rooms
            WHERE adviser_id = ?
            AND is_archived = FALSE
            LIMIT 1
        ");
        $stmt->execute([$adviser_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($room) {
            $checkStmt = $pdo->prepare("
                SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ? AND user_type = 'student'
            ");
            $checkStmt->execute([$room['id'], $student_id]);

            if (!$checkStmt->fetch()) {
                $joinStmt = $pdo->prepare("
                    INSERT INTO room_members (room_id, user_id, user_type)
                    VALUES (?, ?, 'student')
                ");
                $joinStmt->execute([$room['id'], $student_id]);
            }

            $current_room_id = (int) $room['id'];
        }
    }
}

// for the application section
// $bookmarksStmt = $pdo->prepare("
//     SELECT 
//         ib.id AS bookmark_id,
//         ib.internship_id,
//         ib.created_at,
//         i.title,
//         i.company,
//         i.location,
//         i.program,
//         i.deadline,
//         i.internship_type,
//         CASE
//             WHEN sp_ojt.is_done = TRUE  THEN 'Internship Confirmed'
//             WHEN sp_doc.is_done = TRUE  THEN 'Documents Submitted'
//             WHEN sp_app.is_done = TRUE  THEN 'Application Submitted'
//             WHEN sd.student_id IS NOT NULL THEN 'Resume Uploaded'
//             ELSE 'No Progress'
//         END AS current_phase
//     FROM internship_bookmarks ib
//     JOIN internships i ON i.id = ib.internship_id
//     LEFT JOIN student_documents sd 
//         ON sd.student_id = ib.student_id
//     LEFT JOIN student_progress sp_app 
//         ON sp_app.student_id = ib.student_id AND sp_app.step_key = 'application'
//     LEFT JOIN student_progress sp_doc 
//         ON sp_doc.student_id = ib.student_id AND sp_doc.step_key = 'documents'
//     LEFT JOIN student_progress sp_ojt 
//         ON sp_ojt.student_id = ib.student_id AND sp_ojt.step_key = 'ojt_accepted'
//     WHERE ib.student_id = ?
//     ORDER BY ib.created_at DESC
// ");
// $bookmarksStmt->execute([$_SESSION['user_id']]);
// $bookmarkedInternships = $bookmarksStmt->fetchAll(PDO::FETCH_ASSOC);

//for the applications list
$application_internship_id = null;

$stmt = $pdo->prepare("
    SELECT internship_id
    FROM ojt_applications
    WHERE student_id = ?
    ORDER BY id DESC
    LIMIT 1
");

$stmt->execute([$_SESSION['user_id']]);

$application = $stmt->fetch(PDO::FETCH_ASSOC);

if ($application) {
    $application_internship_id = $application['internship_id'];
}

//to get all the available co.
$stmt = $pdo->query("
    SELECT id, company, title
    FROM internships
    ORDER BY company ASC
");

$availableInternships = $stmt->fetchAll(PDO::FETCH_ASSOC);

// for rooms (only used by advisers now)
$stmt = $pdo->prepare("
    SELECT r.*, a.full_name, a.title, a.role
    FROM rooms r
    LEFT JOIN advisers a ON r.adviser_id = a.id
    JOIN room_members rm ON r.id = rm.room_id
    WHERE rm.user_id = ? AND rm.user_type = 'student'
    " . (!$isAdviser ? "AND r.is_archived = FALSE" : "") . "
");
$stmt->execute([$_SESSION['user_id']]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$current_section = $_GET['section'] ?? 'home';
$current_chat_id = $_GET['chat_id'] ?? null;
$current_chat_type = $_GET['chat_type'] ?? null;
$current_user_type = getUserType($_SESSION['role']);

$stmt = $pdo->prepare("
    SELECT 
        CASE 
            WHEN sender_id = :uid THEN receiver_id
            ELSE sender_id
        END as chat_user_id,
        CASE 
            WHEN sender_id = :uid THEN receiver_type
            ELSE sender_type
        END as chat_user_type,
        CONCAT(
            LEAST(sender_id, receiver_id), '-',
            GREATEST(sender_id, receiver_id), '-',
            CASE 
                WHEN sender_id = :uid THEN receiver_type
                ELSE sender_type
            END
        ) AS chat_key
    FROM messages
    WHERE sender_id = :uid OR receiver_id = :uid
    GROUP BY chat_key, chat_user_id, chat_user_type
    ORDER BY MAX(created_at) DESC
");
$stmt->execute(['uid' => $_SESSION['user_id']]);
$chatUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$chatStudents = [];

if (!empty($_GET['room_id']) && ctype_digit((string) $_GET['room_id'])) {
    $room_id = (int) $_GET['room_id'];

    // verify they're actually a member of this room before trusting it
    $verify = $pdo->prepare("
        SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ? AND user_type = 'student'
    ");
    $verify->execute([$room_id, $student_id]);
    if ($verify->fetch()) {
        $current_room_id = $room_id;
    }
}

// //This is for adding the student to hte adviser's room
// if (!isset($_GET['room_id'])) {
//     // Find HTE Adv
//     $stmt = $pdo->prepare("
//         SELECT i.adviser_id
//         FROM ojt_applications oa
//         JOIN internships i ON i.id = oa.internship_id
//         WHERE oa.student_id = ?
//         ORDER BY oa.submitted_at DESC
//         LIMIT 1
//     ");
//     $stmt->execute([$student_id]);
//     $placement = $stmt->fetch(PDO::FETCH_ASSOC);

//     if (!$placement || !$placement['adviser_id']) {
//         die("No internship application with an assigned adviser found.");
//     }

//     $adviser_id = $placement['adviser_id'];

//     // Find The Specific Room Of The HTE
//     $stmt = $pdo->prepare("
//         SELECT id FROM rooms
//         WHERE adviser_id = ? 
//         AND is_archived = FALSE
//         LIMIT 1
//     ");
//     $stmt->execute([$adviser_id]);
//     $room = $stmt->fetch(PDO::FETCH_ASSOC);

//     if (!$room) {
//         die("Your adviser hasn't set up a room yet. Please check back later.");
//     }
//     // Get the room ID
//     $checkStmt = $pdo->prepare("
//         SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ? AND user_type = 'student'
//     ");
//     $checkStmt->execute([$room['id'], $student_id]);

//     if (!$checkStmt->fetch()) {
//         $joinStmt = $pdo->prepare("
//             INSERT INTO room_members (room_id, user_id, user_type)
//             VALUES (?, ?, 'student')
//         ");
//         $joinStmt->execute([$room['id'], $student_id]);
//     }

//     header("Location: message.php?room_id=" . $room['id']);
//     exit;
// }

if ($current_room_id !== null) {
    $chatStudentsStmt = $pdo->prepare("
        SELECT
            s.id AS user_id,
            s.full_name,
            'student' AS user_type
        FROM students s
        JOIN room_members rm
            ON s.id = rm.user_id
           AND rm.user_type = 'student'
        WHERE rm.room_id = ?
        ORDER BY s.full_name
    ");

    $chatStudentsStmt->execute([$current_room_id]);
    $chatStudents = $chatStudentsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$chatHteAdvisersStmt = $pdo->prepare("
    SELECT DISTINCT
        a.id          AS user_id,
        a.full_name,
        'adviser'     AS user_type
    FROM advisers a
    JOIN internship_bookmarks ib
        ON a.internship_id = ib.internship_id
    WHERE ib.student_id = ?
      AND a.role = 'HTE_adviser'
    ORDER BY a.full_name
");
$chatHteAdvisersStmt->execute([$_SESSION['user_id']]);
$chatHteAdvisers = $chatHteAdvisersStmt->fetchAll(PDO::FETCH_ASSOC);

$roomAdviserStmt = $pdo->prepare("
    SELECT
        a.id          AS user_id,
        a.full_name,
        'adviser'     AS user_type
    FROM advisers a
    JOIN room_members rm_adviser
        ON a.id = rm_adviser.user_id
       AND rm_adviser.user_type = 'adviser'
    JOIN room_members rm_student
        ON rm_adviser.room_id = rm_student.room_id
    WHERE rm_student.user_id = ?
      AND rm_student.user_type = 'student'
    ORDER BY a.full_name
");
$roomAdviserStmt->execute([$_SESSION['user_id']]);
$roomAdvisers = $roomAdviserStmt->fetchAll(PDO::FETCH_ASSOC);

$chatSection_id = $_GET['chat_id'] ?? null;
$chatSection_type = $_GET['chat_type'] ?? null;
$chatMessages = [];

// var_dump($current_user_type, $_SESSION['role'], $chatSection_id, $chatSection_type);
// exit;

if ($chatSection_id && $current_section === 'chats') {
    $msgStmt = $pdo->prepare("
        SELECT m.*,
               COALESCE(s.full_name, a.full_name) AS sender_name
        FROM   messages m
        LEFT JOIN students s ON m.sender_id = s.id AND m.sender_type = 'student'
        LEFT JOIN advisers a ON m.sender_id = a.id AND m.sender_type = 'adviser'
        WHERE
            (m.sender_id = ? AND m.sender_type = ?
             AND m.receiver_id = ? AND m.receiver_type = ?)
            OR
            (m.sender_id = ? AND m.sender_type = ?
             AND m.receiver_id = ? AND m.receiver_type = ?)
        ORDER BY m.created_at ASC
    ");
    $msgStmt->execute([
        $_SESSION['user_id'],
        $current_user_type,
        $chatSection_id,
        $chatSection_type,
        $chatSection_id,
        $chatSection_type,
        $_SESSION['user_id'],
        $current_user_type,
    ]);
    $chatMessages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
}

$chatLookup = [];
foreach ($chatUsers as $cu) {
    $key = $cu['chat_user_id'] . '-' . $cu['chat_user_type'];
    $chatLookup[$key] = $cu;
}

// Helper – get display name for the open chat header
function getRoomChatName($pdo, $id, $type)
{
    $tbl = ($type === 'student') ? 'students' : 'advisers';
    $col = ($tbl === 'admins') ? 'name' : 'full_name';
    $stmt = $pdo->prepare("SELECT full_name FROM {$tbl} WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['full_name'] ?? 'Unknown';
}

$avatarPalette = ['#ff2c8f', '#2c6fff', '#1abc9c', '#9b59b6', '#e67e22', '#e74c3c', '#16a085'];


$messages = [];

$requiredSteps = ['mou', 'waiver', 'reco_letter'];
$selectedInternship = [];
if (!empty($application_internship_id)) {
    $intStmt = $pdo->prepare("
        SELECT company_classification, is_plv_internal, is_valenzuela_lgu
        FROM internships
        WHERE id = ?
    ");
    $intStmt->execute([(int) $application_internship_id]);
    $selectedInternship = $intStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// 2. Your logic, unchanged
$classification = $selectedInternship['company_classification'] ?? 'private';
$is_plv = filter_var($selectedInternship['is_plv_internal'] ?? false, FILTER_VALIDATE_BOOLEAN);
$is_val_lgu = filter_var($selectedInternship['is_valenzuela_lgu'] ?? false, FILTER_VALIDATE_BOOLEAN);
$is_public = ($classification === 'public');

$needs_bir_dti_sec = !$is_public;
$needs_waiver = !$is_plv;
$needs_reco_letter = !$is_plv && !$is_val_lgu;

// 3. Required steps (addendum = your MOU step)
$requiredSteps = ['addendum'];
if ($needs_reco_letter)
    $requiredSteps[] = 'reco_letter';
if ($needs_waiver)
    $requiredSteps[] = 'waiver';

$placeholders = implode(',', array_fill(0, count($requiredSteps), '?'));
$hasProgressStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT step_key)
    FROM student_progress
    WHERE student_id = ? AND is_done = TRUE AND step_key IN ($placeholders)
");
$hasProgressStmt->execute(array_merge([$_SESSION['user_id']], $requiredSteps));

// locked if the student hasn't chosen an internship yet
$hasActiveProgress = !empty($application_internship_id)
    && (int) $hasProgressStmt->fetchColumn() === count($requiredSteps);

if ($current_chat_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM messages
        WHERE 
            (sender_id = ? AND sender_type = ? AND receiver_id = ? AND receiver_type = ?)
            OR
            (sender_id = ? AND sender_type = ? AND receiver_id = ? AND receiver_type = ?)
        ORDER BY created_at ASC
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        getUserType($_SESSION['role']),
        $current_chat_id,
        $current_chat_type,
        $current_chat_id,
        $current_chat_type,
        $_SESSION['user_id'],
        getUserType($_SESSION['role'])
    ]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getUserName($pdo, $id, $type)
{
    switch (strtolower($type)) {
        case 'student':
            $stmt = $pdo->prepare("SELECT full_name FROM students WHERE id=?");
            break;
        case 'adviser':
            $stmt = $pdo->prepare("SELECT full_name FROM advisers WHERE id=?");
            break;
        case 'admin':
            $stmt = $pdo->prepare("SELECT name FROM admins WHERE id=?");
            break;
        default:
            return "Unknown ($type)";
    }
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user['full_name'] ?? $user['name'] ?? "Unknown";
}

function ensureOjtTables($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ojt_weeks (
        user_id INTEGER NOT NULL,
        user_type VARCHAR(32) NOT NULL,
        week_index INTEGER NOT NULL,
        week_label VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
        updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
        PRIMARY KEY (user_id, user_type, week_index)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ojt_hours (
        user_id INTEGER NOT NULL,
        user_type VARCHAR(32) NOT NULL,
        week_index INTEGER NOT NULL,
        row_index INTEGER NOT NULL,
        date DATE,
        m_in TIME,
        m_out TIME,
        a_in TIME,
        a_out TIME,
        created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
        updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
        PRIMARY KEY (user_id, user_type, week_index, row_index)
    )");
}

function loadOjtWeeks($pdo, $userId, $userType)
{
    ensureOjtTables($pdo);

    $stmt = $pdo->prepare("SELECT w.week_index, w.week_label, h.row_index, h.date, h.m_in, h.m_out, h.a_in, h.a_out
        FROM ojt_weeks w
        LEFT JOIN ojt_hours h
            ON h.user_id = w.user_id
            AND h.user_type = w.user_type
            AND h.week_index = w.week_index
        WHERE w.user_id = ? AND w.user_type = ?
        ORDER BY w.week_index, h.row_index");
    $stmt->execute([$userId, $userType]);

    $weeks = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $wi = (int) $row['week_index'];
        if (!isset($weeks[$wi])) {
            $weeks[$wi] = [
                'week_index' => $wi,
                'week_label' => $row['week_label'] !== '' ? $row['week_label'] : 'Week ' . ($wi + 1),
                'rows' => []
            ];
        }
        if ($row['row_index'] !== null) {
            $weeks[$wi]['rows'][(int) $row['row_index']] = [
                'date' => $row['date'] ?? '',
                'm_in' => $row['m_in'] ?? '',
                'm_out' => $row['m_out'] ?? '',
                'a_in' => $row['a_in'] ?? '',
                'a_out' => $row['a_out'] ?? ''
            ];
        }
    }

    if (empty($weeks)) {
        return [
            [
                'week_index' => 0,
                'week_label' => 'Week 1',
                'rows' => array_map(fn($i) => ['date' => '', 'm_in' => '', 'm_out' => '', 'a_in' => '', 'a_out' => ''], range(0, 5))
            ]
        ];
    }

    foreach ($weeks as &$week) {
        for ($i = 0; $i < 6; $i++) {
            if (!isset($week['rows'][$i])) {
                $week['rows'][$i] = ['date' => '', 'm_in' => '', 'm_out' => '', 'a_in' => '', 'a_out' => ''];
            }
        }
        ksort($week['rows']);
        $week['rows'] = array_values($week['rows']);
    }

    return array_values($weeks);
}

$ojtTimeIn = null;
$ojtTimeOut = null;

$studentId = $_SESSION['student_id'] ?? ($_GET['student_id'] ?? null);

$ojtTimeIn = null;
$ojtTimeOut = null;

if ($student_id) {
    $stmt = $pdo->prepare("
        SELECT i.ojt_time_in, i.ojt_time_out
        FROM ojt_applications oa
        JOIN internships i ON i.id = oa.internship_id
        WHERE oa.student_id = ?
        LIMIT 1
    ");
    $stmt->execute([$student_id]);
    $internship = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($internship) {
        $ojtTimeIn = $internship['ojt_time_in'];
        $ojtTimeOut = $internship['ojt_time_out'];
    }
}

$ojtWeeks = loadOjtWeeks($pdo, $_SESSION['user_id'], $current_user_type);
$colors = ['#d63ba5', '#1abc9c', '#3498db', '#9b59b6'];
$color = $colors[array_rand($colors)];
$page = 'messages';

$rhStmt = $pdo->prepare("
    SELECT COALESCE(i.required_hours, 486)
    FROM internship_bookmarks ib
    JOIN internships i ON i.id = ib.internship_id
    WHERE ib.student_id = ?
    LIMIT 1
");
$rhStmt->execute([$_SESSION['user_id']]);
$requiredHours = $rhStmt->fetchColumn() ?: 486;

$stmt = $pdo->prepare("
    SELECT 
        s.id AS student_id,
        s.student_id AS course_student_no,
        s.full_name AS intern_name,
        s.student_id,
        s.program,
        oa.internship_id,
        i.company AS company_name,
        a.id AS supervisor_id,
        a.full_name AS supervisor_name
    FROM students s
    JOIN ojt_applications oa ON s.id = oa.student_id
    JOIN internships i ON oa.internship_id = i.id
    LEFT JOIN advisers a ON a.internship_id = i.id AND a.role = 'HTE_adviser' AND a.department = s.program
    WHERE s.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Virtual Rooms</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
        }

        body {
            background: #f5f6fa;
            margin: 0;
            padding-top: 70px;
            height: 100vh;
            overflow: auto;
        }

        /* SIDEBAR */
        .sidebar {
            width: 240px;
            background: #fff;
            position: fixed;
            top: 70px;
            bottom: 0;
            padding: 20px;
            border-right: 1px solid #ddd;
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .sidebar a {
            display: block;
            align-items: center;
            padding: 10px 12px;
            color: #333;
            text-decoration: none;
            border-radius: 10px;
            margin-bottom: 6px;
            font-size: 16px;
            font-weight: 500;
        }

        .sidebar a:hover {
            background: #f0f0f0;
        }

        .sidebar a.active {
            background: #ffe5d9;
            color: #ff6b2c;
        }

        .rooms-list {
            font-size: 11px;
            color: #585858;
            margin-top: 20px;
        }

        .room-item {
            padding: 8px 10px;
            border-radius: 10px;
            font-size: 13px;
        }

        .room-link {
            text-decoration: none;
            display: block;
            margin: 4px;
        }

        .room-link .room-item:hover {
            cursor: pointer;
        }

        .active-room {
            background: #ffe5d9;
            color: #ff6b2c;
            font-weight: bold;
            cursor: default;
        }

        /* MAIN */
        .main {
            margin-left: 260px;
            padding: 20px;
            background-color: #fff;
            height: 100%;
        }

        /* SECTION */
        .section {
            display: none;
        }

        .section.active {
            display: block;
        }

        /* CHAT DESIGN */
        .chat-container {
            margin: -20px;
            display: flex !important;
            height: calc(100vh - 70px);
            width: calc(100% + 40px);
            overflow: hidden;
        }

        .chat-list,
        .profile-sidebar {
            width: 280px;
            overflow-y: auto;
            flex-shrink: 0;
        }

        .chat-list {
            border-right: 1px solid #eee;
        }

        .message-area {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
        }

        #text {
            font-size: 14px;
        }

        .fw-bold {
            font-size: 15px;
        }

        .message-content {
            flex-grow: 1;
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            background: #FCFCFC;
        }

        .message-input-box {
            flex-grow: 1;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 25px;
            padding: 8px 20px;
            outline: none;
        }

        .profile-sidebar {
            width: 300px;
            flex-shrink: 0;
            overflow-y: auto;
        }

        .tab-item {
            flex: 1;
            padding-bottom: 10px;
            cursor: pointer;
            font-weight: 600;
            color: #888;
            text-align: center;
        }

        .tab-item.active-tab {
            color: #29335C;
        }

        .avatar-circle {
            width: 45px;
            height: 45px;
            background-color: #e0e0e0;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .big-avatar {
            width: 100px;
            height: 100px;
            background-color: #e0e0e0;
            border-radius: 50%;
            margin-bottom: 15px;
        }

        .media-tabs {
            display: flex;
            width: 100%;
            position: relative;
            border-bottom: 2px solid #eee;
            margin-top: 20px;
        }

        .content-pane {
            display: none;
            width: 100%;
        }

        .active-pane {
            display: block;
        }

        .tab-indicator {
            position: absolute;
            bottom: -2px;
            left: 0;
            height: 3px;
            width: 50%;
            background: #29335C;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .tab-content-area {
            position: relative;
            width: 100%;
            align-self: stretch;
        }

        .content-pane.active-pane {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .icon-btn {
            font-size: 1.3rem;
            color: #29335C;
            cursor: pointer;
        }

        .send-btn {
            color: #29335C;
            font-size: 1.3rem;
            transform: rotate(0deg);
        }

        .bubble {
            max-width: 70%;
            padding: 7px 15px;
            border-radius: 20px;
            margin-bottom: 8px;
        }

        .incoming {
            background: #ffcc80;
            color: #333;
            align-self: flex-start;
        }

        .outgoing {
            background: #f8d7da;
            color: #333;
            align-self: flex-end;
        }

        .chat-entry-hidden {
            display: none !important;
        }

        /* chat slide */
        .chat-slide-track {
            display: flex;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }

        .chat-panel-screen {
            display: flex;
            height: 100%;
            overflow: hidden;
            flex-shrink: 0;
        }

        .chat-panel-screen:nth-child(1) {
            width: 280px;
            flex-shrink: 0;
        }

        .chat-panel-screen:nth-child(2) {
            flex: 1;
            flex-shrink: 1;
            min-width: 0;
        }

        .chat-panel-screen:nth-child(3) {
            width: 300px;
            flex-shrink: 0;
            border-left: 1px solid #eee;
        }

        .mobile-chat-header,
        .mobile-profile-header {
            display: none;
        }

        /* room initial */
        .room-initial {
            display: none;
        }

        .ojt-week-block {
            background: #fafafa;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 24px;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #c0c0c0;
            text-align: center;
        }

        .ojt-table {
            width: 100%;
            align-items: center;
        }

        .ojt-table th {
            padding: 10px;
            font-size: 14px;
            font-weight: 600;
            color: #29303b;
        }

        .ojt-table td {
            padding: 4px;
        }

        .ojt-table input[type="date"],
        .ojt-table input[type="time"],
        .ojt-week-label {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            text-align: center;
            background: #fff;
        }

        .ojt-table input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .ojt-group {
            background: rgb(185, 186, 237);
            border: 1px solid #e5e7eb;
        }

        .th-morning,
        .sub-morning,
        .td-morning {
            background: #ffe9c6;
        }

        .th-afternoon,
        .sub-afternoon,
        .td-afternoon {
            background: #ffdec4;
        }

        .ojt-hrs-val,
        .ojt-daily-val {
            font-weight: 600;
            color: #111827;
        }

        .ojt-total-chip {
            background: #2563eb;
            color: white;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
        }

        .ojt-week-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .ojt-add-btn {
            border: 2px dashed #cbd5e1;
            background: white;
            padding: 14px;
            border-radius: 12px;
            width: 100%;
            cursor: pointer;
            transition: 0.2s;
        }

        .ojt-add-btn:hover {
            background: #f8fafc;
        }

        .rc-wrap {
            display: flex;
            height: calc(100vh - 70px);
            margin: -24px;
            /* cancel .main padding */
            overflow: hidden;
            background: #f5f6fa;
        }

        /* ── LEFT PANEL – contact list ── */
        .rc-list {
            width: 290px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            background: #fff;
            border-right: 1px solid #ebebeb;
            overflow: hidden;
        }

        .rc-list-head {
            padding: 18px 18px 10px;
            flex-shrink: 0;
        }

        .rc-list-head h5 {
            font-weight: 700;
            font-size: 16px;
            margin: 0 0 12px;
            color: #1a1a2e;
        }

        .rc-search {
            position: relative;
        }

        .rc-search input {
            width: 100%;
            padding: 8px 14px 8px 36px;
            border: 1.5px solid #e5e7eb;
            border-radius: 22px;
            font-size: 13px;
            font-family: inherit;
            outline: none;
            transition: border-color .2s;
            background: #f9fafb;
        }

        .rc-search input:focus {
            border-color: #ff6b2c;
            background: #fff;
        }

        .rc-search i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            font-size: 12px;
            pointer-events: none;
        }

        .rc-entries {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 12px;
        }

        .rc-entries::-webkit-scrollbar {
            width: 4px;
        }

        .rc-entries::-webkit-scrollbar-thumb {
            background: #eee;
            border-radius: 4px;
        }

        .rc-divider {
            padding: 8px 18px 4px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .7px;
            text-transform: uppercase;
            color: #b0b0b0;
            background: #fafafa;
            border-bottom: 1px solid #f0f0f0;
            border-top: 1px solid #f0f0f0;
            margin-top: 4px;
        }

        .rc-entry {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 18px;
            text-decoration: none;
            color: inherit;
            border-bottom: 1px solid #f8f8f8;
            transition: background .15s;
            cursor: pointer;
        }

        .rc-entry:hover {
            background: #fdf6f3;
        }

        .rc-entry.active {
            background: #fff3ec;
            border-left: 3px solid #ff6b2c;
        }

        .rc-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
            color: #fff;
            flex-shrink: 0;
        }

        .rc-meta {
            flex: 1;
            min-width: 0;
        }

        .rc-name {
            font-weight: 600;
            font-size: 13.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #1a1a2e;
        }

        .rc-preview {
            font-size: 11.5px;
            color: #aaa;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 2px;
        }

        .rc-badge {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 99px;
            font-weight: 600;
            flex-shrink: 0;
        }

        #prDropZone:hover {
            border-color: #ff6b2c;
            background: #fff8f5;
        }

        /* ── RIGHT PANEL – message area ── */
        .rc-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #fcfcfd;
            min-width: 0;
            overflow: hidden;
        }

        .rc-header {
            padding: 14px 22px;
            border-bottom: 1px solid #ebebeb;
            background: #fff;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .04);
        }

        .rc-header-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .rc-header-name {
            font-weight: 700;
            font-size: 14.5px;
            color: #1a1a2e;
        }

        .rc-header-sub {
            font-size: 11px;
            color: #aaa;
            margin-top: 1px;
        }

        /* messages body */
        .rc-body {
            flex: 1;
            overflow-y: auto;
            padding: 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .rc-body::-webkit-scrollbar {
            width: 4px;
        }

        .rc-body::-webkit-scrollbar-thumb {
            background: #ddd;
            border-radius: 4px;
        }

        .rc-day-sep {
            text-align: center;
            font-size: 11px;
            color: #ccc;
            position: relative;
            margin: 6px 0;
        }

        .rc-day-sep::before,
        .rc-day-sep::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 40%;
            height: 1px;
            background: #f0f0f0;
        }

        .rc-day-sep::before {
            left: 0;
        }

        .rc-day-sep::after {
            right: 0;
        }

        .rc-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }

        .rc-row.me {
            flex-direction: row-reverse;
        }

        .rc-row-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .rc-bwrap {
            max-width: 65%;
        }

        .rc-sender {
            font-size: 11px;
            color: #bbb;
            margin-bottom: 3px;
            padding-left: 3px;
        }

        .rc-row.me .rc-sender {
            text-align: right;
            padding-left: 0;
            padding-right: 3px;
        }

        .rc-bubble {
            padding: 9px 15px;
            border-radius: 18px;
            font-size: 13.5px;
            line-height: 1.5;
            word-break: break-word;
        }

        .rc-row:not(.me) .rc-bubble {
            background: #fff;
            border: 1px solid #ece8e8;
            border-bottom-left-radius: 4px;
            color: #222;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .05);
        }

        .rc-row.me .rc-bubble {
            background: linear-gradient(135deg, #ff6b2c, #ff8c55);
            color: #fff;
            border-bottom-right-radius: 4px;
            box-shadow: 0 2px 8px rgba(255, 107, 44, .3);
        }

        .rc-time {
            font-size: 10.5px;
            color: #ccc;
            margin-top: 4px;
            padding-left: 3px;
        }

        .rc-row.me .rc-time {
            text-align: right;
            padding-left: 0;
            padding-right: 3px;
        }

        /* empty state */
        .rc-empty {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #ccc;
            gap: 10px;
        }

        .rc-empty i {
            font-size: 2.5rem;
        }

        .rc-empty p {
            font-size: 14px;
            margin: 0;
        }

        /* input bar */
        .rc-inputbar {
            border-top: 1px solid #ebebeb;
            background: #fff;
            padding: 12px 18px;
            flex-shrink: 0;
        }

        .rc-inputrow {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rc-attach-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: background .15s;
        }

        .rc-attach-btn:hover {
            background: #f3f4f6;
        }

        .rc-inputrow textarea {
            flex: 1;
            border: 1.5px solid #e5e7eb;
            border-radius: 24px;
            padding: 10px 18px;
            font-size: 13.5px;
            line-height: 1.4;
            max-height: 100px;
            overflow-y: auto;
            font-family: inherit;
            resize: none;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            background: #f9fafb;
        }

        .rc-inputrow textarea:focus {
            border-color: #ff6b2c;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(255, 107, 44, .08);
        }

        .rc-send-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ff6b2c, #ff8c55);
            color: #fff;
            border: none;
            cursor: pointer;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(255, 107, 44, .35);
            transition: filter .15s, transform .1s;
        }

        .rc-send-btn:hover {
            filter: brightness(.92);
            transform: scale(1.05);
        }

        .rc-send-btn:disabled {
            background: #ffc5a8;
            box-shadow: none;
            cursor: not-allowed;
            transform: none;
        }

        .rc-attach-preview {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            background: #f3f4f6;
            border-radius: 12px;
            margin-bottom: 8px;
        }

        .rc-attach-thumb-wrap {
            position: relative;
            width: 52px;
            height: 52px;
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
            border: 1px solid #e5e7eb;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .rc-attach-thumb-wrap img,
        .rc-attach-thumb-wrap video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .rc-attach-remove {
            position: absolute;
            top: -6px;
            right: -6px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #dc2626;
            color: #fff;
            border: none;
            font-size: 11px;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .rc-attach-filename {
            font-size: 12px;
            color: #555;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 180px;
        }

        .rc-msg-delete {
            background: none;
            border: none;
            color: #ccc;
            cursor: pointer;
            font-size: 12px;
            opacity: 0;
            align-self: center;
            padding: 4px;
            transition: opacity .15s, color .15s;
        }

        .rc-row.me:hover .rc-msg-delete {
            opacity: 1;
        }

        .rc-msg-delete:hover {
            color: #dc2626;
        }

        /* ── mobile tweaks ── */
        @media (max-width: 768px) {
            .rc-wrap {
                margin: -15px;
                height: calc(100vh - 70px);
            }

            .rc-list {
                width: 100%;
                position: absolute;
                z-index: 10;
                height: 100%;
                transition: transform .3s ease;
            }

            .rc-list.slide-out {
                transform: translateX(-100%);
            }

            .rc-panel {
                width: 100%;
            }

            .rc-mobile-back {
                display: flex !important;
                align-items: center;
                gap: 10px;
                padding: 12px 16px;
                border-bottom: 1px solid #eee;
                background: #fff;
                flex-shrink: 0;
            }

            .rc-mobile-back button {
                background: none;
                border: none;
                font-size: 1.1rem;
                color: #ff6b2c;
                cursor: pointer;
                padding: 0;
            }

            .rc-mobile-back span {
                font-weight: 700;
                font-size: 14px;
            }
        }

        .rc-mobile-back {
            display: none;
        }

        @media (max-width: 768px) {

            .sidebar {
                width: 60px;
                padding: 10px 0;
                display: flex;
                flex-direction: column;
                align-items: center;
                overflow: hidden;
            }

            /* Hide all text labels */
            .sidebar .sidebar-text,
            .rooms-list h6 {
                display: none !important;
            }

            /* Icon-only links */
            .sidebar a {
                width: 44px;
                height: 44px;
                display: flex !important;
                align-items: center;
                justify-content: center;
                border-radius: 12px;
                margin-bottom: 8px;
                font-size: 1.2rem;
                padding: 0;
            }

            /* Rooms list centering */
            .rooms-list {
                display: flex;
                flex-direction: column;
                align-items: center;
                margin-top: 10px;
                width: 100%;
            }

            .room-link {
                display: flex !important;
                justify-content: center;
                width: 100%;
                text-decoration: none;
            }

            /* Room initial bubble */
            .room-item {
                width: 44px !important;
                height: 44px !important;
                border-radius: 12px !important;
                background: #e8e8e8 !important;
                color: #555 !important;
                font-weight: bold !important;
                font-size: 1.1rem !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                margin-bottom: 8px !important;
                padding: 0 !important;
            }

            /* Hide the letter text inside .room-item directly (only show .room-initial) */
            .room-item .sidebar-text {
                display: none !important;
            }

            .room-initial {
                display: flex !important;
                align-items: center;
                justify-content: center;
            }

            /* Active room same style */
            .active-room {
                width: 44px !important;
                height: 44px !important;
                border-radius: 12px !important;
                background: #ffdac8 !important;
                color: #ff6b2c !important;
                font-weight: bold !important;
                font-size: 1.1rem !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                margin: 0 auto 8px auto !important;
            }

            .main {
                margin-left: 70px;
                padding: 15px;
            }

            .btn-join-top {
                display: none !important;
            }

            .fab-join {
                display: flex !important;
            }

            /*===MOBILE CHAT PANEL===*/
            .chat-container {
                position: fixed;
                top: 70px;
                left: 60px;
                right: 0;
                bottom: 0;
                width: auto;
                margin: 0;
                overflow: hidden;
                display: flex !important;
                flex-direction: row;
            }

            .message-area form {
                margin-top: auto;
                background: #fff;
            }

            .chat-slide-track {
                display: flex !important;
                width: 300%;
                height: 100%;
                transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
                transform: translateX(0%);
                /* Panel 1 default */
            }

            /* slide to panel 2 */
            .chat-slide-track.show-chat {
                transform: translateX(calc(-100% / 3));
            }

            /* slide to panel 3 */
            .chat-slide-track.show-chat.show-profile {
                transform: translateX(calc(-200% / 3));
            }

            .chat-panel-screen {
                width: calc((100vw - 60px));
                flex-shrink: 0 !important;
                height: 100% !important;
                overflow-y: auto !important;
                background: #fff !important;
                display: flex !important;
                flex-direction: column !important;
            }

            .chat-panel-screen:nth-child(2) {
                overflow: hidden;
            }

            .chat-panel-screen .chat-list {
                width: 100% !important;
                overflow-y: auto;
                height: 100%;
                border: none !important;
            }

            .chat-panel-screen .message-area {
                height: 100%;
                width: 100% !important;
                border: none;
                overflow: hidden;
            }

            .chat-panel-screen .profile-sidebar {
                width: 100% !important;
                padding: 20px;
                display: flex;
                flex-direction: column;
                align-items: center;
                border: none !important;
            }

            .message-area>.p-3.border-bottom {
                display: none !important;
            }

            .mobile-chat-header {
                display: flex !important;
                align-items: center;
                padding: 12px 16px;
                border-bottom: 1px solid #eee;
                background: #fff;
                gap: 12px;
                flex-shrink: 0;
            }

            .mobile-profile-header {
                display: flex !important;
                align-items: center;
                padding: 12px 16px;
                border-bottom: 1px solid #eee;
                gap: 12px;
                flex-shrink: 0;
                width: 100%;
            }

            .mobile-back-btn {
                background: none;
                border: none;
                font-size: 1.2rem;
                color: #333;
                cursor: pointer;
                padding: 4px 8px;

            }

            .mobile-chat-header .chat-name {
                flex-grow: 1;
                font-weight: 700;
                font-size: 1rem;
            }

            .mobile-info-btn {
                background: none;
                border: none;
                font-size: 1.2rem;
                color: #ff6b2c;
                cursor: pointer;
            }
        }

        /* =========================
        TABLET LAYOUT
        ========================= */
        @media (min-width: 769px) and (max-width: 1024px) {

            .sidebar {
                width: 70px;
                padding: 10px 0;
                display: flex;
                flex-direction: column;
                align-items: center;
                z-index: 200;
            }

            .sidebar .sidebar-text,
            .rooms-list h6 {
                display: none !important;
            }

            .main {
                margin-left: 70px;
                padding: 10px;
            }

            .chat-container {
                display: flex !important;
                position: relative;
                height: calc(100vh - 70px);
                width: 100%;
                overflow: hidden;
            }

            /* LEFT CHAT LIST */
            .chat-list {
                width: 260px !important;
                border-right: 1px solid #eee;
                overflow-y: auto;
                flex-shrink: 0;
            }

            /* CENTER MESSAGE AREA */
            .message-area {
                flex: 1;
                min-width: 0;
                display: flex;
                flex-direction: column;
            }

            .profile-sidebar {
                display: none;
                width: 300px !important;
                border-left: 1px solid #eee;
                background: #fff;
            }

            /* Hide ONLY by default */
            .chat-panel-screen:nth-child(3) {
                display: none;
            }

            /* when opened */
            .chat-panel-screen:nth-child(3).show-profile {
                display: flex !important;
                flex-direction: column;
            }

            /* DISABLE MOBILE SLIDER */
            .chat-slide-track {
                display: flex !important;
                width: 100% !important;
                transform: none !important;
            }

            .chat-panel-screen {
                width: auto !important;
                flex: 1;
                overflow: hidden;
            }

            .chat-inner-header {
                padding: 15px 20px;
                border-bottom: 1px solid #eee;
                background: #fff;

                display: flex;
                align-items: center;
                justify-content: space-between;
            }

            .desktop-info-btn {
                border: none;
                background: transparent;
                color: #ff6b2c;
                font-size: 1rem;
                cursor: pointer;
                margin-left: auto;
            }

            .desktop-info-btn:hover {
                opacity: 0.8;
            }

            /* SHOW DESKTOP HEADER */
            .mobile-chat-header,
            .mobile-profile-header {
                display: none !important;
            }

            .message-area>.p-3.border-bottom {
                display: block !important;
            }

            .message-content {
                flex: 1;
                overflow-y: auto;
                word-break: break-word;
            }

            form.p-3.border-top {
                flex-shrink: 0;
                background: #fff;
            }

            .chat-slide-track {
                width: 100%;
            }

            .chat-panel-screen:nth-child(1) {
                width: 280px;
                flex-shrink: 0;
            }

            .chat-panel-screen:nth-child(2) {
                flex: 1;
            }

            .chat-panel-screen:nth-child(3) {
                width: 300px;
                flex-shrink: 0;
                border-left: 1px solid #eee;
                background: #fff;
            }

            .chat-panel-screen:nth-child(3).tablet-overlay .profile-sidebar {
                width: 100% !important;
                padding: 20px;
                display: flex;
                flex-direction: column;
                align-items: center;
                height: 100%;
                overflow-y: auto;
            }

            /* Fix 1: Show room bubbles in sidebar */
            .room-initial {
                display: flex !important;
            }

            .room-item {
                width: 44px !important;
                height: 44px !important;
                border-radius: 12px !important;
                background: #e8e8e8 !important;
                color: #555 !important;
                font-weight: bold !important;
                font-size: 1.1rem !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0 !important;
            }

            .room-link {
                display: flex !important;
                justify-content: center !important;
                width: 100% !important;
            }

            .rooms-list {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                width: 100% !important;
            }

            /* Fix 2: Info button on far right */
            .message-area>.p-3.border-bottom {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                width: 100% !important;
            }

            .desktop-info-btn {
                margin-left: auto !important;
                flex-shrink: 0 !important;
            }

            .tablet-close-btn {
                align-self: flex-start !important;
                margin: 12px 16px !important;
                display: flex !important;
                align-items: center;
                gap: 6px;
                font-size: 1rem;
            }
        }

        @media (min-width: 1025px) {

            .chat-container {
                display: flex;
            }

            .message-area {
                flex: 1;
            }

            .profile-sidebar {
                width: 300px;
                border-left: 1px solid #eee;
                background: #fff;
            }
        }

        .desktop-profile-hidden {
            display: none !important;
        }

        .desktop-info-btn {
            border: none;
            background: transparent;
            color: #ff6b2c;
            font-size: 1.2rem;
            cursor: pointer;
        }

        .desktop-info-btn:hover {
            opacity: 0.8;
        }

        /*room*/
        .room-initial {
            display: none;
        }

        /* FAB */
        .fab-join {
            display: none;
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            background: #0B5ED7;
            color: white;
            border: none;
            border-radius: 50%;
            font-size: 4rem;
            padding-bottom: 16px;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
            cursor: pointer;
            z-index: 999;
        }
    </style>
</head>

<body>

    <?php include 'navbar.php'; ?>

    <!-- SIDEBAR -->
    <div class="sidebar">
        <a href="?section=home<?php if ($current_room_id)
            echo "&room_id=$current_room_id"; ?>"
            class="sidebar-link <?= $current_section === 'home' ? 'active' : '' ?>">
            <i class="fa-solid fa-house"></i> <span class="sidebar-text">Home</span>
        </a>

        <a href="?section=chats<?php if ($current_room_id)
            echo "&room_id=$current_room_id"; ?>"
            class="sidebar-link <?= $current_section === 'chats' ? 'active' : '' ?>">
            <i class="fa-solid fa-comments"></i> <span class="sidebar-text">Chats</span>
        </a>

        <a href="?section=application<?php if ($current_room_id)
            echo "&room_id=$current_room_id"; ?>"
            class="sidebar-link <?= $current_section === 'application' ? 'active' : '' ?>">
            <i class="fa-solid fa-user-group"></i> <span class="sidebar-text">Application</span>
        </a>

        <?php if ($hasActiveProgress): ?>
            <a href="?section=hours<?php if ($current_room_id)
                echo "&room_id=$current_room_id"; ?>"
                class="sidebar-link <?= $current_section === 'hours' ? 'active' : '' ?>">
                <i class="fa-solid fa-clock m-1"></i> <span class="sidebar-text">Hours</span>
            </a>
        <?php else: ?>
            <a href="#" onclick="return false;" title="Complete at least one application step to unlock"
                style="opacity:0.4; cursor:not-allowed; pointer-events:none;">
                <i class="fa-solid fa-clock m-1"></i>
                <span class="sidebar-text">Hours <i class="fa-solid fa-lock" style="font-size:10px;"></i></span>
            </a>
        <?php endif; ?>

        <a href="?section=progress_report<?php if ($current_room_id)
            echo "&room_id=$current_room_id"; ?>"
            class="sidebar-link <?= $current_section === 'progress_report' ? 'active' : '' ?>">
            <i class="fa-solid fa-file m-1"></i> <span class="sidebar-text">Progress Report</span>
        </a>

        <div class="rooms-list">
            <hr><br>
            <h6>ROOMS</h6>
            <?php foreach ($rooms as $room): ?>
                <?php if ($current_room_id == $room['id']): ?>
                    <div class="room-item active-room">
                        <span class="room-initial"><?= strtoupper(substr(trim($room['room_name']), 0, 1)) ?></span>
                        <span class="sidebar-text"><?= $room['room_name'] ?></span>
                    </div>
                <?php else: ?>
                    <a href="?room_id=<?= $room['id'] ?>" class="room-link">
                        <div class="room-item">
                            <span class="room-initial"><?= strtoupper(substr(trim($room['room_name']), 0, 1)) ?></span>
                            <span class="sidebar-text"><?= $room['room_name'] ?></span>
                        </div>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- HOME SECTION -->
    <div id="home" class="section <?= $current_section === 'home' ? 'active' : '' ?>">
        <div class="main">
            <?php if ($current_room_id): ?>
                <?php include 'chat-room-content.php'; ?>
            <?php else: ?>
                <div class="text-center mt-5">
                    <i class="fa fa-clock fa-3x text-muted mb-3 d-block"></i>
                    <h5 class="fw-bold">You haven't been assigned to a room yet.</h5>
                    <p class="text-muted">Please wait for your adviser to be assigned.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CHATS SECTION -->
    <div id="chats" class="section <?= $current_section === 'chats' ? 'active' : '' ?>">
        <div class="main">
            <div class="rc-wrap">
                <!-- ── LEFT: contact list ── -->
                <div class="rc-list" id="rcList">

                    <div class="rc-list-head">

                        <h5><i class="fa-solid fa-comments me-2" style="color:#ff6b2c;"></i>Chats</h5>
                        <div class="rc-search">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" placeholder="Search by name…" id="rcSearchInput"
                                oninput="rcFilterContacts()">
                        </div>
                    </div>

                    <div class="rc-entries" id="rcEntries">

                        <!-- Students -->
                        <div class="rc-divider">Students</div>
                        <?php foreach ($chatStudents as $u):
                            $key = $u['user_id'] . '-student';
                            $preview = $chatLookup[$key]['last_message'] ?? 'No messages yet';
                            $bg = $avatarPalette[crc32($u['full_name']) % count($avatarPalette)];
                            $active = ($chatSection_id == $u['user_id'] && $chatSection_type === 'student');
                            ?>
                            <a href="?room_id=<?= $current_room_id ?>&section=chats&chat_id=<?= $u['user_id'] ?>&chat_type=student"
                                class="rc-entry <?= $active ? 'active' : '' ?>"
                                data-rcname="<?= strtolower(htmlspecialchars($u['full_name'])) ?>"
                                onclick="rcMobileOpenChat(this)">
                                <div class="rc-avatar" style="background:<?= $bg ?>;">
                                    <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                </div>
                                <div class="rc-meta">
                                    <div class="rc-name"><?= htmlspecialchars($u['full_name']) ?></div>
                                    <div class="rc-preview"><?= htmlspecialchars(mb_substr($preview, 0, 42)) ?></div>
                                </div>
                                <span class="rc-badge" style="background:#dbeafe;color:#1e40af;">Student</span>
                            </a>
                        <?php endforeach; ?>

                        <?php if (empty($chatStudents)): ?>
                            <div style="padding:14px 18px;font-size:12px;color:#bbb;">No students in this room yet.</div>
                        <?php endif; ?>

                        <div class="rc-divider">My Adviser</div>
                        <?php foreach ($roomAdvisers as $u):
                            $key = $u['user_id'] . '-adviser';
                            $preview = $chatLookup[$key]['last_message'] ?? 'No messages yet'; // note: chatLookup won't have 'last_message' directly, see below
                            $bg = $avatarPalette[crc32($u['full_name']) % count($avatarPalette)];
                            $active = ($current_chat_id == $u['user_id'] && $current_chat_type === 'adviser');
                            ?>
                            <a href="?section=chats&room_id=<?= $current_room_id ?>&chat_id=<?= $u['user_id'] ?>&chat_type=adviser"
                                class="rc-entry <?= $active ? 'active' : '' ?>"
                                data-rcname="<?= strtolower(htmlspecialchars($u['full_name'])) ?>"
                                onclick="rcMobileOpenChat(this)">
                                <div class="rc-avatar" style="background:<?= $bg ?>;">
                                    <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                </div>
                                <div class="rc-meta">
                                    <div class="rc-name"><?= htmlspecialchars($u['full_name']) ?></div>
                                    <div class="rc-preview"><?= htmlspecialchars(mb_substr($preview, 0, 42)) ?></div>
                                </div>
                                <span class="rc-badge" style="background:#dbeafe;color:#1e40af;">Adviser</span>
                            </a>
                        <?php endforeach; ?>

                        <!-- HTE Supervisors -->
                        <?php if (!empty($chatHteAdvisers)): ?>
                            <div class="rc-divider" style="margin-top:4px;">HTE Supervisors</div>
                            <?php foreach ($chatHteAdvisers as $u):
                                $key = $u['user_id'] . '-adviser';
                                $preview = $chatLookup[$key]['last_message'] ?? 'No messages yet';
                                $bg = $avatarPalette[crc32($u['full_name']) % count($avatarPalette)];
                                $active = ($current_chat_id == $u['user_id'] && $current_chat_type === 'adviser');
                                ?>
                                <a href="?section=chats&room_id=<?= $current_room_id ?>&chat_id=<?= $u['user_id'] ?>&chat_type=adviser"
                                    class="rc-entry <?= $active ? 'active' : '' ?>"
                                    data-rcname="<?= strtolower(htmlspecialchars($u['full_name'])) ?>"
                                    onclick="rcMobileOpenChat(this)">
                                    <div class="rc-avatar" style="background:<?= $bg ?>;">
                                        <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                    </div>
                                    <div class="rc-meta">
                                        <div class="rc-name"><?= htmlspecialchars($u['full_name']) ?></div>
                                        <div class="rc-preview"><?= htmlspecialchars(mb_substr($preview, 0, 42)) ?></div>
                                    </div>
                                    <span class="rc-badge" style="background:#d1fae5;color:#065f46;">HTE</span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div><!-- /rc-entries -->
                </div><!-- /rc-list -->

                <!-- ── RIGHT: message panel ── -->
                <div class="rc-panel" id="rcPanel">

                    <?php if ($chatSection_id): ?>

                        <?php
                        $openName = getRoomChatName($pdo, $chatSection_id, $chatSection_type);
                        $openBg = $avatarPalette[crc32($openName) % count($avatarPalette)];
                        $isHte = ($chatSection_type === 'adviser');
                        $roleLabel = $isHte ? 'HTE / Co-Adviser' : 'Student';
                        ?>

                        <!-- Mobile back arrow -->
                        <div class="rc-mobile-back" id="rcMobileBack">
                            <button onclick="rcMobileGoBack()"><i class="fa-solid fa-arrow-left"></i></button>
                            <span><?= htmlspecialchars($openName) ?></span>
                        </div>

                        <!-- Header -->
                        <div class="rc-header">
                            <div class="rc-header-avatar" style="background:<?= $openBg ?>;">
                                <?= strtoupper(substr($openName, 0, 1)) ?>
                            </div>
                            <div>
                                <div class="rc-header-name"><?= htmlspecialchars($openName) ?></div>
                                <div class="rc-header-sub"><?= $roleLabel ?></div>
                            </div>
                        </div>

                        <!-- Messages -->
                        <div class="rc-body" id="rcBody">
                            <?php if (empty($chatMessages)): ?>
                                <div style="margin:auto;text-align:center;color:#ccc;">
                                    <i class="fa-regular fa-comment-dots fa-2x d-block mb-2"></i>
                                    <span style="font-size:13px;">No messages yet — say hello!</span>
                                </div>
                            <?php else:
                                $lastDay = '';
                                foreach ($chatMessages as $msg):
                                    $currentUserType = getUserType($_SESSION['role']);
                                    $isMe = ($msg['sender_id'] == $_SESSION['user_id'] && $msg['sender_type'] === $currentUserType);
                                    $bubBg = $avatarPalette[crc32($msg['sender_name'] ?? '') % count($avatarPalette)];
                                    $msgDay = date('F j, Y', strtotime($msg['created_at']));
                                    if ($msgDay !== $lastDay):
                                        $lastDay = $msgDay;
                                        ?>
                                        <div class="rc-day-sep"><?= $msgDay ?></div>
                                    <?php endif; ?>
                                    <div class="rc-row <?= $isMe ? 'me' : '' ?>">
                                        <?php if (!$isMe): ?>
                                            <div class="rc-row-avatar" style="background:<?= $bubBg ?>;">
                                                <?= strtoupper(substr($msg['sender_name'] ?? '?', 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="rc-bwrap">
                                            <?php if (!$isMe): ?>
                                                <div class="rc-sender"><?= htmlspecialchars($msg['sender_name'] ?? '') ?></div>
                                            <?php endif; ?>
                                            <div class="rc-bubble">
                                                <?php if (!empty($msg['message'])): ?>
                                                    <?= nl2br(htmlspecialchars($msg['message'])) ?>
                                                <?php endif; ?>

                                                <?php if (!empty($msg['attachment_path'])): ?>
                                                    <div class="rc-attachment"
                                                        style="<?= !empty($msg['message']) ? 'margin-top:8px;' : '' ?>">
                                                        <?php if ($msg['attachment_type'] === 'image'): ?>
                                                            <a href="<?= htmlspecialchars($msg['attachment_path']) ?>" target="_blank">
                                                                <img src="<?= htmlspecialchars($msg['attachment_path']) ?>" alt="attachment"
                                                                    style="max-width:220px; border-radius:10px; display:block;">
                                                            </a>

                                                        <?php elseif ($msg['attachment_type'] === 'video'): ?>
                                                            <video controls style="max-width:240px; border-radius:10px;">
                                                                <source src="<?= htmlspecialchars($msg['attachment_path']) ?>">
                                                                Your browser does not support video playback.
                                                            </video>

                                                        <?php else: /* pdf or doc */ ?>
                                                            <a href="<?= htmlspecialchars($msg['attachment_path']) ?>" target="_blank"
                                                                style="display:flex; align-items:center; gap:8px; padding:8px 12px;
                                                                        background:#fff; border:1px solid #e5e7eb; border-radius:10px;
                                                                        text-decoration:none; color:#333; font-size:12.5px;">
                                                                <i class="fa-solid <?= $msg['attachment_type'] === 'pdf' ? 'fa-file-pdf' : 'fa-file-word' ?>"
                                                                    style="font-size:18px; color:<?= $msg['attachment_type'] === 'pdf' ? '#dc2626' : '#2563eb' ?>;"></i>
                                                                <span><?= htmlspecialchars($msg['attachment_name']) ?></span>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="rc-time"><?= date('g:i A', strtotime($msg['created_at'])) ?></div>
                                        </div>
                                        <?php if ($isMe): ?>
                                            <button type="button" class="rc-msg-delete"
                                                onclick="rcDeleteMessage(<?= $msg['id'] ?>, this)" title="Delete message">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach;
                            endif; ?>
                        </div>

                        <!-- Input -->
                        <div class="rc-inputbar">
                            <form method="POST" action="message-db.php" id="rcForm" enctype="multipart/form-data">
                                <input type="hidden" name="receiver_id" value="<?= htmlspecialchars($chatSection_id) ?>">
                                <input type="hidden" name="receiver_type"
                                    value="<?= htmlspecialchars($chatSection_type) ?>">
                                <input type="hidden" name="redirect"
                                    value="Message.php?room_id=<?= $current_room_id ?>&section=chats&chat_id=<?= $chatSection_id ?>&chat_type=<?= $chatSection_type ?>">

                                <!-- attachment preview (shown via JS when a file is picked) -->
                                <div id="rcAttachPreview" class="rc-attach-preview" style="display:none;">
                                    <div class="rc-attach-thumb-wrap">
                                        <img id="rcAttachThumbImg" style="display:none;">
                                        <video id="rcAttachThumbVideo" style="display:none;" muted></video>
                                        <div id="rcAttachThumbIcon" style="display:none;"></div>
                                        <button type="button" class="rc-attach-remove"
                                            onclick="rcClearAttachment()">×</button>
                                    </div>
                                    <span id="rcAttachName" class="rc-attach-filename"></span>
                                </div>

                                <div class="rc-inputrow">
                                    <label for="rcAttachInput" class="rc-attach-btn"
                                        style="cursor:pointer; display:flex; align-items:center; padding:0 6px;">
                                        <i class="fa-solid fa-paperclip" style="font-size:16px;color:#888;"></i>
                                    </label>
                                    <input type="file" id="rcAttachInput" name="attachment" style="display:none;"
                                        accept=".pdf,.doc,.docx,image/*,video/*" onchange="rcShowAttachment(this)">

                                    <textarea name="message" id="rcTextarea" rows="1" placeholder="Type a message…"
                                        onkeydown="rcEnterSend(event)"></textarea>

                                    <button type="submit" class="rc-send-btn" id="rcSendBtn">
                                        <i class="fa-solid fa-paper-plane"></i>
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php else: ?>
                        <div class="rc-empty">
                            <i class="fa-regular fa-comments"></i>
                            <p>Select a conversation to get started</p>
                        </div>
                    <?php endif; ?>

                </div><!-- /rc-panel -->
            </div><!-- /rc-wrap -->
        </div>
    </div>

    <!-- APPLICATION SECTION -->
    <div id="application" class="section <?= $current_section === 'application' ? 'active' : '' ?>">
        <div class="main">
            <?php if ($application_internship_id): ?>

                <?php
                $internship_id = $application_internship_id;
                include 'application-progress.php';
                ?>

            <?php else: ?>

                <!-- HTE NOTICE -->
                <div class="card border-0 shadow-sm rounded-4 mb-3">
                    <div class="card-body p-4 d-flex align-items-start gap-3">

                        <div class="fs-3" style="color:#272f54;">
                            <i class="fa fa-info-circle"></i>
                        </div>

                        <div class="flex-grow-1">

                            <h6 class="fw-bold mb-1" style="color:#272f54;">
                                Before you choose a company
                            </h6>

                            <p class="text-muted mb-2 small">
                                Please fill out the Host Training Establishment (HTE) form first.
                                This is also where you go if your company is
                                <strong>not on the list below</strong>, for example, if it's a new
                                partner company that hasn't been added yet.
                            </p>

                            <a href="https://docs.google.com/forms/d/e/1FAIpQLSdKZ4TbEup2aH6AG5CgATeTcIotCjITlqlWF5VMiTHNrtJyRw/alreadyresponded?pli=1&pli=1"
                                target="blank" class="btn btn-sm fw-semibold" style="
                        background:#272f54;
                        color:white;
                        border-radius:8px;
                    ">
                                <i class="fa fa-file-lines me-1"></i>
                                Go to HTE Form
                            </a>

                        </div>

                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4">

                        <h4 class="fw-bold mb-2" style="color:#272f54;">
                            Apply for an Internship
                        </h4>

                        <p class="text-muted mb-4">
                            Select an internship below to open your checklist.
                        </p>

                        <form action="student-progress.php" method="POST">

                            <input type="hidden" name="action" value="apply_internship">

                            <div class="row align-items-end g-3">

                                <!-- Internship -->
                                <div class="col-md-9">

                                    <label for="internship_id" class="form-label fw-semibold">
                                        Select Internship
                                    </label>

                                    <select name="internship_id" id="internship_id" class="form-select" required>

                                        <option value="" selected disabled>
                                            Select an internship
                                        </option>

                                        <?php foreach ($availableInternships as $internship): ?>

                                            <option value="<?= htmlspecialchars($internship['id']) ?>">
                                                <?= htmlspecialchars($internship['company']) ?>
                                                <?php if (!empty($internship['title'])): ?>
                                                    — <?= htmlspecialchars($internship['title']) ?>
                                                <?php endif; ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <!-- Apply -->
                                <div class="col-md-3">

                                    <button type="submit" class="btn w-100 fw-semibold" style="
                                            background:#272f54;
                                            color:white;
                                            border-radius:8px;
                                        ">

                                        <i class="fa fa-paper-plane me-1"></i>
                                        Choose

                                    </button>

                                </div>

                            </div>

                        </form>

                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <!-- HOURS SECTION -->
    <div id="hours" class="section <?= $current_section === 'hours' ? 'active' : '' ?>">
        <div class="main" style="overflow-y:auto; height:calc(100vh - 70px);">
            <?php if (!$hasActiveProgress): ?>
                <div class="text-center mt-5 py-5">
                    <i class="fa fa-lock fa-3x text-muted mb-3 d-block"></i>
                    <h5 class="fw-bold text-muted">Hours Tracking Locked</h5>
                    <p class="text-muted" style="font-size:14px; max-width:360px; margin:0 auto;">
                        You need to have an active OJT application in progress before you can log your hours.
                    </p>
                    <a href="?section=application<?php if ($current_room_id)
                        echo "&room_id=$current_room_id"; ?>" class="btn btn-sm mt-3 fw-semibold"
                        style="background:#272f54; color:white; border-radius:8px;">
                        <i class="fa fa-arrow-right me-1"></i> Go to Applications
                    </a>
                </div>
            <?php else: ?>
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h3>Rendered Hours</h3>
                        <p class="text-muted mb-0">OJT Daily Time Record</p>
                    </div>
                    <div class="d-flex align-items-center gap-2 text-end">
                        <label style="font-size:16px; color:#555; font-weight:500;">OJT Hours Required:</label>
                        <span style="font-size:16px; font-weight:600; color:#29335C;">
                            <?= $requiredHours ?> hrs
                        </span>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="card border-0 shadow-sm rounded-3 p-3 mb-2" style="background-color:#29335C; opacity:0.90;">
                    <div class="d-flex justify-content-between" style="color:#fff; margin-bottom:6px;">
                        <span>Progress</span>
                        <span id="ojt-pct-label">0%</span>
                    </div>
                    <div style="height:8px; background:#ddd; border-radius:99px; overflow:hidden;">
                        <div id="ojt-progress-fill" style="height:100%; width:0%; border-radius:99px; background:#1abc9c;">
                        </div>
                    </div>
                </div>

                <!-- <div class="card border-0 shadow-sm rounded-3 p-3 mb-2 d-flex flex-row align-items-center justify-content-between" style="background-color:#29335C;">
                    <span style="color:#fff;">Progress</span>

                    <div style="position:relative; width:64px; height:64px;">
                        <svg width="64" height="64" viewBox="0 0 64 64">
                            <circle cx="32" cy="32" r="27" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="6"></circle>
                            <circle id="ojt-progress-fill" cx="32" cy="32" r="27" fill="none" stroke="#1abc9c" stroke-width="6"
                                stroke-linecap="round" stroke-dasharray="169.6" stroke-dashoffset="169.6"
                                transform="rotate(-90 32 32)"></circle>
                        </svg>
                        <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;">
                            <span id="ojt-pct-label" style="color:#fff; font-size:13px; font-weight:600;">0%</span>
                        </div>
                    </div>
                </div> -->

                <!-- Summary Cards -->
                <div class="row g-2 mb-2" style="margin-top:4px;">
                    <div class="col-md-4">
                        <div style="border:2px solid #ababab; border-radius:8px; padding:1rem; margin-right:5px;">
                            <div style="letter-spacing:.05em; color:#29335C;">MONTHLY OJT HOURS</div>
                            <div id="ojt-sum-monthly" style="font-size:20px; font-weight:500; color:#29335C;">0h 0m</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="border:2px solid #ababab; border-radius:8px; padding:1rem; margin:0 5px;">
                            <div style="letter-spacing:.05em; color:#29335C;">HOURS COMPLETED</div>
                            <div id="ojt-sum-completed" style="font-size:20px; font-weight:500; color:#29335C;">0h 0m</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="border:2px solid #ababab; border-radius:8px; padding:1rem; margin-left:5px;">
                            <div style="letter-spacing:.05em; color:#29335C;">REMAINING HOURS</div>
                            <div id="ojt-sum-remaining" style="font-size:20px; font-weight:500; color:#29335C;">
                                <?= $requiredHours ?>h 0m
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Weeks Container -->
                <div id="ojt-weeks-container">
                    <?php foreach ($ojtWeeks as $week): ?>
                        <div class="ojt-week-block" data-week-id="<?= $week['week_index'] ?>"
                            id="ojt-week-block-<?= $week['week_index'] ?>">
                            <div class="ojt-week-header">
                                <div class="d-flex align-items-center gap-2">
                                    <input class="ojt-week-label" type="text"
                                        value="<?= htmlspecialchars($week['week_label']) ?>" readonly
                                        style="background:transparent; border:none; font-weight:600; text-align:left;">
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="ojt-total-chip ojt-week-total"
                                        id="ojt-wtotal-<?= $week['week_index'] ?>">0h</span>
                                    <?php if ($week['week_index'] > 0): ?>
                                        <button class="ojt-remove-btn" type="button"
                                            onclick="ojtRemoveWeek(<?= $week['week_index'] ?>)" title="Remove">×</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="ojt-table-scroll">
                                <table class="ojt-table">
                                    <thead>
                                        <tr>
                                            <th rowspan="2" class="ojt-group">Date</th>
                                            <th rowspan="2" class="ojt-group">Day</th>
                                            <th class="th-morning" colspan="3"
                                                style="background:#FFB62F; border:1px solid #e5e7eb;">Morning</th>
                                            <th class="th-afternoon" colspan="3"
                                                style="background:#FF673A; border:1px solid #e5e7eb;">Afternoon</th>
                                            <th rowspan="2" class="ojt-group">Daily<br>Hours</th>
                                            <th rowspan="2" class="ojt-group">Status</th>
                                        </tr>
                                        <tr class="ojt-sub">
                                            <th class="sub-morning" style="background:#f9c565; border:1px solid #e5e7eb;">In
                                            </th>
                                            <th class="sub-morning" style="background:#f9c565; border:1px solid #e5e7eb;">Out
                                            </th>
                                            <th class="sub-morning" style="background:#f9c565; border:1px solid #e5e7eb;">Hrs
                                            </th>
                                            <th class="sub-afternoon" style="background:#f49679; border:1px solid #e5e7eb;">In
                                            </th>
                                            <th class="sub-afternoon" style="background:#f49679; border:1px solid #e5e7eb;">Out
                                            </th>
                                            <th class="sub-afternoon" style="background:#f49679; border:1px solid #e5e7eb;">Hrs
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($week['rows'] as $ri => $row): ?>
                                            <tr data-row-index="<?= $ri ?>">
                                                <td style="min-width:120px">
                                                    <input type="date" data-field="date"
                                                        value="<?= htmlspecialchars($row['date']) ?>"
                                                        onchange="ojtOnFieldChange(this)">
                                                </td>
                                                <td style="min-width:50px;text-align:center">
                                                    <span class="ojt-day-badge" data-display="day"
                                                        id="ojt-day-<?= $week['week_index'] ?>-<?= $ri ?>"></span>
                                                </td>
                                                <td class="td-morning" style="min-width:105px">
                                                    <input type="time" data-field="mIn"
                                                        value="<?= htmlspecialchars($row['m_in']) ?>"
                                                        onchange="ojtOnFieldChange(this)">
                                                </td>
                                                <td class="td-morning" style="min-width:105px">
                                                    <input type="time" data-field="mOut"
                                                        value="<?= htmlspecialchars($row['m_out']) ?>"
                                                        onchange="ojtOnFieldChange(this)">
                                                </td>
                                                <td class="td-morning" style="min-width:55px">
                                                    <span class="ojt-hrs-val" data-display="mhrs"
                                                        id="ojt-mhrs-<?= $week['week_index'] ?>-<?= $ri ?>">—</span>
                                                </td>
                                                <td class="td-afternoon" style="min-width:105px">
                                                    <input type="time" data-field="aIn"
                                                        value="<?= htmlspecialchars($row['a_in']) ?>"
                                                        onchange="ojtOnFieldChange(this)">
                                                </td>
                                                <td class="td-afternoon" style="min-width:105px">
                                                    <input type="time" data-field="aOut"
                                                        value="<?= htmlspecialchars($row['a_out']) ?>"
                                                        onchange="ojtOnFieldChange(this)">
                                                </td>
                                                <td class="td-afternoon" style="min-width:55px">
                                                    <span class="ojt-hrs-val" data-display="ahrs"
                                                        id="ojt-ahrs-<?= $week['week_index'] ?>-<?= $ri ?>">—</span>
                                                </td>
                                                <td style="min-width:62px">
                                                    <span class="ojt-daily-val" data-display="daily"
                                                        id="ojt-daily-<?= $week['week_index'] ?>-<?= $ri ?>">—</span>
                                                </td>
                                                <td style="min-width:110px">
                                                    <span class="ojt-status-badge" data-display="status"
                                                        id="ojt-status-<?= $week['week_index'] ?>-<?= $ri ?>">—</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <template id="ojt-week-template">
                    <div class="ojt-week-block" data-week-id="">
                        <div class="ojt-week-header">
                            <div class="d-flex align-items-center gap-2">
                                <input class="ojt-week-label" type="text" data-field="week_label" placeholder="Week label"
                                    onchange="ojtOnFieldChange(this)">
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="ojt-total-chip ojt-week-total">0h</span>
                                <button class="ojt-remove-btn" type="button" style="display:none;" title="Remove">×</button>
                            </div>
                        </div>
                        <div class="ojt-table-scroll">
                            <table class="ojt-table">
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="ojt-group" style="padding-left:10px;">Date</th>
                                        <th rowspan="2" class="ojt-group">Day</th>
                                        <th class="th-morning" colspan="3"
                                            style="background:#FFB62F; border:1px solid #e5e7eb;">Morning</th>
                                        <th class="th-afternoon" colspan="3"
                                            style="background:#FF673A; border:1px solid #e5e7eb;">Afternoon</th>
                                        <th rowspan="2" class="ojt-group">Daily<br>Hours</th>
                                    </tr>
                                    <tr class="ojt-sub">
                                        <th class="sub-morning" style="background:#f9c565; border:1px solid #e5e7eb;">In
                                        </th>
                                        <th class="sub-morning" style="background:#f9c565; border:1px solid #e5e7eb;">Out
                                        </th>
                                        <th class="sub-morning" style="background:#f9c565; border:1px solid #e5e7eb;">Hrs
                                        </th>
                                        <th class="sub-afternoon" style="background:#f49679; border:1px solid #e5e7eb;">In
                                        </th>
                                        <th class="sub-afternoon" style="background:#f49679; border:1px solid #e5e7eb;">Out
                                        </th>
                                        <th class="sub-afternoon" style="background:#f49679; border:1px solid #e5e7eb;">Hrs
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($ri = 0; $ri < 6; $ri++): ?>
                                        <tr data-row-index="<?= $ri ?>">
                                            <td style="min-width:120px">
                                                <input type="date" data-field="date" onchange="ojtOnFieldChange(this)">
                                            </td>
                                            <td style="min-width:50px;text-align:center">
                                                <span class="ojt-day-badge" data-display="day"></span>
                                            </td>
                                            <td class="td-morning" style="min-width:105px">
                                                <input type="time" data-field="mIn" onchange="ojtOnFieldChange(this)">
                                            </td>
                                            <td class="td-morning" style="min-width:105px">
                                                <input type="time" data-field="mOut" onchange="ojtOnFieldChange(this)">
                                            </td>
                                            <td class="td-morning" style="min-width:55px">
                                                <span class="ojt-hrs-val" data-display="mhrs">—</span>
                                            </td>
                                            <td class="td-afternoon" style="min-width:105px">
                                                <input type="time" data-field="aIn" onchange="ojtOnFieldChange(this)">
                                            </td>
                                            <td class="td-afternoon" style="min-width:105px">
                                                <input type="time" data-field="aOut" onchange="ojtOnFieldChange(this)">
                                            </td>
                                            <td class="td-afternoon" style="min-width:55px">
                                                <span class="ojt-hrs-val" data-display="ahrs">—</span>
                                            </td>
                                            <td style="min-width:62px">
                                                <span class="ojt-daily-val" data-display="daily">—</span>
                                            </td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>

                <!-- Add Week -->
                <div>
                    <button onclick="ojtAddWeek()" class="btn btn-outline-secondary w-100 mb-2 rounded-3"
                        style="border-style:dashed; font-size:13px; border-width:0.5px;">
                        <i class="fa-solid fa-plus me-1"></i> Add another week
                    </button>
                </div>

                <!-- Save Bar -->
                <div id="ojt-save-bar" style="display:none; margin-top:16px; padding:12px;
            background:#f0f9ff; border-radius:8px; border:1px solid #0ea5e9;">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <span id="ojt-save-status" style="font-size:13px; color:#0369a1; font-weight:500;">
                            <i class="fa-solid fa-circle me-1" style="color:#f59e0b;"></i>Unsaved changes
                        </span>
                        <button type="button" id="ojt-save-btn" onclick="ojtSaveAll()" class="btn btn-sm" style="background:#0ea5e9; color:white; border-radius:6px;
                           font-size:12px; font-weight:600;">
                            <i class="fa-solid fa-floppy-disk me-1"></i>Save Changes
                        </button>
                    </div>
                    <div id="ojt-save-message" style="margin-top:8px; font-size:12px; display:none;"></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- PROGRESS REPORT SECTION -->

    <div class="section <?= $current_section === 'progress_report' ? 'active' : '' ?>">
        <div class="main" style="padding-bottom:60px;">
            <h3 class="fw-bold mb-1">Weekly Progress Report</h3>
            <p class="text-muted mb-4">Upload your filled-out weekly report, or download the blank template below.</p>

            <!-- TEMPLATE DOWNLOAD -->
            <div class="card shadow-sm rounded-3 p-3 mb-3 d-flex flex-row align-items-center justify-content-between"
                style="border:1px solid #e5e7eb;">
                <div class="d-flex align-items-center gap-3">
                    <div
                        style="width:42px;height:42px;border-radius:10px;background:#fde3d8;display:flex;align-items:center;justify-content:center;">
                        <i class="fa-solid fa-file-lines" style="color:#ff6b2c;"></i>
                    </div>
                    <div>
                        <strong style="font-size:14px;">Weekly Report Template</strong>
                        <div class="text-muted" style="font-size:12px;">DOCX · Download and fill out before uploading
                        </div>
                    </div>
                </div>
                <a href="/Sources/weekly-report-template.docx" download class="btn btn-sm fw-semibold"
                    style="background:#272f54;color:#fff;border-radius:8px;">
                    <i class="fa-solid fa-download me-1"></i> Download
                </a>
            </div>

            <!-- UPLOAD FORM -->
            <form method="POST" action="progress-report-db.php" enctype="multipart/form-data">
                <input type="hidden" name="room_id" value="<?= htmlspecialchars($current_room_id ?? '') ?>">

                <div class="card shadow-sm rounded-3 p-4 mb-3" style="border:1px solid #e5e7eb;">
                    <div class="d-flex gap-2 align-items-center mt-3">
                        <span style="font-size:15px; font-weight: bold;">Week number</span>
                        <input type="number" name="week_number" class="form-control form-control-sm"
                            style="max-width:120px;" min="1" max="52" placeholder="e.g. 1" required>

                    </div>
                    <br>
                    <label id="prDropZone" for="prFileInput" style="
                border: 2px dashed #ddd;
                border-radius: 12px;
                padding: 32px;
                text-align: center;
                cursor: pointer;
                display: block;
                transition: border-color .2s, background .2s;">
                        <i class="fa-solid fa-cloud-arrow-up fa-2x mb-2" style="color:#ff6b2c;"></i>
                        <div style="font-weight:600; font-size:14px;">Click to upload or drag and drop</div>
                        <div class="text-muted" style="font-size:12px;">PDF or DOCX, up to 10MB</div>
                        <div id="prFileName" style="margin-top:10px; font-size:13px; color:#272f54; font-weight:600;">
                        </div>
                    </label>
                    <input type="file" id="prFileInput" name="report_file" accept=".pdf,.doc,.docx"
                        style="display:none;" required onchange="prShowFileName(this)">

                    <button type="submit" class="btn btn-sm fw-semibold ms-auto mt-3 align-self-start"
                        style="background:#ff6b2c;color:#fff;border-radius:8px;padding:8px 18px;">
                        <i class="fa-solid fa-paper-plane me-1"></i> Submit Report
                    </button>
                </div>
            </form>

            <!-- SUBMITTED REPORTS LIST -->
            <h6 class="fw-bold mb-2" style="font-size:14px;">Your Submitted Reports</h6>
            <div class="card  shadow-sm rounded-3" style="overflow:hidden; border:1px solid #e5e7eb;">
                <table class="w-100" style="font-size:13px; border-collapse:collapse;">
                    <thead style="background:#f8f9fa;">
                        <tr>
                            <th class="p-3 text-start">Week</th>
                            <th class="p-3 text-start">Submitted</th>
                            <th class="p-3 text-start">File</th>
                            <th class="p-3 text-start">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $reportsStmt = $pdo->prepare("
                        SELECT id, week_number, wr_filepath, created_at
                        FROM weekly_reports
                        WHERE student_id = ?
                        ORDER BY week_number DESC
                    ");
                        $reportsStmt->execute([$student_id]);
                        $reports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <?php if (empty($reports)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted p-4">No reports submitted yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reports as $r): ?>
                                <tr style="border-top:1px solid #f0f0f0;">
                                    <td class="p-3">Week <?= (int) $r['week_number'] ?></td>
                                    <td class="p-3"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                                    <td class="p-3">
                                        <i class="fa-solid fa-file-lines me-1" style="color:#888;"></i>
                                        <?= htmlspecialchars(basename($r['wr_filepath'])) ?>
                                    </td>
                                    <td class="p-3">
                                        <a href="<?= htmlspecialchars($r['wr_filepath']) ?>" target="_blank" class="btn btn-sm"
                                            style="background:#eef2ff;color:#272f54;border-radius:6px;">
                                            <i class="fa-solid fa-eye me-1"></i> Preview
                                        </a>
                                        <form action="student-progress.php" method="POST" style="display:inline;"
                                            onsubmit="return confirm('Are you sure?')">
                                            <input type="hidden" name="action" value="delete-weekly-report">
                                            <input type="hidden" name="report_id" value="<?= (int) $r['id'] ?>">
                                            <button type="submit" class="btn btn-sm"
                                                style="background:#fef2f2;color:#dc2626;border-radius:6px;">
                                                <i class="fa-solid fa-trash " me-1></i>Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===== OJT COMPLETION EVALUATION MODAL ===== -->
    <div class="modal fade" id="ojtEvalModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">

                <!-- Header -->
                <div class="modal-header"
                    style="background:linear-gradient(135deg,#29335C,#1a2240); color:#fff; padding:20px 28px;">
                    <div>
                        <div
                            style="font-size:11px; letter-spacing:.12em; opacity:.7; text-transform:uppercase; margin-bottom:4px;">
                            CEIT-OJTF-011 · Pamantasan ng Lungsod ng Valenzuela
                        </div>
                        <h5 class="modal-title fw-bold mb-0" style="font-size:1.2rem;">
                            Congratulations! You've Completed Your OJT Hours
                        </h5>
                        <div style="font-size:13px; opacity:.8; margin-top:4px;">
                            Please complete the Student's Evaluation of Internship before proceeding.
                        </div>
                    </div>
                </div>

                <div class="modal-body" style="padding:28px 32px; background:#f8f9fb;">
                    <form id="ojtEvalForm">
                        <!-- Student info strip -->
                        <div style="background:#fff; border-radius:10px; padding:16px 20px; margin-bottom:20px;
                                     border:1px solid #e2e8f0; display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                            <div>
                                <label style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;"
                                    name="intern_name">Name of Intern</label>
                                <div style="font-weight:600;color:#1e293b;">
                                    <input type="text" name="intern_name" class="form-control form-control-sm mt-1"
                                        placeholder="Name..."
                                        value="<?= htmlspecialchars($student['intern_name'] ?? '') ?>">
                                </div>
                            </div>
                            <div>
                                <label
                                    style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;">Course
                                    / Student No.</label>
                                <div style="font-weight:600;color:#1e293b;">
                                    <input type="text" name="course_student_no"
                                        class="form-control form-control-sm mt-1" placeholder="Course / Student No."
                                        value="<?= htmlspecialchars($student['course_student_no'] ?? '') ?>">
                                </div>
                            </div>
                            <div>
                                <label
                                    style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;">Name
                                    of Company</label>
                                <input type="text" name="company_name" class="form-control form-control-sm mt-1"
                                    placeholder="Enter company name"
                                    value="<?= htmlspecialchars($student['company_name'] ?? '') ?>">
                            </div>
                            <div>
                                <label
                                    style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;">Site
                                    Internship Supervisor</label>
                                <input type="text" name="supervisor_name" class="form-control form-control-sm mt-1"
                                    placeholder="Enter supervisor name"
                                    value="<?= htmlspecialchars($student['supervisor_name'] ?? '') ?>">
                            </div>
                        </div>
                        <?= var_dump($current_room_id) ?>
                        <!-- Rating legend -->
                        <div
                            style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 18px;margin-bottom:20px;font-size:13px;">
                            <strong>Rating Scale:</strong>
                            <span style="margin-left:12px;">1 – Poor</span>
                            <span style="margin-left:12px;">2 – Fair</span>
                            <span style="margin-left:12px;">3 – Good</span>
                            <span style="margin-left:12px;">4 – Exceptional</span>
                        </div>

                        <!-- ── PART I ── -->
                        <div
                            style="font-size:12px;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;margin-bottom:10px;">
                            Part I · HTE / Company Experience
                        </div>

                        <?php
                        $sections = [
                            'A. Site Experience' => [
                                'site_secure' => 'The physical surroundings were secure.',
                                'site_orientation' => 'The HTE conducted a session for orientation.',
                                'site_resources' => 'The projects assigned had access to enough resources necessary for successful completion.',
                                'site_colleagues' => 'Colleagues were supportive and accommodating.',
                            ],
                            'B. OJT Supervisor Experience' => [
                                'sup_job_desc' => 'A precise job description was given by the OJT Supervisor.',
                                'sup_feedback' => 'Consistent feedback was provided regarding my performance and skills.',
                                'sup_learning' => 'Measures were taken to ensure that the OJT experience was a valuable learning experience.',
                                'sup_duties' => 'The OJT Supervisor assigned duties that matched my competencies and abilities.',
                                'sup_schedule' => 'The OJT Supervisor was accommodating of the agreed-upon work schedule.',
                            ],
                            'C. Learning Experience' => [
                                'learn_aligned' => 'The work experience aligned with my academic field and future career aspirations.',
                                'learn_verbal' => 'I was given chances to enhance my abilities in verbal communication and presentation.',
                                'learn_interpersonal' => 'I had opportunities to improve my skills in building relationships and interacting with others.',
                                'learn_creativity' => 'I was given opportunities to foster my creativity.',
                                'learn_problem' => 'I was presented with occasions to strengthen my problem-solving capabilities.',
                                'learn_critical' => 'I was given chances to develop my critical thinking skills during the experience.',
                                'learn_writing' => 'I was given opportunities to enhance my writing abilities.',
                                'learn_career' => 'This experience has assisted me in preparing for a career in my field.',
                            ],
                        ];
                        foreach ($sections as $sectionTitle => $fields):
                            ?>
                            <div
                                style="background:#fff;border-radius:10px;padding:18px 22px;margin-bottom:14px;border:1px solid #e2e8f0;">
                                <div style="font-weight:700;color:#29335C;margin-bottom:12px;font-size:14px;">
                                    <?= $sectionTitle ?>
                                </div>
                                <?php $i = 1;
                                foreach ($fields as $name => $label): ?>
                                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
                 padding:8px 0;<?= $i > 1 ? 'border-top:1px solid #f1f5f9;' : '' ?>">
                                        <span style="font-size:13px;color:#374151;flex:1;"><?= $i ?>.
                                            <?= htmlspecialchars($label) ?></span>
                                        <div class="d-flex gap-2 flex-shrink-0">
                                            <?php for ($r = 1; $r <= 4; $r++): ?>
                                                <label
                                                    style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;">
                                                    <input type="radio" name="<?= $name ?>" value="<?= $r ?>" required
                                                        style="accent-color:#29335C;width:16px;height:16px;">
                                                    <span style="font-size:10px;color:#94a3b8;"><?= $r ?></span>
                                                </label>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <?php $i++; endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        <!-- ── PART II ── -->
                        <div
                            style="font-size:12px;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;margin:20px 0 10px;">
                            Part II · HEI / Institutional Experience
                        </div>

                        <?php
                        $sections2 = [
                            'D. HEI OJT Program Experience' => [
                                'hei_prepared' => 'The school prepared me well for the OJT process.',
                                'hei_guidance' => 'I received sufficient information and guidance on the OJT process and placement.',
                                'hei_supported' => 'I felt supported by the school throughout my internship.',
                                'hei_communication' => "The school's communication systems were effective in keeping me informed during my internship.",
                                'hei_coursework' => 'The academic coursework equipped me with the professional knowledge and necessary skills for the internship.',
                                'hei_goals' => 'The OJT goals and objectives were clearly communicated to me.',
                                'hei_valuable' => 'The OJT experience was valuable for my personal and professional growth.',
                                'hei_satisfied' => 'I am satisfied with the OJT process conducted by the school.',
                            ],
                            'E. OJT Coordinator Experience' => [
                                'coord_instructions' => 'Clear instructions and guidance were provided by the OJT Coordinator throughout the internship.',
                                'coord_goals' => 'The OJT Coordinator helped me identify and achieve my internship goals.',
                                'coord_responsive' => 'The OJT Coordinator was available and responsive to my questions, queries, and concerns during the internship.',
                                'coord_feedback' => 'The OJT Coordinator provided adequate supervision and feedback on my performance during the internship.',
                                'coord_challenges' => 'The OJT Coordinator helped me navigate any challenges or issues that arose during the internship.',
                            ],
                        ];
                        foreach ($sections2 as $sectionTitle => $fields):
                            ?>
                            <div
                                style="background:#fff;border-radius:10px;padding:18px 22px;margin-bottom:14px;border:1px solid #e2e8f0;">
                                <div style="font-weight:700;color:#29335C;margin-bottom:12px;font-size:14px;">
                                    <?= $sectionTitle ?>
                                </div>
                                <?php $i = 1;
                                foreach ($fields as $name => $label): ?>
                                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
                 padding:8px 0;<?= $i > 1 ? 'border-top:1px solid #f1f5f9;' : '' ?>">
                                        <span style="font-size:13px;color:#374151;flex:1;"><?= $i ?>.
                                            <?= htmlspecialchars($label) ?></span>
                                        <div class="d-flex gap-2 flex-shrink-0">
                                            <?php for ($r = 1; $r <= 4; $r++): ?>
                                                <label
                                                    style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;">
                                                    <input type="radio" name="<?= $name ?>" value="<?= $r ?>" required
                                                        style="accent-color:#29335C;width:16px;height:16px;">
                                                    <span style="font-size:10px;color:#94a3b8;"><?= $r ?></span>
                                                </label>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <?php $i++; endforeach; ?>
                            </div>
                        <?php endforeach; ?>

                        <!-- ── PART III ── -->
                        <div
                            style="font-size:12px;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;margin:20px 0 10px;">
                            Part III · Assessment
                        </div>
                        <div
                            style="background:#fff;border-radius:10px;padding:18px 22px;margin-bottom:14px;border:1px solid #e2e8f0;">

                            <!-- Q1 -->
                            <div style="padding:8px 0;border-bottom:1px solid #f1f5f9;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                                    <span style="font-size:13px;color:#374151;flex:1;">1. Overall, how would you rate
                                        this internship?</span>
                                    <div class="d-flex gap-2">
                                        <?php for ($r = 1; $r <= 4; $r++): ?>
                                            <label
                                                style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;">
                                                <input type="radio" name="overall_rating" value="<?= $r ?>" required
                                                    style="accent-color:#29335C;width:16px;height:16px;">
                                                <span style="font-size:10px;color:#94a3b8;"><?= $r ?></span>
                                            </label>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Q2 paid -->
                            <div style="padding:12px 0;border-bottom:1px solid #f1f5f9;">
                                <div style="font-size:13px;color:#374151;margin-bottom:8px;">2. Was the internship paid?
                                </div>
                                <div class="d-flex align-items-center gap-4 flex-wrap">
                                    <label class="d-flex align-items-center gap-2"
                                        style="cursor:pointer;font-size:13px;">
                                        <input type="radio" name="was_paid" value="yes"
                                            onclick="document.getElementById('pay-details').style.display='flex'"
                                            style="accent-color:#29335C;"> Yes
                                    </label>
                                    <label class="d-flex align-items-center gap-2"
                                        style="cursor:pointer;font-size:13px;">
                                        <input type="radio" name="was_paid" value="no"
                                            onclick="document.getElementById('pay-details').style.display='none'"
                                            style="accent-color:#29335C;"> No
                                    </label>
                                </div>
                                <div id="pay-details"
                                    style="display:none;margin-top:10px;flex-wrap:wrap;gap:12px;align-items:center;">
                                    <span style="font-size:12px;color:#64748b;">Type:</span>
                                    <?php foreach (['Hourly', 'Daily', 'Stipend/Allowance'] as $pt): ?>
                                        <label class="d-flex align-items-center gap-1"
                                            style="cursor:pointer;font-size:13px;">
                                            <input type="radio" name="pay_type" value="<?= $pt ?>"
                                                style="accent-color:#29335C;"> <?= $pt ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span style="font-size:12px;color:#64748b;">Amount (Php):</span>
                                        <input type="number" name="pay_amount" min="0" step="0.01" placeholder="0.00"
                                            class="form-control form-control-sm" style="width:130px;">
                                    </div>
                                </div>
                            </div>

                            <!-- Q3-6 -->
                            <?php
                            $assessItems = [
                                'recommend_internship' => '3. Would you recommend this internship to other students?',
                                'work_supervisor_again' => '4. Would you work for this OJT Supervisor again?',
                                'work_coordinator_again' => '5. Would you work for this OJT Coordinator again?',
                                'recommend_hte' => '6. Would you recommend this HTE to other students who will take internship in the future?',
                            ];
                            foreach ($assessItems as $name => $label):
                                ?>
                                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
                 padding:8px 0;border-bottom:1px solid #f1f5f9;">
                                    <span
                                        style="font-size:13px;color:#374151;flex:1;"><?= htmlspecialchars($label) ?></span>
                                    <div class="d-flex gap-2 flex-shrink-0">
                                        <?php for ($r = 1; $r <= 4; $r++): ?>
                                            <label
                                                style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;">
                                                <input type="radio" name="<?= $name ?>" value="<?= $r ?>" required
                                                    style="accent-color:#29335C;width:16px;height:16px;">
                                                <span style="font-size:10px;color:#94a3b8;"><?= $r ?></span>
                                            </label>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- ── PART IV ── -->
                        <div
                            style="font-size:12px;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;margin:20px 0 10px;">
                            Part IV · General Comments
                        </div>
                        <div
                            style="background:#fff;border-radius:10px;padding:18px 22px;margin-bottom:14px;border:1px solid #e2e8f0;display:flex;flex-direction:column;gap:14px;">
                            <?php
                            $comments = [
                                'most_valuable' => '1. What was the most valuable aspect of the internship?',
                                'least_valuable' => '2. What was the least valuable aspect of the internship?',
                                'concerns' => '3. Is there anything, problems or concerns, related to your internship experience that we should be aware of?',
                                'suggestions' => '4. Can you suggest any ways to improve the internship experience in the future?',
                            ];
                            foreach ($comments as $name => $label):
                                ?>
                                <div>
                                    <label
                                        style="font-size:13px;color:#374151;font-weight:500;margin-bottom:6px;display:block;">
                                        <?= htmlspecialchars($label) ?>
                                    </label>
                                    <textarea name="<?= $name ?>" rows="3" class="form-control"
                                        style="font-size:13px;border-radius:8px;"
                                        placeholder="Write your answer here…"></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- error msg -->
                        <div id="eval-error-msg" style="display:none;color:#dc2626;font-size:13px;margin-bottom:10px;">
                        </div>

                    </form>
                </div><!-- /modal-body -->

                <div class="modal-footer" style="background:#f8f9fb;padding:16px 28px;gap:10px;">
                    <span style="font-size:12px;color:#94a3b8;flex:1;">
                        Responses will be kept within the department for record purposes only.
                    </span>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="evalPreviewBtn">
                        <i class="fa-regular fa-eye me-1"></i> Preview PDF
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="evalLaterBtn">
                        Later
                    </button>
                    <button type="button" class="btn btn-sm fw-semibold px-4" id="evalSubmitBtn"
                        style="background:#29335C;color:#fff;border-radius:8px;">
                        <i class="fa-solid fa-paper-plane me-1"></i> Submit Evaluation
                    </button>
                </div>

            </div>
        </div>
    </div><!-- end of eval modal-->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>


        async function rcDeleteMessage(id, btn) {
            if (!confirm('Delete this message? This cannot be undone.')) return;
            try {
                const res = await fetch('message-delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ message_id: id })
                });
                const data = await res.json();
                if (data.success) {
                    const row = btn.closest('.rc-row');
                    if (row) row.remove();
                } else {
                    alert(data.message || 'Failed to delete message.');
                }
            } catch (err) {
                alert('Network error while deleting message.');
            }
        }

        function rcShowAttachment(input) {
            const preview = document.getElementById('rcAttachPreview');
            const nameEl = document.getElementById('rcAttachName');
            const imgEl = document.getElementById('rcAttachThumbImg');
            const vidEl = document.getElementById('rcAttachThumbVideo');
            const iconEl = document.getElementById('rcAttachThumbIcon');

            imgEl.style.display = 'none';
            vidEl.style.display = 'none';
            iconEl.style.display = 'none';
            imgEl.src = '';
            vidEl.src = '';

            if (input.files && input.files[0]) {
                const file = input.files[0];
                const maxMB = 25;
                if (file.size > maxMB * 1024 * 1024) {
                    alert(`File too large. Max ${maxMB}MB.`);
                    input.value = '';
                    preview.style.display = 'none';
                    return;
                }

                const url = URL.createObjectURL(file);

                if (file.type.startsWith('image/')) {
                    imgEl.src = url;
                    imgEl.style.display = 'block';
                } else if (file.type.startsWith('video/')) {
                    vidEl.src = url;
                    vidEl.style.display = 'block';
                } else {
                    let icon = 'fa-file';
                    if (/\.pdf$/i.test(file.name)) icon = 'fa-file-pdf';
                    else if (/\.docx?$/i.test(file.name)) icon = 'fa-file-word';
                    iconEl.innerHTML = `<i class="fa-solid ${icon}" style="font-size:20px;color:#888;"></i>`;
                    iconEl.style.display = 'flex';
                }

                nameEl.textContent = file.name;
                preview.style.display = 'flex';
            }
        }

        function rcClearAttachment() {
            document.getElementById('rcAttachInput').value = '';
            document.getElementById('rcAttachPreview').style.display = 'none';
        }

        /* ── send on Enter (Shift+Enter = newline) ── */
        function rcEnterSend(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                const ta = document.getElementById('rcTextarea');
                const fileInput = document.getElementById('rcAttachInput');
                const btn = document.getElementById('rcSendBtn');
                const hasText = ta && ta.value.trim();
                const hasFile = fileInput && fileInput.files.length > 0;
                if (hasText || hasFile) {
                    btn.disabled = true;
                    document.getElementById('rcForm').submit();
                }
            }
        }



        function showSection(event, sectionId, el) {
            if (event) event.preventDefault();
            document.querySelectorAll('.section').forEach(sec => sec.classList.remove('active'));
            document.getElementById(sectionId)?.classList.add('active');
            document.querySelectorAll('.sidebar a').forEach(link => link.classList.remove('active'));
            if (el) el.classList.add('active');
        }

        function switchTab(type) {
            const indicator = document.getElementById('tabIndicator');
            const mediaPane = document.getElementById('mediaPane');
            const filesPane = document.getElementById('filesPane');
            const tabs = document.querySelectorAll('.tab-item');
            if (type === 'media') {
                indicator.style.transform = 'translateX(0%)';
                tabs[0].classList.add('active-tab');
                tabs[1].classList.remove('active-tab');
                mediaPane.classList.add('active-pane');
                filesPane.classList.remove('active-pane');
            } else {
                indicator.style.transform = 'translateX(100%)';
                tabs[1].classList.add('active-tab');
                tabs[0].classList.remove('active-tab');
                filesPane.classList.add('active-pane');
                mediaPane.classList.remove('active-pane');
            }
        }

        function filterChats() {
            const query = document.getElementById('chat-search').value.toLowerCase().trim();
            document.querySelectorAll('#chat-users-list .chat-user-entry').forEach(entry => {
                const name = entry.dataset.name ?? '';
                entry.style.setProperty('display', name.includes(query) ? 'block' : 'none', 'important');
            });
        }

        const msgContent = document.querySelector('.message-content');
        if (msgContent) msgContent.scrollTop = msgContent.scrollHeight;

        function toggleNewChat() {
            const panel = document.getElementById('new-chat-panel');
            const isHidden = panel.offsetParent === null || panel.style.display === 'none' || panel.style.display === '';
            panel.style.display = isHidden ? 'block' : 'none';
            if (isHidden) {
                document.getElementById('user-search-input').focus();
                document.getElementById('user-search-input').value = '';
                document.getElementById('user-search-results').innerHTML = '';
            }
        }

        function searchAllUsers() {
            const q = document.getElementById('user-search-input').value.trim();
            const resultsBox = document.getElementById('user-search-results');
            if (q.length < 1) { resultsBox.innerHTML = ''; return; }
            fetch(`search-users.php?q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(users => {
                    if (users.length === 0) {
                        resultsBox.innerHTML = '<div class="p-2 text-muted">No users found</div>';
                        return;
                    }
                    resultsBox.innerHTML = users.map(u => `
                        <a href="?chat_id=${u.id}&chat_type=${u.type}&section=chats"
                            class="d-flex align-items-center gap-2 p-2 text-dark text-decoration-none border-bottom"
                            style="cursor:pointer;"
                            onmouseover="this.style.background='#f5f5f5'"
                            onmouseout="this.style.background=''">
                            <div class="avatar-circle" style="width:32px;height:32px;"></div>
                            <div>
                                <div class="fw-semibold">${u.full_name}</div>
                                <small class="text-muted text-capitalize">${u.type}</small>
                            </div>
                        </a>
                    `).join('');
                });
        }

        const track = document.getElementById('chatSlideTrack');

        function isMobile() { return window.innerWidth <= 768; }

        document.addEventListener('DOMContentLoaded', () => {
            <?php if ($current_chat_id && $current_section === 'chats'): ?>
                if (isMobile() && track) track.classList.add('show-chat');
            <?php endif; ?>

            document.querySelectorAll('.chat-user-link').forEach(link => {
                link.addEventListener('click', function (e) {
                    if (isMobile()) {
                        e.preventDefault();
                        const href = this.href;
                        const name = this.dataset.name;
                        if (track) {
                            track.classList.add('show-chat');
                            track.classList.remove('show-profile');
                        }
                        const mobileNameEl = document.getElementById('mobileChatName');
                        if (mobileNameEl) mobileNameEl.textContent = name;
                        setTimeout(() => { window.location.href = href; }, 200);
                    }
                });
            });
        });

        function mobileChatInfo() {
            if (track) { track.classList.add('show-chat'); track.classList.add('show-profile'); }
        }
        function mobileChatBack() {
            if (track) track.classList.remove('show-chat', 'show-profile');
        }
        function mobileProfileBack() {
            if (track) track.classList.remove('show-profile');
        }

        function truncateRoomItems() {
            if (window.innerWidth <= 768) {
                document.querySelectorAll('.room-link .room-item, .active-room').forEach(el => {
                    if (!el.dataset.original) el.dataset.original = el.textContent.trim();
                    el.textContent = el.dataset.original.trim().charAt(0).toUpperCase();
                });
            } else {
                document.querySelectorAll('.room-link .room-item, .active-room').forEach(el => {
                    if (el.dataset.original) el.textContent = el.dataset.original;
                });
            }
        }

        window.addEventListener('resize', truncateRoomItems);
        document.addEventListener('DOMContentLoaded', truncateRoomItems);

        // TRACK RENDERED HOURS
        const OJT_REQUIRED_HOURS = <?= (int) $requiredHours ?>;
        const OJT_DAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
        let ojtWeekCounter = 0;
        let ojtUnsavedChanges = {};

        function ojtToMins(t) {
            if (!t) return 0;
            const [h, m] = t.split(':').map(Number);
            return h * 60 + m;
        }
        function ojtCalcMins(inV, outV) {
            const diff = ojtToMins(outV) - ojtToMins(inV);
            return diff > 0 ? diff : 0;
        }
        function ojtFmtHM(mins) {
            if (!mins || mins <= 0) return '0h 0m';
            return Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm';
        }
        function ojtFmtShort(mins) {
            if (!mins || mins <= 0) return '—';
            const h = Math.floor(mins / 60), m = mins % 60;
            return m === 0 ? h + 'h' : h + 'h ' + m + 'm';
        }
        function ojtGetDay(dateStr) {
            if (!dateStr) return '';
            return OJT_DAYS[new Date(dateStr + 'T00:00:00').getDay()];
        }
        function ojtIsWeekend(day) { return day === 'SUN' || day === 'SAT'; }

        function ojtRecalcAll() {
            let grand = 0;
            ojtWeeks.forEach(w => {
                let wTotal = 0;
                w.rows.forEach(r => {
                    r.mHrs = ojtCalcMins(r.mIn, r.mOut);
                    r.aHrs = ojtCalcMins(r.aIn, r.aOut);
                    r.daily = r.mHrs + r.aHrs;
                    if (r.date) r.day = ojtGetDay(r.date);
                    wTotal += r.daily;
                });
                w.total = wTotal;
                grand += wTotal;
            });

            const req = OJT_REQUIRED_HOURS * 60;
            const rem = Math.max(0, req - grand);
            const pct = Math.min(100, Math.round((grand / req) * 100));

            document.getElementById('ojt-sum-monthly').textContent = ojtFmtHM(grand);
            document.getElementById('ojt-sum-completed').textContent = ojtFmtHM(grand);
            document.getElementById('ojt-sum-remaining').textContent = ojtFmtHM(rem);

            // progress bar
            document.getElementById('ojt-progress-fill').style.width = pct + '%';
            document.getElementById('ojt-pct-label').textContent = pct + '%';

            ojtWeeks.forEach((_, i) => ojtRenderWeek(i));
        }

        function ojtRenderWeek(wi) {
            const w = ojtWeeks[wi];
            const block = document.querySelector(`.ojt-week-block[data-week-id="${w.id}"]`);
            if (!block) return;

            const totalEl = block.querySelector('.ojt-week-total');
            if (totalEl) totalEl.textContent = ojtFmtShort(w.total);

            w.rows.forEach((row, ri) => {
                const tr = block.querySelector(`tr[data-row-index="${ri}"]`);
                if (!tr) return;
                const mhEl = tr.querySelector('[data-display="mhrs"]');
                const ahEl = tr.querySelector('[data-display="ahrs"]');
                const dhEl = tr.querySelector('[data-display="daily"]');
                const dayEl = tr.querySelector('[data-display="day"]');

                if (mhEl) { mhEl.textContent = ojtFmtShort(row.mHrs); mhEl.className = 'ojt-hrs-val' + (row.mHrs > 0 ? ' has-val' : ''); }
                if (ahEl) { ahEl.textContent = ojtFmtShort(row.aHrs); ahEl.className = 'ojt-hrs-val' + (row.aHrs > 0 ? ' has-val' : ''); }
                if (dhEl) { dhEl.textContent = ojtFmtShort(row.daily); dhEl.className = 'ojt-daily-val' + (row.daily > 0 ? ' has-val' : ''); }
                if (dayEl && row.day) {
                    dayEl.textContent = row.day;
                    dayEl.className = 'ojt-day-badge ' + (ojtIsWeekend(row.day) ? 'weekend' : 'weekday');
                }
            });
        }

        function ojtInit() {
            // Build ojtWeeks from the already-rendered PHP HTML
            ojtWeeks = [];
            document.querySelectorAll('.ojt-week-block').forEach(block => {
                const id = parseInt(block.dataset.weekId, 10);
                const labelInput = block.querySelector('.ojt-week-label');
                const rows = [];

                block.querySelectorAll('tbody tr').forEach(tr => {
                    const dateInput = tr.querySelector('[data-field="date"]');
                    const mInInput = tr.querySelector('[data-field="mIn"]');
                    const mOutInput = tr.querySelector('[data-field="mOut"]');
                    const aInInput = tr.querySelector('[data-field="aIn"]');
                    const aOutInput = tr.querySelector('[data-field="aOut"]');
                    rows.push({
                        date: dateInput ? dateInput.value : '',
                        mIn: mInInput ? mInInput.value : '',
                        mOut: mOutInput ? mOutInput.value : '',
                        aIn: aInInput ? aInInput.value : '',
                        aOut: aOutInput ? aOutInput.value : '',
                        mHrs: 0, aHrs: 0, daily: 0, day: ''
                    });
                });

                ojtWeeks.push({
                    id,
                    weekIndex: id,
                    weekLabel: labelInput ? labelInput.value : `Week ${ojtWeeks.length + 1}`,
                    rows,
                    total: 0
                });

                if (id > ojtWeekCounter) ojtWeekCounter = id;
            });

            ojtRecalcAll(); // This will now have data to work with
        }

        document.addEventListener('DOMContentLoaded', ojtInit);

        function ojtCloneWeekTemplate(id, wIdx) {
            const template = document.getElementById('ojt-week-template');
            const clone = template.content.firstElementChild.cloneNode(true);
            clone.dataset.weekId = id;
            clone.id = 'ojt-week-block-' + id;
            clone.querySelector('.ojt-week-label').value = `Week ${wIdx + 1}`;

            clone.querySelectorAll('tbody tr').forEach((tr, ri) => {
                tr.dataset.rowIndex = ri;
                const dayEl = tr.querySelector('[data-display="day"]');
                const mhEl = tr.querySelector('[data-display="mhrs"]');
                const ahEl = tr.querySelector('[data-display="ahrs"]');
                const dhEl = tr.querySelector('[data-display="daily"]');
                if (dayEl) dayEl.id = `ojt-day-${id}-${ri}`;
                if (mhEl) mhEl.id = `ojt-mhrs-${id}-${ri}`;
                if (ahEl) ahEl.id = `ojt-ahrs-${id}-${ri}`;
                if (dhEl) dhEl.id = `ojt-daily-${id}-${ri}`;
            });

            const removeBtn = clone.querySelector('.ojt-remove-btn');
            if (removeBtn) {
                removeBtn.style.display = wIdx > 0 ? '' : 'none';
                removeBtn.onclick = () => ojtRemoveWeek(id);
            }
            return clone;
        }

        function ojtOnFieldChange(el) {
            const block = el.closest('.ojt-week-block');
            if (!block) return;
            const weekId = parseInt(block.dataset.weekId, 10);
            const field = el.dataset.field;
            if (!field) return;

            if (field === 'week_label') {
                const week = ojtWeeks.find(w => w.id === weekId);
                if (week) {
                    week.weekLabel = el.value.trim();
                    ojtUnsavedChanges[`${weekId}-label`] = el.value.trim();
                    ojtShowSaveBar();
                }
                return;
            }

            const tr = el.closest('tr');
            if (!tr) return;
            const rowIndex = parseInt(tr.dataset.rowIndex, 10);
            const week = ojtWeeks.find(w => w.id === weekId);
            if (!week || rowIndex < 0 || rowIndex >= week.rows.length) return;

            week.rows[rowIndex][field] = el.value;
            ojtUnsavedChanges[`${weekId}-${rowIndex}-${field}`] = el.value;
            ojtShowSaveBar();
            ojtRecalcAll();
            ojtComputeStatus(weekId, rowIndex);
        }

        function ojtShowSaveBar() {
            const bar = document.getElementById('ojt-save-bar');
            if (bar && Object.keys(ojtUnsavedChanges).length > 0) bar.style.display = 'block';
        }

        function ojtHideSaveBar() {
            const bar = document.getElementById('ojt-save-bar');
            if (bar) bar.style.display = 'none';
            ojtUnsavedChanges = {};
        }

        function ojtShowMessage(msg, isError = false) {
            const msgEl = document.getElementById('ojt-save-message');
            if (!msgEl) return;
            msgEl.textContent = msg;
            msgEl.style.color = isError ? '#dc2626' : '#059669';
            msgEl.style.display = 'block';
            setTimeout(() => { msgEl.style.display = 'none'; }, 4000);
        }

        async function ojtSaveAll() {
            const btn = document.getElementById('ojt-save-btn');
            const statusEl = document.getElementById('ojt-save-status');
            if (!btn) return;

            const changes = ojtUnsavedChanges;
            if (Object.keys(changes).length === 0) { ojtShowMessage('No changes to save.'); return; }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';
            statusEl.innerHTML = '<i class="fa-solid fa-circle me-1" style="color:#f59e0b;"></i>Saving...';

            try {
                const saves = [];
                for (const [key, value] of Object.entries(changes)) {
                    if (key.endsWith('-label')) {
                        saves.push({ action: 'save_week_label', week_index: parseInt(key.split('-')[0], 10), week_label: value });
                    } else {
                        const parts = key.split('-');
                        saves.push({ action: 'save_row', week_index: parseInt(parts[0], 10), row_index: parseInt(parts[1], 10), field: parts[2], value });
                    }
                }

                let allSuccess = true;
                for (const save of saves) {
                    const response = await fetch('ojt-hours-db.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams(save)
                    });
                    if (!response.ok) throw new Error(`Server returned ${response.status}`);
                    const data = await response.json();
                    if (!data.success) { allSuccess = false; }
                }

                btn.disabled = false;
                if (allSuccess) {
                    btn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Saved!';
                    statusEl.innerHTML = '<i class="fa-solid fa-circle-check me-1" style="color:#059669;"></i>All changes saved';
                    ojtShowMessage('All changes saved successfully!');
                    setTimeout(() => { ojtHideSaveBar(); btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Save Changes'; }, 2000);

                    // Check completion after save
                    const req = OJT_REQUIRED_HOURS * 60;
                    let grand = 0;
                    ojtWeeks.forEach(w => w.rows.forEach(r => { grand += (r.daily || 0); }));
                    const pct = Math.min(100, Math.round((grand / req) * 100));
                    if (pct >= 100 && !window.ojtCompletionNotified) {
                        window.ojtCompletionNotified = true;
                        ojtTriggerCompletion();
                    }
                } else {
                    btn.innerHTML = '<i class="fa-solid fa-exclamation-triangle me-1"></i>Error';
                    statusEl.innerHTML = '<i class="fa-solid fa-circle me-1" style="color:#dc2626;"></i>Save failed';
                    ojtShowMessage('Some changes failed to save. Please try again.', true);
                }
            } catch (error) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-exclamation-triangle me-1"></i>Error';
                statusEl.innerHTML = '<i class="fa-solid fa-circle me-1" style="color:#dc2626;"></i>Save failed';
                ojtShowMessage(`Save failed: ${error.message}`, true);
            }
        }

        function ojtAddWeek() {
            const id = ojtWeekCounter + 1;
            const weekLabel = `Week ${ojtWeeks.length + 1}`;
            const block = ojtCloneWeekTemplate(id, ojtWeeks.length);
            document.getElementById('ojt-weeks-container').appendChild(block);
            ojtWeeks.push({
                id, weekIndex: id, weekLabel,
                rows: Array.from({ length: 6 }, () => ({ date: '', mIn: '', mOut: '', aIn: '', aOut: '', mHrs: 0, aHrs: 0, daily: 0, day: '' })),
                total: 0
            });
            ojtWeekCounter = id;
            saveWeekLabel(id, weekLabel);
            ojtRecalcAll();
        }

        function ojtRemoveWeek(id) {
            const idx = ojtWeeks.findIndex(w => w.id === id);
            if (idx === -1) return;
            ojtWeeks.splice(idx, 1);
            const el = document.querySelector(`.ojt-week-block[data-week-id="${id}"]`);
            if (el) el.remove();
            deleteWeekFromBackend(id);
            if (ojtWeeks.length === 0) { ojtAddWeek(); return; }
            ojtRecalcAll();
        }

        function saveWeekLabel(weekId, label) {
            fetch('ojt-hours-db.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'save_week_label', week_index: weekId, week_label: label })
            }).then(r => r.json()).then(data => { if (!data.success) console.warn('Week label save error:', data.message); })
                .catch(err => console.error('Week label save failed:', err));
        }

        function deleteWeekFromBackend(weekId) {
            fetch('ojt-hours-db.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'delete_week', week_index: weekId })
            }).then(r => r.json()).then(data => { if (!data.success) console.warn('Delete error:', data.message); })
                .catch(err => console.error('Delete failed:', err));
        }

        //  OJT COMPLETION EVALUATION MODAL
        function ojtTriggerCompletion() {
            ojtShowEvalModal();
        }

        function ojtShowEvalModal() {
            const modal = new bootstrap.Modal(document.getElementById('ojtEvalModal'), {
                backdrop: 'static',
                keyboard: false
            });
            modal.show();
        }

        document.addEventListener('DOMContentLoaded', function () {
            const laterBtn = document.getElementById('evalLaterBtn');
            if (laterBtn) {
                laterBtn.addEventListener('click', function () {
                    bootstrap.Modal.getInstance(document.getElementById('ojtEvalModal'))?.hide();
                });
            }

            const submitBtn = document.getElementById('evalSubmitBtn');
            if (submitBtn) {
                submitBtn.addEventListener('click', async function () {
                    const form = document.getElementById('ojtEvalForm');
                    const errorEl = document.getElementById('eval-error-msg');
                    errorEl.style.display = 'none';

                    if (!form.checkValidity()) {
                        form.reportValidity();
                        errorEl.textContent = 'Please complete all required fields before submitting.';
                        errorEl.style.display = 'block';
                        return;
                    }

                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Submitting…';

                    const data = new FormData(form);
                    data.append('action', 'submit_evaluation');

                    try {
                        const res = await fetch('ojt-evaluation-submit.php', { method: 'POST', body: data });
                        const json = await res.json();

                        if (json.success) {
                            bootstrap.Modal.getInstance(document.getElementById('ojtEvalModal'))?.hide();

                            window.open('ojt-evaluation-download.php', '_blank');

                            // Success toast
                            const toast = document.createElement('div');
                            toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#065f46;color:#fff;' +
                                'padding:14px 22px;border-radius:10px;font-size:14px;font-weight:600;z-index:9999;' +
                                'box-shadow:0 4px 20px rgba(0,0,0,.2);';
                            toast.textContent = '✓ Evaluation submitted and PDF downloaded!';
                            document.body.appendChild(toast);
                            setTimeout(() => toast.remove(), 5000);
                        } else {
                            errorEl.textContent = json.message || 'Submission failed. Please try again.';
                            errorEl.style.display = 'block';
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Submit Evaluation';
                        }
                    } catch (err) {
                        errorEl.textContent = 'Network error. Please try again.';
                        errorEl.style.display = 'block';
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Submit Evaluation';
                    }
                });
            }

            const previewBtn = document.getElementById('evalPreviewBtn');
            if (previewBtn) {
                previewBtn.addEventListener('click', async function () {
                    const form = document.getElementById('ojtEvalForm');
                    const errorEl = document.getElementById('eval-error-msg');
                    errorEl.style.display = 'none';

                    if (!form.checkValidity()) {
                        form.reportValidity();
                        errorEl.textContent = 'Please complete all required fields before previewing.';
                        errorEl.style.display = 'block';
                        return;
                    }

                    const originalHtml = previewBtn.innerHTML;
                    previewBtn.disabled = true;
                    previewBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Building preview…';

                    try {
                        const data = new FormData(form);
                        const res = await fetch('ojt-evaluation-preview.php', { method: 'POST', body: data });

                        if (!res.ok) throw new Error('Preview failed');

                        const blob = await res.blob();
                        const url = URL.createObjectURL(blob);
                        window.open(url, '_blank');
                        // Revoke later so the opened tab has time to load the blob
                        setTimeout(() => URL.revokeObjectURL(url), 60000);
                    } catch (err) {
                        errorEl.textContent = 'Could not generate preview. Please try again.';
                        errorEl.style.display = 'block';
                    } finally {
                        previewBtn.disabled = false;
                        previewBtn.innerHTML = originalHtml;
                    }
                });
            }
        });

        document.addEventListener('DOMContentLoaded', ojtInit);

        function rcFilterContacts() {
            const q = document.getElementById('rcSearchInput').value.toLowerCase().trim();
            document.querySelectorAll('#rcEntries .rc-entry').forEach(el => {
                el.style.display = (el.dataset.rcname || '').includes(q) ? '' : 'none';
            });
        }

        function prShowFileName(input) {
            const nameEl = document.getElementById('prFileName');
            nameEl.textContent = input.files.length ? '✓ ' + input.files[0].name : '';
        }

        // drag-and-drop visual support
        (function () {
            const dz = document.getElementById('prDropZone');
            const input = document.getElementById('prFileInput');
            if (!dz || !input) return;
            ['dragover', 'dragleave', 'drop'].forEach(evt => {
                dz.addEventListener(evt, e => e.preventDefault());
            });
            dz.addEventListener('drop', e => {
                if (e.dataTransfer.files.length) {
                    input.files = e.dataTransfer.files;
                    prShowFileName(input);
                }
            });
        })();

        /* ── auto-scroll to bottom ── */
        (function () {
            const b = document.getElementById('rcBody');
            if (b) b.scrollTop = b.scrollHeight;
        })();

        /* ── auto-resize textarea ── */
        (function () {
            const ta = document.getElementById('rcTextarea');
            if (!ta) return;
            ta.addEventListener('input', () => {
                ta.style.height = 'auto';
                ta.style.height = Math.min(ta.scrollHeight, 100) + 'px';
            });
        })();

        /* ── mobile: slide list out when a chat is opened ── */
        function rcMobileOpenChat(el) {
            if (window.innerWidth <= 768) {
                document.getElementById('rcList').classList.add('slide-out');
                document.getElementById('rcMobileBack') && (document.getElementById('rcMobileBack').style.display = 'flex');
            }
        }
        function rcMobileGoBack() {
            document.getElementById('rcList').classList.remove('slide-out');
        }

        /* On mobile with a chat already open, slide list away immediately */
        (function () {
            <?php if ($chatSection_id && $current_section === 'chats'): ?>
                if (window.innerWidth <= 768) {
                    const list = document.getElementById('rcList');
                    if (list) list.classList.add('slide-out');
                }
            <?php endif; ?>
        })();



        const OJT_EXPECTED_TIME_IN = "<?= htmlspecialchars($ojtTimeIn ?? '') ?>";
        const OJT_EXPECTED_TIME_OUT = "<?= htmlspecialchars($ojtTimeOut ?? '') ?>";
        function timeToMinutes(t) {
            if (!t) return null;
            const [h, m] = t.split(':').map(Number);
            return (h * 60) + m;
        }

        function ojtComputeStatus(weekIndex, rowIndex) {
            const row = document.querySelector(
                `#ojt-week-block-${weekIndex} tr[data-row-index="${rowIndex}"]`
            );
            if (!row) return;

            const mIn = row.querySelector('[data-field="mIn"]')?.value;
            const aOut = row.querySelector('[data-field="aOut"]')?.value;

            const statusEl = document.getElementById(`ojt-status-${weekIndex}-${rowIndex}`);
            if (!statusEl) return;

            const expectedIn = timeToMinutes(OJT_EXPECTED_TIME_IN);
            const expectedOut = timeToMinutes(OJT_EXPECTED_TIME_OUT);
            const actualIn = timeToMinutes(mIn);
            const actualOut = timeToMinutes(aOut);

            // Not enough data yet (student hasn't timed in/out this day)
            if (actualIn === null || actualOut === null || expectedIn === null || expectedOut === null) {
                statusEl.textContent = '—';
                statusEl.style.color = '';
                statusEl.style.fontWeight = '';
                return;
            }

            const GRACE_MINUTES = 15; // adjust tolerance as needed

            const lateBy = actualIn - expectedIn;     // + = arrived late
            const leftEarlyBy = expectedOut - actualOut;    // + = left before schedule
            const overtimeBy = actualOut - expectedOut;    // + = stayed beyond schedule

            if (lateBy > GRACE_MINUTES || leftEarlyBy > GRACE_MINUTES) {
                statusEl.textContent = lateBy > GRACE_MINUTES ? 'Late' : 'Undertime';
                statusEl.style.color = '#dc3545'; // red
                statusEl.style.fontWeight = '600';
            } else if (overtimeBy > GRACE_MINUTES) {
                statusEl.textContent = 'Overtime';
                statusEl.style.color = '#0d6efd'; // blue
                statusEl.style.fontWeight = '600';
            } else {
                statusEl.textContent = 'On Time';
                statusEl.style.color = '#198754'; // green
                statusEl.style.fontWeight = '600';
            }
        }

        // Run once on load for all existing filled-in rows
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.ojt-week-block').forEach(block => {
                const weekIndex = block.dataset.weekId;
                block.querySelectorAll('tr[data-row-index]').forEach(row => {
                    const rowIndex = row.dataset.rowIndex;
                    ojtComputeStatus(weekIndex, rowIndex);
                });
            });
        });
    </script>
</body>

</html>