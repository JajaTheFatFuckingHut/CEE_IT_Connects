<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'student' || empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
}

$uid = (int) $_SESSION['user_id'];

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Required hours (same query as your page)
    $rh = $pdo->prepare("
        SELECT COALESCE(i.required_hours, 486)
        FROM ojt_applications oa
        JOIN internships i ON i.id = oa.internship_id
        WHERE oa.student_id = ? LIMIT 1
    ");
    $rh->execute([$uid]);
    $required = $rh->fetchColumn();
    if ($required === false) {
        echo json_encode(['ok' => false, 'error' => 'No application found.']);
        exit;
    }

    // Hours rendered: REPLACE table/column names with your real ones
    $hs = $pdo->prepare("SELECT COALESCE(SUM(minutes), 0) FROM ojt_hours WHERE user_id = ? = user_type = 'student'");
    $hs->execute([$uid]);
    $renderedMins = (int) $hs->fetchColumn();

    if ($renderedMins < ((int) $required) * 60) {
        echo json_encode(['ok' => false, 'error' => 'Required hours not yet completed.']);
        exit;
    }

    // Deactivate
    $up = $pdo->prepare("UPDATE students SET is_archived = :a WHERE id = :id");
    $up->bindValue(':a', true, PDO::PARAM_BOOL);
    $up->bindValue(':id', $uid, PDO::PARAM_INT);
    $up->execute();

    $au = $pdo->prepare("INSERT INTO audits (user_id, roles, activity, activity_date) VALUES (:u, 'student', :a, NOW())");
    $au->execute([':u' => $uid, ':a' => 'Account auto-deactivated after completing required OJT hours']);

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);   // temporary
}