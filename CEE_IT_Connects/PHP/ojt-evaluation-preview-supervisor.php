<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
require 'db.php';
require 'auth.php';

$role = $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['internship_adviser', 'hte_adviser', 'supervisor'])) {
    http_response_code(403);
    exit('Unauthorized');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ojt-pdf-common.php';
use setasign\Fpdi\Tcpdf\Fpdi;

function ratingInt(array $post, string $key): int
{
    $v = (int) ($post[$key] ?? 0);
    return ($v >= 1 && $v <= 4) ? $v : 0;
}
function scale11(array $post, string $key): int
{
    $v = (int) ($post[$key] ?? -1);
    return ($v >= 0 && $v <= 10) ? $v : -1;
}

$ratingKeys = [
    'ls_questions',
    'ls_resources',
    'ls_accountability',
    'rw_written',
    'rw_express',
    'rw_math',
    'lv_listens',
    'lv_meetings',
    'lv_verbal',
    'ps_divides',
    'ps_brainstorm',
    'ps_solve',
    'pd_proactive',
    'pd_priorities',
    'pd_demeanor',
    'it_conflicts',
    'it_team',
    'it_assertive',
    'oe_endorse',
    'oe_adapts',
    'oe_channels',
    'wh_punctual',
    'wh_attitude',
    'wh_dress',
    'ca_ethics',
    'ca_principled',
    'ca_diversity',
    'is_proficiency',
    'is_willingness',
    'is_additional',
];

$eval = [];
foreach ($ratingKeys as $key)
    $eval[$key] = ratingInt($_POST, $key);

$eval['overall_intern'] = scale11($_POST, 'overall_intern');
$eval['overall_experience'] = scale11($_POST, 'overall_experience');

foreach (['impact', 'strengths', 'improvements', 'suggestions', 'supervise_future_reason'] as $key) {
    $eval[$key] = trim($_POST[$key] ?? '');
}
$eval['supervise_future_yes'] = ($_POST['supervise_future'] ?? '') === 'yes';
$eval['supervise_future_no'] = ($_POST['supervise_future'] ?? '') === 'no';

$meta = [
    'intern_name' => trim($_POST['intern_name'] ?? ''),
    'student_no' => trim($_POST['student_no'] ?? ''),
    'company_name' => trim($_POST['company_name'] ?? ''),
    'supervisor_name' => trim($_POST['supervisor_name'] ?? ''),
    'title_position' => trim($_POST['title_position'] ?? ''),
    'contact_details' => trim($_POST['contact_details'] ?? ''),
    'eval_date' => trim($_POST['eval_date'] ?? date('Y-m-d')),
];

$templatePdf = __DIR__ . '/../Sources/forms/CEIT-OJTF-010_Supervisors_Evaluation_of_Student_Intern.pdf';
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

renderSupervisorEvaluationPdf($pdf, $templatePdf, $eval, $meta);
drawSupervisorSummaryPage($pdf, $eval, $meta);

header('Content-Type: application/pdf');
$pdf->Output('CEIT-OJTF-010_preview.pdf', 'I');
exit;