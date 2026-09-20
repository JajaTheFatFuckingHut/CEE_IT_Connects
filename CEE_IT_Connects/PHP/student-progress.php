<?php

require 'auth.php';
require 'db.php';

$student_id = (int) ($_SESSION['user_id'] ?? 0);
$current_room_id = $_GET['room_id'] ?? null;
if (!$student_id) {
    $_SESSION['error'] = 'You must be logged in.';
    header('Location: login-ui.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? ''));
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'apply_internship') {

    $internship_id = (int) ($_POST['internship_id'] ?? 0);

    if (!$internship_id) {
        $_SESSION['error'] = 'Please select an internship.';
        header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? ''));
        exit;
    }

    // Check if student already has an application
    $check = $pdo->prepare("
        SELECT id
        FROM ojt_applications
        WHERE student_id = ?
        LIMIT 1
    ");

    $check->execute([$student_id]);

    if ($check->fetch()) {
        $_SESSION['error'] = 'You already have an internship application.';
        header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? '' ?? ''));
        exit;
    }

    // Get internship information
    $stmt = $pdo->prepare("
        SELECT id, company
        FROM internships
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$internship_id]);

    $internship = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$internship) {
        $_SESSION['error'] = 'Internship not found.';
        header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? '' ?? ''));
        exit;
    }

    // Create application
    $insert = $pdo->prepare("
        INSERT INTO ojt_applications
            (
                student_id,
                internship_id,
                company_name,
                submitted_at,
                status
            )
        VALUES
            (?, ?, ?, CURRENT_TIMESTAMP, 'pending')
    ");

    $insert->execute([
        $student_id,
        $internship_id,
        $internship['company']
    ]);

    $_SESSION['success'] = 'Internship application submitted successfully.';

    header("Location: message.php?section=application");
    exit;
} elseif ($action === 'mark_step') {

    $step_key = trim($_POST['step_key'] ?? '');
    $internship_id = (int) ($_POST['internship_id'] ?? 0);

    $allowed_steps = [
        'medical_cert',
        'company_profile',
        'addendum',
        'reco_letter',
        'waiver',
        'internship_plan',
        'vicinity_map',
        'oath',
        'resume',
        'mou'
    ];

    if (!in_array($step_key, $allowed_steps, true)) {

        $_SESSION['error'] = 'Invalid step.';
        header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? ''));
        exit;
    }

    // Verify this internship_id actually belongs to the student's application
    $verify = $pdo->prepare("
        SELECT id FROM ojt_applications
        WHERE student_id = ? AND internship_id = ?
        LIMIT 1
    ");
    $verify->execute([$student_id, $internship_id]);

    if (!$internship_id || !$verify->fetch()) {

        $_SESSION['error'] = 'Invalid or mismatched internship.';
        header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? ''));
        exit;
    }

    $steps_without_proof = ['hte_form'];

    if (!in_array($step_key, $steps_without_proof, true)) {

        if (
            !isset($_FILES['proof_file']) ||
            empty($_FILES['proof_file']['tmp_name'])
        ) {

            $_SESSION['error'] = 'Please attach proof before marking as done.';
            header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? ''));
            exit;
        }

        $file = $_FILES['proof_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {

            $_SESSION['error'] = 'Upload error. Please try again.';
            header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? ''));
            exit;
        }

        $max_size = 5 * 1024 * 1024; // 5 MB

        if ($file['size'] > $max_size) {

            $_SESSION['error'] = 'File must be under 5MB.';
            header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? ''));
            exit;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);

        $allowed_types = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf'
        ];

        if (!isset($allowed_types[$mime_type])) {

            $_SESSION['error'] = 'Only JPG, PNG, or PDF files are allowed.';
            header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? ''));
            exit;
        }

        $extension = $allowed_types[$mime_type];

    } else {

        $file_path = null;
        $file_stream = null;
        $mime_type = null;
    }

    // save the student's progress
    try {

        $upsert = $pdo->prepare("
    INSERT INTO student_progress
        (student_id, internship_id, step_key, is_done, updated_at, file_path, file_data, file_mime)
    VALUES
        (:student_id, :internship_id, :step_key, TRUE, NOW(), :file_path, :file_data, :file_mime)
    ON CONFLICT (student_id, internship_id, step_key)
    DO UPDATE SET
        is_done = TRUE,
        updated_at = NOW(),
        file_path = EXCLUDED.file_path,
        file_data = EXCLUDED.file_data,
        file_mime = EXCLUDED.file_mime
");
        $upsert->bindValue(':student_id', $student_id);
        $upsert->bindValue(':internship_id', $internship_id);
        $upsert->bindValue(':step_key', $step_key);
        $upsert->bindValue(':file_path', $file_path);
        $upsert->bindValue(':file_mime', $mime_type);
        if ($file_stream) {
            $upsert->bindParam(':file_data', $file_stream, PDO::PARAM_LOB);
        } else {
            $upsert->bindValue(':file_data', null, PDO::PARAM_NULL);
        }
        $upsert->execute();

        $_SESSION['success'] = 'Step marked as complete.';

    } catch (PDOException $e) {

        // Delete uploaded file if database operation fails
        if (file_exists($destination)) {
            unlink($destination);
        }

        $_SESSION['error'] = 'Unable to save progress. Please try again.';
    }
}


// OJT Application
elseif ($action === 'confirm_ojt') {

    $company_name = trim($_POST['company_name'] ?? '');
    $internship_id = (int) ($_POST['internship_id'] ?? 0);

    if ($company_name === '') {

        $_SESSION['error'] = 'Please enter your company name.';

    } elseif (!$internship_id) {

        $_SESSION['error'] = 'Invalid internship.';

    } else {

        $checkStmt = $pdo->prepare("
            SELECT id, status
            FROM ojt_applications
            WHERE student_id = ?
              AND internship_id = ?
            LIMIT 1
        ");

        $checkStmt->execute([
            $student_id,
            $internship_id
        ]);

        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {

            $insertStmt = $pdo->prepare("
                INSERT INTO ojt_applications
                    (
                        student_id,
                        internship_id,
                        company_name,
                        submitted_at,
                        status
                    )
                VALUES
                    (
                        ?,
                        ?,
                        ?,
                        CURRENT_TIMESTAMP,
                        'pending'
                    )
            ");

            $insertStmt->execute([
                $student_id,
                $internship_id,
                $company_name
            ]);

            $_SESSION['success'] =
                'Your OJT application has been submitted for adviser approval.';

        } else {

            $_SESSION['error'] =
                "You have already submitted an application " .
                "(status: {$existing['status']}).";
        }
    }
}


/*
|--------------------------------------------------------------------------
| SUBMIT HTE SUPERVISOR
|--------------------------------------------------------------------------
*/ elseif ($action === 'submit_hte_supervisor') {

    $full_name = trim($_POST['sup_full_name'] ?? '');
    $email = trim($_POST['sup_email'] ?? '');
    $contact = trim($_POST['sup_contact'] ?? '');
    $company = trim($_POST['sup_company'] ?? '');
    $int_id = (int) ($_POST['internship_id'] ?? 0);

    if ($full_name === '') {

        $_SESSION['error'] =
            'Supervisor full name is required.';

    } else {

        try {

            $upsert = $pdo->prepare("
                INSERT INTO student_hte_supervisor_submissions
                    (
                        student_id,
                        internship_id,
                        full_name,
                        email,
                        contact_number,
                        company_name,
                        status
                    )
                VALUES
                    (?, ?, ?, ?, ?, ?, 'pending')

                ON CONFLICT (student_id)

                DO UPDATE SET
                    internship_id = EXCLUDED.internship_id,
                    full_name = EXCLUDED.full_name,
                    email = EXCLUDED.email,
                    contact_number = EXCLUDED.contact_number,
                    company_name = EXCLUDED.company_name,
                    status = 'pending',
                    submitted_at = NOW()
            ");

            $upsert->execute([
                $student_id,
                $int_id,
                $full_name,
                $email,
                $contact,
                $company
            ]);

            $_SESSION['success'] =
                'Supervisor details submitted. Your coordinator will review shortly.';

        } catch (PDOException $e) {

            $_SESSION['error'] =
                'Unable to submit supervisor details. Please try again.';
        }
    }
}


// cancel Application
elseif ($action === 'cancel_application') {

    $application_id = (int) ($_POST['application_id'] ?? 0);

    if (!$application_id) {

        $_SESSION['error'] = 'Invalid application.';
        header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? ''));
        exit;
    }

    $checkStmt = $pdo->prepare("
        SELECT internship_id
        FROM ojt_applications
        WHERE id = ?
          AND student_id = ?
        LIMIT 1
    ");

    $checkStmt->execute([
        $application_id,
        $student_id
    ]);

    $application_internship_id = $checkStmt->fetchColumn();

    if ($application_internship_id === false) {

        $_SESSION['error'] = 'Application not found.';
        header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? ''));
        exit;
    }

    $pdo->prepare("
        DELETE FROM ojt_applications
        WHERE id = ?
          AND student_id = ?
    ")->execute([
                $application_id,
                $student_id
            ]);

    if ($application_internship_id) {

        $filesStmt = $pdo->prepare("
            SELECT file_path
            FROM student_progress
            WHERE student_id = ?
              AND internship_id = ?
              AND file_path IS NOT NULL
        ");
        $filesStmt->execute([$student_id, $application_internship_id]);

        foreach ($filesStmt->fetchAll(PDO::FETCH_COLUMN) as $file_path) {
            $full_path = __DIR__ . '/' . $file_path;
            if (is_file($full_path)) {
                unlink($full_path);
            }
        }

        $pdo->prepare("
            DELETE FROM student_progress
            WHERE student_id = ?
              AND internship_id = ?
        ")->execute([$student_id, $application_internship_id]);

        $pdo->prepare("
            DELETE FROM ojt_hours
            WHERE user_id  = ? AND user_type = 'student'
        ")->execute([
                    $student_id
                ]);
    }

    $pdo->prepare("
        DELETE FROM student_hte_supervisor_submissions
        WHERE student_id = ?
    ")->execute([
                $student_id
            ]);

    $_SESSION['success'] =
        'Application cancelled and progress reset.';

    header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? ''));
    exit;
} elseif ($action === 'delete-weekly-report') {

    $report_id = (int) ($_POST['report_id']);

    if (!$report_id) {
        $_SESSION['error'] = 'Invalid report.';
        header('Location: message.php?section=progress_report&room_id=' . urlencode($current_room_id ?? ''));
        exit;
    }

    $checkStmt = $pdo->prepare("
        SELECT id
        FROM weekly_reports
        WHERE id = ?
          AND student_id = ?
        LIMIT 1
        ");
    $checkStmt->execute([$report_id, $student_id]);
    $report = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        $_SESSION['error'] = 'Report not found.';
        header('Location: message.php?section=progress_report&room_id=' . urlencode($current_room_id ?? ''));
        exit;
    }

    $pdo->prepare("
    DELETE FROM weekly_reports
    where id = ? AND student_id = ?
    ")->execute([$report_id, $student_id]);

    $full_path = __DIR__ . '/' . $report['wr_filepath'];
    if (is_file($full_path)) {
        unlink($full_path);
    }

    $_SESSION['success'] = 'Weekly report deleted.';

    header('Location: message.php?section=progress_report&room_id=' . urlencode($current_room_id ?? ''));
    exit;

} else {

    $_SESSION['error'] = 'Unknown action.';
}

header('Location: message.php?section=application&room_id=' . urlencode($current_room_id ?? '' ?? ''));
exit;