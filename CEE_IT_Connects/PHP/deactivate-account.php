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
    $hs = $pdo->prepare("
        SELECT COALESCE(SUM(
            GREATEST(0, COALESCE(EXTRACT(EPOCH FROM (m_out - m_in)) / 60, 0))
        + GREATEST(0, COALESCE(EXTRACT(EPOCH FROM (a_out - a_in)) / 60, 0))
        ), 0)
        FROM ojt_hours
        WHERE user_id = :uid AND user_type = 'student'
    ");
    $hs->execute([':uid' => $uid]);
    $renderedMins = (int) round($hs->fetchColumn());

    if ($renderedMins < ((int) $required) * 60) {
        echo json_encode(['ok' => false, 'error' => 'Required hours not yet completed.']);
        exit;
    }

    // Deactivate
    $up = $pdo->prepare("
        UPDATE students
        SET deactivate_at = NOW() + make_interval(days => :d)
        WHERE id = :id AND deactivate_at IS NULL
    ");
    $up->bindValue(':d', 30, PDO::PARAM_INT);   // days until deactivation
    $up->bindValue(':id', $uid, PDO::PARAM_INT);
    $up->execute();

    $q = $pdo->prepare("SELECT CEIL(EXTRACT(EPOCH FROM (deactivate_at - NOW()))) FROM students WHERE id = ?");
    $q->execute([$uid]);
    $secs = max(0, (int) $q->fetchColumn());

    echo json_encode(['ok' => true, 'secs' => $secs]);
    exit;
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error.']);
    exit;
}