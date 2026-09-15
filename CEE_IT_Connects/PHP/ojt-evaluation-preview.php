<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';
require 'auth.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('Unauthorized');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ojt-pdf-common.php';
use setasign\Fpdi\Tcpdf\Fpdi;

$studentId = (int) $_SESSION['user_id'];

$sStmt = $pdo->prepare("SELECT full_name, student_id, program FROM students WHERE id = ?");
$sStmt->execute([$studentId]);
$student = $sStmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    http_response_code(404);
    exit('Student not found.');
}

$coordStmt = $pdo->prepare("
    SELECT a.full_name FROM advisers a
    JOIN rooms r ON r.adviser_id = a.id
    JOIN room_members rm ON rm.room_id = r.id
    WHERE rm.user_id = ? AND rm.user_type = 'student' AND r.is_archived = FALSE
    LIMIT 1
");
$coordStmt->execute([$studentId]);
$coordName = $coordStmt->fetchColumn() ?: '';

// Company/supervisor come straight from what the student typed in the
// form, since nothing has been saved yet — fall back to blank, not a DB
// lookup, so the preview matches exactly what's on screen.
$companyName = trim($_POST['company_name'] ?? '');
$supervisorName = trim($_POST['supervisor_name'] ?? '');

function ratingInt(array $post, string $key): int
{
    $v = (int) ($post[$key] ?? 0);
    return ($v >= 1 && $v <= 4) ? $v : 0;
}

$ratingKeys = [
    'site_secure',
    'site_orientation',
    'site_resources',
    'site_colleagues',
    'sup_job_desc',
    'sup_feedback',
    'sup_learning',
    'sup_duties',
    'sup_schedule',
    'learn_aligned',
    'learn_verbal',
    'learn_interpersonal',
    'learn_creativity',
    'learn_problem',
    'learn_critical',
    'learn_writing',
    'learn_career',
    'hei_prepared',
    'hei_guidance',
    'hei_supported',
    'hei_communication',
    'hei_coursework',
    'hei_goals',
    'hei_valuable',
    'hei_satisfied',
    'coord_instructions',
    'coord_goals',
    'coord_responsive',
    'coord_feedback',
    'coord_challenges',
    'overall_rating',
    'recommend_internship',
    'work_supervisor_again',
    'work_coordinator_again',
    'recommend_hte',
];

$eval = [];
foreach ($ratingKeys as $key) {
    $eval[$key] = ratingInt($_POST, $key);
}

$eval['was_paid'] = ($_POST['was_paid'] ?? '') === 'yes';
$eval['pay_type'] = $_POST['pay_type'] ?? '';
$eval['pay_amount'] = isset($_POST['pay_amount']) ? (float) $_POST['pay_amount'] : 0.0;

foreach (['most_valuable', 'least_valuable', 'concerns', 'suggestions'] as $key) {
    $eval[$key] = trim($_POST[$key] ?? '');
}

$templatePdf = __DIR__ . '/Sources/forms/CEIT-OJTF-011_Students_Evaluation_of_Internship.pdf';
if (!file_exists($templatePdf)) {
    http_response_code(500);
    exit('Template PDF not found.');
}

$pdf = new Fpdi('P', 'mm', 'A4');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);
$pdf->SetFont('Helvetica', '', 9);

renderStudentEvaluationPdf($pdf, $templatePdf, $eval, [
    'student_name' => $student['full_name'] ?? '',
    'student_no' => $student['student_id'] ?? '',
    'program' => $student['program'] ?? '',
    'company_name' => $companyName,
    'supervisor_name' => $supervisorName,
    'coord_name' => $coordName,
    'submitted_at' => date('m/d/Y') . ' (preview)',
]);

header('Content-Type: application/pdf');
$pdf->Output('CEIT-OJTF-011_preview.pdf', 'I');
exit;