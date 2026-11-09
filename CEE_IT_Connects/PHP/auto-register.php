<?php
require 'db.php';

$csvPath = __DIR__ . '/../Sources/students.csv';

if (!file_exists($csvPath)) {
    die("CSV file not found.");
}

if (($handle = fopen($csvPath, 'r')) !== false) {

    $headers = fgetcsv($handle, 1000, "\t"); // <-- fixed: was ','

    $headers = array_map(function ($h) {
        $h = strtolower(trim($h));
        return match ($h) {
            'student id' => 'student_id',
            'full name' => 'full_name',
            'e-mail', 'email' => 'email',
            'program' => 'program',
            'year' => 'year_level',
            'section' => 'section',
            'contact no.', 'contact number' => 'contact_number',
            default => $h
        };
    }, $headers);

    if (!$headers) {
        fclose($handle);
        die("CSV header missing.");
    }

    $headers = array_map('trim', $headers);

    $stmt = $pdo->prepare("
        INSERT INTO students (email, full_name, student_id, program, year_level, section, contact_number, password_hash)
        VALUES (:email, :full_name, :student_id, :program, :year_level, :section, :contact_number, :password_hash)
        ON CONFLICT (student_id) DO NOTHING
    ");

    while (($row = fgetcsv($handle, 1000, "\t")) !== false) { // <-- fixed: was ','

        if (count($row) !== count($headers))
            continue;

        $student = array_combine($headers, $row);
        if (!$student)
            continue;

        $studentId = trim($student['student_id'] ?? '');
        if ($studentId === '')
            continue;

        $stmt->execute([
            'email' => trim($student['email'] ?? ''),
            'full_name' => trim($student['full_name'] ?? ''),
            'student_id' => $studentId,
            'program' => trim($student['program'] ?? ''),
            'year_level' => (int) ($student['year_level'] ?? 0),
            'section' => trim($student['section'] ?? ''),
            'contact_number' => trim($student['contact_number'] ?? ''),
            'password_hash' => password_hash($studentId, PASSWORD_DEFAULT)
        ]);
    }

    fclose($handle);
    header("Location: internship-admins.php?imported=1");
    exit;
}

die("Error opening the file.");