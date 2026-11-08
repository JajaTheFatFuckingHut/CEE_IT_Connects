<?php
session_start();
require 'db.php';
require_once 'auth.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'internship_adviser') {
    echo '<script>alert("You are not authorized to access this page.");</script>';
    header("Location: login-ui.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $adviser_id = $_SESSION['user_id'];

    // ── Supervisor request: approve / reject ──────────────────────────────────
    if (isset($_POST['action']) && in_array($_POST['action'], ['approve', 'reject'])) {

        $action = $_POST['action'];
        $submission_id = (int) ($_POST['submission_id'] ?? 0);
        $student_id = (int) ($_POST['student_id'] ?? 0);
        $room_id = (int) ($_POST['room_id'] ?? 0);
        $redirect = "ojt-adviser.php?room_id={$room_id}&section=supervisor_requests";

        $stmt = $pdo->prepare("SELECT * FROM student_hte_supervisor_submissions WHERE id = ?");
        $stmt->execute([$submission_id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            $_SESSION['error'] = 'Submission not found.';
            header("Location: $redirect");
            exit;
        }

        if ($action === 'approve') {

            $tempPassword = 12345;//bin2hex(random_bytes(5));
            $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

            // Insert into the shared advisers table as an HTE supervisor role
            // Change 'hte_supervisor' below to match your actual adviser_role enum value
            $insertStmt = $pdo->prepare("
    INSERT INTO advisers
        (full_name, email, password_hash, role, internship_id, created_at)
    VALUES (?, ?, ?, 'HTE_adviser', ?, NOW())
");
            $insertStmt->execute([
                $sub['full_name'],
                $sub['email'],
                $hashedPassword,
                $sub['internship_id'],
            ]);

            $updateStmt = $pdo->prepare("
                UPDATE student_hte_supervisor_submissions
                SET status = 'approved', reviewed_by = ?, reviewed_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$adviser_id, $submission_id]);

            $_SESSION['success'] =
                "Supervisor account created for {$sub['full_name']}. Temporary password: {$tempPassword}";

            header("Location: $redirect");
            exit;
        } else {

            $note = trim($_POST['rejection_note'] ?? '');

            $updateStmt = $pdo->prepare("
                UPDATE student_hte_supervisor_submissions
                SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_note = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$adviser_id, $note, $submission_id]);

            $_SESSION['success'] = 'Submission returned to student.';
        }

        header("Location: $redirect");
        exit;
    }

    // ── Create room ───────────────────────────────────────────────────────────
    $room_name = trim($_POST['room_name']);
    $section = $_POST['section'];

    if (!preg_match('/^[0-9]+-[0-9]+$/', $section)) {
        die("Invalid section format. Use format like 3-4.");
    }

    $check = $pdo->prepare("SELECT id FROM rooms WHERE adviser_id = ? AND is_archived = FALSE");
    $check->execute([$adviser_id]);
    if ($check->fetch()) {
        $_SESSION['error'] = "You already have an active room.";
        header("Location: ojt-adviser.php");
        exit;
    }

    $room_code = generateUniqueRoomCode($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO rooms (room_name, room_code, adviser_id)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$room_name, $room_code, $adviser_id]);
    $room_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO room_members (room_id, user_id, user_type) VALUES (?, ?, 'adviser')");
    $stmt->execute([$room_id, $adviser_id]);

    $_SESSION['success'] = "Room created successfully.";
    header("Location: ojt-adviser.php?room_id=" . $room_id);
    exit;
}

function generateRoomCode($length = 6)
{
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $code;
}

function generateUniqueRoomCode($pdo)
{
    do {
        $code = generateRoomCode(9);
        $stmt = $pdo->prepare("SELECT id FROM rooms WHERE room_code = ?");
        $stmt->execute([$code]);
        $exists = $stmt->fetch();
    } while ($exists);
    return $code;
}
?>