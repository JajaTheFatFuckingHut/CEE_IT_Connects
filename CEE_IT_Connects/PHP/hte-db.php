<?php
session_start();
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {

    if (!isset($_SESSION['user_id'])) {
        die("Not logged in");
    }

    $student_id = $_POST['student_id'];
    $internship_id = $_POST['internship_id'];
    $status = $_POST['status'] ?? null;
    $hours = $_POST['hours'] ?? 0;

    $remarks = $_POST['remarks'];
    $rating = $_POST['rating'];
    $is_completed = isset($_POST['completed']) ? 1 : 0;

    $adviser_id = $_SESSION['user_id'];
    $date = date('Y-m-d');

    // 1. SAVE LOG
    $check = $pdo->prepare("SELECT id FROM ojt_logs WHERE student_id=? AND date=?");
    $check->execute([$student_id, $date]);

    if ($check->rowCount() > 0) {
        $stmt = $pdo->prepare("
            UPDATE ojt_logs
            SET status=?, hours_worked=?
            WHERE student_id=? AND date=?
        ");
        $stmt->execute([$status, $hours, $student_id, $date]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO ojt_logs(student_id, internship_id, date, status, hours_worked)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$student_id, $internship_id, $date, $status, $hours]);
    }

    // 2. SAVE REMARKS
    $check2 = $pdo->prepare("SELECT id FROM ojt_remarks WHERE student_id=?");
    $check2->execute([$student_id]);

    if ($check2->rowCount() > 0) {
        $stmt = $pdo->prepare("
            UPDATE ojt_remarks
            SET remarks=?, rating=?, is_completed=?, updated_at=NOW()
            WHERE student_id=?
        ");
        $stmt->execute([$remarks, $rating, $is_completed, $student_id]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO ojt_remarks
            (student_id, adviser_id, internship_id, remarks, rating, is_completed)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $student_id,
            $adviser_id,
            $internship_id,
            $remarks,
            $rating,
            $is_completed
        ]);
    }

    header("Location: hte-adviser.php?saved=1");
    exit;
}