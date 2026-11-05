<?php
session_start();
require 'db.php';
require_once 'auth.php';

$sender_id = $_SESSION['user_id'];
$sender_type = getUserType($_SESSION['role']);

$receiver_id = $_POST['receiver_id'];
$receiver_type = getUserType($_POST['receiver_type']);
$message = trim($_POST['message'] ?? '');

$attachment_path = null;
$attachment_name = null;
$attachment_type = null;

// ── Handle file upload (optional) ──
if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'avi', 'pdf', 'doc', 'docx'];
    $originalName = $_FILES['attachment']['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt)) {
        die('Invalid file type.');
    }

    $maxBytes = 25 * 1024 * 1024; // 25MB
    if ($_FILES['attachment']['size'] > $maxBytes) {
        die('File too large.');
    }

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $attachment_type = 'image';
    } elseif (in_array($ext, ['mp4', 'mov', 'avi'])) {
        $attachment_type = 'video';
    } elseif ($ext === 'pdf') {
        $attachment_type = 'pdf';
    } else {
        $attachment_type = 'doc';
    }

    $uploadDir = __DIR__ . '.../uploads/chat/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $safeName = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
    $destPath = $uploadDir . $safeName;

    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $destPath)) {
        die('Upload failed.');
    }

    $webBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

    $attachment_path = $webBase . '/uploads/chat/' . $safeName;
    $attachment_name = $originalName;
    $attachment_name = $originalName;
}

// Require at least a message OR an attachment
if ($message === '' && !$attachment_path) {
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO messages 
    (sender_id, sender_type, receiver_id, receiver_type, message,
     attachment_path, attachment_name, attachment_type, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

$stmt->execute([
    $sender_id,
    $sender_type,
    $receiver_id,
    $receiver_type,
    $message,
    $attachment_path,
    $attachment_name,
    $attachment_type
]);

header("Location: " . $_SERVER['HTTP_REFERER']);
exit;