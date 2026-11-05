<?php
session_start();
require 'db.php';
require_once 'auth.php';

$sender_id = $_SESSION['user_id'];
$sender_type = getUserType($_SESSION['role']);

$receiver_id = $_POST['receiver_id'];
$receiver_type = getUserType($_POST['receiver_type']);
$message = trim($_POST['message'] ?? '');

$attachment_stream = null;
$attachment_name = null;
$attachment_type = null;
$attachment_mime = null;

if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'pdf', 'doc', 'docx'];
    $originalName = $_FILES['attachment']['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true))
        die('Invalid file type.');
    if ($_FILES['attachment']['size'] > 10 * 1024 * 1024)
        die('File too large (10MB max).');

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']))
        $attachment_type = 'image';
    elseif ($ext === 'mp4')
        $attachment_type = 'video';
    elseif ($ext === 'pdf')
        $attachment_type = 'pdf';
    else
        $attachment_type = 'doc';

    $attachment_mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['attachment']['tmp_name']);
    $attachment_stream = fopen($_FILES['attachment']['tmp_name'], 'rb');   // stream, not file_get_contents
    $attachment_name = $originalName;
}

if ($message === '' && $attachment_stream === null) {
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO messages
    (sender_id, sender_type, receiver_id, receiver_type, message,
     attachment_name, attachment_type, attachment_mime, attachment_data, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->bindValue(1, $sender_id);
$stmt->bindValue(2, $sender_type);
$stmt->bindValue(3, $receiver_id);
$stmt->bindValue(4, $receiver_type);
$stmt->bindValue(5, $message);
$stmt->bindValue(6, $attachment_name);
$stmt->bindValue(7, $attachment_type);
$stmt->bindValue(8, $attachment_mime);
if ($attachment_stream !== null) {
    $stmt->bindParam(9, $attachment_stream, PDO::PARAM_LOB);
} else {
    $stmt->bindValue(9, null, PDO::PARAM_NULL);
}
$stmt->execute();

header("Location: " . $_SERVER['HTTP_REFERER']);
exit;