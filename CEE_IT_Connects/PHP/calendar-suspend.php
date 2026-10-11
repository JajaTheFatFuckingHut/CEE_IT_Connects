<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
}

$day = (int) ($_POST['day'] ?? 0);
$month = (int) ($_POST['month'] ?? 0);   // 1-12
$year = (int) ($_POST['year'] ?? 0);
$is = ($_POST['is_suspended'] ?? '0') === '1';
$reason = trim($_POST['reason'] ?? '');

if (!checkdate($month, $day, $year)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid date']);
    exit;
}
if ($is && $reason === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Reason is required']);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO calendar_suspensions (day, month, year, is_suspended, reason)
    VALUES (:d, :m, :y, :s, :r)
    ON CONFLICT (year, month, day)
    DO UPDATE SET is_suspended = EXCLUDED.is_suspended,
                  reason       = EXCLUDED.reason
");
$stmt->bindValue(':d', $day, PDO::PARAM_INT);
$stmt->bindValue(':m', $month, PDO::PARAM_INT);
$stmt->bindValue(':y', $year, PDO::PARAM_INT);
$stmt->bindValue(':s', $is, PDO::PARAM_BOOL);
$stmt->bindValue(':r', $is ? $reason : null, $is ? PDO::PARAM_STR : PDO::PARAM_NULL);
$stmt->execute();

echo json_encode(['ok' => true]);