<?php
require 'db.php';

$stmt = $pdo->query("SELECT * FROM students");
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $full_name = $_POST['full_name'];

    $prefix = $_POST['year_no'];
    $suffix = $_POST['id_no'];
    $email = $_POST['email'];
    if (!preg_match('/^(20|21|22|23|24|25)$/', $prefix)) {
        die("Invalid student year.");
    }

    if (!preg_match('/^\d{4}$/', $suffix)) {
        die("Student ID must be exactly 4 digits.");
    }
    foreach ($students as $student) {
        if ($student['student_id'] === $prefix . '-' . $suffix) {
            die("Student ID already exists.");
        }
        if ($email === $student['email']) {
            die("Email already registered.");
        }
    }
    // Combine final student ID
    $student_id = $prefix . '-' . $suffix;

    $program = $_POST['program'];
    $year_level = $_POST['year_level'];
    $section = $_POST['section'];
    $contact_number = $_POST['contact_number'];

    // VALIDATE PASSWORD
    $passwordRegex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>_\-+=~`\[\];\'\/\\\\]).{8,16}$/';

    if (!preg_match($passwordRegex, $_POST['password'])) {
        die("Password must be 8-16 characters and include an uppercase letter, a lowercase letter, a number, and a special character.");
    }

    // HASH PASSWORD
    $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // UPLOAD
    $cor_path = null;
    $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];

    if (!in_array($_FILES['cor_upload']['type'], $allowed_types)) {
        die("Only JPG, PNG, PDF allowed.");
    }
    if (!empty($_FILES['cor_upload']['name'])) {
        $target_dir = "../uploads/";

        $file_name = time() . "_" . basename($_FILES["cor_upload"]["name"]);
        $full_path = $target_dir . $file_name;

        if (move_uploaded_file($_FILES["cor_upload"]["tmp_name"], $full_path)) {
            $cor_path = $file_name; // Save only filename
        } else {
            die("Upload failed.");
        }
    }

    // INSERT INTO DATABASE
    $stmt = $pdo->prepare("
        INSERT INTO students
        (full_name, student_id, program, year_level, section, cor_file, password_hash, contact_number, email)
        VALUES
        (:full_name, :student_id, :program, :year_level, :section, :cor_file, :password_hash, :contact_number, :email)
    ");

    $stmt->execute([
        ':full_name' => $full_name,
        ':student_id' => $student_id,
        ':program' => $program,
        ':year_level' => $year_level,
        ':section' => $section,
        ':cor_file' => $cor_path,
        ':password_hash' => $password_hash,
        ':contact_number' => $contact_number,
        ':email' => $email
    ]);


    $newStudentId = $pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO audits (user_id, roles, activity, activity_date) VALUES (:user_id, :roles, :activity, NOW())");
    $stmt->execute([
        ':user_id' => $newStudentId,
        ':roles' => 'student',
        ':activity' => 'Student registered an account with schoold Id ' . $student_id
    ]);
    // Redirect after success
    header("Location: ../PHP/student-welcome.php");
    exit();
}
?>