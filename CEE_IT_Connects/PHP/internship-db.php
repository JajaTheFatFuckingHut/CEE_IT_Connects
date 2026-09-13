<?php
session_start();
require 'db.php';


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'internship_admin') {
    header("Location: login-ui.php?role=admin");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: internship-ui.php");
    exit();
}

$form_type = $_POST['form_type'] ?? '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if ($form_type === 'internship_posting') {
        // Handle internship posting
        $title = $_POST['title'] ?? '';
        $company = $_POST['company'] ?? '';
        // $duration = $_POST['year'] ?? '';
        $email = $_POST['email'] ?? '';
        $location = $_POST['location'] ?? '';
        $description = $_POST['description'] ?? '';
        $program = $_POST['program'] ?? '';
        $latitude = $_POST['latitude'] ?? null;
        $longitude = $_POST['longitude'] ?? null;
        $phonenumber = $_POST['phonenumber'] ?? '';
        $deadline = $_POST['deadline'] ?? null;
        $openTime = $_POST['openTime'] ?? null;
        $closeTime = $_POST['closeTime'] ?? null;
        $is_valenzuela_lgu = ($_POST['is_valenzuela_lgu'] ?? 'false') === 'true';

        $is_plv_ojt = ($_POST['is_plv_ojt'] ?? 'false') === 'true';
        $company_classification = $_POST['company_classification'] ?? null;

        $admin_id = $_SESSION['user_id'];
        $available = 'true';
        $allowed_programs = ['Information Technology', 'Civil Engineering', 'Electrical Engineering'];

        if (empty($title) || empty($company) || empty($description) || empty($program)) {
            echo "<script>alert('Title, Company, Description, and Program are required.'); window.history.back();</script>";
            exit;
        }
        if (!in_array($program, $allowed_programs)) {
            echo "<script>alert('Invalid program selection.'); window.history.back();</script>";
            exit;
        }


        try {
            $hoursStmt = $pdo->prepare("
                SELECT required_hours
                FROM internships
                WHERE program = :program
                LIMIT 1
            ");

            $hoursStmt->execute([
                'program' => $program
            ]);

            $required_hours = $hoursStmt->fetchColumn();

            $stmt = $pdo->prepare(
                "INSERT INTO internships (title, company, email, location, description, 
                program, latitude, longtitude, phone_numbers, available, time_open, time_close, 
                admin_id, is_plv_internal, is_valenzuela_lgu, required_hours, company_classification) 
                VALUES (:title, :company, :email, :location, :description, :program, :latitude, 
                :longtitude, :phonenumber, :available, :openTime, :closeTime, :admin_id, 
                :is_plv_ojt, :is_valenzuela_lgu, :required_hours, :company_classification)"
            );

            $stmt->execute([
                'title' => $title,
                'company' => $company,
                'email' => $email,
                'location' => $location,
                'description' => $description,
                'program' => $program,
                'latitude' => $latitude,
                'longtitude' => $longitude,
                'phonenumber' => $phonenumber,
                'available' => true,
                'openTime' => $openTime,
                'closeTime' => $closeTime,
                'admin_id' => $admin_id,
                'is_plv_ojt' => $is_plv_ojt,
                'is_valenzuela_lgu' => $is_valenzuela_lgu,
                'required_hours' => $required_hours,
                'company_classification' => $company_classification
            ]);

            // This is for notifying students about the new internship posting
            $stmtStudents = $pdo->query("SELECT id FROM students");
            $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
                VALUES (?, 'student', ?, ?, ?, FALSE, NOW())
            ");

            // MESSAGE CONTENT
            $notifTitle = "New Internship Posted";
            $notifMessage = "$title at $company is now available. Apply now!";

            foreach ($students as $student) {
                $notifStmt->execute([
                    $student['id'],
                    $notifTitle,
                    $notifMessage,
                    'applied-internship-programs.php'
                ]);
            }

            $stmtAudit = $pdo->prepare("
                INSERT INTO audits (user_id, roles, activity)
                VALUES (:user_id, :roles, :activity)
            ");

            $stmtAudit->execute([
                ':user_id' => $admin_id,
                ':roles' => $_SESSION['role'],
                ':activity' => 'Posted a new internship: ' . $title . ' at ' . $company
            ]);
            header("Location: internship-ui.php?success=1");
            exit();
        } catch (PDOException $e) {
            echo "<!DOCTYPE html>
                <html>
                <head>
                    <title>Database Error</title>
                </head>
                <body>
                    <h2>Database Error</h2>
                    <pre>" . htmlspecialchars($e->getMessage()) . "</pre>
                    <button onclick=\"window.location.href='internship-ui.php'\">
                        Return to Internship System
                    </button>
                    <?php
                    var_dump($is_valenzuela_lgu);
                    var_dump($is_plv_ojt);
                    ?>
                </body>
                </html>";
            exit();
        }
    }
    if ($form_type === 'announcement_posting') {
        // Handle announcement posting
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';

        try {
            $stmt = $pdo->prepare("INSERT INTO announcements (title, message, created_at, category) VALUES (:title, :message, NOW(), :category)");
            $stmt->execute([
                'title' => $title,
                'message' => $message,
                'category' => $_POST['category'] ?? ''
            ]);

            $stmtActivity = $pdo->prepare("INSERT INTO audits (user_id, roles, activity, activity_date) VALUES (:user_id, :roles, :activity, NOW())");
            $stmtActivity->execute([
                ':user_id' => $_SESSION['user_id'],
                ':roles' => $_SESSION['role'],
                ':activity' => 'Posted a new announcement: ' . $title
            ]);
        } catch (PDOException $e) {
            echo "<script>
                alert('Database error: " . addslashes($e->getMessage()) . "');
                window.history.back();
            </script>";
            exit;
        }

        $stmtStudents = $pdo->query("SELECT id FROM students");
        $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

        $notifTitle = "New Announcement Posted";
        $notifMessage = "A new announcement has been posted: $title";
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
            VALUES (?, 'student', ?, ?, ?, FALSE, NOW())
        ");

        foreach ($students as $student) {
            $notifStmt->execute([
                $student['id'],
                $notifTitle,
                $notifMessage,
                'announcement.php'
            ]);
        }

        $_SESSION['success'] = "New Announcement '$title' has been created.";
        header("Location: internship-ui.php?success=1");
        exit();
    }
    if ($form_type === 'send_feedback') {

        $bookmark_id = $_POST['bookmark_id'] ?? null;
        $feedback = trim($_POST['feedback'] ?? '');

        if (!$bookmark_id || !$feedback) {
            http_response_code(400);
            echo "Missing required fields.";
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
            SELECT ib.student_id, i.title, i.company, s.full_name
            FROM internship_bookmarks ib
            JOIN internships i ON i.id = ib.internship_id
            JOIN students s ON s.id = ib.student_id
            WHERE ib.id = ?
        ");
            $stmt->execute([$bookmark_id]);
            $bookmark = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$bookmark) {
                throw new Exception("Bookmark not found.");
            }

            $notifTitle = "Internship Interested Update";
            $notifMessage = "Your interest in {$bookmark['title']} at {$bookmark['company']} was rejected. Feedback: $feedback";
            $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
            VALUES (?, 'student', ?, ?, ?, FALSE, NOW())
            ");
            $notifStmt->execute([
                $bookmark['student_id'],
                $notifTitle,
                $notifMessage,
                'applied-internship-program.php'
            ]);


            $_SESSION['success'] = "Feedback to " . $bookmark['full_name'] . " has been sent.";
            $stmtDelete = $pdo->prepare("DELETE FROM internship_bookmarks WHERE id = ?");
            $stmtDelete->execute([$bookmark_id]);



            $stmtActivity = $pdo->prepare("INSERT INTO audits (user_id, roles, activity, activity_date) VALUES (:user_id, :roles, :activity, NOW())");
            $stmtActivity->execute([
                ':user_id' => $_SESSION['user_id'],
                ':roles' => $_SESSION['role'],
                ':activity' => 'Sent feedback for student ' . $bookmark['student_id'] .
                    ' regarding internship ' . $bookmark['title'] . ' at ' . $bookmark['company']
            ]);

            $pdo->commit();

            echo "success";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo $e->getMessage();
            exit;
        }
    }



    /*$stmt = $pdo->prepare("
    DELETE FROM internship_bookmarks
    WHERE id = ?
");

    $stmt->execute([$bookmark_id]);

    header("Location: internship-ui.php?removed=1");
    exit;
} */

    if (isset($_POST['delete_announcement'])) {
        $title = $_POST['title'] ?? '';
        $announcement_id = $_POST['announcement_id'];

        $stmt = $pdo->prepare("
    DELETE FROM announcements
    WHERE id = ?
    ");
        $stmt->execute([$announcement_id]);

        $stmtActivity = $pdo->prepare("INSERT INTO audits (user_id, roles, activity, activity_date) VALUES (:user_id, :roles, :activity, NOW())");
        $stmtActivity->execute([
            ':user_id' => $_SESSION['user_id'],
            ':roles' => $_SESSION['role'],
            ':activity' => 'Deleted the announcement: ' . $title
        ]);

        $stmtStudents = $pdo->query("SELECT id FROM students");
        $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

        $notifTitle = "Announcement Delete";
        $notifMessage = "The announcement {$_POST['title']} has been deleted.";
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
            VALUES (?, 'student', ?, ?, ?, FALSE, NOW())
            ");

        foreach ($students as $student) {
            $notifStmt->execute([
                $student['id'],
                $notifTitle,
                $notifMessage,
                'announcement.php'
            ]);
        }

        $_SESSION['success'] = "Announcement " . $_POST['title'] . " has been deleted.";
        header("location: internship-ui.php?removed=1");
        exit;
    }

    if (isset($_POST['edit_announcement'])) {
        $title = $_POST['title'] ?? '';
        $announcement_id = $_POST['announcement_id'];

        $stmt = $pdo->prepare("UPDATE announcements SET title = ?, message = ?, category = ? WHERE id = ?");
        $stmt->execute([
            $_POST['title'] ?? '',
            $_POST['message'] ?? '',
            $_POST['category'] ?? '',
            $announcement_id
        ]);

        $stmtStudents = $pdo->query("SELECT id FROM students");
        $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

        $notifTitle = "Announcement Update";
        $notifMessage = "The announcement {$_POST['title']} has been updated.";
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
            VALUES (?, 'student', ?, ?, ?, FALSE, NOW())
            ");

        foreach ($students as $student) {
            $notifStmt->execute([
                $student['id'],
                $notifTitle,
                $notifMessage,
                'announcement.php'
            ]);
        }

        $stmtActivity = $pdo->prepare("INSERT INTO audits (user_id, roles, activity, activity_date) VALUES (:user_id, :roles, :activity, NOW())");
        $stmtActivity->execute([
            ':user_id' => $_SESSION['user_id'],
            ':roles' => $_SESSION['role'],
            ':activity' => 'Edited the announcement: ' . $title
        ]);

        $_SESSION['success'] = "Announcement " . $_POST['title'] . " has been updated succesfully!";
        header("location: internship-ui.php?updated=1");
        exit;
    }

    if (($_POST['form_type'] ?? '') === 'document_availability') {
        $internshipId = $_POST['internship_id'] ?? null;
        $mou = isset($_POST['mou_available']) ? 1 : 0;
        $rl = isset($_POST['recommendation_letter_available']) ? 1 : 0;
        $waiver = isset($_POST['waiver_available']) ? 1 : 0;

        if (!$internshipId) {
            $_SESSION['error'] = "Please select an internship.";
        } else {
            $stmt = $pdo->prepare("
            INSERT INTO internship_document_availability
                (internship_id, mou_available, recommendation_letter_available, waiver_available, updated_at)
            VALUES (:internship_id, :mou1, :rl1, :waiver1, NOW())
            ON CONFLICT (internship_id) DO UPDATE
            SET mou_available = :mou2,
                recommendation_letter_available = :rl2,
                waiver_available = :waiver2,
                updated_at = NOW()
        ");
            $stmt->execute([
                ':internship_id' => $internshipId,
                ':mou1' => $mou,
                ':rl1' => $rl,
                ':waiver1' => $waiver,
                ':mou2' => $mou,
                ':rl2' => $rl,
                ':waiver2' => $waiver,
            ]);
            $_SESSION['success'] = "Document availability updated.";
        }
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    if (($_POST['form_type'] ?? '') === 'mou_upload') {

        if (empty($_POST['internship_id']) || !ctype_digit($_POST['internship_id'])) {
            $_SESSION['error'] = 'Please select a valid internship.';
            header('Location: internship-ui.php#upload_mou');
            exit;
        }
        $internship_id = (int) $_POST['internship_id'];

        if (empty($_FILES['mou_file']['name'])) {
            $_SESSION['error'] = 'Please choose a file to upload.';
            header('Location: internship-ui.php#upload_mou');
            exit;
        }

        $file = $_FILES['mou_file'];

        // Basic validation
        $allowedExt = ['pdf'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt) || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
            $_SESSION['error'] = 'Invalid file. Must be a PDF under 5MB.';
            header('Location: internship-ui.php#upload_mou');
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/mou/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'mou_' . $internship_id . '_' . time() . '.' . $ext;
        $destPath = $uploadDir . $filename;
        $webPath = 'uploads/mou/' . $filename; // path stored in DB, used in <a href>

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $_SESSION['error'] = 'Failed to save file.';
            header('Location: internship-ui.php#upload_mou');
            exit;
        }

        $stmt = $pdo->prepare("
        INSERT INTO mou_uploads (file_path, internship_id, updated_at)
        VALUES (:file_path, :internship_id, NOW())
    ");
        $stmt->execute([
            ':file_path' => $webPath,
            ':internship_id' => $internship_id,
        ]);

        $_SESSION['success'] = 'MOU uploaded successfully.';
        header('Location: internship-ui.php#upload_mou');
        exit;
    }

    if (($_POST['form_type'] ?? '') === 'mou_delete') {

        if (empty($_POST['mou_id']) || !ctype_digit($_POST['mou_id'])) {
            header('Location: internship-ui.php#upload_mou');
            exit;
        }
        $mou_id = (int) $_POST['mou_id'];

        // Fetch file path first so we can remove it from disk
        $stmt = $pdo->prepare("SELECT file_path FROM mou_uploads WHERE id = ?");
        $stmt->execute([$mou_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && file_exists(__DIR__ . '/' . $row['file_path'])) {
            unlink(__DIR__ . '/' . $row['file_path']);
        }

        $del = $pdo->prepare("DELETE FROM mou_uploads WHERE id = ?");
        $del->execute([$mou_id]);

        $_SESSION['success'] = 'MOU deleted.';
        header('Location: internship-ui.php#upload_mou');
        exit;
    }
}
?>