<?php
session_start();
require 'auth.php';
require 'db.php';

// require_once __DIR__ . '/phpmailer-master/src/PHPMailer.php';
// require_once __DIR__ . '/phpmailer-master/src/SMTP.php';
// require_once __DIR__ . '/phpmailer-master/src/Exception.php';

// use PHPMailer\PHPMailer\PHPMailer;
// use PHPMailer\PHPMailer\SMTP;
// use PHPMailer\PHPMailer\Exception;

// echo realpath(__DIR__ . '/../Sources/forms/CEIT-OJTF-010_Supervisors_Evaluation_of_Student_Intern.pdf');
// die();
$current_room_id = $_GET['room_id'] ?? null;
$section = $_GET['section'] ?? '';
$adviser_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? null;
$roleMap = [
    'hte_adviser' => ['user_type' => 'adviser', 'table' => 'advisers'],
    'internship_adviser' => ['user_type' => 'adviser', 'table' => 'advisers'],
    'superadmin' => ['user_type' => 'admin', 'table' => 'admins'],
];
$user_id = $adviser_id;

if (!isset($roleMap[$role])) {
    die('Unauthorized: no room mapping for role "' . htmlspecialchars((string) $role) . '"');
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

$isAdviser = isset($_SESSION['role']) && $_SESSION['role'] === 'hte_adviser';
if ($isAdviser) {

    $checkRoomStmt = $pdo->prepare("
        SELECT id FROM rooms WHERE adviser_id = ? LIMIT 1
    ");
    $checkRoomStmt->execute([$adviser_id]);
    $advisersRoomId = $checkRoomStmt->fetchColumn();

    if (!$advisersRoomId) {

        // Get the adviser's name to build a default room name
        $advNameStmt = $pdo->prepare("SELECT full_name FROM advisers WHERE id = ?");
        $advNameStmt->execute([$adviser_id]);
        $advName = $advNameStmt->fetchColumn() ?: 'Adviser';

        $roomName = $advName . "'s Room";

        $insertRoom = $pdo->prepare("
            INSERT INTO rooms (room_name, adviser_id, is_archived)
            VALUES (?, ?, FALSE)
            RETURNING id
        ");
        $insertRoom->execute([$roomName, $adviser_id]);
        $advisersRoomId = $insertRoom->fetchColumn();

        // Make the adviser a member of their own room so it shows up
        // in the sidebar's room list query (rm.user_id = adviser_id)
        $pdo->prepare("
            INSERT INTO room_members (room_id, user_id, user_type)
            VALUES (?, ?, 'adviser')
        ")->execute([$advisersRoomId, $adviser_id]);
    }
    if (!$current_room_id) {
        $current_room_id = $advisersRoomId;
    }
}

$stmt = $pdo->prepare("
    SELECT DISTINCT r.*, a.full_name, a.title, a.role
    FROM rooms r
    LEFT JOIN advisers a ON r.adviser_id = a.id
    LEFT JOIN room_members rm ON r.id = rm.room_id
    WHERE (
            (rm.user_id = :uid AND rm.user_type = :utype)
         OR (:utype2 = 'adviser' AND r.adviser_id = :uid2)
    )
    " . (!$isAdviser ? "AND r.is_archived = FALSE" : "") . "
");
$stmt->execute([
    ':uid' => $user_id,
    ':utype' => $user_type,
    ':utype2' => $user_type,
    ':uid2' => $user_id,
]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$studentId = $_POST['student_id'] ?? $_GET['student_id'] ?? null;

// Get the HTE adviser's internship_id
$adviserStmt = $pdo->prepare("SELECT internship_id FROM advisers WHERE id = ?");
$adviserStmt->execute([$adviser_id]);
$adviserInternshipId = $adviserStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.full_name,
        oa.company_name,
        oa.status AS application_status,
        oa.submitted_at,
        i.required_hours,
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
        (SELECT remarks FROM ojt_remarks m
         WHERE m.student_id = s.id
         ORDER BY m.updated_at DESC LIMIT 1) AS latest_remarks
    FROM ojt_applications oa
    JOIN students s ON s.id = oa.student_id
    LEFT JOIN internships i ON i.id = oa.internship_id
    WHERE oa.internship_id = ?
    ORDER BY oa.submitted_at DESC
");
$stmt->execute([$adviserInternshipId]);
$statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all students bookmarked to this adviser's internship
$roomStatusesStmt = $pdo->prepare("
    SELECT
        s.id,
        s.full_name,
        i.company,
        i.required_hours,
        COALESCE(
            SUM(
                CASE WHEN oh.m_in IS NOT NULL AND oh.m_out IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (oh.m_out - oh.m_in)) / 3600
                    ELSE 0
                END
                +
                CASE WHEN oh.a_in IS NOT NULL AND oh.a_out IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (oh.a_out - oh.a_in)) / 3600
                    ELSE 0
                END
            ), 0
        ) AS total_hours
    FROM students s
    JOIN ojt_applications oa ON oa.student_id = s.id
    JOIN internships i ON i.id = oa.internship_id
    JOIN room_members rm ON rm.user_id = s.id AND rm.user_type = 'student'
    LEFT JOIN ojt_hours oh ON oh.user_id = s.id AND oh.user_type = 'student'
    WHERE oa.internship_id = ?
    GROUP BY s.id, s.full_name, i.company, i.required_hours
");
$roomStatusesStmt->execute([$adviserInternshipId]);
$roomStatuses = $roomStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

$adviserDtrStmt = $pdo->prepare("
    SELECT
        s.id AS student_id,
        s.full_name,
        i.company,
        i.required_hours,
        COALESCE(
            SUM(
                CASE WHEN oh.m_in IS NOT NULL AND oh.m_out IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (oh.m_out - oh.m_in)) / 3600
                    ELSE 0
                END
                +
                CASE WHEN oh.a_in IS NOT NULL AND oh.a_out IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (oh.a_out - oh.a_in)) / 3600
                    ELSE 0
                END
            ), 0
        ) AS total_hours
    FROM students s
    JOIN ojt_applications oa ON oa.student_id = s.id
    JOIN internships i ON i.id = oa.internship_id
    LEFT JOIN ojt_hours oh ON oh.user_id = s.id AND oh.user_type = 'student'
    WHERE oa.internship_id = ?
    GROUP BY s.id, s.full_name, i.company, i.required_hours
    ORDER BY s.full_name
");
$adviserDtrStmt->execute([$adviserInternshipId]);
$adviserDtrRows = $adviserDtrStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($adviserDtrRows as &$row) {
    $required = (float) $row['required_hours'];
    $completed = (float) $row['total_hours'];
    $row['remaining'] = max($required - $completed, 0);
    $row['percent'] = $required > 0 ? min(100, round(($completed / $required) * 100)) : 0;
}
unset($row);
// echo '<pre>';
// echo "current_room_id: " . $current_room_id . "\n";
// echo "requiredHours: " . $requiredHours . "\n";

// // Check what's in room_members for this room
// $debugStmt = $pdo->prepare("SELECT * FROM room_members WHERE room_id = ?");
// $debugStmt->execute([$current_room_id]);
// echo "room_members rows:\n";
// print_r($debugStmt->fetchAll(PDO::FETCH_ASSOC));

// echo "roomStatuses:\n";
// print_r($roomStatuses);
// echo '</pre>';
// die();

$chattableStmt = $pdo->prepare("
    SELECT 
        s.id          AS user_id,
        s.full_name,
        'student'     AS user_type
    FROM students s
    JOIN ojt_applications oa ON oa.student_id = s.id
    JOIN advisers a ON a.internship_id = oa.internship_id
    WHERE a.id = ? AND a.role = 'HTE_adviser'

    UNION

    -- OJT advisers (internship_adviser) assigned to those students via rooms
    SELECT
        adv.id        AS user_id,
        adv.full_name,
        'adviser'     AS user_type
    FROM advisers adv
    JOIN rooms r ON r.adviser_id = adv.id
    JOIN room_members rm ON rm.room_id = r.id AND rm.user_type = 'student'
    JOIN students s ON s.id = rm.user_id
    JOIN ojt_applications oa ON oa.student_id = s.id
    JOIN advisers hte ON hte.internship_id = oa.internship_id
    WHERE hte.id = ? AND hte.role = 'HTE_adviser'
    AND adv.role = 'internship_adviser'

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

$supAssignStmt = $pdo->prepare("
    SELECT ss.*
    FROM student_supervisors ss
    JOIN internship_bookmarks ib ON ib.student_id = ss.student_id
    WHERE ib.internship_id = ?
");
$supAssignStmt->execute([$adviserInternshipId]);
$supAssignments = [];
foreach ($supAssignStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $supAssignments[$row['student_id']] = $row;
}

foreach ($roomStatuses as $s) {
    $required = $s['required_hours'] ?: 486;
    if ($s['total_hours'] < $required)
        continue;

    // Check if supervisor is assigned and email not yet sent
    $supCheck = $pdo->prepare("
        SELECT student_id, supervisor_email, eval_sent_at
        FROM student_supervisors
        WHERE student_id = ? AND supervisor_email IS NOT NULL AND eval_sent_at IS NULL
    ");
    $supCheck->execute([$s['id']]);
    $pendingSup = $supCheck->fetch(PDO::FETCH_ASSOC);

    if ($pendingSup) {
        // Trigger the send via internal HTTP call or direct function
        // Since send-eval-email.php uses session/auth, call the logic directly here:
        $supRow = $pdo->prepare("
            SELECT ss.supervisor_name, ss.supervisor_email, ss.eval_token,
                   st.full_name AS student_name
            FROM student_supervisors ss
            JOIN students st ON st.id = ss.student_id
            WHERE ss.student_id = ?
        ");
        $supRow->execute([$s['id']]);
        $sup = $supRow->fetch(PDO::FETCH_ASSOC);

        if ($sup) {
            $baseUrl = rtrim((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'], '/');
            $evalUrl = $baseUrl . '/supervisor-eval-form.php?token=' . urlencode($sup['eval_token']);
            $pdfPath = realpath(__DIR__ . '/Sources/forms/CEIT-OJTF-010_Supervisors_Evaluation_of_Student_Intern.pdf');

            if ($pdfPath && file_exists($pdfPath)) {

                $htmlBody = "Dear <strong>{$sup['supervisor_name']}</strong>,<br><br>"
                    . "Student intern <strong>{$sup['student_name']}</strong> has completed their required OJT hours. "
                    . "Please fill out the attached evaluation form or <a href='{$evalUrl}'>click here</a> to complete it online.";

                $payload = [
                    'sender' => [
                        'name' => 'PLV OJT System',
                        'email' => 'jamesherold25@gmail.com'
                    ],
                    'to' => [
                        [
                            'email' => $sup['supervisor_email'],
                            'name' => $sup['supervisor_name']
                        ]
                    ],
                    'subject' => "Evaluation Request - {$sup['student_name']}",
                    'htmlContent' => $htmlBody,
                    'attachment' => [
                        [
                            'content' => base64_encode(file_get_contents($pdfPath)),
                            'name' => 'CEIT-OJTF-010_Supervisors_Evaluation.pdf',
                        ],
                    ],
                ];

                $ch = curl_init('https://api.brevo.com/v3/smtp/email');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => [
                        'accept: application/json',
                        'api-key:' . getenv('BREVO_API_KEY'),
                        'content-type: application/json',
                    ],
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 300) {
                    $pdo->prepare("UPDATE student_supervisors SET eval_sent_at = NOW() WHERE student_id = ?")
                        ->execute([$s['id']]);
                } else {
                    file_put_contents(
                        __DIR__ . '/mail_debug.log',
                        date('Y-m-d H:i:s') . " | Auto-send error for student {$s['id']}: HTTP $httpCode | $response | $curlError\n",
                        FILE_APPEND
                    );
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HTE Adviser | CEE IT Connects</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        /* * {
            outline: 1px solid red !important;
        }
        html, body {
            overflow: visible !important;
            height: auto !important;
        } */
        body {
            background-color: #f0f2f7;
            margin: 0;
            padding-top: 70px;
            min-height: 100vh;
            overflow-y: auto;
        }

        /* ── SECTION PANELS ── */
        .section-panel {
            display: none;
        }

        .section-panel.active {
            display: block;
        }

        /* ── SIDEBAR ── */
        /* .sidebar {
            width: 260px;
            background: #272f54;
            position: fixed;
            top: 70px;
            bottom: 0;
            height: calc(100vh - 70px);
            overflow-y: auto;
            scrollbar-width: none;
            display: flex;
            flex-direction: column;
            -ms-overflow-style: none;
        } */
        /* .sidebar {
            width: 260px;
            background: #272f54;
            position: fixed;
            top: 70px;
            bottom: 0;
            height: calc(100vh - 70px);
            padding: 20px 0px 20px 20px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            overflow-y: auto;
        } */

        .sidebar {
            width: 260px;
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
        }

        .active-room {
            background: #ff6b2c;
            color: #fff;
            font-weight: bold;
            cursor: default;
            width: calc(100% - 24px);
            padding: 10px 10px;
        }

        /* ── MAIN ── */
        .main {
            margin-left: 260px;
            padding: 20px;
            background-color: #f0f2f7;
            min-height: calc(100vh - 70px);
        }

        /* ── ROOM CARDS ── */
        .room-card {
            border-radius: 12px 12px 0 0;
            color: white;
            padding: 15px;
            min-height: 110px;
        }

        .room-footer {
            background: #fff;
            padding: 10px;
            border-radius: 0 0 12px 12px;
            text-align: center;
            border: 1px solid #bbb;
            border-top: none;
        }

        .enter-btn {
            background: #f4a62a;
            border: none;
            color: #fff;
            font-weight: 600;
            padding: 5px 15px;
            border-radius: 8px;
        }

        /* ── SEARCH BOX ── */
        /* .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            background: white;
            border: 1px solid #bbb;
            border-radius: 24px;
            padding: 7px 14px;
            flex: 1;
            max-width: 300px;
        }

        .search-box input {
            border: none;
            outline: none;
            font-size: 13px;
            width: 100%;
            color: #333;
        } */

        /* ── TABLES ── */
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

        /* ── AVATARS / STUDENT CELL ── */
        .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
            min-width: 34px;
            min-height: 34px;
            flex-shrink: 0;
        }

        .student-cell {
            display: flex;
            align-items: center;
        }

        /* ── PROGRESS BAR ── */
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

        /* ── STUDENT CARDS (Remarks) ── */
        .student-card {
            background: white;
            border: 1px solid #bbb;
            border-radius: 8px;
            margin-bottom: 16px;
            overflow: hidden;
        }

        .log-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            border-bottom: 1px solid #e0e0e0;
        }

        /*susu*/
        .log-row-top {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
        }

        .log-row-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .student-info {
            flex: 1;
            min-width: 0;
        }

        .student-info strong {
            display: block;
            font-size: 14px;
        }

        .student-info span {
            font-size: 12px;
            color: #888;
        }

        .log-row select,
        .log-row input[type="number"] {
            padding: 6px 10px;
            border: 1px solid #bbb;
            border-radius: 6px;
            font-size: 13px;
            outline: none;
        }

        .log-row input[type="number"] {
            width: 70px;
        }

        .total-hrs {
            font-weight: 700;
            font-size: 13px;
            white-space: nowrap;
        }

        .btn-log {
            background: #0ea5c8;
            color: white;
            border: none;
            padding: 7px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-log:hover {
            background: #0c8fad;
        }

        /* ── REMARKS SECTION ── */
        .remarks-section {
            padding: 14px 18px;
            background-color: #fbfbfb;
        }

        .remarks-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .5px;
            color: #555;
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .remarks-section textarea {
            width: 100%;
            border: 1px solid #bbb;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 13px;
            resize: vertical;
            min-height: 80px;
            outline: none;
            background: #fbfbfb;
            box-sizing: border-box;
        }

        .remarks-footer {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 10px;
        }

        .remarks-footer select {
            padding: 6px 10px;
            border: 1px solid #bbb;
            border-radius: 6px;
            font-size: 13px;
            outline: none;
        }

        .remarks-footer label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            cursor: pointer;
        }

        .btn-submit {
            margin-left: auto;
            background: #0ea5c8;
            color: white;
            border: none;
            padding: 7px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-submit:hover {
            background: #0c8fad;
        }

        /* ── PAGE SECTION HEADINGS ── */
        .page-section h2 {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .page-section p {
            font-size: .85rem;
            color: #888;
            margin-bottom: 16px;
        }

        .room-initial {
            display: none;
        }

        .room-name-text {
            display: inline;
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

        @media (max-width: 768px) {
            .sidebar {
                position: fixed !important;
                top: 70px !important;
                left: 0 !important;
                bottom: 0 !important;
                width: 70px !important;
                height: calc(100vh - 70px) !important;
                padding: 10px 0 !important;
                border-radius: 0 !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                overflow-x: hidden !important;
                z-index: 100;
            }

            .sidebar .sidebar-text,
            .rooms-list h6,
            .rooms-list hr {
                display: none !important;
            }

            .sidebar>a {
                width: 44px !important;
                height: 44px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                border-radius: 12px !important;
                margin: 0 auto 8px auto !important;
                font-size: 1.2rem !important;
                padding: 0 !important;
            }

            .sidebar>a i {
                margin: 0 !important;
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

            .rooms-list {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                margin-top: 0 !important;
                width: (100% - 24px) !important;
                max-height: unset !important;
                overflow-y: auto;
                overflow-x: hidden !important;
                scrollbar-width: none;
                gap: 8px;
            }

            .room-link {
                display: flex !important;
                justify-content: center !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .room-item {
                width: 44px !important;
                height: 44px !important;
                min-width: 44px !important;
                min-height: 44px !important;
                border-radius: 12px !important;
                font-weight: bold !important;
                font-size: 1.1rem !important;
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

            .room-item:hover {
                background: rgba(255, 255, 255, 0.1);
                color: #FFB62F;
                width: calc(100% - 24px);
            }

            /* .rooms-list:hover {
                background: rgba(255, 255, 255, 0.1);
                color: #FFB62F;
                width: calc(100% - 24px);
            } */

            .room-name-text {
                display: none !important;
            }

            .room-initial {
                display: flex !important;
            }

            .main {
                margin-left: 70px !important;
                padding: 15px !important;
            }

            /* Room cards — clamp title to 2 lines */
            .room-card h5 {
                display: -webkit-box !important;
                -webkit-line-clamp: 2 !important;
                line-clamp: 2 !important;
                -webkit-box-orient: vertical !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }

            .room-card {
                min-height: 110px !important;
                max-height: 110px !important;
                overflow: hidden !important;
            }

            /* Table horizontal scroll */
            #status .search-box {
                max-width: 100% !important;
            }

            .avatar {
                width: 34px !important;
                height: 34px !important;
                min-width: 34px !important;
                min-height: 34px !important;
                border-radius: 50% !important;
                flex-shrink: 0 !important;
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

            .log-row {
                flex-wrap: wrap !important;
                gap: 10px !important;
                padding: 12px !important;
            }

            /* Student info takes full width on its own row */
            .log-row .student-info {
                flex: 1 1 auto !important;
                min-width: 0 !important;
            }

            /* Avatar stays circular and doesn't shrink */
            .log-row .avatar {
                flex-shrink: 0 !important;
                align-self: center !important;
            }

            /* Status select and hours input go to their own row */
            .log-row select,
            .log-row input[type="number"] {
                flex: 1 !important;
                min-width: 80px !important;
            }

            /* Make the top part (avatar + name) take full row */
            .log-row-top {
                width: 100% !important;
            }

            /* Controls (select + input) on second row */
            .log-row-controls {
                width: 100% !important;
            }

            .log-row-controls select,
            .log-row-controls[type="number"] {
                flex: 1;
            }

            /* Remarks footer wraps on mobile */
            .remarks-footer {
                flex-wrap: wrap !important;
                gap: 8px !important;
            }

            .remarks-footer select {
                width: 100% !important;
            }

            .remarks-footer label {
                flex: 1 !important;
            }

            .btn-submit {
                margin-left: auto !important;
            }
        }
    </style>
</head>

<body>
    <?php include 'navbar.php'; ?>
    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="rooms-list" style="margin-top:0;">
            <h6>ROOMS</h6>
            <!-- <?php foreach ($rooms as $room): ?>
                <?php if ($current_room_id == $room['id']): ?>
                    <div class="room-item active-room">
                        <span class="room-initial"><?= strtoupper(substr(trim($room['room_name']), 0, 1)) ?></span>
                        <span class="room-name-text"><?= htmlspecialchars($room['room_name']) ?></span>
                    </div>
                <?php else: ?>
                    <a href="?room_id=<?= $room['id'] ?>" class="room-link">
                        <div class="room-item">
                            <span class="room-initial"><?= strtoupper(substr(trim($room['room_name']), 0, 1)) ?></span>
                            <span class="room-name-text"><?= htmlspecialchars($room['room_name']) ?></span>
                        </div>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?> -->

            <?php foreach ($rooms as $room): ?>
                <a href="?room_id=<?= $room['id'] ?>" title="<?= htmlspecialchars($room['room_name']) ?>"
                    class="room-link <?= $current_room_id == $room['id'] ? 'active-room-link' : '' ?>">
                    <div class="room-item <?= $current_room_id == $room['id'] ? 'active-room' : '' ?>">
                        <span class="room-initial"><?= strtoupper(substr(trim($room['room_name']), 0, 1)) ?></span>
                        <span class="room-name-text"><?= htmlspecialchars($room['room_name']) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <hr style="border-top: 2px solid rgba(255,255,255,0.59); margin: 16px auto; width: calc(100% - 32px);">

        <a href="#" onclick="showSection('status', event)" id="nav-status" tooltip="Status" title="Status">
            <i class="fa-solid fa-calendar-check me-2"></i><span class="sidebar-text">Status</span>
        </a>
        <a href="#" onclick="showSection('dtr_summary', event)" id="nav-dtr_summary" tooltip="DTR" title="DTR">
            <i class="fa-solid fa-clock me-2"></i><span class="sidebar-text">DTR</span>
        </a>
        <a href="#" onclick="showSection('chats', event)" id="nav-chats" tooltip="Chats" title="Chats">
            <i class="fa-solid fa-comments me-2"></i><span class="sidebar-text">Chats</span>
        </a>

        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?= strtoupper(substr($userFullName, 0, 1)) ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($userFullName) ?></div>
                <div class="sidebar-user-role"><?= htmlspecialchars($userRoleLabel) ?></div>
            </div>
        </div>

    </div>
    <!-- end of sidebar -->

    <!-- MAIN CONTENT -->
    <div class="main">

        <!-- ROOMS SECTION -->
        <div id="rooms" class="section-panel active">
            <?php if ($current_room_id): ?>
                <?php include 'chat-room-content.php'; ?>
            <?php else: ?>
                <?= var_dump($current_room_id) ?>
                <?= var_dump($_SESSION['role']) ?>
            <?php endif; ?>
        </div>

        <!-- STATUS SECTION -->

        <div id="status" class="section-panel section sysAdm-section">
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

            <div
                style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                <!-- <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;"> -->
                <div style="position:relative; flex:1; min-width:200px;">
                    <i class="fa fa-search"
                        style="position:absolute; color: #f97316; left:10px; top:50%; transform:translateY(-50%); font-size:13px;"></i>
                    <input type="text" id="searchInput" placeholder="Search student..." oninput="filterTable()" style="width:25%; padding:8px 12px 8px 32px; border:1.5px solid #aeaeae; border-radius:22px;
                            font-size:13px; font-family:inherit; outline:none; transition:border-color .2s;"
                        onfocus="this.style.borderColor='#f97316'" onblur="this.style.borderColor='#e5e7eb'">
                </div>

                <!-- <?php if ($isAdviser): ?>
                    <form method="POST" action="ojt-required-hours.php" style="display:flex; 
                    align-items:center; gap:8px;">
                        <input type="hidden" name="room_id" value="<?= $current_room_id ?>">
                        <label style="font-size:13px; color:#555; font-weight:500; white-space:nowrap;">
                            Required OJT Hours:
                        </label>
                        <input type="number" name="required_hours" value="<?= $requiredHours ?>" min="1" style="width:70px; border:1.5px solid #e5e7eb; border-radius:8px;
                       padding:6px 8px; font-size:13px; text-align:center; outline:none;">
                        <button type="submit" class="btn-update" style="background:#ff6b2c; color:white; border-radius:8px;" tooltip="Save" title="Save">
                            <i class="fa fa-save me-1"></i>
                        </button>
                    </form>
                <?php endif; ?> -->
            </div>
            <div class="sysAdm-table-wrapper">
                <!-- <div style="background:white; border:1px solid #ddd; border-radius:8px; overflow:hidden; overflow-x:auto;"> -->
                <table class="sysAdm-table" id="ojt-status-table">
                    <thead>
                        <tr>
                            <th>STUDENT</th>
                            <th>COMPANY</th>
                            <th>HOURS</th>
                            <th>PROGRESS</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody id="all-students-tbody">
                        <?php if (empty($roomStatuses)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    No students in this room yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $avatarColors = ['#ff2c8f', '#2c6fff', '#1abc9c', '#9b59b6', '#e67e22'];
                            foreach ($roomStatuses as $s):
                                $requiredHours = $s['required_hours'] ?: 486;
                                $progressWidth = min(round(($s['total_hours'] / $requiredHours) * 100, 2), 100);
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
                                            <span class="rounded-pill px-3 py-1"
                                                style="background:#f8d7da; color:#721c24; font-size:12px; font-weight:500;">
                                                No company
                                            </span>
                                        <?php else: ?>
                                            <?= htmlspecialchars($s['company']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= round($s['total_hours'], 2) ?></strong> / <?= $requiredHours ?></td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <div class="progress-bar-bg">
                                                <div class="progress-bar-fill" style="width:<?= $progressWidth ?>%"></div>
                                            </div>
                                            <span><?= $progressWidth ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display:flex; flex-direction:column; gap:6px;">

                                            <button type="button" onclick="openSupEvalModal(<?= $s['id'] ?>)"
                                                style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;
                                                background:#dbeafe;color:#1e40af;border-radius:6px;font-size:11px;
                                                font-weight:600;border:1px solid #93c5fd;cursor:pointer;white-space:nowrap;">
                                                <i class="fa fa-file-pdf"></i> Supervisor Eval Form
                                            </button>

                                            <?php
                                            $sup = $supAssignments[$s['id']] ?? null;
                                            $hasSup = !empty($sup);
                                            ?>
                                            <button type="button" onclick="openAssignSupModal(
                                                    <?= $s['id'] ?>,
                                                    '<?= htmlspecialchars(addslashes($s['full_name'])) ?>',
                                                    '<?= htmlspecialchars(addslashes($sup['supervisor_name'] ?? '')) ?>',
                                                    '<?= htmlspecialchars(addslashes($sup['supervisor_email'] ?? '')) ?>',
                                                    '<?= htmlspecialchars(addslashes($sup['department_note'] ?? '')) ?>'
                                                )" style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;
                                                    background:<?= $hasSup ? '#d1fae5' : '#fef3c7' ?>;
                                                    color:<?= $hasSup ? '#065f46' : '#92400e' ?>;
                                                    border-radius:6px;font-size:11px;font-weight:600;
                                                    border:1px solid <?= $hasSup ? '#6ee7b7' : '#fde68a' ?>;
                                                    cursor:pointer;white-space:nowrap;">
                                                <i class="fa <?= $hasSup ? 'fa-user-check' : 'fa-user-plus' ?>"></i>
                                                <?= $hasSup ? 'Supervisor Set' : 'Assign Supervisor' ?>
                                            </button>

                                            <?php if ($hasSup): ?>
                                                <span
                                                    style="font-size:10px;color:#6b7280;padding:2px 6px;background:#f9fafb;
                                                            border:1px solid #e5e7eb;border-radius:4px;white-space:nowrap;
                                                            overflow:hidden;text-overflow:ellipsis;max-width:160px;display:inline-block;"
                                                    title="<?= htmlspecialchars($sup['supervisor_email']) ?>">
                                                    <?= htmlspecialchars($sup['supervisor_email']) ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php
                                            $supEvalStmt = $pdo->prepare("SELECT id FROM ojt_evaluations_supervisor WHERE student_id = ?");
                                            $supEvalStmt->execute([$s['id']]);
                                            $hasSupEval = (bool) $supEvalStmt->fetchColumn();
                                            ?>

                                            <?php if ($hasSupEval): ?>
                                                <a href="ojt-evaluation-download.php?student_id=<?= $s['id'] ?>" target="_blank"
                                                    style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;
                                                            background:#dbeafe;color:#1e40af;border-radius:6px;font-size:11px;
                                                            font-weight:600;border:1px solid #93c5fd;cursor:pointer;text-decoration:none;">
                                                    <i class="fa fa-file-pdf"></i> Supervisor Eval
                                                </a>

                                            <?php elseif ($hasSup): ?>
                                                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                                    <button type="button"
                                                        onclick="sendEvalEmail(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($sup['supervisor_name'])) ?>', '<?= htmlspecialchars(addslashes($sup['supervisor_email'])) ?>')"
                                                        id="send-email-btn-<?= $s['id'] ?>"
                                                        style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;
                                                            background:#ede9fe;color:#5b21b6;border-radius:6px;font-size:11px;
                                                            font-weight:600;border:1px solid #c4b5fd;cursor:pointer;white-space:nowrap;">
                                                        <i class="fa fa-envelope"></i>
                                                        <?= $sup['eval_sent_at'] ? 'Resend Email' : 'Send Eval Email' ?>
                                                    </button>
                                                    <button type="button" onclick="openSupEvalModal(<?= $s['id'] ?>)"
                                                        style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;
                                                                background:#dbeafe;color:#1e40af;border-radius:6px;font-size:11px;
                                                                font-weight:600;border:1px solid #93c5fd;cursor:pointer;white-space:nowrap;">
                                                        <i class="fa fa-file-pen"></i> Fill Manually
                                                    </button>
                                                </div>
                                                <?php if ($sup['eval_sent_at']): ?>
                                                    <span style="font-size:10px;color:#6b7280;">
                                                        <i class="fa fa-clock" style="margin-right:2px;"></i>
                                                        Sent <?= date('M d, g:i A', strtotime($sup['eval_sent_at'])) ?>
                                                    </span>
                                                <?php endif; ?>

                                            <?php else: ?>
                                                <span
                                                    style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;
                                                            background:#f3f4f6;color:#9ca3af;border-radius:6px;font-size:11px;
                                                            font-weight:600;white-space:nowrap;border:1px solid #e5e7eb;"
                                                    title="Assign a supervisor first">
                                                    <i class="fa fa-file-pen"></i> Supervisor Eval
                                                </span>
                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>


        <!-- WEEKLY REPORTS SECTION -->
        <div id="weekly_reports" class="section-panel">
            <h4><strong>Weekly Progress Reports</strong></h4><br>

            <div style="display:flex; gap:10px; margin-bottom:16px; align-items:center;">
                <div style="position:relative; flex:1; min-width:200px;">
                    <i class="fa fa-search"
                        style="position:absolute; color: #f97316; left:10px; top:50%; transform:translateY(-50%); font-size:13px;"></i>
                    <input type="text" id="reportsSearchInput" placeholder="Search student..."
                        oninput="filterReportsTable()" style="width:100%; padding:8px 12px 8px 32px; border:1.5px solid #aeaeae; border-radius:22px;
                            font-size:13px; font-family:inherit; outline:none; transition:border-color .2s;"
                        onfocus="this.style.borderColor='#f97316'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
                <select id="reportsRoomFilter" onchange="filterReportsTable()"
                    style="padding:7px 14px; border:1px solid #bbb; border-radius:24px; font-size:12px;">
                    <option value="">All Rooms</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= htmlspecialchars($r['room_name']) ?>">
                            <?= htmlspecialchars($r['room_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ojt-table-wrapper">
                <table class="ojt-status-table">
                    <thead style="background:#f8f9fa;">
                        <tr>
                            <th>Student</th>
                            <th>Room</th>
                            <th>Week #</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="reports-tbody">
                        <?php
                        $reports = [
                            ['student_name' => 'Jake Cob', 'room_name' => 'Norma Castillo\'s Room', 'week_start' => '2026-08-04', 'submitted_at' => '2026-08-10'],

                        ];
                        ?>
                        <?php if (empty($reports)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    No weekly reports submitted yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reports as $r):
                                $avatarColors = ['#ff2c8f', '#2c6fff', '#1abc9c', '#9b59b6', '#e67e22'];
                                $avatarColor = $avatarColors[crc32($r['student_name']) % count($avatarColors)];
                                ?>
                                <tr data-room="">
                                    <td>
                                        <div class="student-cell">
                                            <div class="avatar" style="background:<?= $avatarColor ?>;">
                                                <strong><?= strtoupper(substr($r['student_name'], 0, 1)) ?></strong>
                                            </div>
                                            <span><?= htmlspecialchars($r['student_name']) ?></span>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($r['room_name']) ?></td>
                                    <td><?= date('M d, Y', strtotime($r['week_start'])) ?></td>
                                    <td><?= date('M d, Y', strtotime($r['submitted_at'])) ?></td>
                                    <td>
                                        <a href="#" style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;
                                    background:#dbeafe;color:#1e40af;border-radius:6px;font-size:11px;
                                    font-weight:600;border:1px solid #93c5fd;text-decoration:none;white-space:nowrap;">
                                            <i class="fa fa-download"></i> Download
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="dtr_summary"
            class="section-panel section sysAdm-section <?= $section === 'dtr_summary' ? 'active' : '' ?>">
            <!-- <div class="page-section">
                <h2>Student DTR Summary</h2>
                <p>Overview of rendered OJT hours per student</p>
            </div> -->

            <div class="sysAdm-header--danger sysAdm-header--blue mb-4">
                <div class="sysAdm-header-left">
                    <div class="sysAdm-header-icon">
                        <i class="bi bi-pencil-fill"></i>
                    </div>
                    <div class="sysAdm-header-text">
                        <h2>Student DTR Summary</h2>
                        <p>Overview of rendered OJT hours per student</p>
                    </div>
                </div>
            </div>

            <div
                style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                <div style="position:relative; flex:1; min-width:200px;">
                    <i class="fa fa-search"
                        style="position:absolute; color: #f97316; left:10px; top:50%; transform:translateY(-50%); font-size:13px;"></i>
                    <input type="text" id="searchDtr" placeholder="Search student or company..." oninput="filterDtr()"
                        style="width:25%; padding:8px 12px 8px 32px; border:1.5px solid #aeaeae; border-radius:22px;
                            font-size:13px; font-family:inherit; outline:none; transition:border-color .2s;"
                        onfocus="this.style.borderColor='#f97316'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
            </div>

            <?php if (empty($adviserDtrRows)): ?>
                <div class="text-center mt-5 py-5">
                    <i class="fa fa-inbox fa-3x text-muted mb-3 d-block"></i>
                    <h5 class="fw-bold text-muted">No Students Yet</h5>
                    <p class="text-muted" style="font-size:14px;">
                        You don't have any students under your supervision yet.
                    </p>
                </div>
            <?php else: ?>
                <div class="sysAdm-table-wrapper">
                    <table class="sysAdm-table" id="dtr-summary-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Company</th>
                                <th>Required Hours</th>
                                <th>Completed</th>
                                <th>Remaining</th>
                                <th>Progress</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="dtr-student">
                            <?php foreach ($adviserDtrRows as $row): ?>
                                <tr>
                                    <td>
                                        <div class="student-cell">
                                            <span class="avatar" style="background:#ff6b2c;">
                                                <?= strtoupper(substr(trim($row['full_name']), 0, 1)) ?>
                                            </span>
                                            <?= htmlspecialchars($row['full_name']) ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($row['company']) ?></td>
                                    <td><?= number_format($row['required_hours'], 1) ?> hrs</td>
                                    <td><?= number_format($row['total_hours'], 1) ?> hrs</td>
                                    <td><?= number_format($row['remaining'], 1) ?> hrs</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress-bar-bg">
                                                <div class="progress-bar-fill" style="width:<?= $row['percent'] ?>%;"></div>
                                            </div>
                                            <span style="font-size:12px; color:#888;"><?= $row['percent'] ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <!-- <button class="btn-delete" onclick="viewStudentDtr(<?= $row['student_id'] ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button> -->

                                        <button class="btn-update" onclick="viewStudentDtr(<?= $row['student_id'] ?>)"
                                            target="_blank" target="_blank" class="btn-update" tooltip="View DTR"
                                            title="View DTR"
                                            style="text-decoration: none; background: #FFE7B3;
                                            color: #7a5200; border:2px solid #7a5200; background-color: #FFE7B3; transition: background-color 0.2s ease;"
                                            onmouseover="this.style.backgroundColor='#dbbe83';"
                                            onmouseout="this.style.backgroundColor='#FFE7B3';"><i class="bi bi-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="chats" class="section-panel <?= $section === 'chats' ? 'active' : '' ?>">
            <?php
            $chatLookup = [];
            foreach ($adviserChats as $ac) {
                $key = $ac['chat_user_id'] . '-' . $ac['chat_user_type'];
                $chatLookup[$key] = $ac;
            }
            $chatStudents = array_filter($chattableUsers, fn($u) => $u['user_type'] === 'student');
            $chatOjtAdvisers = array_filter($chattableUsers, fn($u) => $u['user_type'] === 'adviser');
            $avatarColors = ['#ff2c8f', '#2c6fff', '#1abc9c', '#9b59b6', '#e67e22', '#e74c3c', '#16a085'];
            ?>

            <div class="room-chat-wrap">
                <div class="room-chat-list">
                    <div class="room-chat-list-header">
                        <h5>Chats</h5>
                    </div>
                    <div class="room-chat-search">
                        <input type="text" placeholder="Search..." id="roomChatSearch" oninput="filterRoomChats()">
                    </div>
                    <div class="room-chat-entries" id="roomChatEntries">

                        <div class="room-chat-divider">My Students</div>
                        <?php foreach ($chatStudents as $u):
                            $key = $u['user_id'] . '-student';
                            $preview = $chatLookup[$key]['last_message'] ?? 'No messages yet';
                            $avatarColor = $avatarColors[crc32($u['full_name']) % count($avatarColors)];
                            $isActive = ($chatSection_id == $u['user_id'] && $chatSection_type === 'student');
                            ?>
                            <a href="?room_id=<?= $current_room_id ?>&section=chats&chat_id=<?= $u['user_id'] ?>&chat_type=student"
                                class="room-chat-entry <?= $isActive ? 'active' : '' ?>"
                                data-name="<?= strtolower(htmlspecialchars($u['full_name'])) ?>">
                                <div class="room-chat-avatar" style="background:<?= $avatarColor ?>;">
                                    <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                </div>
                                <div class="room-chat-entry-meta">
                                    <div class="room-chat-entry-name">
                                        <?= htmlspecialchars($u['full_name']) ?>
                                    </div>
                                    <div class="room-chat-entry-preview">
                                        <?= htmlspecialchars(substr($preview, 0, 40)) ?>
                                    </div>
                                </div>
                                <span class="room-chat-badge" style="background:#dbeafe; color:#1e40af;">Student</span>
                            </a>
                        <?php endforeach; ?>

                        <?php if (!empty($chatOjtAdvisers)): ?>
                            <div class="room-chat-divider">OJT Advisers</div>
                            <?php foreach ($chatOjtAdvisers as $u):
                                $key = $u['user_id'] . '-adviser';
                                $preview = $chatLookup[$key]['last_message'] ?? 'No messages yet';
                                $avatarColor = $avatarColors[crc32($u['full_name']) % count($avatarColors)];
                                $isActive = ($chatSection_id == $u['user_id'] && $chatSection_type === 'adviser');
                                ?>
                                <a href="?room_id=<?= $current_room_id ?>&section=chats&chat_id=<?= $u['user_id'] ?>&chat_type=adviser"
                                    class="room-chat-entry <?= $isActive ? 'active' : '' ?>"
                                    data-name="<?= strtolower(htmlspecialchars($u['full_name'])) ?>">
                                    <div class="room-chat-avatar" style="background:<?= $avatarColor ?>;">
                                        <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                    </div>
                                    <div class="room-chat-entry-meta">
                                        <div class="room-chat-entry-name">
                                            <?= htmlspecialchars($u['full_name']) ?>
                                        </div>
                                        <div class="room-chat-entry-preview">
                                            <?= htmlspecialchars(substr($preview, 0, 40)) ?>
                                        </div>
                                    </div>
                                    <span class="room-chat-badge" style="background:#d1fae5; color:#065f46;">OJT</span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div>
                </div>

                <div class="room-message-panel">
                    <?php if ($chatSection_id): ?>
                        <?php
                        $openName = getRoomChatName($pdo, $chatSection_id, $chatSection_type);
                        $openColor = $avatarColors[crc32($openName) % count($avatarColors)];
                        $isHte = ($chatSection_type === 'adviser');
                        ?>
                        <div class="room-message-header">
                            <div class="room-chat-avatar"
                                style="background:<?= $openColor ?>; width:36px; height:36px; font-size:14px;">
                                <?= strtoupper(substr($openName, 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-weight:700; font-size:14px;">
                                    <?= htmlspecialchars($openName) ?>
                                </div>
                                <div style="font-size:11px; color:#aaa;">
                                    <?= $isHte ? 'HTE Supervisor' : 'Student' ?>
                                </div>
                            </div>
                        </div>

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
                                                <div class="room-msg-sender">
                                                    <?= htmlspecialchars($msg['sender_name'] ?? '') ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="room-msg-bubble">
                                                <?= htmlspecialchars($msg['message']) ?>
                                            </div>
                                            <div class="room-msg-time">
                                                <?= date('M d, g:i A', strtotime($msg['created_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="room-message-input-bar">
                            <form method="POST" action="message-db.php" id="roomChatForm">
                                <input type="hidden" name="receiver_id" value="<?= $chatSection_id ?>">
                                <input type="hidden" name="receiver_type" value="<?= $chatSection_type ?>">
                                <input type="hidden" name="redirect"
                                    value="?room_id=<?= $current_room_id ?>&section=chats&chat_id=<?= $chatSection_id ?>&chat_type=<?= $chatSection_type ?>">
                                <div class="room-message-input-row">
                                    <textarea name="message" id="roomChatInput" rows="1" placeholder="Type a message…"
                                        required onkeydown="roomChatEnterSend(event)"></textarea>
                                    <button type="submit" class="room-chat-send-btn">
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
        </div>
    </div><!-- /.main -->

    <!-- Supervisor Evaluation Modal -->
    <div class="modal fade" id="supEvalModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                <!-- Header -->
                <div class="modal-header"
                    style="background:linear-gradient(135deg,#065f46,#047857); color:#fff; padding:20px 28px;">
                    <div>
                        <div
                            style="font-size:11px; letter-spacing:.12em; opacity:.7; text-transform:uppercase; margin-bottom:4px;">
                            CEIT-OJTF-010 · Pamantasan ng Lungsod ng Valenzuela
                        </div>
                        <h5 class="modal-title fw-bold mb-0" style="font-size:1.2rem;">
                            Supervisor's Evaluation of Student Intern
                        </h5>
                        <div style="font-size:13px; opacity:.8; margin-top:4px;">
                            Please evaluate the student intern on all applicable criteria below.
                        </div>
                    </div>
                </div>

                <div class="modal-body" style="padding:28px 32px; background:#f8f9fb;">
                    <form id="supEvalForm">

                        <!-- Student info strip -->
                        <div style="background:#fff; border-radius:10px; padding:16px 20px; margin-bottom:20px;
                         border:1px solid #e2e8f0; display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                            <div>
                                <label
                                    style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;">Name
                                    of Intern</label>
                                <input type="text" name="intern_name" class="form-control form-control-sm mt-1"
                                    placeholder="Full name of intern" required>
                            </div>
                            <div>
                                <label style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;">
                                    Course / Student No.</label>
                                <input type="text" name="student_no" class="form-control form-control-sm mt-1"
                                    placeholder="e.g. BSIT / 2021-00001">
                            </div>
                            <div>
                                <label
                                    style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;">Name
                                    of Company</label>
                                <input type="text" name="company_name" class="form-control form-control-sm mt-1"
                                    placeholder="Company / organization name" required>
                            </div>
                            <div>
                                <label
                                    style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;">Site
                                    Internship Supervisor</label>
                                <input type="text" name="supervisor_name" class="form-control form-control-sm mt-1"
                                    placeholder="Supervisor's full name" required>
                            </div>
                        </div>

                        <!-- Rating legend -->
                        <div
                            style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 18px;margin-bottom:20px;font-size:13px;">
                            <strong>Rating Scale:</strong>
                            <span style="margin-left:12px;">1 – Unsatisfactory</span>
                            <span style="margin-left:12px;">2 – Fair</span>
                            <span style="margin-left:12px;">3 – Commendable</span>
                            <span style="margin-left:12px;">4 – Exceptional</span>
                        </div>

                        <!-- ── PART I · About the Intern ── -->
                        <div
                            style="font-size:12px;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;margin-bottom:10px;">
                            Part I · About the Intern
                        </div>

                        <?php
                        $supSections = [
                            'A. Learning Skills' => [
                                'ls_questions' => 'Asks relevant and purposeful questions.',
                                'ls_resources' => 'Searches for and employs suitable resources.',
                                'ls_accountability' => 'Takes accountability for errors and gains knowledge from experiences.',
                            ],
                            'B. Reading, Writing, and Computational Skills' => [
                                'rw_written' => 'Demonstrates the ability to understand and follow written materials.',
                                'rw_express' => 'Expresses ideas and concepts effectively through written communication.',
                                'rw_math' => 'Applies mathematical procedures, engineering theories, and relevant concepts to the job at hand.',
                            ],
                            'C. Listening and Verbal Communications Skills' => [
                                'lv_listens' => 'Actively listens to others with attentiveness.',
                                'lv_meetings' => 'Effectively engages in meetings or group discussions.',
                                'lv_verbal' => 'Demonstrates proficiency in verbal communication.',
                            ],
                            'D. Creative Thinking and Problem-Solving Skills' => [
                                'ps_divides' => 'Divides complex tasks and problems into manageable components.',
                                'ps_brainstorm' => 'Generates and formulates ideas and options through brainstorming.',
                                'ps_solve' => 'Demonstrates the ability to solve encountered problems.',
                            ],
                            'E. Professional and Career Development Skills' => [
                                'pd_proactive' => 'Shows a proactive attitude towards work.',
                                'pd_priorities' => 'Displays proficiency in setting realistic priorities and objectives.',
                                'pd_demeanor' => 'Demonstrates professional demeanor and conduct.',
                            ],
                            'F. Interpersonal and Teamwork Skills' => [
                                'it_conflicts' => 'Resolves conflicts efficiently and effectively.',
                                'it_team' => 'Contributes to a collaborative team environment.',
                                'it_assertive' => 'Demonstrates assertiveness with appropriate behavior.',
                            ],
                            'G. Organizational Effectiveness Skills' => [
                                'oe_endorse' => "Shows a willingness to comprehend and endorse the organization's objectives and aims.",
                                'oe_adapts' => 'Adapts to the established standards and anticipations of the organization.',
                                'oe_channels' => 'Operates within the appropriate channels for decision-making and authority.',
                            ],
                            'H. Basic Work Habits and Skills' => [
                                'wh_punctual' => 'Arrives at work punctually and as per the schedule.',
                                'wh_attitude' => 'Demonstrate a positive and constructive attitude.',
                                'wh_dress' => "Adheres to the organization's policies and rules regarding dress code and appearance.",
                            ],
                            'I. Character Attributes' => [
                                'ca_ethics' => 'Demonstrates a commitment to ethical values and integrity in their work.',
                                'ca_principled' => 'Conducts themselves in an ethical and principled manner.',
                                'ca_diversity' => 'Respects and values the diverse religious, cultural, and ethnic backgrounds of their colleagues.',
                            ],
                            'J. Industry-Specific Skills' => [
                                'is_proficiency' => 'Demonstrated proficiency in industry-specific skills required for their role.',
                                'is_willingness' => 'Showed a willingness to learn and improve industry-specific skills.',
                                'is_additional' => 'Based on the profession represented by your company, are there any additional skills or competencies that you feel are important for the intern to possess? If yes, please rate the intern\'s performance in these skills.',
                            ],
                        ];

                        foreach ($supSections as $sectionTitle => $fields):
                            ?>
                            <div
                                style="background:#fff;border-radius:10px;padding:18px 22px;margin-bottom:14px;border:1px solid #e2e8f0;">
                                <div style="font-weight:700;color:#065f46;margin-bottom:12px;font-size:14px;">
                                    <?= htmlspecialchars($sectionTitle) ?>
                                </div>
                                <?php $i = 1;
                                foreach ($fields as $name => $label): ?>
                                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
                                     padding:8px 0;<?= $i > 1 ? 'border-top:1px solid #f1f5f9;' : '' ?>">
                                        <span style="font-size:13px;color:#374151;flex:1;">
                                            <?= $i ?>. <?= htmlspecialchars($label) ?>
                                        </span>
                                        <div class="d-flex gap-2 flex-shrink-0">
                                            <?php for ($r = 1; $r <= 4; $r++): ?>
                                                <label
                                                    style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;">
                                                    <input type="radio" name="<?= $name ?>" value="<?= $r ?>"
                                                        style="accent-color:#065f46;width:16px;height:16px;" required>
                                                    <span style="font-size:10px;color:#94a3b8;"><?= $r ?></span>
                                                </label>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <?php $i++; endforeach; ?>
                            </div>
                        <?php endforeach; ?>

                        <!-- ── K. Comments ── -->
                        <div
                            style="font-size:12px;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;margin:20px 0 10px;">
                            K. Comments
                        </div>
                        <div
                            style="background:#fff;border-radius:10px;padding:18px 22px;margin-bottom:14px;border:1px solid #e2e8f0;display:flex;flex-direction:column;gap:14px;">
                            <?php
                            $comments = [
                                'impact' => '1. Please explain how the intern\'s performance has impacted the organization.',
                                'strengths' => '2. What do you think are the intern\'s key skills or strengths?',
                                'improvements' => '3. Can you identify any areas where the intern could make improvements?',
                            ];
                            foreach ($comments as $name => $label):
                                ?>
                                <div>
                                    <label
                                        style="font-size:13px;color:#374151;font-weight:500;margin-bottom:6px;display:block;">
                                        <?= htmlspecialchars($label) ?>
                                    </label>
                                    <textarea name="<?= $name ?>" rows="3" class="form-control"
                                        style="font-size:13px;border-radius:8px;" placeholder="Write your answer here…"
                                        required></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- ── L. Overall Performance ── -->
                        <div
                            style="font-size:12px;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;margin:20px 0 10px;">
                            L. Overall Performance
                        </div>
                        <div
                            style="background:#fff;border-radius:10px;padding:18px 22px;margin-bottom:14px;border:1px solid #e2e8f0;">
                            <p style="font-size:13px;color:#374151;margin-bottom:14px;">
                                If I were to rate the intern at the present time:
                            </p>

                            <!-- Labels row -->
                            <div
                                style="display:flex;justify-content:space-between;font-size:11px;color:#64748b;margin-bottom:4px;padding:0 2px;">
                                <span>Unsatisfactory</span>
                                <span>Poor</span>
                                <span>Average</span>
                                <span>Good</span>
                                <span>Outstanding</span>
                            </div>

                            <!-- 0–10 radio scale -->
                            <div style="display:flex;justify-content:space-between;gap:4px;" id="overallScaleIntern">
                                <?php for ($v = 0; $v <= 10; $v++): ?>
                                    <label
                                        style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:3px;flex:1;">
                                        <input type="radio" name="overall_intern" value="<?= $v ?>" required
                                            style="accent-color:#065f46;width:16px;height:16px;">
                                        <span style="font-size:11px;color:#94a3b8;"><?= $v ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <!-- ── PART II · Internship Experience ── -->
                        <div
                            style="font-size:12px;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;margin:20px 0 10px;">
                            Part II · Internship Experience
                        </div>
                        <div
                            style="background:#fff;border-radius:10px;padding:18px 22px;margin-bottom:14px;border:1px solid #e2e8f0;display:flex;flex-direction:column;gap:14px;">

                            <!-- A: Suggestions -->
                            <div>
                                <label
                                    style="font-size:13px;color:#374151;font-weight:500;margin-bottom:6px;display:block;">
                                    A. What are your suggestions for improving the internship program of PLV College of
                                    Engineering and Information Technology?
                                </label>
                                <textarea name="suggestions" rows="3" class="form-control"
                                    style="font-size:13px;border-radius:8px;" placeholder="Write your suggestions here…"
                                    required></textarea>
                            </div>

                            <!-- B: Future supervision -->
                            <div style="border-top:1px solid #f1f5f9;padding-top:14px;">
                                <label
                                    style="font-size:13px;color:#374151;font-weight:500;margin-bottom:8px;display:block;">
                                    B. Based on this experience, would you consider supervising other students from PLV
                                    College of Engineering and Information Technology in the future? Why, or why not?
                                </label>
                                <div class="d-flex align-items-center gap-4 flex-wrap mb-2">
                                    <label class="d-flex align-items-center gap-2"
                                        style="cursor:pointer;font-size:13px;">
                                        <input type="radio" name="supervise_future" value="yes"
                                            style="accent-color:#065f46;" required> Yes
                                    </label>
                                    <label class="d-flex align-items-center gap-2"
                                        style="cursor:pointer;font-size:13px;">
                                        <input type="radio" name="supervise_future" value="no"
                                            style="accent-color:#065f46;" required> No
                                    </label>
                                </div>
                                <textarea name="supervise_future_reason" rows="2" class="form-control"
                                    style="font-size:13px;border-radius:8px;" placeholder="Please explain your answer…"
                                    required></textarea>
                            </div>

                            <!-- C: Overall experience scale 0–10 -->
                            <div style="border-top:1px solid #f1f5f9;padding-top:14px;">
                                <p style="font-size:13px;color:#374151;font-weight:500;margin-bottom:14px;">
                                    C. Overall, how do you rate your experience with this internship?
                                </p>
                                <div
                                    style="display:flex;justify-content:space-between;font-size:11px;color:#64748b;margin-bottom:4px;padding:0 2px;">
                                    <span>Unsatisfactory</span>
                                    <span>Poor</span>
                                    <span>Average</span>
                                    <span>Good</span>
                                    <span>Outstanding</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;gap:4px;">
                                    <?php for ($v = 0; $v <= 10; $v++): ?>
                                        <label
                                            style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:3px;flex:1;">
                                            <input type="radio" name="overall_experience" value="<?= $v ?>" required
                                                style="accent-color:#065f46;width:16px;height:16px;">
                                            <span style="font-size:11px;color:#94a3b8;"><?= $v ?></span>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                            </div>

                        </div>

                        <!-- Signature / title strip -->
                        <div style="background:#fff;border-radius:10px;padding:16px 20px;margin-bottom:14px;
                         border:1px solid #e2e8f0;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div>
                                <label
                                    style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;">Title
                                    / Position</label>
                                <input type="text" name="title_position" class="form-control form-control-sm mt-1"
                                    placeholder="e.g. IT Manager" required>
                            </div>
                            <div>
                                <label
                                    style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;">Contact
                                    Details</label>
                                <input type="text" name="contact_details" class="form-control form-control-sm mt-1"
                                    placeholder="Email / phone" required>
                            </div>
                            <div>
                                <label
                                    style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;">Date</label>
                                <input type="date" name="eval_date" class="form-control form-control-sm mt-1"
                                    value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>

                        <!-- Notice -->
                        <div
                            style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 18px;font-size:12px;color:#166534;margin-bottom:8px;">
                            <strong>Note:</strong> Place this document in a long brown envelope, signed and sealed by
                            the OJT Coordinator across the flap.
                            Return to: PLV College of Engineering and Information Technology, 2F CEIT Building, Tongco
                            St., Maysan Valenzuela.
                            <strong>This document must not be viewed, seen, or read by the Student Interns after filling
                                out.</strong>
                        </div>

                        <!-- Error msg -->
                        <div id="sup-eval-error-msg"
                            style="display:none;color:#dc2626;font-size:13px;margin-bottom:10px;"></div>

                    </form>
                </div><!-- /modal-body -->

                <div class="modal-footer" style="background:#f8f9fb;padding:16px 28px;gap:10px;">
                    <span style="font-size:12px;color:#94a3b8;flex:1;">
                        Responses will be kept within the department for record purposes only.
                    </span>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="supEvalPreviewBtn">
                        <i class="fa-regular fa-eye me-1"></i> Preview PDF
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="supEvalLaterBtn">
                        Later
                    </button>
                    <button type="button" class="btn btn-sm fw-semibold px-4" id="supEvalSubmitBtn"
                        style="background:#065f46;color:#fff;border-radius:8px;">
                        <i class="fa-solid fa-paper-plane me-1"></i> Submit Evaluation
                    </button>
                </div>

            </div>
        </div>
    </div><!-- /#supEvalModal -->
    <!-- JOIN ROOM MODAL -->
    <div class="modal fade" id="joinRoomModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content rounded-4">
                <div class="modal-header">
                    <h5 class="modal-title"><strong>Join a Room</strong></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="join-room.php">
                        <div class="mb-3">
                            <label class="form-label">Room Code</label>
                            <input type="text" name="room_code" class="form-control" placeholder="Enter room code"
                                required>
                        </div>
                        <div class="text-end">
                            <button type="submit" class="btn btn-warning px-4">Join</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Supervisor Assign Modal -->

    <div class="modal fade" id="assignSupModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
            <div class="modal-content" style="border-radius:16px;overflow:hidden;">

                <div class="modal-header"
                    style="background:linear-gradient(135deg,#1e3a5f,#2563eb);color:#fff;padding:18px 24px;">
                    <div>
                        <div
                            style="font-size:10px;letter-spacing:.12em;opacity:.7;text-transform:uppercase;margin-bottom:3px;">
                            HTE Supervisor Assignment
                        </div>
                        <h5 class="modal-title fw-bold mb-0" style="font-size:1.05rem;">
                            Assign Supervisor for <span id="assignSup_studentName"></span>
                        </h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body" style="padding:24px 28px;background:#f8f9fb;">
                    <form id="assignSupForm">
                        <input type="hidden" name="student_id" id="assignSup_studentId">

                        <div class="mb-3">
                            <label style="font-size:11px;color:#64748b;font-weight:700;
                                      text-transform:uppercase;letter-spacing:.05em;">
                                Supervisor Full Name <span style="color:#dc2626;">*</span>
                            </label>
                            <input type="text" name="supervisor_name" id="assignSup_name" class="form-control mt-1"
                                placeholder="e.g. Juan dela Cruz" required>
                        </div>

                        <div class="mb-3">
                            <label style="font-size:11px;color:#64748b;font-weight:700;
                                      text-transform:uppercase;letter-spacing:.05em;">
                                Supervisor Email <span style="color:#dc2626;">*</span>
                            </label>
                            <input type="email" name="supervisor_email" id="assignSup_email" class="form-control mt-1"
                                placeholder="supervisor@company.com" required>
                            <div style="font-size:11px;color:#94a3b8;margin-top:4px;">
                                The evaluation form link will be sent to this address.
                            </div>
                        </div>

                        <div class="mb-1">
                            <label style="font-size:11px;color:#64748b;font-weight:700;
                                      text-transform:uppercase;letter-spacing:.05em;">
                                Department / Note <span style="color:#94a3b8;font-weight:400;">
                                    (optional)</span>
                            </label>
                            <input type="text" name="department_note" id="assignSup_dept" class="form-control mt-1"
                                placeholder="e.g. Finance Dept, Design Team">
                        </div>

                        <div id="assignSup_error" style="display:none;color:#dc2626;font-size:12px;
                        margin-top:10px;">
                        </div>
                    </form>
                </div>

                <div class="modal-footer" style="background:#f8f9fb;padding:14px 24px;gap:8px;">
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm fw-semibold px-4" id="assignSupSaveBtn"
                        style="background:#2563eb;color:#fff;border-radius:8px;">
                        <i class="fa fa-save me-1"></i> Save Supervisor
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- Student dtr summary -->
    <div id="dtr-view-modal" class="modal" tabindex="-1" style="display:none;">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dtr-view-modal-title">Student DTR</h5>
                    <button type="button" class="btn-close" onclick="closeDtrModal()"></button>
                </div>
                <div class="modal-body" id="dtr-view-modal-body">
                    <div class="text-center py-5"><i class="fa fa-spinner fa-spin fa-2x text-muted"></i></div>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openDtrModal() {
            document.getElementById('dtr-view-modal').style.display = 'block';
            document.getElementById('dtr-view-modal').classList.add('show');
        }

        function closeDtrModal() {
            document.getElementById('dtr-view-modal').style.display = 'none';
            document.getElementById('dtr-view-modal').classList.remove('show');
        }

        async function viewStudentDtr(studentId) {
            openDtrModal();

            const body = document.getElementById('dtr-view-modal-body');

            body.innerHTML = `
        <div class="text-center py-5">
            <i class="fa fa-spinner fa-spin fa-2x text-muted"></i>
        </div>
    `;

            try {
                console.log('Student ID:', studentId);

                const res = await fetch(
                    `/get-student-dtr.php?student_id=${encodeURIComponent(studentId)}`,
                    { credentials: 'same-origin' }
                );

                console.log('HTTP Status:', res.status);
                console.log('Response OK:', res.ok);

                const text = await res.text();

                console.log('RAW RESPONSE:', text);

                const data = JSON.parse(text);

                console.log('Parsed JSON:', data);

                if (!data.success) {
                    body.innerHTML = `<p class="text-danger">${data.message}</p>`;
                    return;
                }

                document.getElementById('dtr-view-modal-title').textContent =
                    `${data.student.full_name} — ${data.student.company}`;

                if (data.weeks.length === 0) {
                    body.innerHTML =
                        '<p class="text-muted">No DTR entries logged yet.</p>';
                    return;
                }

                body.innerHTML =
                    data.weeks.map(week => renderDtrWeekReadOnly(week, data.student)).join('');

            } catch (err) {
                console.error('DTR ERROR:', err);

                body.innerHTML = `
            <p class="text-danger">Failed to load DTR.</p>
            <p class="small text-muted">${err.message}</p>
        `;
            }
        }

        function renderDtrWeekReadOnly(week) {
            const rows = week.rows.length > 0 ? week.rows : Array.from({ length: 6 }, (_, i) => ({
                row_index: i, date: '', m_in: '', m_out: '', a_in: '', a_out: ''
            }));

            const rowsHtml = rows.map(row => {
                const mHrs = calcHrs(row.m_in, row.m_out);
                const aHrs = calcHrs(row.a_in, row.a_out);
                const daily = (mHrs + aHrs).toFixed(2);
                const dayName = row.date ? new Date(row.date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short' }) : '—';

                return `
            <tr>
                <td>${row.date || '—'}</td>
                <td style="text-align:center">${dayName}</td>
                <td class="td-morning">${row.m_in || '—'}</td>
                <td class="td-morning">${row.m_out || '—'}</td>
                <td class="td-morning">${mHrs ? mHrs.toFixed(2) : '—'}</td>
                <td class="td-afternoon">${row.a_in || '—'}</td>
                <td class="td-afternoon">${row.a_out || '—'}</td>
                <td class="td-afternoon">${aHrs ? aHrs.toFixed(2) : '—'}</td>
                <td>${daily}</td>
            </tr>`;
            }).join('');

            return `
        <div class="ojt-week-block mb-3">
            <div class="ojt-week-header">
                <strong>${week.week_label}</strong>
            </div>
            <div class="ojt-table-scroll">
                <table class="ojt-table">
                    <thead>
                        <tr>
                            <th rowspan="2" class="ojt-group">Date</th>
                            <th rowspan="2" class="ojt-group">Day</th>
                            <th colspan="3" style="background:#FFB62F;">Morning</th>
                            <th colspan="3" style="background:#FF673A;">Afternoon</th>
                            <th rowspan="2" class="ojt-group">Daily<br>Hours</th>
                        </tr>
                        <tr>
                            <th style="background:#f9c565;">In</th>
                            <th style="background:#f9c565;">Out</th>
                            <th style="background:#f9c565;">Hrs</th>
                            <th style="background:#f49679;">In</th>
                            <th style="background:#f49679;">Out</th>
                            <th style="background:#f49679;">Hrs</th>
                        </tr>
                    </thead>
                    <tbody>${rowsHtml}</tbody>
                </table>
            </div>
        </div>`;
        }

        function calcHrs(t1, t2) {
            if (!t1 || !t2) return 0;
            const [h1, m1] = t1.split(':').map(Number);
            const [h2, m2] = t2.split(':').map(Number);
            return Math.max(0, (h2 * 60 + m2 - (h1 * 60 + m1)) / 60);
        }

        function showSection(sectionId, e) {
            if (e) e.preventDefault();

            document.querySelectorAll('.section-panel').forEach(sec => sec.classList.remove('active'));
            document.getElementById(sectionId).classList.add('active');

            document.querySelectorAll('.sidebar a').forEach(link => link.classList.remove('active'));
            const navEl = document.getElementById('nav-' + sectionId);
            if (navEl) navEl.classList.add('active');

            document.querySelectorAll('.room-item.active-room').forEach(room => room.classList.remove('active-room'));
        }

        // function filterTable() {
        //     const search = document.getElementById('statusSearchInput').value.toLowerCase();
        //     const room = document.getElementById('statusRoomFilter').value.toLowerCase();

        //     document.querySelectorAll('#all-students-tbody tr').forEach(row => {
        //         const name = row.querySelector('.student-cell h6')?.textContent.toLowerCase() ?? '';
        //         const rowRoom = (row.dataset.room ?? '').toLowerCase();

        //         const matchSearch = name.includes(search);
        //         const matchRoom = room === '' || rowRoom === room;

        //         row.style.display = (matchSearch && matchRoom) ? '' : 'none';
        //     });
        // }

        function filterTable() {
            const search = document.getElementById('searchInput')?.value.toLowerCase() ?? '';
            document.querySelectorAll('#all-students-tbody tr').forEach(row => {
                const name = row.querySelector('.student-cell span')?.textContent.toLowerCase() ?? '';
                row.style.display = name.includes(search) ? '' : 'none';
            });
        }

        function filterDtr() {
            const search = document.getElementById('searchDtr')?.value.toLowerCase() ?? '';
            document.querySelectorAll('#dtr-student tr').forEach(row => {
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
        const ta = document.getElementById('roomChatInput');
        if (ta) {
            ta.addEventListener('input', () => {
                ta.style.height = 'auto';
                ta.style.height = Math.min(ta.scrollHeight, 100) + 'px';
            });
        }
        function filterCards() {
            const search = document.getElementById('remarksSearchInput').value.toLowerCase();
            const room = document.getElementById('remarksRoomFilter').value.toLowerCase();

            document.querySelectorAll('#student-list .student-card').forEach(card => {
                const name = (card.dataset.name ?? '').toLowerCase();
                const rowRoom = (card.dataset.room ?? '').toLowerCase();

                const matchSearch = name.includes(search);
                const matchRoom = room === '' || rowRoom === room;

                card.closest('form, div.student-card').style.display =
                    (matchSearch && matchRoom) ? '' : 'none';
            });
        }

        function openSupEvalModal(studentId) {
            fetch('get-student-eval.php?student_id=' + studentId)
                .then(res => res.json())
                .then(data => {
                    document.querySelector('[name="intern_name"]').value = data.intern_name || '';
                    document.querySelector('[name="student_no"]').value = data.student_id || '';
                    document.querySelector('[name="company_name"]').value = data.company_name || '';
                    document.querySelector('[name="supervisor_name"]').value = data.supervisor_name || '';

                    const modal = new bootstrap.Modal(document.getElementById('supEvalModal'), {
                        backdrop: 'static',
                        keyboard: false
                    });
                    modal.show();
                });
        }

        document.addEventListener('DOMContentLoaded', function () {

            /* Later button */
            const laterBtn = document.getElementById('supEvalLaterBtn');
            if (laterBtn) {
                laterBtn.addEventListener('click', function () {
                    bootstrap.Modal.getInstance(document.getElementById('supEvalModal'))?.hide();
                });
            }

            /* Submit button */
            const submitBtn = document.getElementById('supEvalSubmitBtn');
            if (submitBtn) {
                submitBtn.addEventListener('click', async function () {
                    const form = document.getElementById('supEvalForm');
                    const errorEl = document.getElementById('sup-eval-error-msg');
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
                    data.append('action', 'submit_supervisor_evaluation');

                    const studentId = document.getElementById('supEvalModal').dataset.studentId ?? '';
                    if (studentId) data.append('student_id', studentId);

                    try {
                        const res = await fetch('ojt-evaluation-submit.php', { method: 'POST', body: data });
                        const json = await res.json();

                        if (json.success) {
                            bootstrap.Modal.getInstance(document.getElementById('supEvalModal'))?.hide();

                            /* open download in new tab */
                            if (studentId) {
                                window.open('ojt-evaluation-download.php?student_id=' + studentId, '_blank');
                            }

                            /* success toast */
                            const toast = document.createElement('div');
                            toast.style.cssText =
                                'position:fixed;bottom:24px;right:24px;background:#065f46;color:#fff;' +
                                'padding:14px 22px;border-radius:10px;font-size:14px;font-weight:600;z-index:9999;' +
                                'box-shadow:0 4px 20px rgba(0,0,0,.2);';
                            toast.textContent = 'Supervisor evaluation submitted and PDF downloaded!';
                            document.body.appendChild(toast);
                            setTimeout(() => toast.remove(), 5000);

                            /* refresh page so the green download button appears */
                            setTimeout(() => location.reload(), 1200);

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
        });
        function openAssignSupModal(studentId, studentName, supName, supEmail, supDept) {
            document.getElementById('assignSup_studentId').value = studentId;
            document.getElementById('assignSup_studentName').textContent = studentName;
            document.getElementById('assignSup_name').value = supName || '';
            document.getElementById('assignSup_email').value = supEmail || '';
            document.getElementById('assignSup_dept').value = supDept || '';
            document.getElementById('assignSup_error').style.display = 'none';
            new bootstrap.Modal(document.getElementById('assignSupModal')).show();
        }

        document.addEventListener('DOMContentLoaded', function () {

            /* Save supervisor */
            document.getElementById('assignSupSaveBtn')?.addEventListener('click', async function () {
                const form = document.getElementById('assignSupForm');
                const errEl = document.getElementById('assignSup_error');
                errEl.style.display = 'none';

                if (!form.checkValidity()) { form.reportValidity(); return; }

                this.disabled = true;
                this.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Saving…';

                try {
                    const res = await fetch('assign-supervisor.php', {
                        method: 'POST',
                        body: new FormData(form)
                    });
                    const json = await res.json();

                    if (json.success) {
                        bootstrap.Modal.getInstance(
                            document.getElementById('assignSupModal'))?.hide();
                        location.reload();
                    } else {
                        errEl.textContent = json.message || 'Save failed.';
                        errEl.style.display = 'block';
                        this.disabled = false;
                        this.innerHTML = '<i class="fa fa-save me-1"></i> Save Supervisor';
                    }
                } catch {
                    errEl.textContent = 'Network error. Please try again.';
                    errEl.style.display = 'block';
                    this.disabled = false;
                    this.innerHTML = '<i class="fa fa-save me-1"></i> Save Supervisor';
                }
            });

        });

        /* ── SEND EVAL EMAIL ── */
        async function sendEvalEmail(studentId, supName, supEmail) {
            const btn = document.getElementById('send-email-btn-' + studentId);
            if (!btn) return;

            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Sending…';

            try {
                const fd = new FormData();
                fd.append('student_id', studentId);
                fd.append('supervisor_name', supName);
                fd.append('supervisor_email', supEmail);

                const res = await fetch('send-eval-email.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (json.success) {
                    btn.style.background = '#d1fae5';
                    btn.style.color = '#065f46';
                    btn.style.borderColor = '#6ee7b7';
                    btn.innerHTML = '<i class="fa fa-check me-1"></i> Email Sent!';
                    setTimeout(() => location.reload(), 1800);
                } else {
                    alert('Failed to send: ' + (json.message || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = orig;
                }
            } catch {
                alert('Network error. Please try again.');
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }
        document.addEventListener('DOMContentLoaded', function () {
            const supPreviewBtn = document.getElementById('supEvalPreviewBtn');
            if (supPreviewBtn) {
                supPreviewBtn.addEventListener('click', async function () {
                    const form = document.getElementById('supEvalForm');
                    const errorEl = document.getElementById('sup-eval-error-msg');
                    errorEl.style.display = 'none';

                    if (!form.checkValidity()) {
                        form.reportValidity();
                        errorEl.textContent = 'Please complete all required fields before previewing.';
                        errorEl.style.display = 'block';
                        return;
                    }

                    const originalHtml = supPreviewBtn.innerHTML;
                    supPreviewBtn.disabled = true;
                    supPreviewBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Building preview…';

                    try {
                        const data = new FormData(form);
                        const res = await fetch('ojt-evaluation-preview-supervisor.php', { method: 'POST', body: data });
                        if (!res.ok) throw new Error('Preview failed');

                        const blob = await res.blob();
                        const url = URL.createObjectURL(blob);
                        window.open(url, '_blank');
                        setTimeout(() => URL.revokeObjectURL(url), 60000);
                    } catch (err) {
                        errorEl.textContent = 'Could not generate preview. Please try again.';
                        errorEl.style.display = 'block';
                    } finally {
                        supPreviewBtn.disabled = false;
                        supPreviewBtn.innerHTML = originalHtml;
                    }
                });
            }
        });
        document.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            const section = params.get('section');
            if (section) {
                showSection(section, null);
            }
        });

        function toMinutes(t) {
            if (!t) return null;
            const [h, m] = t.split(':').map(Number);
            return h * 60 + m;
        }

        function getDtrStatus(row, schedule) {
            const schedIn = toMinutes(schedule.ojt_time_in);
            const schedOut = toMinutes(schedule.ojt_time_out);

            const firstIn = toMinutes(row.m_in || row.a_in);
            const lastOut = toMinutes(row.a_out || row.m_out);

            // No entry at all
            if (!row.date && firstIn === null && lastOut === null) {
                return { label: '—', color: '#6b7280', bg: 'transparent' };
            }
            // Clocked in but never clocked out (or vice versa)
            if (firstIn === null || lastOut === null) {
                return { label: 'Incomplete', color: '#92400e', bg: '#fef3c7' };
            }
            // No schedule set on the internship
            if (schedIn === null || schedOut === null) {
                return { label: 'Present', color: '#065f46', bg: '#d1fae5' };
            }

            const late = firstIn > schedIn;
            const undertime = lastOut < schedOut;

            if (late && undertime) return { label: 'Late + Undertime', color: '#991b1b', bg: '#fee2e2' };
            if (late) return { label: 'Late', color: '#991b1b', bg: '#fee2e2' };
            if (undertime) return { label: 'Undertime', color: '#9a3412', bg: '#ffedd5' };
            return { label: 'On time', color: '#065f46', bg: '#d1fae5' };
        }

        function renderDtrWeekReadOnly(week, schedule) {
            const rows = week.rows.length > 0 ? week.rows : Array.from({ length: 6 }, (_, i) => ({
                row_index: i, date: '', m_in: '', m_out: '', a_in: '', a_out: ''
            }));

            const rowsHtml = rows.map(row => {
                const mHrs = calcHrs(row.m_in, row.m_out);
                const aHrs = calcHrs(row.a_in, row.a_out);
                const daily = (mHrs + aHrs).toFixed(2);
                const dayName = row.date
                    ? new Date(row.date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short' })
                    : '—';
                const st = getDtrStatus(row, schedule);

                return `
        <tr>
            <td>${row.date || '—'}</td>
            <td style="text-align:center">${dayName}</td>
            <td class="td-morning">${row.m_in || '—'}</td>
            <td class="td-morning">${row.m_out || '—'}</td>
            <td class="td-morning">${mHrs ? mHrs.toFixed(2) : '—'}</td>
            <td class="td-afternoon">${row.a_in || '—'}</td>
            <td class="td-afternoon">${row.a_out || '—'}</td>
            <td class="td-afternoon">${aHrs ? aHrs.toFixed(2) : '—'}</td>
            <td>${daily}</td>
            <td>
                <span class="ojt-status-badge"
                      style="color:${st.color}; background:${st.bg}; padding:2px 8px; border-radius:999px; font-size:.8rem; white-space:nowrap;">
                    ${st.label}
                </span>
            </td>
        </tr>`;
            }).join('');

            return `
    <div class="ojt-week-block mb-3">
        <div class="ojt-week-header">
            <strong>${week.week_label}</strong>
        </div>
        <div class="ojt-table-scroll">
            <table class="ojt-table">
                <thead>
                    <tr>
                        <th rowspan="2" class="ojt-group">Date</th>
                        <th rowspan="2" class="ojt-group">Day</th>
                        <th colspan="3" style="background:#FFB62F;">Morning</th>
                        <th colspan="3" style="background:#FF673A;">Afternoon</th>
                        <th rowspan="2" class="ojt-group">Daily<br>Hours</th>
                        <th rowspan="2" class="ojt-group">Status</th>
                    </tr>
                    <tr>
                        <th style="background:#f9c565;">In</th>
                        <th style="background:#f9c565;">Out</th>
                        <th style="background:#f9c565;">Hrs</th>
                        <th style="background:#f49679;">In</th>
                        <th style="background:#f49679;">Out</th>
                        <th style="background:#f49679;">Hrs</th>
                    </tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
            </table>
        </div>
    </div>`;
        }
    </script>
</body>

</html>