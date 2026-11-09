<?php
session_start();
require 'db.php';
require 'auth.php';

if ($_SESSION['role'] !== 'internship_adviser') {
    header("Location: login-page.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['room_id'])) {
    if (isset($_POST['delete'])) {
        $stmt = $pdo->prepare("UPDATE rooms SET is_archived = TRUE WHERE id = :id");
        $stmt->execute([':id' => $_POST['room_id']]);
    }
    if (isset($_POST['restore'])) {
        $stmt = $pdo->prepare("UPDATE rooms SET is_archived = FALSE WHERE id = :id");
        $stmt->execute([':id' => $_POST['room_id']]);
    }

}

header("Location: ojt-adviser.php");
exit();