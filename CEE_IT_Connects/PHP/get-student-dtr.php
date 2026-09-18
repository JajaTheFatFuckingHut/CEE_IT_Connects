<?php
session_start();
require 'db.php';
require 'auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'], $_SESSION['role']) || getUserType($_SESSION['role']) !== 'adviser') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$adviser_id = $_SESSION['user_id'];
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : null;

if (!$student_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing student_id']);
    exit;
}

// Get the HTE adviser's internship_id
$adviserStmt = $pdo->prepare("SELECT internship_id FROM advisers WHERE id = ?");
$adviserStmt->execute([$adviser_id]);
$adviserInternshipId = $adviserStmt->fetchColumn();

// Confirm this adviser actually oversees this student before exposing any DTR data
$accessStmt = $pdo->prepare("
    SELECT s.id, s.full_name, i.company, i.required_hours
    FROM students s
    JOIN ojt_applications oa ON oa.student_id = s.id
    JOIN internships i ON i.id = oa.internship_id
    WHERE s.id = ? AND oa.internship_id = ?
    LIMIT 1
");
$accessStmt->execute([$student_id, $adviserInternshipId]);
$student = $accessStmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to this student.']);
    exit;
}

// Pull weeks
$weeksStmt = $pdo->prepare("
    SELECT week_index, week_label
    FROM ojt_weeks
    WHERE user_id = ? AND user_type = 'student'
    ORDER BY week_index ASC
");
$weeksStmt->execute([$student_id]);
$weeks = $weeksStmt->fetchAll(PDO::FETCH_ASSOC);

// Pull hours
$hoursStmt = $pdo->prepare("
    SELECT week_index, row_index, date, m_in, m_out, a_in, a_out
    FROM ojt_hours
    WHERE user_id = ? AND user_type = 'student'
    ORDER BY week_index ASC, row_index ASC
");
$hoursStmt->execute([$student_id]);
$hoursRows = $hoursStmt->fetchAll(PDO::FETCH_ASSOC);

// Group hours by week_index
$hoursByWeek = [];
foreach ($hoursRows as $h) {
    $hoursByWeek[$h['week_index']][] = [
        'row_index' => (int) $h['row_index'],
        'date' => $h['date'] ?? '',
        'm_in' => $h['m_in'] ?? '',
        'm_out' => $h['m_out'] ?? '',
        'a_in' => $h['a_in'] ?? '',
        'a_out' => $h['a_out'] ?? '',
    ];
}

// Build ojtWeeks structure (mirrors the student page's expected shape)
$ojtWeeks = [];
foreach ($weeks as $w) {
    $ojtWeeks[] = [
        'week_index' => (int) $w['week_index'],
        'week_label' => $w['week_label'],
        'rows' => $hoursByWeek[$w['week_index']] ?? [],
    ];
}

echo json_encode([
    'success' => true,
    'student' => [
        'full_name' => $student['full_name'],
        'company' => $student['company'],
        'required_hours' => (float) $student['required_hours'],
    ],
    'weeks' => $ojtWeeks,
]);

$accessStmt = $pdo->prepare("
    SELECT s.id, s.full_name, i.company, i.required_hours,
           i.ojt_time_in, i.ojt_time_out
    FROM students s
    JOIN ojt_applications oa ON oa.student_id = s.id
    JOIN internships i ON i.id = oa.internship_id
    WHERE s.id = ? AND oa.internship_id = ?
    LIMIT 1
");

echo json_encode([
    'success' => true,
    'student' => [
        'full_name' => $student['full_name'],
        'company' => $student['company'],
        'required_hours' => (float) $student['required_hours'],
        'ojt_time_in' => $student['ojt_time_in'],
        'ojt_time_out' => $student['ojt_time_out'],
    ],
    'weeks' => $ojtWeeks,
]);
exit;