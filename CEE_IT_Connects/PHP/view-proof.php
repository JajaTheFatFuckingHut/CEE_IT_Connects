<?php
session_start();
require 'db.php';

$sid = (int) ($_GET['sid'] ?? 0);
$iid = (int) ($_GET['iid'] ?? 0);
$step = $_GET['step'] ?? '';

$role = $_SESSION['role'] ?? null;
$uid = (int) ($_SESSION['user_id'] ?? 0);

// students can only open their own files; staff roles can open any
$allowed = ($role === 'student' && $uid === $sid)
    || in_array($role, ['internship_adviser', 'hte_adviser', 'superadmin', 'internship_admin'], true);

if (!$allowed) {
    http_response_code(403);
    exit('Forbidden');
}

$stmt = $pdo->prepare("
    SELECT file_data, file_mime FROM student_progress
    WHERE student_id = ? AND internship_id = ? AND step_key = ?
");
$stmt->execute([$sid, $iid, $step]);
$stmt->bindColumn(1, $data, PDO::PARAM_LOB);
$stmt->bindColumn(2, $mime);
$stmt->fetch(PDO::FETCH_BOUND);

if (!$data) {
    http_response_code(404);
    exit('File not found');
}

$safe = ['application/pdf', 'image/png', 'image/jpeg'];
header('Content-Type: ' . (in_array($mime, $safe, true) ? $mime : 'application/octet-stream'));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');

if (is_resource($data)) {
    fpassthru($data);
} else {
    echo $data;
}