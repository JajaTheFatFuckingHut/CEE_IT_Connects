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

require_once __DIR__ . '/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';
//For sending student credentials via email
function sendStudentCredentials(
    $email,
    $fullName,
    $studentId,
    $temporaryPassword
) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $_ENV['MAIL_HOST'] ?? getenv('MAIL_HOST');
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['MAIL_USERNAME'] ?? getenv('MAIL_USERNAME');
        $mail->Password = $_ENV['MAIL_PASSWORD'] ?? getenv('MAIL_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom(
            $_ENV['MAIL_USERNAME'] ?? getenv('MAIL_USERNAME'),
            'CEE IT Connects'
        );

        $mail->addAddress($email, $fullName);

        $mail->isHTML(true);
        $mail->Subject = 'CEE IT Connects Account Credentials';

        $mail->Body = "
            <div style='font-family: Arial, sans-serif; line-height: 1.6;'>
                <h2 style='color: #272f54;'>CEE IT Connects</h2>

                <p>Hello <strong>" . htmlspecialchars($fullName) . "</strong>,</p>

                <p>
                    Your CEE IT Connects student account has been
                    successfully created.
                </p>

                <p><strong>Your login credentials:</strong></p>

                <table style='border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 6px 12px 6px 0;'>
                            <strong>Student ID:</strong>
                        </td>
                        <td>
                            " . htmlspecialchars($studentId) . "
                        </td>
                    </tr>

                    <tr>
                        <td style='padding: 6px 12px 6px 0;'>
                            <strong>Email:</strong>
                        </td>
                        <td>
                            " . htmlspecialchars($email) . "
                        </td>
                    </tr>

                    <tr>
                        <td style='padding: 6px 12px 6px 0;'>
                            <strong>Temporary Password:</strong>
                        </td>
                        <td>
                            <code>" . htmlspecialchars($temporaryPassword) . "</code>
                        </td>
                    </tr>
                </table>

                <p>
                    You may now use these credentials to log in to
                    <strong>CEE IT Connects</strong>.
                </p>

                <p>
                    <strong>For security, please change your password
                    after your first login.</strong>
                </p>

                <p>
                    Thank you,<br>
                    <strong>CEE IT Connects</strong>
                </p>
            </div>
        ";

        $mail->AltBody =
            "Hello {$fullName},\n\n" .
            "Your CEE IT Connects student account has been created.\n\n" .
            "Student ID: {$studentId}\n" .
            "Email: {$email}\n" .
            "Temporary Password: {$temporaryPassword}\n\n" .
            "Please change your password after your first login.\n\n" .
            "CEE IT Connects";

        $mail->send();

        return true;

    } catch (Exception $e) {

        error_log(
            "Failed to send credentials to {$email}: " .
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
        VALUES (:email, :full_name, :student_id, :program, :year_level, :section, :contact_number, 
        :password_hash)");

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

            // Send credentials after successful database insertion
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

            error_log($e->getMessage());

            $errors[] =
                "Row " . ($rowIndex + 1) .
                ": Could not add this student.";
        }


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


            // DEFAULT PASSWORD
            $password_hash = password_hash(
                $student_id,
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