<?php

require 'auth.php';
require 'db.php';

$student_id = (int) ($_SESSION['user_id'] ?? 0);
$current_room_id = $_POST['room_id'] ?? null;

if (!$student_id) {
    $_SESSION['error'] = 'You must be logged in.';
    header('Location: login-ui.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: message.php?section=progress_report&room_id=' . urlencode($current_room_id));
    exit;
}

$week_number = (int) ($_POST['week_number'] ?? 0);

if (!$week_number) {
    $_SESSION['error'] = 'Please enter a valid week number.';
    header('Location: message.php?section=progress_report&room_id=' . urlencode($current_room_id));
    exit;
}

if (
    !isset($_FILES['report_file']) ||
    empty($_FILES['report_file']['tmp_name'])
) {
    $_SESSION['error'] = 'Please attach your report file.';
    header('Location: message.php?section=progress_report&room_id=' . urlencode($current_room_id));
    exit;
}

$file = $_FILES['report_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error'] = 'Upload error. Please try again.';
    header('Location: message.php?section=progress_report&room_id=' . urlencode($current_room_id));
    exit;
}

$max_size = 10 * 1024 * 1024; // 10 MB

if ($file['size'] > $max_size) {
    $_SESSION['error'] = 'File must be under 10MB.';
    header('Location: message.php?section=progress_report&room_id=' . urlencode($current_room_id));
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime_type = $finfo->file($file['tmp_name']);

$allowed_types = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
];

if (!isset($allowed_types[$mime_type])) {
    $_SESSION['error'] = 'Only PDF or DOCX files are allowed.';
    header('Location: message.php?section=progress_report&room_id=' . urlencode($current_room_id));
    exit;
}
$file_stream = fopen($file['tmp_name'], 'rb');
if (!$file_stream) {
    $_SESSION['error'] = 'Could not read the uploaded file.';
    header('Location: message.php?section=progress_report&room_id=' . urlencode($current_room_id));
    exit;
}

try {
    $insert = $pdo->prepare("
        INSERT INTO weekly_reports
            (student_id, week_number, wr_filepath, file_data, file_mime, created_at)
        VALUES
            (:sid, :week, '', :data, :mime, CURRENT_DATE)
        RETURNING id
    ");
    $insert->bindValue(':sid', $student_id, PDO::PARAM_INT);
    $insert->bindValue(':week', $week_number, PDO::PARAM_INT);
    $insert->bindParam(':data', $file_stream, PDO::PARAM_LOB);
    $insert->bindValue(':mime', $mime_type);
    $insert->execute();

    $newId = (int) $insert->fetchColumn();

    // the path your View links already use
    $pdo->prepare("UPDATE weekly_reports SET wr_filepath = ? WHERE id = ?")
        ->execute(['view-report.php?id=' . $newId, $newId]);

    $_SESSION['success'] = 'Weekly report submitted successfully.';

} catch (PDOException $e) {
    error_log('weekly report save failed: ' . $e->getMessage());
    $_SESSION['error'] = 'Unable to save your report. Please try again.';
}

if (is_resource($file_stream))
    fclose($file_stream);

header('Location: message.php?section=progress_report&room_id=' . urlencode($current_room_id));
exit;