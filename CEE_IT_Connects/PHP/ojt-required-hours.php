<?php
session_start();
require 'db.php';
require 'auth.php';

if ($_SESSION['role'] !== 'internship_adviser') {
    header('Location: index.php');
    exit;
}

$room_id = (int) ($_POST['room_id'] ?? 0);
$required_hours = max(1, (int) ($_POST['required_hours'] ?? 486));

if ($room_id) {
    $stmt = $pdo->prepare("UPDATE rooms SET required_hours = ? WHERE id = ?");
    $stmt->execute([$required_hours, $room_id]);
    $_SESSION['success'] = "Required hours updated to $required_hours.";
}

header("Location: ojt-adviser.php?room_id=$room_id&section=status");
exit;