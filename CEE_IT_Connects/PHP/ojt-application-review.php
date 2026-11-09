<?php
require 'auth.php';
require 'db.php';

$application_id = (int) ($_POST['application_id'] ?? 0);
$action = $_POST['action'] ?? '';
$room_id = (int) ($_POST['room_id'] ?? 0);
$remarks = trim($_POST['remarks'] ?? '');
$adviser_id = (int) $_SESSION['user_id'];

if (!$application_id || !$action || !$room_id) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: ojt-adviser.php?room_id=$room_id&section=ojt_applications");
    exit;
}

if ($action === 'approve') {
    $stmt = $pdo->prepare("
        UPDATE ojt_applications
        SET status = 'approved', reviewed_by = NULL, reviewed_by_adviser = ?, reviewed_at = NOW(), remarks = NULL 
        WHERE id = ?
    ");
    $stmt->execute([$adviser_id, $application_id]);
    $_SESSION['success'] = "OJT application approved successfully.";

} elseif ($action === 'reject') {
    if (!$remarks) {
        $_SESSION['error'] = "Please provide a rejection reason.";
        header("Location: ojt-adviser.php?room_id=$room_id&section=ojt_applications");
        exit;
    }
    $stmt = $pdo->prepare("
        UPDATE ojt_applications
        SET status = 'rejected', reviewed_by = null, reviewed_by_adviser = ?, reviewed_at = NOW(), remarks = ?
        WHERE id = ?
    ");
    $stmt->execute([$adviser_id, $remarks, $application_id]);
    $_SESSION['success'] = "Application rejected with feedback sent to student.";

} else {
    $_SESSION['error'] = "Unknown action.";
}

header("Location: ojt-adviser.php?room_id=$room_id&section=ojt_applications");
exit;