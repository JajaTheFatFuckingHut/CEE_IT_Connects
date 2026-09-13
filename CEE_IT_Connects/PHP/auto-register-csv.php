<?php
session_start();

$sourceDir = __DIR__ . '/Sources/';

if (!isset($_FILES['students_csv']) || $_FILES['students_csv']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error'] = "No file uploaded or upload error.";
    header("Location: superadmin.php");
    exit();
}

$ext = strtolower(pathinfo($_FILES['students_csv']['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    $_SESSION['error'] = "Only CSV files are allowed.";
    header("Location: superadmin.php");
    exit();
}

$uploadedFilename = basename($_FILES['students_csv']['name']);
$targetPath = $sourceDir . $uploadedFilename;

// Delete old CSVs 
foreach (glob($sourceDir . '*.csv') as $oldCsv) {
    unlink($oldCsv);
}

if (!move_uploaded_file($_FILES['students_csv']['tmp_name'], $targetPath)) {
    $_SESSION['error'] = "Failed to save the file. Check folder permissions.";
    header("Location: superadmin.php");
    exit();
}

// Save active filename
file_put_contents($sourceDir . 'active_csv.txt', $uploadedFilename);

$_SESSION['success'] = "CSV '{$uploadedFilename}' uploaded successfully.";
header("Location: superadmin.php");
exit();