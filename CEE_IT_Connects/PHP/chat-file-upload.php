<?php
session_start();
require 'db.php';
require_once 'auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$uid = $_SESSION['user_id'];
$utype = getUserType($_SESSION['role']);

$stmt = $pdo->prepare("
    SELECT attachment_data, attachment_mime, attachment_name
    FROM messages
    WHERE id = ?
      AND attachment_data IS NOT NULL
      AND ((sender_id = ? AND sender_type = ?) OR (receiver_id = ? AND receiver_type = ?))
");
$stmt->execute([$id, $uid, $utype, $uid, $utype]);
$f = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$f) {
    http_response_code(404);
    exit;
}

// pdo_pgsql returns BYTEA as a stream resource
$data = is_resource($f['attachment_data'])
    ? stream_get_contents($f['attachment_data'])
    : $f['attachment_data'];

$name = str_replace(['"', "\r", "\n"], '', $f['attachment_name']);

header('Content-Type: ' . $f['attachment_mime']);
header('Content-Length: ' . strlen($data));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
echo $data;