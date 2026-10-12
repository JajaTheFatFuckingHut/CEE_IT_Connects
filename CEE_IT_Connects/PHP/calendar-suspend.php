<?php
session_start();
require 'db.php';

$back = 'superadmin.php?section=calendar';   // adjust if the page needs a query string

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    exit('Not allowed');
}

$action = $_POST['action'] ?? '';
$dateStr = $_POST['date'] ?? '';
$reason = trim($_POST['reason'] ?? '');

$dt = DateTime::createFromFormat('Y-m-d', $dateStr);
if (!$dt || $dt->format('Y-m-d') !== $dateStr) {
    $_SESSION['error'] = 'Invalid date.';
    header("Location: $back");
    exit;
}

$day = (int) $dt->format('j');
$month = (int) $dt->format('n');
$year = (int) $dt->format('Y');

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'add') {
        if ($reason === '') {
            $_SESSION['error'] = 'Reason is required.';
            header("Location: $back");
            exit;
        }
        $stmt = $pdo->prepare("
            INSERT INTO calendar_suspensions (day, month, year, is_suspended, reason)
            VALUES (:d, :m, :y, TRUE, :r)
            ON CONFLICT (year, month, day)
            DO UPDATE SET is_suspended = TRUE, reason = EXCLUDED.reason
        ");
        $stmt->execute([':d' => $day, ':m' => $month, ':y' => $year, ':r' => $reason]);
        $_SESSION['success'] = 'Day suspended successfully.';

    } elseif ($action === 'remove') {
        $stmt = $pdo->prepare("
            UPDATE calendar_suspensions SET is_suspended = FALSE
            WHERE year = :y AND month = :m AND day = :d
        ");
        $stmt->execute([':d' => $day, ':m' => $month, ':y' => $year]);
        $_SESSION['success'] = 'Day unsuspended successfully.';

    } else {
        $_SESSION['error'] = 'Unknown action.';
    }
} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
}

header("Location: $back");
exit;