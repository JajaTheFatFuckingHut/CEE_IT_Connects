<?php
session_start();
require 'db.php';
require_once 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ojt-rooms.php");
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';
require __DIR__ . '/PHPMailer-master/src/Exception.php';

function sendStudentCredentials(
    string $email,
    string $full_name,
    string $student_id,
    string $temporaryPassword
): bool {

    $mail = new PHPMailer(true);

    try {
        $payload = json_encode([
            'from' => 'CEE IT Connects <onboarding@resend.dev>',
            'to' => [$email],
            'subject' => 'Your CEE IT Connects Account',
            'html' => "
                <h2>Welcome to CEE IT Connects!</h2>

            <p>Hello <strong>" .
                htmlspecialchars($full_name) .
                "</strong>,</p>

            <p>Your student account has been successfully created.</p>

            <h3>Your Login Credentials</h3>

            <p>
                <strong>Student ID:</strong>
                " . htmlspecialchars($student_id) . "
            </p>

            <p>
                <strong>Email:</strong>
                " . htmlspecialchars($email) . "
            </p>

            <p>
                <strong>Temporary Password:</strong>
                " . htmlspecialchars($temporaryPassword) . "
            </p>

            <p>
                Please log in and change your password after your
                first successful login.
            </p>

            <p>
                Regards,<br>
                <strong>CEE IT Connects</strong>
            </p>
            ",
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . getenv('RESEND_API_KEY'),
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("Resend cURL error: " . $curlError);
            $error = "Email failed: " . $curlError;
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            header("Location: forgot-password.php?step=code&email=" . urlencode($email) . "&msg=Code sent!");
            exit;
        } else {
            error_log("Resend API error ({$httpCode}): " . $response);
            $error = "Email failed: " . $response;
        }

        error_log("Credential email sent to: {$email}");

        return true;

    } catch (Exception $e) {

        error_log(
            "Credential email failed for {$email}: " .
            $mail->ErrorInfo
        );

        return false;
    }
}

$source = $_POST['source'] ?? '';

if (isset($_POST['edit_csv'])) {

    $headers = $_POST['headers'] ?? [];
    $rows = $_POST['csv'] ?? [];

    $sourceDir = __DIR__ . '/Sources/';

    $activeFile = file_exists($sourceDir . 'active_csv.txt')
        ? trim(file_get_contents($sourceDir . 'active_csv.txt'))
        : 'students.csv';

    $csvPath = $sourceDir . $activeFile;


    // SAVE CSV ONLY
    if (!isset($_POST['import_to_database'])) {

        $handle = fopen($csvPath, 'w');

        if ($handle === false) {
            $_SESSION['error'] = "Unable to open CSV file: " . $csvPath;
            header("Location: superadmin.php?section=student_register");
            exit;
        }

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        $_SESSION['success'] = "CSV saved successfully.";

        header("Location: superadmin.php?section=student_register");
        exit;
    }

    // Add to database
    if (isset($_POST['import_to_database'])) {
        $normalizedHeaders = array_map(
            fn($h) => strtolower(trim($h)),
            $headers
        );

        $requiredColumns = [
            'student_id',
            'full_name',
            'email',
            'program',
            'year_level',
            'section',
            'contact_number'
        ];

        $columnIndexes = [];

        foreach ($requiredColumns as $column) {

            $index = array_search($column, $normalizedHeaders);

            if ($index === false) {

                $_SESSION['error'] =
                    "CSV is missing the required column: {$column}";

                header("Location: superadmin.php?section=student_register");
                exit;
            }

            $columnIndexes[$column] = $index;
        }


        // PREPARE DATABASE STATEMENTS

        $checkEmailStmt = $pdo->prepare("
            SELECT id
            FROM students
            WHERE email = ?
            LIMIT 1
        ");

        $checkStudentIdStmt = $pdo->prepare("
            SELECT id
            FROM students
            WHERE student_id = ?
            LIMIT 1
        ");

        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $randomString = substr(str_shuffle($characters), 0, 10);

        $temporaryPassword = $randomString;

        $password_hash = password_hash(
            $temporaryPassword,
            PASSWORD_DEFAULT
        );

        $insertStmt = $pdo->
            prepare("INSERT INTO students (email, full_name, student_id, program, 
            year_level, section, contact_number, password_hash) 
        VALUES (?,?,?,?,?,?,?,?)");


        // COUNTERS
        $added = 0;
        $skipped = 0;
        $errors = [];


        // PROCESS EACH CSV ROW
        foreach ($rows as $rowIndex => $row) {

            $student_id = trim(
                $row[$columnIndexes['student_id']] ?? ''
            );

            $full_name = trim(
                $row[$columnIndexes['full_name']] ?? ''
            );

            $email = trim(
                $row[$columnIndexes['email']] ?? ''
            );

            $program = trim(
                $row[$columnIndexes['program']] ?? ''
            );

            $year_level = trim(
                $row[$columnIndexes['year_level']] ?? ''
            );

            $section = trim(
                $row[$columnIndexes['section']] ?? ''
            );

            $contact_number = trim(
                $row[$columnIndexes['contact_number']] ?? ''
            );


            // -----------------------------------------------------
            // SKIP COMPLETELY EMPTY ROW
            // -----------------------------------------------------

            if (
                $student_id === '' &&
                $full_name === '' &&
                $email === '' &&
                $program === '' &&
                $year_level === '' &&
                $section === '' &&
                $contact_number === ''
            ) {
                continue;
            }


            // -----------------------------------------------------
            // VALIDATE REQUIRED DATA
            // -----------------------------------------------------

            if ($student_id === '') {
                $errors[] = "Row " . ($rowIndex + 1) . ": Student ID is empty.";
                continue;
            }

            if ($full_name === '') {
                $errors[] = "Row " . ($rowIndex + 1) . ": Full name is empty.";
                continue;
            }

            if ($email === '') {
                $errors[] = "Row " . ($rowIndex + 1) . ": Email is empty.";
                continue;
            }


            // -----------------------------------------------------
            // VALIDATE EMAIL
            // -----------------------------------------------------

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

                $errors[] =
                    "Row " . ($rowIndex + 1) .
                    ": Invalid email ({$email}).";

                continue;
            }


            // -----------------------------------------------------
            // CHECK DUPLICATE EMAIL
            // -----------------------------------------------------

            $checkEmailStmt->execute([$email]);

            if ($checkEmailStmt->fetch()) {

                $skipped++;

                $errors[] =
                    "Row " . ($rowIndex + 1) .
                    ": Email already exists ({$email}).";

                continue;
            }


            // CHECK DUPLICATE STUDENT ID
            $checkStudentIdStmt->execute([$student_id]);

            if ($checkStudentIdStmt->fetch()) {

                $skipped++;

                $errors[] =
                    "Row " . ($rowIndex + 1) .
                    ": Student ID already exists ({$student_id}).";

                continue;
            }


            $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
            $randomString = substr(str_shuffle($characters), 0, 5);

            $temporaryPassword = $randomString;

            // DEFAULT PASSWORD
            $password_hash = password_hash(
                $temporaryPassword,
                PASSWORD_DEFAULT
            );


            // INSERT STUDENT
            try {

                $insertStmt->execute([
                    $email,
                    $full_name,
                    $student_id,
                    $program,
                    $year_level !== '' ? (int) $year_level : null,
                    $section,
                    $contact_number,
                    $password_hash
                ]);

                $added++;

                $emailSent = sendStudentCredentials(
                    $email,
                    $full_name,
                    $student_id,
                    $temporaryPassword
                );

                if (!$emailSent) {

                    $errors[] =
                        "Row " . ($rowIndex + 1) .
                        ": Student was added, but the credential email could not be sent.";
                }


            } catch (PDOException $e) {

                $errors[] =
                    "Row " . ($rowIndex + 1) .
                    ": Database error - " . $e->getMessage();
            }
        }


        $handle = fopen($csvPath, 'w');

        if ($handle !== false) {

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }


        // CREATE RESULT MESSAGE
        if ($added > 0 && empty($errors)) {

            $_SESSION['success'] =
                "Successfully added {$added} student(s) to the database.";

        } elseif ($added > 0) {

            $_SESSION['warning'] =
                "Added {$added} student(s). " .
                ($skipped > 0
                    ? "{$skipped} student(s) were skipped. "
                    : "") .
                implode(' ', $errors);

        } elseif ($skipped > 0) {

            $_SESSION['warning'] =
                "No new students were added. " .
                "{$skipped} student(s) were skipped. " .
                implode(' ', $errors);

        } else {

            $_SESSION['error'] =
                "No students were added. " .
                implode(' ', $errors);
        }


        header("Location: superadmin.php?section=student_register");
        exit;
    }
}
// Ojt rooms
if ($source === 'ojt-rooms') {
    $headers = $_POST['headers'] ?? [];
    $rows = $_POST['csv'] ?? [];
    $room_id = $_POST['room_id'] ?? null;

    if (!$room_id) {
        $_SESSION['error'] = "No room specified.";
        header("Location: ojt-rooms.php");
        exit;
    }

    $normalized = array_map(fn($h) => strtolower(trim($h)), $headers);
    $sidIdx = array_search('student_id', $normalized);
    if ($sidIdx === false) {
        $sidIdx = array_search('student id', $normalized);
    }

    if ($sidIdx === false) {
        $_SESSION['error'] = "Column 'student_id' not found in CSV.";
        header("Location: ojt-rooms.php?room_id=" . $room_id . "&tab=members");
        exit;
    }

    $added = 0;
    $notFound = [];
    $skipped = 0;

    $findStmt = $pdo->prepare("SELECT id FROM students WHERE student_id = ?");
    $checkStmt = $pdo->prepare("
        SELECT id FROM room_members 
        WHERE room_id = ? AND user_id = ? AND user_type = 'student'
    ");
    $insertStmt = $pdo->prepare("
        INSERT INTO room_members (room_id, user_id, user_type) 
        VALUES (?, ?, 'student')
    ");

    foreach ($rows as $row) {
        $student_id = trim($row[$sidIdx] ?? '');
        if ($student_id === '')
            continue;

        $findStmt->execute([$student_id]);
        $student = $findStmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            $notFound[] = $student_id;
            continue;
        }

        $checkStmt->execute([$room_id, $student['id']]);
        if ($checkStmt->fetch()) {
            $skipped++;
            continue;
        }

        $insertStmt->execute([$room_id, $student['id']]);
        $added++;
    }

    if (!empty($notFound)) {
        $_SESSION['warning'] = "Added {$added} student(s). "
            . ($skipped ? "{$skipped} already in room. " : "")
            . "Not found: " . implode(', ', $notFound);
    } elseif ($skipped > 0 && $added === 0) {
        $_SESSION['info'] = "No new students added — all {$skipped} are already in this room.";
    } else {
        $_SESSION['success'] = "Successfully added {$added} student(s) to the room."
            . ($skipped ? " ({$skipped} already in room, skipped.)" : "");
    }

    header("Location: ojt-rooms.php?room_id=" . $room_id . "&tab=members");
    exit;
}

// put here the upload

$_SESSION['error'] = "Unknown action.";
header("Location: superadmin.php");
exit;