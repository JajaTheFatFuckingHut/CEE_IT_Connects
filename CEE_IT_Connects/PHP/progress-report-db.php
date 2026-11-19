<?php

require 'auth.php';
require 'db.php';

$student_id = (int) ($_SESSION['user_id'] ?? 0);
$current_room_id = $_POST['room_id'] ?? null;

$redirect = 'rooms.php?section=progress_report&room_id=' . urlencode($current_room_id ?? '');

if (!$student_id) {
    $_SESSION['error'] = 'You must be logged in.';
    header('Location: login-apge.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
const ALLOWED_TYPES = [
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword', 'application/octet-stream'],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
    ],
];

function fail(string $msg, string $redirect): void
{
    $_SESSION['error'] = $msg;
    header('Location: ' . $redirect);
    exit;
}

/**
 * Validates the uploaded file. Returns [originalName, mime, tmpPath] or null if no file was sent.
 */
function validateUpload(string $redirect): ?array
{
    if (!isset($_FILES['wr_file']) || $_FILES['wr_file']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $f = $_FILES['wr_file'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        fail('File upload failed. Please try again.', $redirect);
    }
    if ($f['size'] > MAX_FILE_SIZE) {
        fail('File is too large (max 10 MB).', $redirect);
    }

    $name = basename($f['name']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(ALLOWED_TYPES[$ext])) {
        fail('Only PDF, DOC or DOCX files are allowed.', $redirect);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    if (!in_array($mime, ALLOWED_TYPES[$ext], true)) {
        fail('The file content does not match its extension.', $redirect);
    }

    return [mb_substr($name, 0, 250), $mime, $f['tmp_name']];
}

function validWeek($raw): int
{
    $w = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
    return $w === false ? 0 : $w;
}

$action = $_POST['action'] ?? '';

try {
    /* ---------- UPLOAD ---------- */
    if ($action === 'upload') {
        $week = validWeek($_POST['week_number'] ?? null);
        if (!$week) {
            fail('Please enter a valid week number (1–60).', $redirect);
        }

        $file = validateUpload($redirect);
        if (!$file) {
            fail('Please choose a file to upload.', $redirect);
        }
        [$name, $mime, $tmp] = $file;

        // No repeated weeks per student
        $chk = $pdo->prepare("SELECT 1 FROM weekly_reports WHERE student_id = ? AND week_number = ?");
        $chk->execute([$student_id, $week]);
        if ($chk->fetchColumn()) {
            fail("You already submitted a report for Week $week. Use Edit to replace it.", $redirect);
        }

        $fh = fopen($tmp, 'rb');
        $stmt = $pdo->prepare("
            INSERT INTO weekly_reports (student_id, week_number, file_name, created_at, file_data, file_mime)
            VALUES (:sid, :week, :name, CURRENT_DATE, :data, :mime)
        ");
        $stmt->bindValue(':sid', $student_id, PDO::PARAM_INT);
        $stmt->bindValue(':week', $week, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name);
        $stmt->bindParam(':data', $fh, PDO::PARAM_LOB);
        $stmt->bindValue(':mime', $mime);
        $stmt->execute();
        fclose($fh);

        $_SESSION['success'] = "Week $week report uploaded successfully.";
    }

    /* ---------- UPDATE ---------- */ elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $week = validWeek($_POST['week_number'] ?? null);
        if (!$id || !$week) {
            fail('Invalid request.', $redirect);
        }

        // Make sure this record belongs to the student
        $own = $pdo->prepare("SELECT 1 FROM weekly_reports WHERE id = ? AND student_id = ?");
        $own->execute([$id, $student_id]);
        if (!$own->fetchColumn()) {
            fail('Report not found.', $redirect);
        }

        // Week must not collide with another of the student's reports
        $dup = $pdo->prepare("
            SELECT 1 FROM weekly_reports
            WHERE student_id = ? AND week_number = ? AND id <> ?
        ");
        $dup->execute([$student_id, $week, $id]);
        if ($dup->fetchColumn()) {
            fail("You already have a report for Week $week.", $redirect);
        }

        $file = validateUpload($redirect);

        if ($file) {
            [$name, $mime, $tmp] = $file;
            $fh = fopen($tmp, 'rb');
            $stmt = $pdo->prepare("
                UPDATE weekly_reports
                SET week_number = :week, file_name = :name, file_data = :data,
                    file_mime = :mime, created_at = CURRENT_DATE
                WHERE id = :id AND student_id = :sid
            ");
            $stmt->bindValue(':name', $name);
            $stmt->bindParam(':data', $fh, PDO::PARAM_LOB);
            $stmt->bindValue(':mime', $mime);
        } else {
            $stmt = $pdo->prepare("
                UPDATE weekly_reports SET week_number = :week
                WHERE id = :id AND student_id = :sid
            ");
        }
        $stmt->bindValue(':week', $week, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':sid', $student_id, PDO::PARAM_INT);
        $stmt->execute();
        if (isset($fh)) {
            fclose($fh);
        }

        $_SESSION['success'] = 'Report updated successfully.';
    }

    /* ---------- DELETE ---------- */ elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) {
            fail('Invalid request.', $redirect);
        }

        $stmt = $pdo->prepare("DELETE FROM weekly_reports WHERE id = ? AND student_id = ?");
        $stmt->execute([$id, $student_id]);

        $_SESSION['success'] = $stmt->rowCount() ? 'Report deleted.' : 'Report not found.';
    } else {
        $_SESSION['error'] = 'Unknown action.';
    }
} catch (PDOException $e) {
    error_log('weekly report failed: ' . $e->getMessage());
    // 23505 = unique violation (safety net if two requests race)
    $_SESSION['error'] = $e->getCode() === '23505'
        ? 'A report for that week already exists.'
        : 'Something went wrong. Please try again.';
}

header('Location: ' . $redirect);
exit;