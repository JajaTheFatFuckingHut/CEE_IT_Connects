<?php
session_start();
require 'db.php';
// only superadmin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    $_SESSION['error'] = "Invalid role. Please log in using a system admin account.";
    header("Location: login-ui.php");
    exit();
}
function formatSection(?string $key): string
{
    if (!$key)
        return '';
    [$p, $y, $s] = array_pad(explode('|', $key), 3, '');
    return ucwords($p) . " {$y}-{$s}";
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // for assigning a section to an adviser

    if (isset($_POST['assign_section'])) {
        $sy = $_POST['school_year'] ?? '';
        if (!preg_match('/^\d{4}-\d{4}$/', $sy)) {
            $_SESSION['error'] = "Invalid school year.";
            header("Location: superadmin.php");
            exit;
        }

        $adviser_id = (int) ($_POST['adviser_id'] ?? 0);
        $parts = array_map('trim', explode('|', $_POST['section'] ?? ''));

        if (!$adviser_id || count($parts) !== 2 || in_array('', $parts, true)) {
            $_SESSION['error'] = "Please select an adviser and a section.";
            header("Location: superadmin.php");
            exit;
        }
        [$year, $section] = $parts;
        $year = (int) $year;
        $section = (int) $section;

        // must be an internship adviser
        $advStmt = $pdo->prepare("SELECT full_name FROM advisers WHERE id = ? AND role = 'internship_adviser'");
        $advStmt->execute([$adviser_id]);
        $adviserName = $advStmt->fetchColumn();
        if (!$adviserName) {
            $_SESSION['error'] = "Invalid adviser.";
            header("Location: superadmin.php");
            exit;
        }

        // section must be within the base amount for that year level
        $cfg = $pdo->prepare("SELECT section_count FROM section_settings WHERE school_year = ? AND year_level = ?");
        $cfg->execute([$sy, $year]);
        $max = (int) $cfg->fetchColumn();
        if ($section < 1 || $section > $max) {
            $_SESSION['error'] = "That section is outside the configured range for year {$year}.";
            header("Location: superadmin.php");
            exit;
        }
        $takenStmt = $pdo->prepare("
            SELECT id FROM rooms
            WHERE adviser_id IS NOT NULL AND is_archived = FALSE
            AND school_year = :sy
            AND CAST(year_level AS TEXT) = :year AND section = :section
        ");
        $takenStmt->execute([':sy' => $sy, ':year' => (string) $year, ':section' => (string) $section]);
        if ($takenStmt->fetch()) {
            $_SESSION['error'] = "That section already has an adviser.";
            header("Location: superadmin.php");
            exit;
        }
        $step = 'start';

        try {
            $pdo->beginTransaction();
            $step = '1 create room';
            // 1. create the new room
            $roomName = "Year {$year}-{$section} Room";
            $roomStmt = $pdo->prepare("
            INSERT INTO rooms (room_name, section, year_level, school_year, adviser_id, is_archived, created_at)
            VALUES (:name, :section, :year, :sy, :adviser, FALSE, NOW())
            RETURNING id
        ");
            $roomStmt->execute([
                ':name' => $roomName,
                ':section' => (string) $section,
                ':year' => $year,
                ':sy' => $sy,
                ':adviser' => $adviser_id
            ]);
            $new_room_id = (int) $roomStmt->fetchColumn();
            $pdo->prepare("
                INSERT INTO room_members (room_id, user_id, user_type)
                VALUES (?, ?, 'adviser')
            ")->execute([$new_room_id, $adviser_id]);

            $step = '2 delete old memberships';
            $err = $pdo->errorInfo();
            if (!empty($err[0]) && $err[0] !== '00000') {
                throw new Exception("Step [{$step}] SQL error: " . print_r($err, true));
            }
            // 2. delete these students' memberships from other internship-adviser rooms
            $pdo->prepare("
            DELETE FROM room_members
            WHERE user_type = 'student'
              AND room_id <> :room
              AND room_id IN (
                  SELECT r.id FROM rooms r
                  JOIN advisers a ON a.id = r.adviser_id
                  WHERE a.role = 'internship_adviser'
              )
              AND user_id IN (
                  SELECT id FROM students
                  WHERE CAST(year_level AS TEXT) = :year AND CAST(section AS TEXT) = :section
              )
        ")->execute([':room' => $new_room_id, ':year' => (string) $year, ':section' => (string) $section]);
            $step = '3 add students';
            $err = $pdo->errorInfo();
            if (!empty($err[0]) && $err[0] !== '00000') {
                throw new Exception("Step [{$step}] SQL error: " . print_r($err, true));
            }
            // 3. add every student of that year level + section to the new room
            $ins = $pdo->prepare("
                INSERT INTO room_members (room_id, user_id, user_type)
                SELECT CAST(:room AS INTEGER), s.id, 'student'
                FROM students s
                WHERE CAST(s.year_level AS TEXT) = :year AND CAST(s.section AS TEXT) = :section
            ");
            $ins->execute([':room' => $new_room_id, ':year' => (string) $year, ':section' => (string) $section]);
            $added = $ins->rowCount();
            $step = '4 audit log';
            $err = $pdo->errorInfo();
            if (!empty($err[0]) && $err[0] !== '00000') {
                throw new Exception("Step [{$step}] SQL error: " . print_r($err, true));
            }
            // 4. audit log
            $pdo->prepare("
            INSERT INTO audits (user_id, roles, activity, activity_date)
            VALUES (:user_id, :roles, :activity, NOW())
        ")->execute([
                        ':user_id' => $_SESSION['user_id'],
                        ':roles' => 'superadmin',
                        ':activity' => "Assigned year {$year} section {$section} to adviser ID {$adviser_id} (room ID {$new_room_id})"
                    ]);
            $err = $pdo->errorInfo();
            if (!empty($err[0]) && $err[0] !== '00000') {
                throw new Exception("Step [{$step}] SQL error: " . print_r($err, true));
            }
            $pdo->commit();
            $_SESSION['success'] = "Room created. {$added} student(s) moved into {$roomName}.";
        } catch (Exception $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $_SESSION['error'] = "Failed at step [{$step}]: " . $e->getMessage();
        }

        header("Location: superadmin.php");
        exit;
    }

    // ---- data for the page ----
    $sectionSettings = $pdo->query("
    SELECT year_level, section_count FROM section_settings ORDER BY year_level
")->fetchAll(PDO::FETCH_ASSOC);

    $adviserList = $pdo->query("
    SELECT id, full_name, email
    FROM advisers
    WHERE role = 'internship_adviser'
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

    // sections already handled, grouped by adviser
    $assignedSections = [];
    $takenKeys = [];
    $rows = $pdo->query("
    SELECT adviser_id, year_level, section
    FROM rooms
    WHERE adviser_id IS NOT NULL AND is_archived = FALSE
      AND section IS NOT NULL AND section <> '' AND year_level IS NOT NULL
    ORDER BY year_level, section
")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $assignedSections[$r['adviser_id']][] = "Year {$r['year_level']}-{$r['section']}";
        $takenKeys[] = "{$r['year_level']}|{$r['section']}";
    }

    // open sections = 1..N for each year level, minus the ones already taken
    $openSections = [];
    foreach ($sectionSettings as $cfg) {
        for ($n = 1; $n <= (int) $cfg['section_count']; $n++) {
            if (!in_array("{$cfg['year_level']}|{$n}", $takenKeys, true)) {
                $openSections[] = ['year_level' => $cfg['year_level'], 'section' => $n];
            }
        }
    }

    // ---- data for the table ----
    $adviserList = $pdo->query("
    SELECT id, full_name, email
    FROM advisers
    WHERE role = 'internship_adviser'
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

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
        // $id = $_POST['id'] ?? null;
        // $source = $_POST['source'] ?? '';
        // $title = $_POST['title'] ?? '';

        $internship_id = null;

        $name = $_POST['name'];
        $email = $_POST['email'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $department = $_POST['department'];
        $role = $_POST['role'];
        // $title = $_POST['title'];

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
        INSERT INTO advisers (full_name, email, password_hash, role, created_at, department)
        VALUES (:name, :email, :password, :role, NOW(), :department)
        ");

        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            // 'title' => $title,
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

        $stmt = $pdo->prepare("UPDATE $source SET is_archived = TRUE WHERE id = ?");
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
    if (isset($_POST['save_section_settings'])) {
        error_log('POST KEYS: ' . implode(',', array_keys($_POST)));
        $sy = $_POST['school_year'] ?? '';
        if (!preg_match('/^\d{4}-\d{4}$/', $sy)) {
            $_SESSION['error'] = "Invalid school year.";
            header("Location: superadmin.php");
            exit;
        }
        $counts = $_POST['section_count'] ?? [];

        try {
            $pdo->beginTransaction();
            $up = $pdo->prepare("
            INSERT INTO section_settings (school_year, year_level, section_count)
            VALUES (:sy, :y, :c)
            ON CONFLICT (school_year, year_level) DO UPDATE SET section_count = EXCLUDED.section_count
        ");
            for ($y = 1; $y <= 4; $y++) {
                $c = max(0, min(50, (int) ($counts[$y] ?? 0)));
                $up->execute([':sy' => $sy, ':y' => $y, ':c' => $c]);
            }
            $pdo->prepare("
            INSERT INTO audits (user_id, roles, activity, activity_date)
            VALUES (:user_id, :roles, :activity, NOW())
        ")->execute([
                        ':user_id' => $_SESSION['user_id'],
                        ':roles' => 'superadmin',
                        ':activity' => "Updated section counts for school year {$sy}"
                    ]);
            $pdo->commit();
            $_SESSION['success'] = "Section counts saved for {$sy}.";

            for ($y = 1; $y <= 4; $y++) {
                $c = max(0, min(50, (int) ($counts[$y] ?? 0)));
                $ok = $up->execute([':sy' => $sy, ':y' => $y, ':c' => $c]);
                if (!$ok) {
                    die('INSERT FAILED: ' . print_r($up->errorInfo(), true));
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $_SESSION['error'] = "Could not save section counts: " . $e->getMessage();
        }
        header("Location: superadmin.php?sy=" . urlencode($sy));
        exit;
    }
    if (isset($_POST['add_school_year'])) {
        try {
            // latest existing school year, e.g. 2026-2027
            $latest = $pdo->query("SELECT MAX(school_year) FROM school_years")->fetchColumn();

            if ($latest) {
                $start = (int) substr($latest, 0, 4) + 1;
            } else {
                $m = (int) date('n');
                $y = (int) date('Y');
                $start = $m >= 6 ? $y : $y - 1;
            }
            $newSY = $start . '-' . ($start + 1);

            $pdo->prepare("INSERT INTO school_years (school_year) VALUES (?) ON CONFLICT DO NOTHING")
                ->execute([$newSY]);

            $pdo->prepare("
            INSERT INTO audits (user_id, roles, activity, activity_date)
            VALUES (:user_id, :roles, :activity, NOW())
        ")->execute([
                        ':user_id' => $_SESSION['user_id'],
                        ':roles' => 'superadmin',
                        ':activity' => "Added school year {$newSY}"
                    ]);

            $_SESSION['success'] = "School year {$newSY} added.";
            header("Location: superadmin.php?sy=" . urlencode($newSY) . "#section_settings");
        } catch (Exception $e) {
            $_SESSION['error'] = "Could not add school year: " . $e->getMessage();
            header("Location: superadmin.php");
        }
        exit;
    }

    if (isset($_POST['restore'])) {

        $tables = ['students' => 'students', 'admins' => 'admins', 'advisers' => 'advisers'];
        $table = $tables[$_POST['source'] ?? ''] ?? null;
        $userId = (int) ($_POST['user_id'] ?? 0);

        if (!$table || !$userId) {
            $_SESSION['error'] = "Invalid user.";
            header("Location: superadmin.php");
            exit;
        }

        try {
            $pdo->prepare("UPDATE {$table} SET is_archived = FALSE WHERE id = :id")
                ->execute([':id' => $userId]);

            $pdo->prepare("
            INSERT INTO audits (user_id, roles, activity, activity_date)
            VALUES (:admin, :roles, :activity, NOW())
        ")->execute([
                        ':admin' => $_SESSION['user_id'],
                        ':roles' => 'superadmin',
                        ':activity' => "Restored {$table} user ID {$userId}",
                    ]);

            $_SESSION['success'] = "User restored.";
        } catch (Exception $e) {
            $_SESSION['error'] = "Could not restore user: " . $e->getMessage();
        }

        header("Location: superadmin.php#archived");
        exit;
    }
}

?>