<?php

require 'auth.php';
require 'db.php';

$student_id = (int) ($_SESSION['user_id'] ?? 0);
$current_room_id = $_POST['room_id'] ?? null;

$redirect = 'message.php?section=progress_report&room_id=' . urlencode($current_room_id ?? '');

if (!$student_id) {
    $_SESSION['error'] = 'You must be logged in.';
    header('Location: login-ui.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

$link = trim($_POST['report_link'] ?? '');

if ($link === '') {
    $_SESSION['error'] = 'Please enter your Google Document link.';
    header('Location: ' . $redirect);
    exit;
}

if (
    strlen($link) > 500 ||
    !filter_var($link, FILTER_VALIDATE_URL) ||
    !preg_match('#^https://docs\.google\.com/document/#', $link)
) {
    $_SESSION['error'] = 'Please enter a valid Google Docs link (https://docs.google.com/document/...).';
    header('Location: ' . $redirect);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO report_links (student_id, link, updated_at)
        VALUES (:sid, :link, NOW())
        ON CONFLICT (student_id)
        DO UPDATE SET link = EXCLUDED.link, updated_at = NOW()
    ");
    $stmt->bindValue(':sid', $student_id, PDO::PARAM_INT);
    $stmt->bindValue(':link', $link);
    $stmt->execute();

    $_SESSION['success'] = 'Report link revised successfully.';
} catch (PDOException $e) {
    error_log('report link save failed: ' . $e->getMessage());
    $_SESSION['error'] = 'Unable to save your link. Please try again.';
}

header('Location: ' . $redirect);
exit;
?>