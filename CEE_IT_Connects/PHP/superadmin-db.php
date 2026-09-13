<?php
session_start();
require 'db.php';

// only superadmin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    $_SESSION['error'] = "Invalid role. Please log in using a system admin account.";
    header("Location: login-ui.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // for create admin and adviser, we will check if the delete button was clicked first to avoid conflicts
    //admin 
    if (isset($_POST['create-admin'])) {

        $name = $_POST['name'];
        $email = $_POST['email'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role'];

        $allowed = ['internship_admin', 'cma'];

        if (!in_array($role, $allowed)) {
            die("Invalid role");
        }

        $stmt = $pdo->prepare("
        INSERT INTO admins (name, email, password, role)
        VALUES (:name, :email, :password, :role)
        ");

        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role
        ]);
        $newAdminId = $pdo->lastInsertId();

        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
            VALUES (?, 'admin', ?, ?, ?, FALSE, NOW())
        ");

        $notifStmt->execute([
            $newAdminId,
            'Welcome to CEE IT Connects',
            'Your admin account has been created. Role: ' . $role . '.',
            'internship-ui.php'
        ]);

        $allAdmins = $pdo->query("SELECT id FROM admins WHERE id != " . $newAdminId)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allAdmins as $admin) {
            $notifStmt->execute([
                $admin['id'],
                'New Admin Account Created',
                'A new ' . $role . ' account has been created for ' . $name . '.',
                'superadmin.php'
            ]);
        }

        $stmtActivity = $pdo->prepare("INSERT INTO audits (user_id, roles, activity, activity_date) VALUES (:user_id, :roles, :activity, NOW())");
        $stmtActivity->execute([
            ':user_id' => $_SESSION['user_id'],
            ':roles' => $_SESSION['role'],
            ':activity' => 'Created new admin: ' . $name
        ]);

        $_SESSION['success'] = "Successfully created an account.";
        header("Location: superadmin.php?success=1");
        exit();
    }

    //adviser
    if (isset($_POST['create-adviser'])) {
        $id = $_POST['id'] ?? null;
        $source = $_POST['source'] ?? '';
        $title = $_POST['title'] ?? '';

        if ($id === null || $source === '' || $title === '') {
            die('Missing required form data.');
        }
        $internship_id = null;

        $name = $_POST['name'];
        $email = $_POST['email'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $department = $_POST['department'];
        $role = $_POST['role'];
        $title = $_POST['title'];

        if ($role === 'HTE_adviser') {
            $internship_id = $_POST['internship_id'] ?? null;

            if (!$internship_id) {
                die("Please select an internship.");
            }
        }


        $allowed = ['internship_adviser', 'HTE_adviser'];

        if (!in_array($role, $allowed)) {
            die("Invalid role");
        }

        $stmt = $pdo->prepare("
        INSERT INTO advisers (full_name, email, password_hash, role, title, created_at, department)
        VALUES (:name, :email, :password, :role, :title, NOW(), :department)
        ");

        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'title' => $title,
            'department' => $department
        ]);

        $newAdviserId = $pdo->lastInsertId();
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
            VALUES (?, 'adviser', ?, ?, ?, FALSE, NOW())
        ");
        if ($role === 'HTE_adviser') {
            $notifStmt->execute([
                $newAdviserId,
                'Welcome to CEE IT Connects!',
                'Your adviser account has been created. Role: ' . $role . '.',
                'hte-ui.php'
            ]);
        } else {
            $notifStmt->execute([
                $newAdviserId,
                'Welcome to CEE IT Connects!',
                'Your adviser account has been created. Role: ' . $role . '.',
                'ojt-rooms.php'
            ]);
        }

        $adminNotif = $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
            VALUES (?, 'admin', ?, ?, ?, FALSE, NOW())
        ");
        $allAdmins = $pdo->query("SELECT id FROM admins WHERE role = 'superadmin'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allAdmins as $admin) {
            $adminNotif->execute([
                $admin['id'],
                'New Adviser Account Created',
                'A new ' . $role . ' account has been created for ' . $name . '.',
                'superadmin.php'
            ]);
        }

        $allAdmins = $pdo->query("SELECT id FROM admins WHERE role = 'internship_admin'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allAdmins as $admin) {
            $adminNotif->execute([
                $admin['id'],
                'New Adviser Account Created',
                'A new ' . $role . ' account has been created for ' . $name . '.',
                'intership-ui.php'
            ]);
        }

        $stmtActivity = $pdo->prepare("INSERT INTO audits (user_id, roles, activity, activity_date) VALUES (:user_id, :roles, :activity, NOW())");
        $stmtActivity->execute([
            ':user_id' => $_SESSION['user_id'],
            ':roles' => $_SESSION['role'],
            ':activity' => 'Created new adviser: ' . $name
        ]);

        $_SESSION['success'] = "Adviser successfully created!";
        header("Location: superadmin.php?success=1");
        exit();
    }

    if (isset($_POST['delete'])) {
        $id = $_POST['id'];
        $source = $_POST['source'];

        $allowedTables = ['students', 'admins', 'advisers'];
        if (!in_array($source, $allowedTables)) {
            die('invalid table');
        }

        if ($source === 'admins') {
            $checkstmt = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
            $checkstmt->execute([$id]);
            $user = $checkstmt->fetch();

            if ($user && $user['role'] === 'superadmin') {
                die("Cannot delete another superadmin");
            }
        }
        if ($source === 'admins') {
            $fetchStmt = $pdo->prepare("SELECT name, role FROM admins WHERE id = ?");
        } elseif ($source === 'advisers') {
            $fetchStmt = $pdo->prepare("SELECT full_name AS name, role FROM advisers WHERE id = ?");
        } elseif ($source === 'students') {
            $fetchStmt = $pdo->prepare("SELECT full_name AS name, 'student' AS role FROM students WHERE id = ?");
            $cleanStmt = $pdo->prepare("DELETE FROM room_members WHERE user_id = ?");
            $cleanStmt->execute([$id]);
        }

        $fetchStmt->execute([$id]);
        $deletedUser = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("DELETE FROM $source WHERE id = ?");
        $stmt->execute([$id]);

        $stmtActivity = $pdo->prepare("INSERT INTO audits (user_id, roles, activity, activity_date) VALUES (:user_id, :roles, :activity, NOW())");
        $stmtActivity->execute([
            ':user_id' => $_SESSION['user_id'],
            ':roles' => $_SESSION['role'],
            ':activity' => 'Deleted ' . $deletedUser['role'] . ' account: ' . $deletedUser['name']
        ]);

        // Notify superadmin(s)
        $superAdmins = $pdo->query("SELECT id FROM admins WHERE role = 'superadmin'")->fetchAll(PDO::FETCH_ASSOC);
        $superNotif = $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
            VALUES (?, 'admin', ?, ?, ?, FALSE, NOW())
        ");
        foreach ($superAdmins as $admin) {
            $superNotif->execute([
                $admin['id'],
                'Account Deleted',
                'The ' . $deletedUser['role'] . ' account of ' . $deletedUser['name'] . ' has been deleted.',
                'superadmin.php'
            ]);
        }

        // Notify internship_admin(s)
        $internshipAdmins = $pdo->query("SELECT id FROM admins WHERE role = 'internship_admin'")->fetchAll(PDO::FETCH_ASSOC);
        $internshipNotif = $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message, link, is_read, created_at)
            VALUES (?, 'admin', ?, ?, ?, FALSE, NOW())
        ");
        foreach ($internshipAdmins as $admin) {
            $internshipNotif->execute([
                $admin['id'],
                'Account Deleted',
                'The ' . $deletedUser['role'] . ' account of ' . $deletedUser['name'] . ' has been removed from the system.',
                'internship-ui.php'
            ]);
        }

        $_SESSION['success'] = "User " . $deletedUser['name'] . " has been deleted.";
        header("Location: superadmin.php?deleted=1");
        exit();
    }
    if (isset($_POST['action']) && in_array($_POST['action'], ['approve', 'reject'])) {

        $action = $_POST['action'];
        $submission_id = (int) ($_POST['submission_id'] ?? 0);
        $student_id = (int) ($_POST['student_id'] ?? 0);
        $room_id = (int) ($_POST['room_id'] ?? 0);
        $redirect = "superadmin.php?section=supervisor_requests";

        $stmt = $pdo->prepare("SELECT * FROM student_hte_supervisor_submissions WHERE id = ?");
        $stmt->execute([$submission_id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            $_SESSION['error'] = 'Submission not found.';
            header("Location: $redirect");
            exit;
        }

        if ($action === 'approve') {

            $tempPassword = 12345;//bin2hex(random_bytes(5));
            $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

            // Insert into the shared advisers table as an HTE supervisor role
            // Change 'hte_supervisor' below to match your actual adviser_role enum value
            $insertStmt = $pdo->prepare("
    INSERT INTO advisers
        (full_name, email, password_hash, role, internship_id, created_at)
    VALUES (?, ?, ?, 'HTE_adviser', ?, NOW())
");
            $insertStmt->execute([
                $sub['full_name'],
                $sub['email'],
                $hashedPassword,
                $sub['internship_id'],
            ]);

            $updateStmt = $pdo->prepare("
                UPDATE student_hte_supervisor_submissions
                SET status = 'approved', reviewed_by = ?, reviewed_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$adviser_id, $submission_id]);

            $_SESSION['success'] =
                "Supervisor account created for {$sub['full_name']}. Temporary password: {$tempPassword}";

            header("Location: $redirect");
            exit;
        } else {

            $note = trim($_POST['rejection_note'] ?? '');

            $updateStmt = $pdo->prepare("
                UPDATE student_hte_supervisor_submissions
                SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_note = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$adviser_id, $note, $submission_id]);

            $_SESSION['success'] = 'Submission returned to student.';
        }

        header("Location: $redirect");
        exit;
    }
    if (isset($_POST['save_program_hours'])) {
        $programs = $_POST['program'] ?? [];
        $hours = $_POST['required_hours'] ?? [];

        $updateStmt = $pdo->prepare("
        UPDATE internships
        SET required_hours = ?
        WHERE program = ?
    ");

        $updatedCount = 0;
        foreach ($programs as $i => $prog) {
            $prog = trim($prog);
            $hrs = max(1, (int) ($hours[$i] ?? 486));
            if ($prog !== '') {
                $updateStmt->execute([$hrs, $prog]);
                $updatedCount += $updateStmt->rowCount();
            }
        }

        $pdo->prepare("
        INSERT INTO audits (user_id, roles, activity, activity_date)
        VALUES (?, 'superadmin', ?, NOW())
    ")->execute([
                    $_SESSION['user_id'],
                    "Updated required OJT hours for " . count($programs) . " program(s), affecting {$updatedCount} internship(s)"
                ]);

        $_SESSION['success'] = "Required hours updated for {$updatedCount} internship(s).";
        header("Location: superadmin.php?section=ojt_hours");
        exit;
    }
}

?>

if (isset($_POST['save_program_hours'])) {

$programs = $_POST['program'] ?? [];
$hours = $_POST['required_hours'] ?? [];

$updateStmt = $pdo->prepare("
UPDATE internships
SET required_hours = ?
WHERE program = ?
");

$updatedCount = 0;

foreach ($programs as $i => $prog) {

$prog = trim($prog);

$hrs = max(
1,
(int) ($hours[$i] ?? 486)
);

if ($prog !== '') {

$updateStmt->execute([
$hrs,
$prog
]);

$updatedCount += $updateStmt->rowCount();
}
}

$pdo->prepare("
INSERT INTO audits (
user_id,
roles,
activity,
activity_date
)
VALUES (?, 'superadmin', ?, NOW())
")->execute([
$_SESSION['user_id'],
"Updated required OJT hours for "
. count($programs)
. " program(s), affecting "
. $updatedCount
. " internship(s)"
]);

$_SESSION['success'] =
"Required hours updated for {$updatedCount} internship(s).";

header("Location: superadmin.php?section=ojt_hours");
exit;
}