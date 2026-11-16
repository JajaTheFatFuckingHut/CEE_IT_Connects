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
$hteAccessStmt = $pdo->prepare("
    SELECT s.id, s.full_name, i.company, i.required_hours,
           i.ojt_time_in, i.ojt_time_out
    FROM students s
    JOIN ojt_applications oa ON oa.student_id = s.id
    JOIN internships i ON i.id = oa.internship_id
    WHERE s.id = ? AND oa.internship_id = ?
    LIMIT 1
");
$hteAccessStmt->execute([$student_id, $adviserInternshipId]);
$hteStudent = $hteAccessStmt->fetch(PDO::FETCH_ASSOC);


$ojtAccessStmt = $pdo->prepare("
    SELECT s.id, s.full_name, i.company, i.required_hours,
           i.ojt_time_in, i.ojt_time_out
    FROM rooms r
    JOIN advisers a ON a.id = r.adviser_id
    JOIN students s
        ON s.section::text = r.section::text
       AND s.year_level = r.year_level
       AND LOWER(s.program) = LOWER(a.department)
       AND s.is_archived = false
    LEFT JOIN ojt_applications oa ON oa.student_id = s.id
    LEFT JOIN internships i ON i.id = oa.internship_id
    WHERE s.id = ?
      AND r.adviser_id = ?
      AND r.room_type = 'regular'
      AND r.is_archived = false
      AND r.section IS NOT NULL
    LIMIT 1
");
$ojtAccessStmt->execute([$student_id, $adviserId]);
$ojtStudent = $ojtAccessStmt->fetch(PDO::FETCH_ASSOC);


if (!$hteStudent && !$ojtStudent) {
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
    'debug_version' => 'v2',   // temporary marker
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