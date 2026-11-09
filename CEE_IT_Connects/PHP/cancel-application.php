<?php
session_start();
require 'db.php';
require 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: rooms.php?section=application");
    exit;
}

$bookmark_id = $_POST['bookmark_id'] ?? null;

if (!$bookmark_id) {
    header("Location: rooms.php?section=application");
    exit;
}

// Make sure the bookmark belongs to the current user (security check)
$stmt = $pdo->prepare("
    DELETE FROM internship_bookmarks 
    WHERE id = ? AND student_id = ?
");
$stmt->execute([$bookmark_id, $_SESSION['user_id']]);

header("Location: rooms.php?section=application");
exit;