<?php
session_start();
require 'db.php';
require 'auth.php';

$role = $_SESSION['role'] ?? null;
$isAdviser = isset($_SESSION['role']) && $_SESSION['role'] === 'internship_adviser';
$adviser_id = $_SESSION['user_id'];
$current_room_id = $_GET['room_id'] ?? null;

$roleMap = [
    'hte_adviser' => ['user_type' => 'adviser', 'table' => 'advisers'],
    'internship_adviser' => ['user_type' => 'adviser', 'table' => 'advisers'],
    'superadmin' => ['user_type' => 'admin', 'table' => 'admins'],
];

if (!isset($roleMap[$role])) {
    $_SESSION['error'] = "We couldn't find a room for your account type. Please contact support if this keeps happening.";
    header("Location: login-ui.php");
    exit;
}

$user_type = $roleMap[$role]['user_type'];
$table = $roleMap[$role]['table'];

// $departmentRoomIds = [
//     'information technology' => 24,
//     'electrical engineering' => 25,
//     'civil engineering' => 26,
// ];

// added para sa sidebar thingy sa baba
$userInfoStmt = $pdo->prepare("SELECT full_name FROM {$table} WHERE id = ?");
$userInfoStmt->execute([$adviser_id]);
$userFullName = $userInfoStmt->fetchColumn() ?: 'User';

$roleLabels = [
    'hte_adviser' => 'HTE Adviser',
    'internship_adviser' => 'OJT Adviser',
    'superadmin' => 'System Admin',
];
$userRoleLabel = $roleLabels[$role] ?? 'User';

if (!isset($_GET['room_id'])) {
    // Get the department
    $stmt = $pdo->prepare("SELECT department FROM {$table} WHERE id = ?");
    $stmt->execute([$adviser_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$user['department']) {
        die('No department assigned to this account.');
    }

    $deptStmt = $pdo->prepare("
    SELECT id FROM rooms
    WHERE LOWER(department) = LOWER(?) AND adviser_id IS NULL AND is_archived = FALSE
    LIMIT 1
");
    $deptStmt->execute([$user['department']]);
    $roomId = $deptStmt->fetchColumn() ?: null;

    if (!$roomId) {
        die('No room mapped for department: ' . htmlspecialchars($user['department']));
    }

    // Find the room by id
    $roomStmt = $pdo->prepare("
        SELECT id FROM rooms
        WHERE id = ? AND is_archived = FALSE
        LIMIT 1
    ");
    $roomStmt->execute([$roomId]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        die('Room not found or archived for department: ' . htmlspecialchars($user['department']));
    }

    $checkStmt = $pdo->prepare("
        SELECT 1 FROM room_members
        WHERE room_id = ? AND user_id = ? AND user_type = ?
    ");
    $checkStmt->execute([$room['id'], $user_id, $user_type]);

    if (!$checkStmt->fetch()) {
        $joinStmt = $pdo->prepare("
            INSERT INTO room_members (room_id, user_id, user_type)
            VALUES (?, ?, ?)
        ");
        $joinStmt->execute([$room['id'], $user_id, $user_type]);
    }

    header("Location: ojt-rooms.php?room_id=" . $room['id']);
    exit;
}

$myRoomsStmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.room_name, r.school_year, r.year_level, r.section
    FROM rooms r
    LEFT JOIN room_members rm ON r.id = rm.room_id
    WHERE r.is_archived = FALSE
      AND (
            (rm.user_id = :uid AND rm.user_type = :utype)
         OR (:utype2 = 'adviser' AND r.adviser_id = :uid2)
      )
    ORDER BY r.school_year DESC NULLS LAST, r.year_level, r.section, r.room_name
");
$myRoomsStmt->execute([
    ':uid' => $user_id,
    ':utype' => $user_type,
    ':utype2' => $user_type,
    ':uid2' => $user_id,
]);
$myRooms = $myRoomsStmt->fetchAll(PDO::FETCH_ASSOC);
// function generateRoomCode($length = 9)
// {
//     $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
//     $code = '';
//     for ($i = 0; $i < $length; $i++) {
//         $code .= $chars[random_int(0, strlen($chars) - 1)];
//     }
//     return $code;
// }

// function generateUniqueRoomCode($pdo)
// {
//     do {
//         $code = generateRoomCode();
//         $stmt = $pdo->prepare("SELECT id FROM rooms WHERE room_code = ?");
//         $stmt->execute([$code]);
//     } while ($stmt->fetch());
//     return $code;
// }

if (!isset($_GET['room_id'])) {
    // Check if adviser already has a room
    $stmt = $pdo->prepare("
        SELECT r.id FROM rooms r
        LEFT JOIN room_members rm ON r.id = rm.room_id
        WHERE (r.adviser_id = ? OR (rm.user_id = ? AND rm.user_type = 'adviser'))
        AND r.is_archived = FALSE
        LIMIT 1
    ");
    $stmt->execute([$adviser_id, $adviser_id]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        // Auto-create a room for this adviser
        $advStmt = $pdo->prepare("SELECT full_name FROM advisers WHERE id = ?");
        $advStmt->execute([$adviser_id]);
        $adviser = $advStmt->fetch(PDO::FETCH_ASSOC);

        $room_name = ($adviser['full_name'] ?? 'Adviser') . "'s Room";

        $createStmt = $pdo->prepare("
            INSERT INTO rooms (room_name, adviser_id, is_archived)
            VALUES (?, ?, FALSE)
        ");
        $createStmt->execute([$room_name, $adviser_id]);
        $room_id = $pdo->lastInsertId();

        $memberStmt = $pdo->prepare("
            INSERT INTO room_members (room_id, user_id, user_type)
            VALUES (?, ?, 'adviser')
        ");
        $memberStmt->execute([$room_id, $adviser_id]);

        header("Location: ojt-rooms.php?room_id=" . $room_id);
        exit;
    }

    header("Location: ojt-rooms.php?room_id=" . $room['id']);
    exit;
}

$section = $_GET['section'] ?? '';

// Load statuses for the status section
$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.full_name,
        COALESCE(i.company, oa.company_name) AS company,
        COALESCE(i.required_hours, 486),
        COALESCE((
    SELECT ROUND(SUM(
        GREATEST(0, EXTRACT(EPOCH FROM (h.m_out - h.m_in)) / 3600) +
        GREATEST(0, EXTRACT(EPOCH FROM (h.a_out - h.a_in)) / 3600)
    )::numeric, 2)
    FROM ojt_hours h
    WHERE h.user_id = s.id 
    AND h.user_type = 'student'
    AND h.m_in IS NOT NULL AND h.m_out IS NOT NULL
    AND h.a_in IS NOT NULL AND h.a_out IS NOT NULL
), 0) AS total_hours,
        MAX(m.remarks) AS latest_remarks,
        COALESCE(
            JSON_OBJECT_AGG(sp.step_key, sp.is_done)
            FILTER (WHERE sp.step_key IS NOT NULL),
            '{}'::json
        ) AS checklist
    FROM students s
    JOIN room_members rm ON s.id = rm.user_id AND rm.user_type = 'student'
    JOIN rooms r ON rm.room_id = r.id
    LEFT JOIN student_internships si ON s.id = si.student_id
    LEFT JOIN internships i ON si.internship_id = i.id
    LEFT JOIN ojt_applications oa ON s.id = oa.student_id
    LEFT JOIN (
        SELECT DISTINCT ON (student_id)
            student_id, remarks
        FROM ojt_remarks
        ORDER BY student_id, updated_at DESC
    ) m ON s.id = m.student_id
    LEFT JOIN student_progress sp ON s.id = sp.student_id
        AND sp.step_key IN (
            'hte_form','addendum','reco_letter','waiver',
            'medical_cert','internship_plan','vicinity_map','oath','ojt_started'
        )
    WHERE r.adviser_id = ?
    AND r.is_archived = FALSE
    GROUP BY s.id, s.full_name, i.company, oa.company_name, i.required_hours
");
$stmt->execute([$adviser_id]);
$statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$appStmt = $pdo->prepare("
    SELECT
        oa.id AS application_id,
        oa.company_name,
        oa.status,
        oa.submitted_at,
        oa.reviewed_at,
        oa.remarks,
        s.id AS student_id,
        s.full_name,
        s.student_id AS student_no,
        s.program,
        i.location,
        -- Aggregate checklist as JSON with both is_done and file_path
        COALESCE(
            JSON_OBJECT_AGG(
                sp.step_key,
                JSON_BUILD_OBJECT(
                    'done', sp.is_done,
                    'file_path', sp.file_path
                )
            ) FILTER (WHERE sp.step_key IS NOT NULL),
            '{}'::json
        ) AS checklist
    FROM ojt_applications oa
    JOIN students s ON oa.student_id = s.id
    JOIN room_members rm ON s.id = rm.user_id AND rm.user_type = 'student'
    JOIN rooms r ON r.id = rm.room_id
    LEFT JOIN internships i ON oa.internship_id = i.id
    LEFT JOIN student_progress sp ON s.id = sp.student_id
    WHERE r.adviser_id = ?
    AND r.is_archived = FALSE
    GROUP BY
        oa.id, oa.company_name, oa.status, oa.submitted_at,
        oa.reviewed_at, oa.remarks,
        s.id, s.full_name, s.student_id, s.program, i.location
    ORDER BY
        CASE oa.status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
        oa.submitted_at DESC
");
$appStmt->execute([$adviser_id]);
$ojtApplications = $appStmt->fetchAll(PDO::FETCH_ASSOC);

$chattableStmt = $pdo->prepare("
    SELECT 
        s.id          AS user_id,
        s.full_name,
        'student'     AS user_type
    FROM students s
    JOIN room_members rm ON s.id = rm.user_id AND rm.user_type = 'student'
    JOIN rooms r ON r.id = rm.room_id
    WHERE r.adviser_id = ?
    AND r.is_archived = FALSE
    UNION
    SELECT
        a.id AS user_id,
        a.full_name,
        'adviser' AS user_type
    FROM advisers a
    WHERE a.role = 'HTE_adviser'
    AND a.internship_id IN (
        SELECT ib.internship_id
        FROM internship_bookmarks ib
        JOIN room_members rm ON ib.student_id = rm.user_id AND rm.user_type = 'student'
        JOIN rooms r ON r.id = rm.room_id
    WHERE r.adviser_id = ?
    AND r.is_archived = FALSE
    )
    ORDER BY full_name
");
$chattableStmt->execute([$adviser_id, $adviser_id]);
$chattableUsers = $chattableStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch existing chat conversations for this adviser ────────────────────────
$adviserChatStmt = $pdo->prepare("
    SELECT
        CASE WHEN sender_id = :uid THEN receiver_id ELSE sender_id END AS chat_user_id,
        CASE WHEN sender_id = :uid THEN receiver_type ELSE sender_type END AS chat_user_type,
        MAX(created_at) AS last_message_at,
        (SELECT message FROM messages m2
         WHERE (m2.sender_id = :uid AND m2.receiver_id = CASE WHEN sender_id = :uid THEN receiver_id ELSE sender_id END)
            OR (m2.receiver_id = :uid AND m2.sender_id = CASE WHEN sender_id = :uid THEN receiver_id ELSE sender_id END)
         ORDER BY m2.created_at DESC LIMIT 1) AS last_message
    FROM messages
    WHERE (sender_id = :uid AND sender_type = 'adviser')
       OR (receiver_id = :uid AND receiver_type = 'adviser')
    GROUP BY chat_user_id, chat_user_type
    ORDER BY last_message_at DESC
");
$adviserChatStmt->execute(['uid' => $adviser_id]);
$adviserChats = $adviserChatStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch messages if a chat is open ─────────────────────────────────────────
$chatSection_id = $_GET['chat_id'] ?? null;
$chatSection_type = $_GET['chat_type'] ?? null;
$chatMessages = [];

if ($chatSection_id && $section === 'chats') {
    $msgStmt = $pdo->prepare("
        SELECT m.*, 
            COALESCE(s.full_name, a.full_name) AS sender_name
        FROM messages m
        LEFT JOIN students s  ON m.sender_id = s.id AND m.sender_type = 'student'
        LEFT JOIN advisers a  ON m.sender_id = a.id AND m.sender_type = 'adviser'
        WHERE
            (m.sender_id = ? AND m.sender_type = 'adviser'
             AND m.receiver_id = ? AND m.receiver_type = ?)
            OR
            (m.sender_id = ? AND m.sender_type = ?
             AND m.receiver_id = ? AND m.receiver_type = 'adviser')
        ORDER BY m.created_at ASC
    ");
    $msgStmt->execute([
        $adviser_id,
        $chatSection_id,
        $chatSection_type,
        $chatSection_id,
        $chatSection_type,
        $adviser_id,
    ]);
    $chatMessages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
}

// helper — get name for a chat list entry
function getRoomChatName($pdo, $id, $type)
{
    if ($type === 'student') {
        $st = $pdo->prepare("SELECT full_name FROM students WHERE id = ?");
    } else {
        $st = $pdo->prepare("SELECT full_name FROM advisers WHERE id = ?");
    }
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row['full_name'] ?? 'Unknown';
}

$colors = ['#d63ba5', '#1abc9c', '#3498db', '#9b59b6'];
$color = $colors[array_rand($colors)];
$page = 'messages';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OJT Adviser | CEE IT Connects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #f0f2f7;
            margin: 0;
            padding-top: 70px;
            min-height: 100vh;
            overflow-y: auto;
        }

        /* .sidebar {
            width: 260px;
            background: #272f54;
            position: fixed;
            top: 70px;
            left: 0;
            height: calc(100vh - 70px);
            padding: 20px 0 20px 0;
            border-right: 1px solid #eee;
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .sidebar::-webkit-scrollbar {
            display: none;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            margin: 2px 10px;
            font-size: 15px;
            font-weight: 500;
            width: calc(100% - 24px);
        }

        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #FFB62F;
            width: calc(100% - 24px);
            height: 4px;
        }

        .sidebar a.active {
            background: #ff6b2c;
            color: #fff;
            width: calc(100% - 24px);
        } */

        .sidebar {
            width: 240px;
            background: #272f54;
            position: fixed;
            top: 70px;
            bottom: 0;
            height: calc(100dvh - 70px);
            padding: 20px 0px 20px 20px;
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            display: flex;
            flex-direction: column;
        }

        .sidebar::-webkit-scrollbar {
            display: none;
        }

        .sidebar a {
            display: block;
            align-items: center;
            padding: 10px 20px;
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            margin-bottom: 6px;
            font-size: 15px;
            font-weight: 500;
            width: calc(100% - 24px);
        }

        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #FFB62F;
            width: calc(100% - 24px);
        }

        .sidebar a.active {
            background: #ff6b2c;
            color: #fff;
            width: calc(100% - 24px);
        }

        .sidebar-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 22px 0px 22px 22px;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .sidebar-scroll::-webkit-scrollbar {
            display: none;
        }

        .sidebar-user {
            margin-top: auto;
            margin-left: -20px;
            margin-right: 0;
            width: calc(100% + 20px);
            display: flex;
            align-items: center;
            margin-bottom: -20px;
            gap: 10px;
            padding: 14px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: #232a4a;
            cursor: pointer;
            flex-shrink: 0;
            overflow: hidden;
        }

        .sidebar-user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #3a4374;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 15px;
            flex-shrink: 0;
            margin-bottom: 0px;
        }

        .sidebar-user-info {
            flex: 1;
            min-width: 0;
        }

        .sidebar-user-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-user-role {
            font-size: 11px;
            color: #9aa3c7;
        }

        .sidebar a.active-room-link:hover {
            background: transparent !important;
            width: calc(100% - 24px) !important;
            /* color: inherit !important; */
        }

        .sidebar-action-btn {
            display: flex;
            align-items: center;
            width: calc(100% - 24px);
            background: none;
            border: none;
            color: #fff;
            text-align: left;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
        }

        .sidebar-action-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #FFB62F;
        }

        /* ── ROOMS LIST ── */
        .rooms-list {
            font-size: 11px;
            color: #fff;
            margin-top: 20px;
        }

        .room-item {
            border-radius: 10px;
            font-size: 14px;
        }

        .room-link {
            text-decoration: none;
            display: block;
            margin: 4px;
            padding: 0;
            width: auto;
        }

        .room-link .room-item:hover {
            cursor: pointer;
            color: #FFB62F;
            padding: 0px !important;
        }

        .active-room {
            background: #ff6b2c;
            color: #fff;
            font-weight: bold;
            cursor: default;
            width: calc(100% - 24px);
            padding: 10px 10px;
        }

        .main {
            margin-left: 240px;
            padding: 24px;
            min-height: calc(100vh - 70px);
        }

        .btn-create {
            display: flex;
            align-items: center;
            background: #33448f;
            color: #fff;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            margin: 4px 10px;
            transition: 0.2s;
            width: calc(100% - 20px);
        }

        .btn-create:hover {
            background: #272f54;
            color: #fff;
        }

        /* Status table */
        .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            background: white;
            border: 1px solid #ccc;
            border-radius: 24px;
            padding: 7px 14px;
            max-width: 300px;
        }

        .search-box input {
            border: none;
            outline: none;
            font-size: 13px;
            width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        table th {
            padding: 10px 14px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
        }

        table td {
            padding: 12px 14px;
            vertical-align: middle;
        }

        .student-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avatar {
            width: 36px;
            height: 36px;
            min-width: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
            flex-shrink: 0;
        }

        .progress-bar-bg {
            width: 80px;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
        }

        .progress-bar-fill {
            height: 100%;
            border-radius: 4px;
            background: #ff6b2c;
        }

        /* horizontal scroll */
        .ojt-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .ojt-status-table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
        }

        /* Chat styles */
        .chat-container {
            display: flex;
            flex-direction: column;
            height: 520px;
            border: 1px solid #eee;
            border-radius: 12px;
            overflow: hidden;
            background: #fafafa;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .msg-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }

        .msg-row.me {
            flex-direction: row-reverse;
        }

        .msg-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e0c4d8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #d63ba5;
            font-size: .7rem;
            flex-shrink: 0;
        }

        .msg-bubble-wrap {
            max-width: 68%;
        }

        .msg-sender {
            font-size: .72rem;
            font-weight: 600;
            color: #888;
            margin-bottom: 3px;
            padding-left: 2px;
        }

        .me .msg-sender {
            text-align: right;
            padding-right: 2px;
            padding-left: 0;
        }

        .msg-bubble {
            padding: 9px 13px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.45;
            word-break: break-word;
        }

        .msg-row:not(.me) .msg-bubble {
            background: #fff;
            border: 1px solid #ecdaea;
            border-bottom-left-radius: 4px;
            color: #222;
        }

        .msg-row.me .msg-bubble {
            background: #d63ba5;
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .msg-time {
            font-size: 12px;
            color: #bbb;
            margin-top: 3px;
            padding-left: 2px;
        }

        .me .msg-time {
            text-align: right;
            padding-right: 2px;
            padding-left: 0;
        }

        .chat-day-divider {
            text-align: center;
            font-size: 12px;
            color: #bbb;
            position: relative;
            margin: 4px 0;
        }

        .chat-day-divider::before,
        .chat-day-divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 38%;
            height: 1px;
            background: #eee;
        }

        .chat-day-divider::before {
            left: 0;
        }

        .chat-day-divider::after {
            right: 0;
        }

        .chat-input-bar {
            border-top: 1px solid #eee;
            background: #fff;
            padding: 10px 12px;
        }

        .file-preview-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 8px;
        }

        .file-chip {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #fce8f5;
            border: 1px solid #f0c6e8;
            border-radius: 20px;
            padding: 4px 10px 4px 8px;
            font-size: 12px;
            color: #b5237f;
        }

        .file-chip button {
            background: none;
            border: none;
            color: #b5237f;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            font-size: 12px;
        }

        .chat-input-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }

        .chat-input-row textarea {
            flex: 1;
            border: 1.5px solid #e8d0e3;
            border-radius: 22px;
            padding: 9px 16px;
            font-size: 14px;
            line-height: 1.4;
            max-height: 110px;
            overflow-y: auto;
            font-family: inherit;
            resize: none;
            outline: none;
        }

        .chat-input-row textarea:focus {
            border-color: #d63ba5;
        }

        .chat-attach-btn,
        .chat-send-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .chat-attach-btn {
            background: #f5e6f2;
            color: #d63ba5;
        }

        .chat-attach-btn:hover {
            background: #ecd3e8;
        }

        .chat-send-btn {
            background: #d63ba5;
            color: #fff;
        }

        .chat-send-btn:hover {
            background: #bc2e8e;
        }

        .chat-send-btn:disabled {
            background: #e5b8d8;
            cursor: not-allowed;
        }

        .progress-bar-bg {
            width: 80px;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
        }

        .progress-bar-fill {
            height: 100%;
            border-radius: 4px;
            background: #ff6b2c;
        }

        .checklist-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .checklist-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
            border: 1.5px solid;
        }

        .checklist-pill.done {
            background: #d1fae5;
            color: #065f46;
            border-color: #6ee7b7;
        }

        .checklist-pill.pending {
            background: #f3f4f6;
            color: #9ca3af;
            border-color: #e5e7eb;
        }


        .room-chat-wrap {
            display: flex;
            height: calc(100vh - 70px);
            margin: -24px;
            overflow: hidden;
        }

        /* Chat list panel */
        .room-chat-list {
            width: 280px;
            flex-shrink: 0;
            border-right: 1px solid #eee;
            display: flex;
            flex-direction: column;
            background: #fff;
            overflow: hidden;
        }

        .room-chat-list-header {
            padding: 16px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .room-chat-list-header h5 {
            font-weight: 700;
            font-size: 15px;
            margin: 0;
        }

        .room-chat-entries {
            flex: 1;
            overflow-y: auto;
        }

        .room-chat-entry {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            border-bottom: 1px solid #f5f5f5;
            transition: background .15s;
        }

        .room-chat-entry:hover {
            background: #fafafa;
        }

        .room-chat-entry.active {
            background: #fff7ed;
        }

        .room-chat-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
            color: white;
            flex-shrink: 0;
        }

        .room-chat-entry-meta {
            flex: 1;
            min-width: 0;
        }

        .room-chat-entry-name {
            font-weight: 600;
            font-size: 13.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .room-chat-entry-preview {
            font-size: 11.5px;
            color: #aaa;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .room-chat-badge {
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 99px;
            font-weight: 600;
        }

        /* Dividers in chat list */
        .room-chat-divider {
            padding: 6px 16px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .6px;
            text-transform: uppercase;
            color: #aaa;
            background: #fafafa;
            border-bottom: 1px solid #f0f0f0;
        }

        /* Message panel */
        .room-message-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #fcfcfc;
            min-width: 0;
            overflow: hidden;
        }

        .room-message-header {
            padding: 14px 20px;
            border-bottom: 1px solid #eee;
            background: #fff;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        .room-message-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .room-msg-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }

        .room-msg-row.me {
            flex-direction: row-reverse;
        }

        .room-msg-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: white;
            flex-shrink: 0;
        }

        .room-msg-bubble-wrap {
            max-width: 65%;
        }

        .room-msg-sender {
            font-size: 11px;
            color: #aaa;
            margin-bottom: 3px;
            padding-left: 2px;
        }

        .me .room-msg-sender {
            text-align: right;
            padding-left: 0;
            padding-right: 2px;
        }

        .room-msg-bubble {
            padding: 9px 14px;
            border-radius: 16px;
            font-size: 13.5px;
            line-height: 1.45;
            word-break: break-word;
        }

        .room-msg-row:not(.me) .room-msg-bubble {
            background: #fff;
            border: 1px solid #eee;
            border-bottom-left-radius: 4px;
            color: #222;
        }

        .room-msg-row.me .room-msg-bubble {
            background: #ff6b2c;
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .room-msg-time {
            font-size: 11px;
            color: #ccc;
            margin-top: 3px;
            padding-left: 2px;
        }

        .me .room-msg-time {
            text-align: right;
            padding-right: 2px;
        }

        .room-message-input-bar {
            border-top: 1px solid #eee;
            background: #fff;
            padding: 12px 16px;
            flex-shrink: 0;
        }

        .room-message-input-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }

        .room-message-input-row textarea {
            flex: 1;
            border: 1.5px solid #e5e7eb;
            border-radius: 22px;
            padding: 9px 16px;
            font-size: 13.5px;
            line-height: 1.4;
            max-height: 100px;
            overflow-y: auto;
            font-family: inherit;
            resize: none;
            outline: none;
            transition: border-color .2s;
        }

        .room-message-input-row textarea:focus {
            border-color: #ff6b2c;
        }

        .room-chat-send-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #ff6b2c;
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            transition: filter .15s;
        }

        .room-chat-send-btn:hover {
            filter: brightness(.9);
        }

        .room-chat-send-btn:disabled {
            background: #ffb899;
            cursor: not-allowed;
        }

        .room-chat-empty {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #ccc;
            gap: 10px;
        }

        .room-chat-search {
            padding: 10px 16px;
            border-bottom: 1px solid #eee;
        }

        .room-chat-search input {
            width: 100%;
            padding: 7px 12px;
            border: 1.5px solid #e5e7eb;
            border-radius: 20px;
            font-size: 13px;
            outline: none;
            font-family: inherit;
            transition: border-color .2s;
        }

        .room-chat-search input:focus {
            border-color: #ff6b2c;
        }

        .room-initial {
            display: none;
        }

        .room-name-text {
            display: inline;
        }

        /* ADDED FOR BETTER LAYOUT */
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

        .btn-update {
            background: #FFE7B3 !important;
            color: #7a5200 !important;
            /* border: none !important;
            border-radius: 10px !important;
            padding: 8px 18px !important;
            font-weight: 600 !important; */
            padding: 8px 18px !important;
            transition: background-color .15s ease, color .15s ease;
        }

        .btn-update:hover {
            background: #E4572E !important;
            color: #fff !important;
        }

        /*===(MEDIA QUERY)===*/
        @media (max-width: 768px) {
            .sidebar {
                position: fixed !important;
                top: 70px !important;
                left: 0 !important;
                bottom: 0 !important;
                width: 60px !important;
                height: calc(100vh - 70px) !important;
                padding: 10px 0 !important;
                border-radius: 0 !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                overflow-x: hidden !important;
                z-index: 100;
            }

            .sidebar>div {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                width: 100% !important;
            }

            /* Hide all text */
            .sidebar .sidebar-text,
            .rooms-list h6,
            .rooms-list hr {
                display: none !important;
            }

            .sidebar-user {
                flex-direction: column;
                justify-content: center;
                padding: 10px 0;
                gap: 4px;
                width: 100%;
                margin-left: 0;
                margin-bottom: -10px;
            }

            .sidebar-user-info {
                display: none;
            }

            /* Icon nav links */
            .sidebar>div>a {
                width: 44px !important;
                height: 44px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                border-radius: 12px !important;
                font-size: 1.2rem !important;
                padding: 0 !important;
            }

            .sidebar>div>a i {
                margin: 0 !important;
            }

            /* Rooms list */
            .rooms-list {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                margin-top: 0 !important;
                width: (100% - 24px) !important;
                width: 100% !important;
                max-height: unset !important;
                overflow: hidden !important;
            }

            .room-link {
                display: flex !important;
                justify-content: center !important;
                width: 100% !important;
                margin: 0 0 8px 0 !important;
                padding: 0 !important;
            }

            /* Room bubble — show ONLY the initial letter */
            .room-item {
                width: 44px !important;
                height: 44px !important;
                min-width: 44px !important;
                min-height: 44px !important;
                border-radius: 12px !important;
                background: #e8e8e8 !important;
                color: #555 !important;
                font-weight: bold !important;
                font-size: 1.2rem !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0 !important;
                margin: 0 !important;
                overflow: hidden !important;
            }

            .room-item.active-room {
                background: #ff6b2c !important;
                color: #fff !important;
                margin: 0 auto;
            }

            .room-item.text-muted {
                background: #f0f0f0 !important;
                color: #bbb !important;
            }

            .room-item:hover {
                background: rgba(255, 255, 255, 0.1);
                color: #FFB62F;
                width: calc(100% - 24px);
            }

            .room-name-text {
                display: none !important;
            }

            .room-initial {
                display: flex !important;
            }

            /* Push main content past the sidebar */
            .main {
                margin-left: 65px !important;
                padding: 15px !important;
            }

            .room-card h5 {
                display: -webkit-box !important;
                -webkit-line-clamp: 2 !important;
                line-clamp: 2 !important;
                -webkit-box-orient: vertical !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }

            /* Fix card height so all cards are equal */
            .room-card {
                min-height: 130px !important;
                max-height: 130px !important;
                overflow: hidden !important;
            }

            .avatar {
                width: 34px;
                height: 34px;
                min-width: 34px;
                min-height: 34px;
                border-radius: 50%;
                flex-shrink: 0;
            }

            .chat-container {
                position: fixed !important;
                top: 70px !important;
                left: 60px !important;
                right: 0 !important;
                bottom: 0 !important;
                height: calc(100vh - 70px) !important;
                border-radius: 0 !important;
                border: none !important;
            }

            .chat-container .chat-messages {
                flex: 1 !important;
            }

            .btn-create {
                padding: 5px 10px;
                width: 65%;
            }
        }
    </style>
</head>

<body>
    <?php include 'navbar.php'; ?>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="fa fa-circle-check me-1"></i><?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['warning'])): ?>
        <div class="alert alert-warning alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:400px;" role="alert" id="flashAlert">
            <i class="fa fa-triangle-exclamation me-1"></i><?= $_SESSION['warning'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['warning']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="fa fa-circle-xmark me-1"></i><?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['info'])): ?>
        <div class="alert alert-info alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="fa fa-circle-info me-1"></i><?= $_SESSION['info'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['info']); ?>
    <?php endif; ?>

    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="rooms-list" style="margin-top: 0;">
            <h6>ROOMS</h6>
            <?php foreach ($myRooms as $room): ?>
                <a href="ojt-rooms.php?room_id=<?= $room['id'] ?>"
                    class="<?= ((int) $current_room_id === (int) $room['id']) ? 'active' : '' ?>"
                    title="<?= htmlspecialchars($room['room_name']) ?>">
                    <i class="bi bi-people-fill me-2"></i>
                    <span class="sidebar-text">
                        <?= htmlspecialchars($room['room_name']) ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <hr style="border-top: 2px solid rgba(255,255,255,0.59); margin: 16px auto; width: calc(100% - 32px);">

        <div style="display:flex; flex-direction:column; width:100%;">
            <a href="ojt-rooms.php?room_id=<?= $current_room_id ?>&section=status" tooltip="Status"
                class="<?= $section === 'status' ? 'active' : '' ?>" title="Status">
                <i class="fa-solid fa-calendar-check me-2"></i> <span class="sidebar-text">Status</span>
            </a>

            <a href="ojt-rooms.php?room_id=<?= $current_room_id ?>&section=ojt_applications"
                class="<?= $section === 'ojt_applications' ? 'active' : '' ?>" tooltip="Requirements"
                title="Requirements">
                <i class="bi bi-file-earmark-fill me-2"></i> <span class="sidebar-text">Requirements</span>
                <?php
                $pendingStmt = $pdo->prepare("
                SELECT COUNT(*) FROM ojt_applications oa 
                JOIN room_members rm ON oa.student_id = rm.user_id AND rm.user_type = 'student'
                WHERE rm.room_id = ? AND oa.status = 'pending'
            ");
                $pendingStmt->execute([$current_room_id]);
                $pCount = $pendingStmt->fetchColumn();
                if ($pCount > 0):
                    ?>
                    <span class="ms-auto badge rounded-pill"
                        style="background:#ff6b2c;font-size:10px;"><?= $pCount ?></span>
                <?php endif; ?>
            </a>

            <a href="ojt-rooms.php?room_id=<?= $current_room_id ?>&section=weekly_reports"
                class="<?= $section === 'weekly_reports' ? 'active' : '' ?>" tooltip="Weekly Reports"
                title="Weekly Reports">
                <i class="fa-solid fa-file-lines me-2"></i> <span class="sidebar-text">Weekly Reports</span>
            </a>

            <a href="ojt-rooms.php?room_id=<?= $current_room_id ?>&section=chats"
                class="<?= $section === 'chats' ? 'active' : '' ?>" title="Chats">
                <i class="fa-solid fa-comments me-2"></i> <span class="sidebar-text">Chats</span>
            </a>
            <hr style="border-top: 2px solid rgba(255,255,255,0.59); margin: 16px auto; width: calc(100% - 32px);">

            <?php if ($isAdviser): ?>
                <!-- <button class="sidebar-action-btn" data-bs-toggle="modal" data-bs-target="#csvUploadModal" title="Import Students">
                    <i class="fa fa-file-csv me-2"></i> <span class="sidebar-text">Import Students</span>
                </button> -->
            <?php endif; ?>
            <hr>

        </div>
        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?= strtoupper(substr($userFullName, 0, 1)) ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($userFullName) ?></div>
                <div class="sidebar-user-role"><?= htmlspecialchars($userRoleLabel) ?></div>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <?php if ($section === ''): ?>
            <?php include 'chat-room-content.php'; ?>

        <?php elseif ($section === 'status'): ?>
            <div id="status" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue mb-4">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-calendar-fill"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>OJT Status</h2>
                            <p>Monitor student progress on their OJT program</p>
                        </div>
                    </div>
                </div>

                <!-- Adviser: Set Required Hours -->
                <div style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;
                align-items:center; justify-content:space-between;">

                    <div style="position:relative; flex:1; min-width:200px;">
                        <i class="fa fa-search"
                            style="position:absolute; color: #f97316; left:10px; top:50%; transform:translateY(-50%); font-size:13px;"></i>
                        <input type="text" id="searchInput" placeholder="Search student..." oninput="filterTable()" style="width:50%; padding:8px 12px 8px 32px; border:1.5px solid #aeaeae; border-radius:22px;
                            font-size:13px; font-family:inherit; outline:none; transition:border-color .2s;"
                            onfocus="this.style.borderColor='#f97316'" onblur="this.style.borderColor='#e5e7eb'">
                    </div>

                    <div style="display:flex; align-items:center; gap:8px; padding:8px 14px;
                background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px;
                font-size:13px; color:#1e40af;">
                        <i class="fa-solid fa-circle-info"></i>
                        Required OJT hours are set per program by the System Administrator.
                    </div>

                </div>


                <div style="background:white; border:1px solid #ddd; border-radius:8px; overflow:hidden;">
                    <div class="sysAdm-table-wrapper">
                        <table class="sysAdm-table" id="ojt-status-table">
                            <thead style="background:#f8f9fa;">
                                <tr>
                                    <th>STUDENT</th>
                                    <th>COMPANY</th>
                                    <th>HOURS</th>
                                    <th>PROGRESS</th>
                                    <th>ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="all-students-tbody">
                                <?php if (empty($statuses)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            No students in this room yet.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($statuses as $s):
                                        // required_hours now comes from the student's matched internship
                                        $requiredHours = !empty($s['required_hours']) ? (int) $s['required_hours'] : 486;
                                        $progressWidth = min(round(($s['total_hours'] / $requiredHours) * 100, 2), 100);
                                        $avatarColors = ['#ff2c8f', '#2c6fff', '#1abc9c', '#9b59b6', '#e67e22'];
                                        $avatarColor = $avatarColors[crc32($s['full_name']) % count($avatarColors)];
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="student-cell">
                                                    <div class="avatar" style="background:<?= $avatarColor ?>;">
                                                        <strong><?= strtoupper(substr($s['full_name'], 0, 1)) ?></strong>
                                                    </div>
                                                    <span><?= htmlspecialchars($s['full_name']) ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (empty($s['company'])): ?>
                                                    <span style="font-size:12px; font-weight:500;">
                                                        No company
                                                    </span>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($s['company']) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?= $s['total_hours'] ?></strong>
                                                <span style="color:#aaa; font-size:12px;">/ <?= $requiredHours ?> hrs</span>
                                            </td>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:8px;">
                                                    <div class="progress-bar-bg">
                                                        <div class="progress-bar-fill" style="width:<?= $progressWidth ?>%"></div>
                                                    </div>
                                                    <span><?= $progressWidth ?>%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $supEvalStmt = $pdo->prepare("SELECT id FROM ojt_evaluations_supervisor WHERE student_id = ?");
                                                $supEvalStmt->execute([$s['id']]);
                                                $hasSupEval = (bool) $supEvalStmt->fetchColumn();
                                                ?>
                                                <?php if ($hasSupEval): ?>
                                                    <a href="ojt-evaluation-download.php?student_id=<?= $s['id'] ?>" target="_blank"
                                                        style="display:inline-flex; align-items:center; gap:5px; padding:5px 10px;
                              background: #ffe5d9; color: #ff6b2c; border-radius:6px; font-size:11px;
                              font-weight:600; border:1px solid #ff6b2c; text-decoration:none; white-space:nowrap;">
                                                        <i class="fa fa-file-pdf"></i> Supervisor Eval
                                                    </a>
                                                <?php else: ?>
                                                    <span style="display:inline-flex; align-items:center; gap:5px; padding:5px 10px;
                                 background: #f3f4f6; color: #9ca3af; border-radius:6px; font-size:11px;
                                 font-weight:600; white-space:nowrap; border:1px solid #e5e7eb;">
                                                        <i class="fa fa-file-pdf"></i> Supervisor Eval
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($section === 'remarks'): ?>
            <div>
                <h4 class="fw-bold mb-1">Requirements & Remarks</h4>
                <p class="text-muted mb-3" style="font-size:.85rem;">Track student requirement submissions.</p>
                <!-- your existing remarks/requirements content here -->
            </div>

        <?php elseif ($section === 'ojt_applications'): ?>

            <?php
            $stepLabels = [
                'resume' => 'Resume',
                'mou' => 'MOU',
                'company_profile' => 'Company Profile',
                'addendum' => 'Addendum',
                'reco_letter' => 'Reco Letter',
                'waiver' => 'Waiver',
                'medical_cert' => 'Medical Cert',
                'internship_plan' => 'Internship Plan',
                'vicinity_map' => 'Vicinity Map',
                'oath' => 'Oath',
            ];
            ?>

            <!-- REQUIREMENTS -->
            <div id="status" class="section-panel section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue mb-4">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-file-earmark-fill"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Requirements</h2>
                            <p>Track students' pre-deployment requirements submission progress.</p>
                        </div>
                    </div>
                </div>
                <div style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; align-items:center;">
                    <div style="position:relative; flex:1; min-width:200px;">
                        <i class="fa fa-search"
                            style="position:absolute; color: #f97316; left:10px; top:50%; transform:translateY(-50%); font-size:13px;"></i>
                        <input type="text" id="search-input" placeholder="Search by name, student no., or company..."
                            oninput="filterApps()" style="width:50%; padding:8px 12px 8px 32px; border:1.5px solid #aeaeae; border-radius:22px;
                            font-size:13px; font-family:inherit; outline:none; transition:border-color .2s;"
                            onfocus="this.style.borderColor='#f97316'" onblur="this.style.borderColor='#e5e7eb'">
                    </div>
                    <select id="progress-filter" onchange="filterApps()"
                        style="padding:8px 12px; border:1.5px solid #e5e7eb; border-radius:8px; font-size:13px;
                        font-family:inherit; outline:none; background:white; cursor:pointer; transition:border-color .2s;" onfocus="this.style.borderColor='#f97316'"
                        onblur="this.style.borderColor='#e5e7eb'">
                        <option value="all">All Progress</option>
                        <option value="0">Not Started (0%)</option>
                        <option value="1">In Progress (1-49%)</option>
                        <option value="50">Halfway (50-74%)</option>
                        <option value="75">Almost Done (75-99%)</option>
                        <option value="100">Complete (100%)</option>
                    </select>
                </div>

                <?php if (empty($ojtApplications)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fa fa-inbox fa-2x mb-2 d-block"></i>
                        No OJT applications yet.
                    </div>
                <?php else: ?>

                    <!-- Application Cards -->
                    <div style="display:flex; flex-direction:column; gap:12px;" id="apps-list">
                        <?php foreach ($ojtApplications as $app):
                            $avatarColors = ['#ff2c8f', '#2c6fff', '#1abc9c', '#9b59b6', '#e67e22'];
                            $avatarColor = $avatarColors[crc32($app['full_name']) % count($avatarColors)];
                            $checklist = json_decode($app['checklist'] ?? '{}', true) ?: [];
                            $doneCount = count(array_filter($checklist, fn($v) => !empty($v['done'])));
                            $totalCount = count($stepLabels);
                            $progressPct = $totalCount > 0 ? round(($doneCount / $totalCount) * 100) : 0;
                            ?>
                            <div class="app-card" data-name="<?= strtolower(htmlspecialchars($app['full_name'])) ?>"
                                data-student="<?= strtolower(htmlspecialchars($app['student_no'])) ?>"
                                data-company="<?= strtolower(htmlspecialchars($app['company_name'] ?? '')) ?>"
                                data-progress="<?= $progressPct ?>" style="background:white; border:1px solid #eee; border-radius:12px; padding:18px 20px;
                            box-shadow:0 1px 4px rgba(0,0,0,0.04); transition:box-shadow .2s;">

                                <!-- Top row: avatar + student info + company + date + status badge -->
                                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">

                                    <!-- Avatar + Name -->
                                    <div style="display:flex; align-items:center; gap:10px; flex:1; min-width:200px;">
                                        <div style="width:42px;height:42px;border-radius:50%;background:<?= $avatarColor ?>;
                        color:white;display:flex;align-items:center;justify-content:center;
                        font-weight:700;font-size:16px;flex-shrink:0;">
                                            <?= strtoupper(substr($app['full_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div style="font-weight:600;font-size:14px;display:flex;align-items:center;gap:8px;">
                                                <?= htmlspecialchars($app['full_name']) ?>
                                                <span
                                                    style=" background: <?= $progressPct === 100 ? '#d1fae5' : ($progressPct >= 50 ? '#dbeafe' : '#fef3c7') ?>;
                                                color: <?= $progressPct === 100 ? '#065f46' : ($progressPct >= 50 ? '#1e40af' : '#92400e') ?>;
                                                border: 1px solid <?= $progressPct === 100 ? '#6ee7b7' : ($progressPct >= 50 ? '#93c5fd' : '#fde68a') ?>;
                                                font-size:11px; font-weight:600; padding:2px 8px;border-radius:99px; white-space:nowrap;">
                                                    <?= $progressPct ?>%
                                                </span>
                                            </div>
                                            <div style="font-size:12px;color:#888;">
                                                <?= htmlspecialchars($app['student_no']) ?> &middot;
                                                <?= htmlspecialchars($app['program']) ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Company -->
                                    <div style="flex:1; min-width:150px;">
                                        <div style="font-size:11px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;">Company
                                        </div>
                                        <div style="font-size:13px;font-weight:500;">
                                            <?= htmlspecialchars($app['company_name'] ?? '—') ?>
                                        </div>
                                        <?php if (!empty($app['location'])): ?>
                                            <div style="font-size:11px;color:#888;">
                                                <i class="fa fa-location-dot me-1"></i>
                                                <?= htmlspecialchars($app['location']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Submitted date -->
                                    <div style="min-width:100px;">
                                        <div style="font-size:11px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;">
                                            Last Submitted</div>
                                        <div style="font-size:13px;">
                                            <?= date('M d, Y', strtotime($app['submitted_at'])) ?>
                                        </div>
                                    </div>

                                    <!-- Status badge -->
                                </div>

                                <!-- Checklist Pills -->
                                <div class="checklist-grid">
                                    <?php foreach ($stepLabels as $key => $label):
                                        $entry = $checklist[$key] ?? null;
                                        $done = !empty($entry['done']);
                                        $file_path = $entry['file_path'] ?? null;
                                        ?>

                                        <?php if ($done && $file_path):
                                            $url = htmlspecialchars($file_path);
                                            $safeLabel = htmlspecialchars($label);
                                            // download link: view-proof.php streams inline, so add a flag for it (see note below)
                                            $dlUrl = strpos($file_path, 'view-proof.php') !== false
                                                ? $url . '&download=1'
                                                : $url;
                                            ?>
                                            <span class="checklist-pill done" style="display:inline-flex; align-items:center; gap:6px;">
                                                <i class="fa fa-circle-check" style="font-size:10px;"></i>
                                                <?= $label ?>

                                                <a href="<?= $url ?>" target="_blank" rel="noopener" title="Preview <?= $safeLabel ?>"
                                                    style="color:inherit; text-decoration:none; opacity:.8;">
                                                    <i class="fa fa-eye" style="font-size:10px;"></i>
                                                </a>

                                                <a href="<?= $dlUrl ?>" download title="Download <?= $safeLabel ?>"
                                                    style="color:inherit; text-decoration:none; opacity:.8;">
                                                    <i class="fa fa-download" style="font-size:10px;"></i>
                                                </a>
                                            </span>

                                        <?php elseif ($done): ?>
                                            <span class="checklist-pill done" title="Marked done — no file attached"
                                                style="display:inline-flex; align-items:center; gap:4px;">
                                                <i class="fa fa-circle-check" style="font-size:10px;"></i>
                                                <?= $label ?>
                                            </span>

                                        <?php else: ?>
                                            <span class="checklist-pill pending" style="display:inline-flex; align-items:center; gap:4px;">
                                                <i class="fa fa-circle" style="font-size:10px;"></i>
                                                <?= $label ?>
                                            </span>

                                        <?php endif; ?>

                                    <?php endforeach; ?>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </div>

            <!-- start of weekly reports logic -->
            <!-- SUBMITTED REPORTS LIST -->
            <!-- <h6 class="fw-bold mb-2" style="font-size:14px;">Your Submitted Reports</h6>
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
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div> -->
        <?php elseif ($section === 'weekly_reports'): ?>
            <div id="status" class="section-panel section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue mb-4">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-file-earmark-text-fill"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Weekly Progress Reports</h2>
                            <p>View weekly reports submitted by students in this room.</p>
                        </div>
                    </div>
                </div>
                <?php
                $reportsStmt = $pdo->prepare("
                    SELECT wr.id, wr.student_id, wr.week_number, wr.wr_filepath, wr.created_at,
                        s.full_name AS student_name
                    FROM weekly_reports wr
                    JOIN students s ON s.id = wr.student_id
                    WHERE EXISTS (
                        SELECT 1
                        FROM room_members rm
                        JOIN rooms r ON r.id = rm.room_id
                        WHERE rm.user_id = s.id AND rm.user_type = 'student'
                        AND r.adviser_id = ? AND r.is_archived = FALSE
                    )
                    ORDER BY s.full_name, wr.week_number
                ");
                $reportsStmt->execute([$adviser_id]);

                // one entry per student, holding all of their reports
                $byStudent = [];
                foreach ($reportsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sid = (int) $r['student_id'];
                    $byStudent[$sid]['name'] = $r['student_name'];
                    $byStudent[$sid]['reports'][] = [
                        'week' => (int) $r['week_number'],
                        'file' => $r['wr_filepath'],
                        'date' => date('M d, Y', strtotime($r['created_at'])),
                    ];
                }
                ?>
                <div
                    style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; align-items:center; justify-content:space-between;">
                    <!-- <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="reportsSearchInput" placeholder="Search student"
                            oninput="filterReportsTable()">
                    </div> -->

                    <div style="position:relative; flex:1; min-width:200px;">
                        <i class="fa fa-search"
                            style="position:absolute; color: #f97316; left:10px; top:50%; transform:translateY(-50%); font-size:13px;"></i>
                        <input type="text" id="reportsSearchInput" placeholder="Search student..."
                            oninput="filterReportsTable()" style="width:50%; padding:8px 12px 8px 32px; border:1.5px solid #aeaeae; border-radius:22px;
                            font-size:13px; font-family:inherit; outline:none; transition:border-color .2s;"
                            onfocus="this.style.borderColor='#f97316'" onblur="this.style.borderColor='#e5e7eb'">
                    </div>


                </div>

                <div class="ojt-table-wrapper">
                    <table class="ojt-status-table">
                        <thead style="background:#f8f9fa;">
                            <tr>
                                <th>Student</th>
                                <th>Reports Submitted</th>
                                <th>Latest Submission</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="reports-tbody">
                            <?php if (empty($byStudent)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No weekly reports submitted yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($byStudent as $sid => $st):
                                    $avatarColors = ['#ff2c8f', '#2c6fff', '#1abc9c', '#9b59b6', '#e67e22'];
                                    $avatarColor = $avatarColors[crc32($st['name']) % count($avatarColors)];
                                    $count = count($st['reports']);
                                    $latest = end($st['reports'])['date'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="student-cell">
                                                <div class="avatar" style="background:<?= $avatarColor ?>;">
                                                    <strong><?= strtoupper(substr($st['name'], 0, 1)) ?></strong>
                                                </div>
                                                <span><?= htmlspecialchars($st['name']) ?></span>
                                            </div>
                                        </td>
                                        <td><?= $count ?> report<?= $count > 1 ? 's' : '' ?></td>
                                        <td><?= $latest ?></td>
                                        <td>
                                            <button type="button" onclick="openReports(<?= (int) $sid ?>)" style="display:inline-flex; align-items:center; gap:5px; padding:5px 10px;
                               background:#dbeafe; color:#1e40af; border-radius:6px; font-size:11px;
                               font-weight:600; border:1px solid #93c5fd; cursor:pointer; white-space:nowrap;">
                                                <i class="fa fa-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="reportsModal" onclick="if(event.target===this) closeReports()" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:2000;
                                align-items:center; justify-content:center; padding:16px;">
                    <div style="background:#fff; border-radius:14px; width:100%; max-width:520px; max-height:80vh;
                                        overflow:auto; padding:20px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                            <h5 id="reportsModalTitle" style="margin:0; color:#272f54;"></h5>
                            <button type="button" onclick="closeReports()"
                                style="border:none; background:none; font-size:22px; cursor:pointer;">&times;</button>
                        </div>
                        <div id="reportsModalBody"></div>
                    </div>
                </div>
            </div>


        <?php elseif ($section === 'remarks'): ?>

            <!-- end of weekly reports logic -->

        <?php elseif ($section === 'chats'): ?>
            <?php
            // Build a lookup of existing chats keyed by user_id+type for last message preview
            $chatLookup = [];
            foreach ($adviserChats as $ac) {
                $key = $ac['chat_user_id'] . '-' . $ac['chat_user_type'];
                $chatLookup[$key] = $ac;
            }

            // Separate chattable users into students and HTE advisers
            $chatStudents = array_filter($chattableUsers, fn($u) => $u['user_type'] === 'student');
            $chatHteAdvisers = array_filter($chattableUsers, fn($u) => $u['user_type'] === 'adviser');

            $avatarColors = ['#ff2c8f', '#2c6fff', '#1abc9c', '#9b59b6', '#e67e22', '#e74c3c', '#16a085'];
            ?>

            <div class="room-chat-wrap">

                <!-- Left: chat list -->
                <div class="room-chat-list">
                    <div class="room-chat-list-header">
                        <h5>Chats</h5>
                    </div>
                    <div class="room-chat-search">
                        <input type="text" placeholder="Search..." id="roomChatSearch" oninput="filterRoomChats()">
                    </div>
                    <div class="room-chat-entries" id="roomChatEntries">

                        <!-- Students -->
                        <div class="room-chat-divider">Students</div>
                        <?php foreach ($chatStudents as $u):
                            $key = $u['user_id'] . '-student';
                            $preview = $chatLookup[$key]['last_message'] ?? 'No messages yet';
                            $avatarColor = $avatarColors[crc32($u['full_name']) % count($avatarColors)];
                            $isActive = ($chatSection_id == $u['user_id'] && $chatSection_type === 'student');
                            ?>
                            <a href="ojt-rooms.php?room_id=<?= $current_room_id ?>&section=chats&chat_id=<?= $u['user_id'] ?>&chat_type=student"
                                class="room-chat-entry <?= $isActive ? 'active' : '' ?>"
                                data-name="<?= strtolower(htmlspecialchars($u['full_name'])) ?>">
                                <div class="room-chat-avatar" style="background:<?= $avatarColor ?>;">
                                    <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                </div>
                                <div class="room-chat-entry-meta">
                                    <div class="room-chat-entry-name"><?= htmlspecialchars($u['full_name']) ?></div>
                                    <div class="room-chat-entry-preview"><?= htmlspecialchars(substr($preview, 0, 40)) ?></div>
                                </div>
                                <span class="room-chat-badge" style="background:#dbeafe; color:#1e40af;">Student</span>
                            </a>
                        <?php endforeach; ?>

                        <!-- HTE Advisers -->
                        <?php if (!empty($chatHteAdvisers)): ?>
                            <div class="room-chat-divider" style="margin-top:4px;">HTE Supervisors</div>
                            <?php foreach ($chatHteAdvisers as $u):
                                $key = $u['user_id'] . '-adviser';
                                $preview = $chatLookup[$key]['last_message'] ?? 'No messages yet';
                                $avatarColor = $avatarColors[crc32($u['full_name']) % count($avatarColors)];
                                $isActive = ($chatSection_id == $u['user_id'] && $chatSection_type === 'adviser');
                                ?>
                                <a href="ojt-rooms.php?room_id=<?= $current_room_id ?>&section=chats&chat_id=<?= $u['user_id'] ?>&chat_type=adviser"
                                    class="room-chat-entry <?= $isActive ? 'active' : '' ?>"
                                    data-name="<?= strtolower(htmlspecialchars($u['full_name'])) ?>">
                                    <div class="room-chat-avatar" style="background:<?= $avatarColor ?>;">
                                        <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                    </div>
                                    <div class="room-chat-entry-meta">
                                        <div class="room-chat-entry-name"><?= htmlspecialchars($u['full_name']) ?></div>
                                        <div class="room-chat-entry-preview"><?= htmlspecialchars(substr($preview, 0, 40)) ?></div>
                                    </div>
                                    <span class="room-chat-badge" style="background:#d1fae5; color:#065f46;">HTE</span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- Right: message panel -->
                <div class="room-message-panel">

                    <?php if ($chatSection_id): ?>
                        <?php
                        $openName = getRoomChatName($pdo, $chatSection_id, $chatSection_type);
                        $openColor = $avatarColors[crc32($openName) % count($avatarColors)];
                        $isHte = ($chatSection_type === 'adviser');
                        ?>

                        <!-- Header -->
                        <div class="room-message-header">
                            <div class="room-chat-avatar"
                                style="background:<?= $openColor ?>; width:36px; height:36px; font-size:14px;">
                                <?= strtoupper(substr($openName, 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-weight:700; font-size:14px;"><?= htmlspecialchars($openName) ?></div>
                                <div style="font-size:11px; color:#aaa;">
                                    <?= $isHte ? 'HTE Supervisor' : 'Student' ?>
                                </div>
                            </div>
                        </div>

                        <!-- Messages -->
                        <div class="room-message-body" id="roomMsgBody">
                            <?php if (empty($chatMessages)): ?>
                                <div style="text-align:center; color:#ccc; margin:auto; font-size:13px;">
                                    <i class="fa fa-comments fa-2x d-block mb-2"></i>
                                    No messages yet. Say hello!
                                </div>
                            <?php else: ?>
                                <?php foreach ($chatMessages as $msg):
                                    $isMe = ($msg['sender_id'] == $adviser_id && $msg['sender_type'] === 'adviser');
                                    $bubbleColor = $avatarColors[crc32($msg['sender_name'] ?? '') % count($avatarColors)];
                                    ?>
                                    <div class="room-msg-row <?= $isMe ? 'me' : '' ?>">
                                        <?php if (!$isMe): ?>
                                            <div class="room-msg-avatar" style="background:<?= $bubbleColor ?>;">
                                                <?= strtoupper(substr($msg['sender_name'] ?? '?', 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="room-msg-bubble-wrap">
                                            <?php if (!$isMe): ?>
                                                <div class="room-msg-sender"><?= htmlspecialchars($msg['sender_name'] ?? '') ?></div>
                                            <?php endif; ?>
                                            <div class="room-msg-bubble"><?= htmlspecialchars($msg['message']) ?></div>
                                            <div class="room-msg-time">
                                                <?= date('M d, g:i A', strtotime($msg['created_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Input bar -->
                        <div class="room-message-input-bar">
                            <form method="POST" action="message-db.php" id="roomChatForm">
                                <input type="hidden" name="receiver_id" value="<?= $chatSection_id ?>">
                                <input type="hidden" name="receiver_type" value="<?= $chatSection_type ?>">
                                <input type="hidden" name="redirect"
                                    value="ojt-rooms.php?room_id=<?= $current_room_id ?>&section=chats&chat_id=<?= $chatSection_id ?>&chat_type=<?= $chatSection_type ?>">
                                <div class="room-message-input-row">
                                    <textarea name="message" id="roomChatInput" rows="1" placeholder="Type a message…" required
                                        onkeydown="roomChatEnterSend(event)"></textarea>
                                    <button type="submit" class="room-chat-send-btn" id="roomChatSendBtn">
                                        <i class="fa fa-paper-plane"></i>
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php else: ?>
                        <div class="room-chat-empty">
                            <i class="fa fa-comments fa-3x"></i>
                            <div style="font-size:14px;">Select a student or supervisor to start chatting</div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- CSV UPLOAD MODAL -->
    <div class="modal fade" id="csvUploadModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">

                <div class="modal-header"
                    style="background:linear-gradient(135deg,#1e3a5f,#2563eb); color:#fff; padding:20px 28px; border:none;">
                    <div>
                        <div
                            style="font-size:11px; letter-spacing:.12em; opacity:.7; text-transform:uppercase; margin-bottom:4px;">
                            Bulk Enrollment
                        </div>
                        <h5 class="modal-title fw-bold mb-0" style="font-size:1.2rem;">
                            Import Students via CSV
                        </h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body" style="padding:28px 32px; background:#f8f9fb;">

                    <div id="csv-step-1">
                        <div
                            style="background:#fff; border-radius:10px; padding:18px 22px; margin-bottom:16px; border:1px solid #e2e8f0;">
                            <p style="font-size:13px; color:#64748b; margin-bottom:14px;">
                                Upload a <code>.csv</code> file with a single column: <strong
                                    style="color:#1e3a5f;">student_id</strong>.
                                Students matching those IDs will be added to your room.
                            </p>
                            <a href="download-csv-temp.php?type=student_room" name="add_student_room_temp"
                                class="btn btn-sm btn-outline-secondary">
                                <i class="fa fa-download me-1"></i> Download Template
                            </a>
                        </div>

                        <div style="background:#fff; border-radius:10px; padding:18px 22px; border:1px solid #e2e8f0;">
                            <label
                                style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.05em; display:block; margin-bottom:8px;">
                                Choose file
                            </label>
                            <input type="file" id="csvFileInput" accept=".csv,.tsv,.txt" class="form-control"
                                onchange="previewCSV(this)">
                            <div id="csv-error" class="alert alert-danger d-none mt-3"
                                style="border-radius:8px; font-size:13px;"></div>
                        </div>
                    </div>

                    <div id="csv-step-2" class="d-none">
                        <div style="background:#fff; border-radius:10px; padding:18px 22px; border:1px solid #e2e8f0;">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span style="font-size:13px; color:#64748b;" id="csv-preview-count"></span>
                                <button class="btn btn-sm btn-outline-secondary" onclick="resetCSV()">
                                    <i class="fa fa-rotate-left me-1"></i> Choose different file
                                </button>
                            </div>
                            <div style="overflow-x:auto; max-height:380px; border:1px solid #eee; border-radius:8px;">
                                <table class="table table-sm table-bordered mb-0" id="csv-preview-table"
                                    style="font-size:12px; min-width:300px;"></table>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="modal-footer" style="background:#f8f9fb; padding:16px 28px; gap:10px; border:none;">
                    <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-sm fw-semibold px-4" id="csv-confirm-btn" onclick="submitCSV()" disabled
                        style="background:#2563eb; color:#fff; border-radius:8px;">
                        <i class="fa fa-upload me-1"></i> Confirm & Import
                    </button>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Flash alert auto-dismiss
        setTimeout(() => {
            const alert = document.getElementById('flashAlert');
            if (alert) {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 300);
            }
        }, 4000);
        // Status table search
        function filterTable() {
            const search = document.getElementById('searchInput')?.value.toLowerCase() ?? '';
            document.querySelectorAll('#all-students-tbody tr').forEach(row => {
                const name = row.querySelector('.student-cell span')?.textContent.toLowerCase() ?? '';
                row.style.display = name.includes(search) ? '' : 'none';
            });
        }

        function filterRoomChats() {
            const q = document.getElementById('roomChatSearch').value.toLowerCase().trim();
            document.querySelectorAll('#roomChatEntries .room-chat-entry').forEach(el => {
                el.style.display = el.dataset.name.includes(q) ? '' : 'none';
            });
        }

        // Auto-scroll to bottom
        const msgBody = document.getElementById('roomMsgBody');
        if (msgBody) msgBody.scrollTop = msgBody.scrollHeight;

        // Send on Enter (Shift+Enter for newline)
        function roomChatEnterSend(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('roomChatForm').submit();
            }
        }

        // Auto-resize textarea
        const ta = document.getElementById('roomChatInput');
        if (ta) {
            ta.addEventListener('input', () => {
                ta.style.height = 'auto';
                ta.style.height = Math.min(ta.scrollHeight, 100) + 'px';
            });
        }

        // CSV preview
        let parsedHeaders = [];
        let parsedRows = [];

        function previewCSV(input) {
            const file = input.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function (e) {
                const text = e.target.result.trim();
                const lines = text.split(/\r?\n/).filter(l => l.trim() !== '');

                if (lines.length < 2) {
                    showCSVError('File must have at least a header row and one data row.');
                    return;
                }

                function parseCSVLine(line) {
                    const result = [];
                    let cur = '', inQuotes = false;
                    for (let i = 0; i < line.length; i++) {
                        const ch = line[i];
                        if (ch === '"') {
                            if (inQuotes && line[i + 1] === '"') { cur += '"'; i++; }
                            else inQuotes = !inQuotes;
                        } else if (ch === ',' && !inQuotes) {
                            result.push(cur.trim()); cur = '';
                        } else { cur += ch; }
                    }
                    result.push(cur.trim());
                    return result;
                }

                parsedHeaders = parseCSVLine(lines[0]);
                parsedRows = lines.slice(1).map(l => parseCSVLine(l));

                const normalized = parsedHeaders.map(h => h.toLowerCase().trim());
                const hasStudentId = normalized.includes('student_id') || normalized.includes('student id');

                if (!hasStudentId) {
                    showCSVError('Missing required column: student_id');
                    return;
                }

                hideCSVError();
                renderPreview();
            };
            reader.readAsText(file);
        }

        function renderPreview() {
            const table = document.getElementById('csv-preview-table');
            let html = '<thead style="position:sticky;top:0;background:#f8f9fa;"><tr>';
            parsedHeaders.forEach(h => { html += `<th>${h}</th>`; });
            html += '</tr></thead><tbody>';
            parsedRows.forEach(row => {
                html += '<tr>' + row.map(cell => `<td>${cell}</td>`).join('') + '</tr>';
            });
            html += '</tbody>';
            table.innerHTML = html;

            document.getElementById('csv-preview-count').innerHTML =
                `<strong>${parsedRows.length}</strong> student(s) to import`;

            document.getElementById('csv-step-1').classList.add('d-none');
            document.getElementById('csv-step-2').classList.remove('d-none');
            document.getElementById('csv-confirm-btn').disabled = false;
        }

        function resetCSV() {
            parsedHeaders = [];
            parsedRows = [];
            document.getElementById('csvFileInput').value = '';
            document.getElementById('csv-step-1').classList.remove('d-none');
            document.getElementById('csv-step-2').classList.add('d-none');
            document.getElementById('csv-confirm-btn').disabled = true;
            hideCSVError();
        }

        function showCSVError(msg) {
            const el = document.getElementById('csv-error');
            el.textContent = msg;
            el.classList.remove('d-none');
            document.getElementById('csv-confirm-btn').disabled = true;
        }

        function hideCSVError() {
            document.getElementById('csv-error').classList.add('d-none');
        }

        function submitCSV() {
            if (!parsedHeaders.length || !parsedRows.length) {
                showCSVError('No data to submit. Please upload a file first.');
                return;
            }

            const btn = document.getElementById('csv-confirm-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Importing...';

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'auto-register-save-csv.php';

            const fields = {
                source: 'ojt-rooms',
                room_id: '<?= $current_room_id ?>'
            };

            Object.entries(fields).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            });

            parsedHeaders.forEach((h, i) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `headers[${i}]`;
                input.value = h;
                form.appendChild(input);
            });

            parsedRows.forEach((row, rowIdx) => {
                row.forEach((cell, colIdx) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `csv[${rowIdx}][${colIdx}]`;
                    input.value = cell;
                    form.appendChild(input);
                });
            });

            document.body.appendChild(form);
            form.submit();
        }

        function toggleReject(appId) {
            const form = document.getElementById('reject-form-' + appId);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }

        function filterApps() {
            const search = document.getElementById('search-input').value.toLowerCase().trim();
            const progress = document.getElementById('progress-filter').value;
            const cards = document.querySelectorAll('.app-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const name = card.dataset.name || '';
                const student = card.dataset.student || '';
                const company = card.dataset.company || '';
                const pct = parseInt(card.dataset.progress);

                // Search match
                const matchSearch = !search ||
                    name.includes(search) ||
                    student.includes(search) ||
                    company.includes(search);

                // Progress match
                let matchProgress = true;
                if (progress === '0') matchProgress = pct === 0;
                if (progress === '1') matchProgress = pct >= 1 && pct <= 49;
                if (progress === '50') matchProgress = pct >= 50 && pct <= 74;
                if (progress === '75') matchProgress = pct >= 75 && pct <= 99;
                if (progress === '100') matchProgress = pct === 100;

                const visible = matchSearch && matchProgress;
                card.style.display = visible ? '' : 'none';
                if (visible) visibleCount++;
            });

            // Show empty state if nothing matches
            let emptyEl = document.getElementById('filter-empty');
            if (!emptyEl) {
                emptyEl = document.createElement('div');
                emptyEl.id = 'filter-empty';
                emptyEl.style.cssText = 'text-align:center;color:#aaa;padding:40px 0;font-size:14px;';
                emptyEl.innerHTML = '<i class="fa fa-filter fa-2x mb-2 d-block"></i>No students match your filters.';
                document.getElementById('apps-list').after(emptyEl);
            }
            emptyEl.style.display = visibleCount === 0 ? '' : 'none';
        }
        function filterReportsTable() {
            const searchValue = document.getElementById('reportsSearchInput').value.toLowerCase();
            const rows = document.querySelectorAll('#reports-tbody tr');
            rows.forEach(row => {
                const studentName = row.querySelector('td:first-child')?.textContent.toLowerCase() || '';
                row.style.display = studentName.includes(searchValue) ? '' : 'none';
            });
        }

        const studentReports = <?= json_encode($byStudent, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function escH(s) {
            const d = document.createElement('div');
            d.textContent = s ?? '';
            return d.innerHTML;
        }

        function openReports(sid) {
            const st = studentReports[sid];
            if (!st) return;

            document.getElementById('reportsModalTitle').textContent = st.name + ' — Weekly Reports';

            document.getElementById('reportsModalBody').innerHTML = st.reports.map(r => `
        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;
                    padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; margin-bottom:8px;">
            <div>
                <strong>Week ${r.week}</strong><br>
                <small class="text-muted">Submitted ${escH(r.date)}</small>
            </div>
            <div style="display:flex; gap:6px;">
                <a href="${escH(r.file)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                    <i class="fa fa-eye"></i> View
                </a>
                <a href="${escH(r.file)}" download class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-download"></i>
                </a>
            </div>
        </div>`).join('');

            document.getElementById('reportsModal').style.display = 'flex';
        }

        function closeReports() {
            document.getElementById('reportsModal').style.display = 'none';
        }
    </script>
</body>

</html>