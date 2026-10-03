<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

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
                'link' => 'message.php?room_id=' . $room_id . '&tab=updates',
            ]);
        }
    }

    echo $added > 0 ? "success" : "none_added";
    exit;
}

// POST ANNOUNCEMENT 
if (isset($_POST['post_announcement'])) {
    if (!in_array($_SESSION['role'], ['internship_adviser', 'hte_adviser', 'superadmin', 'internship_admin'], true)) {
        http_response_code(403);
        echo var_dump($_SESSION['role']);
        exit("Unauthorized");
    }

    $content = trim($_POST['content'] ?? '');
    if ($content === '') {
        exit("Content is empty");
    }

    // Get adviser name
    $stmt = $pdo->prepare("SELECT full_name FROM advisers WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $adviser = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get room name for notification message
    $stmtRoom = $pdo->prepare("SELECT room_name FROM rooms WHERE id = ?");
    $stmtRoom->execute([$room_id]);
    $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);

    // Insert the post
    $stmt = $pdo->prepare("
        INSERT INTO room_posts (room_id, sender_name, sender_role, content, created_at)
        VALUES (:room_id, :sender_name, :sender_role, :content, NOW())
    ");
    $stmt->execute([
        'room_id' => $room_id,
        'sender_name' => $adviser['full_name'],
        'sender_role' => $_SESSION['role'],
        'content' => $content,
    ]);

    // Fetch all room members EXCEPT the poster
    $membersStmt = $pdo->prepare("
        SELECT user_id, user_type FROM room_members 
        WHERE room_id = ? 
        AND NOT (user_id = ? AND user_type = 'adviser')
    ");
    $membersStmt->execute([$room_id, $_SESSION['user_id']]);
    $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Insert a notification for each member
    $notifStmt = $pdo->prepare("
        INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
        VALUES (:user_id, :user_type, :title, :message, :link, FALSE, NOW())
    ");

    foreach ($members as $member) {
        $notifStmt->execute([
            'user_id' => $member['user_id'],
            'user_type' => $member['user_type'],
            'title' => 'New Post in the room ' . $room['room_name'],
            'message' => $adviser['full_name'] . ' posted: ' . substr($content, 0, 80) . (strlen($content) > 80 ? '...' : ''),
            'link' => 'message.php?room_id=' . $room_id . '&tab=updates',
        ]);
    }

    if ($_SESSION['role'] === 'hte_adviser') {
        header("Location: hte-ui.php?room_id={$room_id}&tab=updates");
    } elseif ($_SESSION['role'] === 'superadmin') {
        header("Location: systemadmin-rooms.php?room_id={$room_id}&tab=members");
    } elseif ($_SESSION['role'] === 'internship_adviser') {
        header("Location: ojt-rooms.php?room_id={$room_id}&tab=updates");
    } elseif ($_SESSION['role'] === 'internship_admin') {
        header("Location: hte-rooms.php?room_id={$room_id}&tab=updates");
    }
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
    header("Location: " . "ojt-rooms.php?room_id={$roomId}&tab=members");
    exit();
}