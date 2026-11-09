<?php
session_start();

require 'db.php';
require 'mailer.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../student-register.php");
    exit();
}

function regFail(string $msg): void
{
    $_SESSION['error'] = $msg;
    header("Location: ../student-register.php");
    exit();
}

$full_name = trim($_POST['full_name'] ?? '');
$prefix = trim($_POST['year_no'] ?? '');
$suffix = trim($_POST['id_no'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));
$program = $_POST['program'] ?? '';
$section = trim($_POST['section'] ?? '');
$contact_number = trim($_POST['contact_number'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($_POST['agree_terms'])) {
    regFail("Please agree to the Terms of Use and Privacy Policy.");
}
if ($full_name === '' || $section === '' || $program === '') {
    regFail("Please fill in all required fields.");
}
if (!preg_match('/^\d{2}$/', $prefix)) {
    regFail("The first part of the Student ID must be exactly 2 digits.");
}
if (!preg_match('/^\d{4}$/', $suffix)) {
    regFail("Student ID must be exactly 4 digits.");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    regFail("A valid email address is required to receive your verification code.");
}

$passwordRegex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>_\-+=~`\[\];\'\/\\\\]).{8,16}$/';
if (!preg_match($passwordRegex, $password)) {
    regFail("Password must be 8-16 characters and include an uppercase letter, a lowercase letter, a number, and a special character.");
}

$student_id = $prefix . '-' . $suffix;

// Targeted duplicate check (instead of loading every student)
$dup = $pdo->prepare("SELECT student_id, email FROM students WHERE student_id = ? OR LOWER(email) = ?");
$dup->execute([$student_id, $email]);
$existing = $dup->fetch(PDO::FETCH_ASSOC);
if ($existing) {
    regFail($existing['student_id'] === $student_id ? "Student ID already exists." : "Email already registered.");
}

// Generate the code and keep everything in the session until it's verified
$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

$_SESSION['pending_reg'] = [
    'full_name' => $full_name,
    'student_id' => $student_id,
    'program' => $program,
    'section' => $section,
    'contact_number' => $contact_number,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'code_hash' => password_hash($code, PASSWORD_DEFAULT),
    'expires' => time() + 600,
    'attempts' => 0,
    'last_sent' => time(),
];

if (!sendVerificationCode($email, $full_name, $code)) {
    unset($_SESSION['pending_reg']);
    regFail("We couldn't send the verification email. Please try again.");
}

$_SESSION['info'] = "We sent a 6-digit code to $email.";
header("Location: verify-code.php");
exit();
?>