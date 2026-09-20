<?php
session_start();
require 'db.php';

$id = (int) ($_GET['id'] ?? 0);
$role = $_SESSION['role'] ?? null;
$uid = (int) ($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare("SELECT student_id, week_number, file_data, file_mime FROM weekly_reports WHERE id = ?");
$stmt->execute([$id]);
$stmt->bindColumn(1, $sid);
$stmt->bindColumn(2, $week);
$stmt->bindColumn(3, $data, PDO::PARAM_LOB);
$stmt->bindColumn(4, $mime);
if (!$stmt->fetch(PDO::FETCH_BOUND)) {
    http_response_code(404);
    exit('Not found');
}

// students see only their own; staff can see any
$allowed = ($role === 'student' && $uid === (int) $sid)
    || in_array($role, ['internship_adviser', 'hte_adviser', 'superadmin', 'internship_admin'], true);
if (!$allowed) {
    http_response_code(403);
    exit('Forbidden');
}

if (!$data) {
    http_response_code(404);
    exit('File missing - please re-upload.');
}

$safe = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$type = in_array($mime, $safe, true) ? $mime : 'application/octet-stream';   // $type is defined here...

$ext = [                                                                      // ...before it is used here
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
][$type] ?? 'bin';

header('Content-Type: ' . $type);
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline')
    . '; filename="week' . (int) $week . '_student' . (int) $sid . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');

if (is_resource($data)) {
    fpassthru($data);
} else {
    echo $data;
}