<?php
session_start();
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_SESSION['user_id'])) {
        header("Location: student-login.php");
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $room_code = trim($_POST['room_code']);

    // check if room exists
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($room) {

        // Check if user is already a member of the room
        $check = $pdo->prepare("
            SELECT * FROM room_members 
            WHERE room_id = ? AND user_id = ?
        ");
        $check->execute([$room['id'], $user_id]);

        if ($check->rowCount() == 0) {

            // insert only if not yet joined
            $stmt = $pdo->prepare("
                INSERT INTO room_members (room_id, user_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$room['id'], $user_id]);
        }

        // redirect
        header("Location: rooms.php?room_id=" . $room['id']);
        exit();

    } else {
        echo "<script>alert('Invalid Room Code'); window.history.back();</script>";
    }
}