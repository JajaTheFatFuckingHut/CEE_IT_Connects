<?php

require 'auth.php';
require 'db.php';

$student_id = (int) ($_SESSION['user_id'] ?? 0);
$id = (int) ($_GET['id'] ?? 0);

if (!$student_id || !$id) {
    http_response_code(403);
    exit('Forbidden');
}

$stmt = $pdo->prepare("
    SELECT file_name, file_mime, file_data
    FROM weekly_reports
    WHERE id = ? AND student_id = ?
");
$stmt->execute([$id, $student_id]);
$stmt->bindColumn('file_name', $name);
$stmt->bindColumn('file_mime', $mime);
$stmt->bindColumn('file_data', $data, PDO::PARAM_LOB);

if (!$stmt->fetch(PDO::FETCH_BOUND)) {
    http_response_code(404);
    exit('File not found');
}

$safeName = str_replace(['"', "\r", "\n"], '', $name);

header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');

if (is_resource($data)) {
    fpassthru($data);
} else {
    echo $data;
}
exit;