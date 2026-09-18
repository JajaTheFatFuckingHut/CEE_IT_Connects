<?php
$page = 'profile';
require 'auth.php';
require 'db.php';

$user_id = (int) $_SESSION['user_id'];
$roleRaw = trim($_SESSION['role'] ?? '');
$roleLower = strtolower($roleRaw);

$roleMap = [
    'student' => 'student',
    'internship_adviser' => 'adviser',
    'hte_adviser' => 'adviser',
    'adviser' => 'adviser',
    'internship_admin' => 'admin',
    'superadmin' => 'admin',
    'admin' => 'admin',
];
$userType = $roleMap[$roleLower] ?? 'student';

// Fetch the correct row 
function getUser(PDO $pdo, string $type, int $id): array
{
    $sql = match ($type) {
        'student' => "SELECT * FROM students WHERE id = ?",
        'adviser' => "SELECT * FROM advisers WHERE id = ?",
        default => "SELECT * FROM admins   WHERE id = ?",
    };
    $s = $pdo->prepare($sql);
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}

// For Post
$success = (isset($_GET['success']) && empty($_POST)) ? "Data successfully updated!" : '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['upload_docs'])) {
    $newEmail = trim($_POST['email'] ?? '');
    $newPass = trim($_POST['new_password'] ?? '');
    $confirmPass = trim($_POST['confirm_password'] ?? '');

    // ── Common validation ──────────────────────────────────────────────
    if (empty($newEmail))
        $errors[] = "Email is required.";
    elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL))
        $errors[] = "Email format is invalid.";

    if ($newPass !== '') {
        if (strlen($newPass) < 6)
            $errors[] = "Password must be at least 6 characters.";
        elseif ($newPass !== $confirmPass)
            $errors[] = "Passwords do not match.";
    }

    // Student Conf
    if ($userType === 'student') {
        if (empty(trim($_POST['full_name'] ?? '')))
            $errors[] = "Full name is required.";

        if (empty(trim($_POST['student_id'] ?? '')))
            $errors[] = "Student ID is required.";

        $contact = trim($_POST['contact_number'] ?? '');
        if (empty($contact))
            $errors[] = "Contact number is required.";
        elseif (!preg_match('/^9\d{2}-\d{3}-\d{4}$/', $contact))
            $errors[] = "Contact number must follow the format: 9XX-XXX-XXXX.";

        if (empty(trim($_POST['program'] ?? '')))
            $errors[] = "Program is required.";

        if (empty(trim($_POST['section'] ?? '')))
            $errors[] = "Section is required.";
        if (empty(trim($_POST['year_level'] ?? '')))
            $errors[] = "Year level is required.";

        if (empty(trim($_POST['section'] ?? '')))
            $errors[] = "Section is required.";
    }

    // Adviser Conf
    if ($userType === 'adviser') {
        if (empty(trim($_POST['full_name'] ?? '')))
            $errors[] = "Full name is required.";
    }

    // For admin confirmation
    if ($userType === 'admin') {
        if (empty(trim($_POST['name'] ?? '')))
            $errors[] = "Name is required.";
    }

    //Password Conf
    if ($newPass !== '') {
        if (strlen($newPass) < 6)
            $errors[] = "Password must be at least 6 characters.";
        elseif ($newPass !== $confirmPass)
            $errors[] = "Passwords do not match.";
    }

    if (empty($errors)) {
        $hash = $newPass !== '' ? password_hash($newPass, PASSWORD_DEFAULT) : null;

        if ($userType === 'student') {
            $cols = "full_name=?, email=?, student_id=?, contact_number=?, program=?, year_level=?, section=?";
            $vals = [
                trim($_POST['full_name'] ?? ''),
                $newEmail,
                trim($_POST['student_id'] ?? ''),
                trim($_POST['contact_number'] ?? ''),
                trim($_POST['program'] ?? ''),
                (int) ($_POST['year_level'] ?? 1),
                trim($_POST['section'] ?? ''),
            ];
            if ($hash) {
                $cols .= ', password_hash=?';
                $vals[] = $hash;
            }
            $vals[] = $user_id;
            $pdo->prepare("UPDATE students SET $cols WHERE id=?")->execute($vals);

        } elseif ($userType === 'adviser') {
            $cols = "full_name=?, email=?, title=?";
            $vals = [
                trim($_POST['full_name'] ?? ''),
                $newEmail,
                trim($_POST['title'] ?? ''),
            ];
            if ($hash) {
                $cols .= ', password_hash=?';
                $vals[] = $hash;
            }
            $vals[] = $user_id;
            $pdo->prepare("UPDATE advisers SET $cols WHERE id=?")->execute($vals);

        } else {
            $cols = "name=?, email=?, title=?";
            $vals = [
                trim($_POST['name'] ?? ''),
                $newEmail,
                trim($_POST['title'] ?? ''),
            ];
            if ($hash) {
                $cols .= ', password=?';
                $vals[] = $hash;
            }
            $vals[] = $user_id;
            $pdo->prepare("UPDATE admins SET $cols WHERE id=?")->execute($vals);
        }

        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;
    }
}
// Handle document uploads
$upload_success = '';
$upload_errors = [];

// Handle credential deletion
// if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_credential']) && $userType === 'student') {
//     $cred_id = (int) $_POST['delete_credential'];
//     $s = $pdo->prepare("SELECT credential_path FROM student_credentials WHERE id = ? AND student_id = ?");
//     $s->execute([$cred_id, $user_id]);
//     $row = $s->fetch(PDO::FETCH_ASSOC);
//     if ($row) {
//         $filePath = '../uploads/credentials/' . $row['credential_path'];
//         if (file_exists($filePath))
//             unlink($filePath);
//         $pdo->prepare("DELETE FROM student_credentials WHERE id = ? AND student_id = ?")
//             ->execute([$cred_id, $user_id]);
//     }
//     header("Location: " . $_SERVER['PHP_SELF'] . "?success=1&tab=docs");
//     exit;
// }

// if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_docs']) && $userType === 'student') {
//     $uploadDir_resume = '../uploads/resumes/';
//     $uploadDir_credential = '../uploads/credentials/';

//     // Resume upload (single, overwrite)
//     if (!empty($_FILES['resume']['name'])) {
//         $resumeExt = strtolower(pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION));
//         if ($resumeExt !== 'pdf') {
//             $upload_errors[] = "Resume must be a PDF file.";
//         } elseif ($_FILES['resume']['size'] > 5 * 1024 * 1024) {
//             $upload_errors[] = "Resume must be under 5MB.";
//         } else {
//             $resumeName = 'resume_' . $user_id . '_' . time() . '.pdf';
//             move_uploaded_file($_FILES['resume']['tmp_name'], $uploadDir_resume . $resumeName);
//             $pdo->prepare("INSERT INTO student_documents (student_id, resume_path, uploaded_at)
//                            VALUES (?, ?, NOW())
//                            ON CONFLICT (student_id)
//                            DO UPDATE SET resume_path = EXCLUDED.resume_path, uploaded_at = NOW()")
//                 ->execute([$user_id, 'uploads/resumes' . $resumeName]);
//         }
//     }

// Credential upload (multiple allowed)
// if (!empty($_FILES['credentials']['name'][0])) {
//     $files = $_FILES['credentials'];
//     $count = count($files['name']);
//     for ($i = 0; $i < $count; $i++) {
//         if (empty($files['name'][$i]))
//             continue;
//         $credExt = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
//         if (!in_array($credExt, ['jpg', 'jpeg', 'png'])) {
//             $upload_errors[] = "'{$files['name'][$i]}' must be a JPG or PNG image.";
//         } elseif ($files['size'][$i] > 5 * 1024 * 1024) {
//             $upload_errors[] = "'{$files['name'][$i]}' must be under 5MB.";
//         } else {
//             $credName = 'credential_' . $user_id . '_' . time() . '_' . $i . '.' . $credExt;
//             move_uploaded_file($files['tmp_name'][$i], $uploadDir_credential . $credName);
//             $pdo->prepare("INSERT INTO student_credentials (student_id, credential_path, uploaded_at)
//                            VALUES (?, ?, NOW())")
//                 ->execute([$user_id, $credName]);
//         }
//     }
// }

//     if (empty($upload_errors)) {
//         header("Location: " . $_SERVER['PHP_SELF'] . "?success=1&tab=docs");
//         exit;
//     }
// }

$user = getUser($pdo, $userType, $user_id);



// Convenience variables used in the form
$val_full_name = $user['full_name'] ?? '';          // students, advisers
$val_name = $user['name'] ?? '';          // admins
$val_email = $user['email'] ?? '';
$val_student_id = $user['student_id'] ?? '';          // students
$val_contact_number = $user['contact_number'] ?? '';          // students
$val_program = $user['program'] ?? '';          // students
$val_year_level = (int) ($user['year_level'] ?? '');       // students
$val_section = $user['section'] ?? '';          // students
$val_title = $user['title'] ?? '';          // advisers, admins
$val_role = $user['role'] ?? $roleRaw;    // advisers, admins
$val_created_at = !empty($user['created_at'])
    ? date('M d, Y', strtotime($user['created_at']))
    : '—';

// Avatar / header display
$displayName = $val_full_name ?: $val_name ?: 'User';
$displayEmail = $val_email;
$displayRole = ucwords(str_replace('_', ' ', $roleRaw));

$nameParts = explode(' ', trim($displayName));
$initials = strtoupper(
    substr($nameParts[0], 0, 1) .
    (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : '')
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Personal Information | CEE IT Connects</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../CSS/index-style.css">

    <style>
        body {
            padding-top: 80px;
            background: #f5f7ff;
        }

        .page-title {
            font-weight: 600;
            font-size: 16px;
            color: #1f2a44;
        }

        .profile-container {
            background: #fff;
            border-radius: 30px;
            border: 2px solid #ff6b00;
            padding: 90px 40px 40px;
            position: relative;
            max-width: 900px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .07);
        }

        .profile-pic {
            width: 130px;
            height: 130px;
            border-radius: 50%;
            background: #eef1ff;
            border: 4px solid #ff6b00;
            position: absolute;
            top: -65px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            font-weight: 700;
            color: #272f54;
            letter-spacing: 1px;
        }

        .section-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #aaa;
            margin: 28px 0 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #eee;
        }

        .form-label {
            font-size: 12px;
            color: #ff6b00;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .form-control,
        .form-select {
            border-radius: 8px;
            border: 1.5px solid #e0e0e0;
            font-size: 14px;
            padding: 10px 12px;
            transition: border-color .2s;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #ff6b00;
            box-shadow: 0 0 0 3px rgba(255, 107, 0, .1);
            outline: none;
        }

        .form-control[readonly] {
            background: #f9f9f9;
            color: #999;
            cursor: not-allowed;
            border-color: #eee;
        }

        .btn-update {
            background: #FFE7B3 !important;
            color: #7a5200 !important;
            border: none !important;
            transition: background-color .15s ease, color .15s ease;
        }

        .btn-update:hover {
            background: #E4572E !important;
            color: #fff !important;
        }

        .flash {
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
        }

        .role-badge {
            display: inline-block;
            background: #eef1ff;
            color: #272f54;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 14px;
            border-radius: 20px;
            margin-top: 6px;
        }

        .pass-wrapper {
            position: relative;
        }

        .pass-wrapper .toggle-eye {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #aaa;
            font-size: 15px;
        }

        .pass-wrapper .toggle-eye:hover {
            color: #ff6b00;
        }
    </style>
</head>

<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4 mb-5">

        <div class="d-flex align-items-center gap-2 mb-4">
            <a href="#" onclick="history.back(); return false;" class="text-dark"><i class="fa fa-arrow-left"
                    style="margin-left: 5px;"></i></a>
            <a href="#" onclick="history.back(); return false;" class="text-dark" style="text-decoration:none;">
                <h5 class="page-title mb-0">Back to Home</h5>
            </a>
        </div>

        <?php if ($success != '' && empty($errors) && empty($upload_errors)): ?>
            <div class="alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
                style="z-index:9999; min-width:350px;" role="alert" id="flash-msg">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($success); ?>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="flash alert alert-danger" id="flash-msg">
                <?php foreach ($errors as $e): ?>
                    <div><i class="fa fa-circle-xmark me-1"></i> <?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="profile-container mx-auto" style="margin-top:100px;">

            <div class="profile-pic"><?= htmlspecialchars($initials) ?></div>

            <div class="text-center mb-2" style="margin-top:-10px;">
                <div class="fw-bold fs-5" style="color:#1f2a44;"><?= htmlspecialchars($displayName) ?></div>
                <div style="font-size:13px;color:#888;margin-top:2px;"><?= htmlspecialchars($displayEmail) ?></div>
                <span><?= htmlspecialchars($displayRole) ?></span>
            </div>

            <form method="POST">

                <!-- Students -->
                <?php if ($userType === 'student'): ?>

                    <div class="section-label">Basic Information</div>
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" required
                                value="<?= htmlspecialchars($val_full_name) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required
                                value="<?= htmlspecialchars($val_email) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Student ID</label>
                            <input type="text" name="student_id" class="form-control"
                                value="<?= htmlspecialchars($val_student_id) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="contact_number" class="form-control" placeholder="9XX-XXX-XXXX"
                                maxlength="12" pattern="9\d{2}-\d{3}-\d{4}" title="Format: 9XX-XXX-XXXX"
                                value="<?= htmlspecialchars($val_contact_number) ?>">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Program</label>
                            <select name="program" class="form-control">
                                <?php foreach (['Information Technology', 'Electrical Engineering', 'Civil Engineering'] as $p): ?>
                                    <option value="<?= $p ?>" <?= $val_program === $p ? 'selected ' : '' ?>>
                                        Bachelor of Science in <?= $p ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Year & Section</label>
                            <div class="d-flex align-items-center gap-1">
                                <input type=text name="year_level" class="form-select" style="max-width:70px;" readonly>
                                <option value="4" <?= $val_year_level === $y ? 'selected' : '' ?>>
                                    4
                                </option>
                                </input>

                                <span class="fw-bold">-</span>

                                <input type="text" name="section" class="form-control" style="max-width:70px;"
                                    value="<?= htmlspecialchars($val_section) ?>" placeholder="Sec">
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Member Since</label>
                            <input type="text" class="form-control" readonly
                                value="<?= htmlspecialchars($val_created_at) ?>">
                        </div>

                    </div>

                    <!-- adviser -->
                <?php elseif ($userType === 'adviser'): ?>

                    <div class="section-label">Basic Information</div>
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" required
                                value="<?= htmlspecialchars($val_full_name) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required
                                value="<?= htmlspecialchars($val_email) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Title</label>
                            <select name="title" class="form-select">
                                <?php foreach (['Adviser', 'Professor', 'Engineer', 'Doctor', 'Instructor'] as $t): ?>
                                    <option value="<?= $t ?>" <?= $val_title === $t ? 'selected' : '' ?>>
                                        <?= $t ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Member Since</label>
                            <input type="text" class="form-control" readonly
                                value="<?= htmlspecialchars($val_created_at) ?>">
                        </div>

                    </div>

                    <!-- admin-->
                <?php elseif ($userType === 'admin'): ?>

                    <div class="section-label">Basic Information</div>
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" required
                                value="<?= htmlspecialchars($val_name) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required
                                value="<?= htmlspecialchars($val_email) ?>">
                        </div>

                        <!-- <div class="col-md-6">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control"
                                value="<?//= htmlspecialchars($val_title) ?>">
                        </div> -->
                    </div>

                <?php endif; ?>

                <!-- This is for change password -->
                <div class="section-label">
                    Change Password
                    <span style="font-weight:400;text-transform:none;font-size:11px;color:#aaa;">
                        — leave blank to keep current
                    </span>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">New Password</label>
                        <div class="pass-wrapper">
                            <input type="password" name="new_password" id="newPass" class="form-control"
                                placeholder="New Password" autocomplete="new-password"
                                pattern="^(?=.*[a-z])(?=.*[A-Z]).{8,16}$">
                            <i class="fa fa-eye toggle-eye" onclick="togglePass('newPass',this)"></i>
                        </div>
                        <span style="font-weight:400;text-transform:none;font-size:12px;color:#aaa;">8-16 characters
                            with at least one uppercase and one lowercase
                            letter</span>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm New Password</label>
                        <div class="pass-wrapper">
                            <input type="password" name="confirm_password" id="confirmPass" class="form-control"
                                placeholder="Repeat new password" autocomplete="new-password">
                            <i class="fa fa-eye toggle-eye" onclick="togglePass('confirmPass',this)"></i>
                        </div>
                    </div>
                </div>

                <div class="text-end mt-4">
                    <button type="submit" class="btn-update">
                        <i class="fa fa-floppy-disk me-1"></i> Apply Changes
                    </button>
                </div>

            </form>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePass(id, icon) {
            const f = document.getElementById(id);
            if (f.type === 'password') {
                f.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                f.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        setTimeout(() => {
            const msg = document.getElementById('flash-msg');
            if (msg) {
                msg.style.transition = 'opacity .5s';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            }
        }, 4000);
    </script>
</body>

</html>