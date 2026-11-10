<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

$room_id = $_POST['room_id'] ?? null;

// ADD PARTICIPANT 
if (isset($_POST['users'])) {
    $users = json_decode($_POST['users'], true);
    if (!$users || !is_array($users)) {
        exit("Invalid data");
    }

    // Is this a department room (IT, CE, EE)?
    $rt = $pdo->prepare("SELECT room_type FROM rooms WHERE id = ?");
    $rt->execute([$room_id]);
    $isDepartmentRoom = $rt->fetchColumn() === 'department';

    // Get room name for notification
    $stmtRoom = $pdo->prepare("SELECT room_name FROM rooms WHERE id = ?");
    $stmtRoom->execute([$room_id]);
    $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);

    // Get adder's name (the adviser adding participants)
    $stmtAdviser = $pdo->prepare("SELECT full_name FROM advisers WHERE id = ?");
    $stmtAdviser->execute([$_SESSION['user_id']]);
    $adviser = $stmtAdviser->fetch(PDO::FETCH_ASSOC);

    $check = $pdo->prepare("
        SELECT 1 FROM room_members 
        WHERE room_id = ? AND user_id = ? AND user_type = ?
    ");
    $insert = $pdo->prepare("
        INSERT INTO room_members (room_id, user_id, user_type)
        VALUES (?, ?, ?)
    ");
    $notifStmt = $pdo->prepare("
        INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
        VALUES (:user_id, :user_type, :title, :message, :link, FALSE, NOW())
    ");

    $added = 0;

    foreach ($users as $user) {
        // Department rooms don't accept students
        if ($isDepartmentRoom && ($user['type'] ?? '') === 'student') {
            continue;
        }

        $check->execute([$room_id, $user['id'], $user['type']]);
        if (!$check->fetch()) {
            // Add to room
            $insert->execute([$room_id, $user['id'], $user['type']]);
            $added++;

            // Notify the newly added participant
            $notifStmt->execute([
                'user_id' => $user['id'],
                'user_type' => $user['type'],
                'title' => 'You were added to ' . $room['room_name'],
                'message' => $adviser['full_name'] . ' added you to the room "' . $room['room_name'] . '".',
                'link' => 'rooms.php?room_id=' . $room_id . '&tab=updates',
            ]);
        }
    }

    echo $added > 0 ? "success" : "none_added";
    exit;
}

// POST ANNOUNCEMENT 
if (isset($_POST['post_announcement'])) {
    $role = $_SESSION['role'] ?? '';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $room_id = (int) ($room_id ?? ($_POST['room_id'] ?? 0));

    if (!in_array($role, ['internship_adviser', 'hte_adviser', 'superadmin', 'internship_admin'], true)) {
        http_response_code(403);
        exit("Unauthorized");
    }

    $content = trim($_POST['content'] ?? '');
    if ($content === '') {
        exit("Content is empty");
    }

    // Load the room
    $stmtRoom = $pdo->prepare("SELECT id, room_name, adviser_id, room_type FROM rooms WHERE id = ? AND is_archived = FALSE");
    $stmtRoom->execute([$room_id]);
    $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);
    if (!$room) {
        http_response_code(404);
        exit("Room not found");
    }

    // Who is posting? Advisers and admins live in different tables.
    $isAdmin = in_array($role, ['superadmin', 'internship_admin'], true);
    $posterType = $isAdmin ? 'admin' : 'adviser';

    // Posting permission
    if ($isAdmin) {
        // superadmin: any room; internship_admin: department rooms only
        if ($role === 'internship_admin' && $room['room_type'] !== 'department') {
            http_response_code(403);
            exit("Unauthorized");
        }
    } else {
        // advisers: only rooms they own or belong to
        $memberChk = $pdo->prepare("
            SELECT 1 FROM room_members
            WHERE room_id = ? AND user_id = ? AND user_type = 'adviser' AND is_archived = FALSE
        ");
        $memberChk->execute([$room_id, $userId]);
        if ((int) $room['adviser_id'] !== $userId && !$memberChk->fetchColumn()) {
            http_response_code(403);
            exit("Unauthorized");
        }
    }

    // Poster's display name from the correct table
    $nameStmt = $pdo->prepare(
        $isAdmin ? "SELECT name AS full_name FROM admins WHERE id = ?"
        : "SELECT full_name FROM advisers WHERE id = ?"
    );
    $nameStmt->execute([$userId]);
    $posterName = $nameStmt->fetchColumn();
    if (!$posterName) {
        http_response_code(403);
        exit("Account not found");
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO room_posts (room_id, sender_name, sender_role, content, created_at)
            VALUES (:room_id, :sender_name, :sender_role, :content, NOW())
        ")->execute([
                    'room_id' => $room_id,
                    'sender_name' => $posterName,
                    'sender_role' => $role,
                    'content' => $content,
                ]);

        // Page each role uses to view a room
        $roomPages = [
            'student' => 'rooms.php',
            'hte_adviser' => 'hte-adviser.php',
            'internship_adviser' => 'ojt-adviser.php',
            'superadmin' => 'systemadmin-rooms.php',
            'internship_admin' => 'internadmin-rooms.php',
        ];

        // Notify all active members except the poster, and get each member's role
        $membersStmt = $pdo->prepare("
    SELECT rm.user_id,
           rm.user_type,
           CASE rm.user_type
                WHEN 'student' THEN 'student'
                WHEN 'adviser' THEN LOWER(a.role)
                WHEN 'admin'   THEN LOWER(ad.role)
           END AS member_role
    FROM room_members rm
    LEFT JOIN advisers a  ON rm.user_type = 'adviser' AND a.id  = rm.user_id
    LEFT JOIN admins   ad ON rm.user_type = 'admin'   AND ad.id = rm.user_id
    WHERE rm.room_id = ? AND rm.is_archived = FALSE
      AND NOT (rm.user_id = ? AND rm.user_type = ?)
");
        $membersStmt->execute([$room_id, $userId, $posterType]);

        $notifStmt = $pdo->prepare("
    INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
    VALUES (:user_id, :user_type, :title, :message, :link, FALSE, NOW())
");
        $preview = mb_substr($content, 0, 80) . (mb_strlen($content) > 80 ? '...' : '');

        foreach ($membersStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $page = $roomPages[$m['member_role'] ?? ''] ?? 'rooms.php';   // fallback

            $notifStmt->execute([
                'user_id' => $m['user_id'],
                'user_type' => $m['user_type'],
                'title' => 'New Post in the room ' . $room['room_name'],
                'message' => $posterName . ' posted: ' . $preview,
                'link' => $page . '?room_id=' . $room_id . '&tab=updates',
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        error_log('Post announcement failed: ' . $e->getMessage());
        http_response_code(500);
        exit("Could not post");
    }

    $redirects = [
        'hte_adviser' => 'hte-adviser.php',
        'internship_adviser' => 'ojt-adviser.php',
        'superadmin' => 'systemadmin-rooms.php',
        'internship_admin' => 'hte-rooms.php',
    ];
    header("Location: {$redirects[$role]}?room_id={$room_id}&tab=updates");
    exit;
}
if (isset($_POST['remove-member'])) {
    $roomId = (int) ($_POST['room_id'] ?? 0);
    $userId = (int) ($_POST['user_id'] ?? 0);
    $userType = $_POST['user_type'] ?? '';

    if (!in_array($userType, ['student', 'adviser', 'admin'], true)) {
        die("Invalid user type");
    }

    $stmt = $pdo->prepare("
        DELETE FROM room_members
        WHERE room_id = ? AND user_id = ? AND user_type = ?
    ");
    $stmt->execute([$roomId, $userId, $userType]);

    $_SESSION['success'] = "Member removed from the room.";
    header("Location: " . "ojt-adviser.php?room_id={$roomId}&tab=members");
    exit();
}