<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';
require 'auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized — session dump: ' . print_r($_SESSION, true));
}

// Also check role
$role = $_SESSION['role'] ?? 'MISSING';
if (!in_array($role, ['internship_adviser', 'hte_adviser', 'supervisor', 'student'])) {
    exit('Unauthorized — role is: ' . $role);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ojt-pdf-common.php';
use setasign\Fpdi\Tcpdf\Fpdi;

$role = $_SESSION['role'];

// Determine mode 
$isSupervisor = in_array($role, ['internship_adviser', 'hte_adviser', 'supervisor'])
    && isset($_GET['student_id']);

if ($isSupervisor) {
    $studentId = (int) $_GET['student_id'];
} elseif ($role === 'student') {
    $studentId = (int) $_SESSION['user_id'];
} else {
    http_response_code(403);
    exit('Unauthorized');
}

// Fetch student 
$sStmt = $pdo->prepare("SELECT full_name, student_id, program FROM students WHERE id = ?");
$sStmt->execute([$studentId]);
$student = $sStmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(404);
    exit('Student not found.');
}

// Fetch evaluation (different table per role
if ($isSupervisor) {
    $stmt = $pdo->prepare("SELECT * FROM ojt_evaluations_supervisor WHERE student_id = ?");
} else {
    $stmt = $pdo->prepare("SELECT * FROM ojt_evaluations_student WHERE student_id = ?");
}
$stmt->execute([$studentId]);
$eval = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$eval) {
    http_response_code(404);
    exit('No evaluation found.');
}

//lookup
$coordStmt = $pdo->prepare("
    SELECT a.full_name FROM advisers a
    JOIN rooms r ON r.adviser_id = a.id
    JOIN room_members rm ON rm.room_id = r.id
    WHERE rm.user_id = ? AND rm.user_type = 'student' AND r.is_archived = FALSE
    LIMIT 1
");
$coordStmt->execute([$studentId]);
$coordName = $coordStmt->fetchColumn() ?: '';

$companyStmt = $pdo->prepare("
    SELECT i.company
    FROM internships i
    JOIN internship_bookmarks ib ON ib.internship_id = i.id
    WHERE ib.student_id = ?
    ORDER BY ib.created_at DESC
    LIMIT 1
");
$companyStmt->execute([$studentId]);
$companyName = $companyStmt->fetchColumn() ?: '';

$supervisorStmt = $pdo->prepare("
    SELECT a.full_name
    FROM advisers a
    JOIN room_members rm ON rm.user_id = a.id
    JOIN room_members rm_s ON rm_s.room_id = rm.room_id
    WHERE rm_s.user_id = ?
      AND rm_s.user_type = 'student'
      AND rm.user_type = 'hte_adviser'
      AND a.role = 'HTE_adviser'
    LIMIT 1
");
$supervisorStmt->execute([$studentId]);
$supervisorName = $supervisorStmt->fetchColumn() ?: '';

$studentName = $student['full_name'] ?? '';
$studentNo = $student['student_id'] ?? '';
$program = $student['program'] ?? '';
$submittedAt = date('m/d/Y', strtotime($eval['submitted_at']));

// ── Template path (swap based on mode) ───────────────────────────────────
if ($isSupervisor) {
    $templatePdf = __DIR__ . '/../Sources/forms/CEIT-OJTF-010_Supervisors_Evaluation_of_Student_Intern.pdf';
    $filenamePrefix = 'CEIT-OJTF-010';
} else {
    $templatePdf = __DIR__ . '/../Sources/forms/CEIT-OJTF-011_Students_Evaluation_of_Internship.pdf';
    $filenamePrefix = 'CEIT-OJTF-011';
}

if (!file_exists($templatePdf)) {
    http_response_code(500);
    exit('Template PDF not found: ' . $templatePdf);
}

// ── Shared helpers ────────────────────────────────────────────────────────
$pdf = new Fpdi('P', 'mm', 'A4');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);
$pdf->SetFont('Helvetica', '', 9);

$RATING_COLS_STUDENT = [159.55, 166.90, 174.19, 181.53];

// function drawRating(Fpdi $pdf, array $cols, float $rowY, int $rating): void
// {
//     if ($rating < 1 || $rating > 4) {
//         return;
//     }
//     $cx = $cols[$rating - 1];
//     $pdf->SetLineWidth(0.55);
//     $pdf->SetDrawColor(41, 51, 92); // #29335C
//     $pdf->Ellipse($cx, $rowY, 3.1, 2.3, 0, 0, 360, 'D');
//     $pdf->SetLineWidth(0.2);
// }

// function drawCheck(Fpdi $pdf, float $x, float $y): void
// {
//     $pdf->SetFont('Helvetica', 'B', 10);
//     $pdf->SetTextColor(41, 51, 92);
//     $pdf->SetXY($x, $y);
//     $pdf->Cell(5, 5, chr(10003), 0, 0, 'C');
//     $pdf->SetTextColor(0, 0, 0);
//     $pdf->SetFont('Helvetica', '', 9);
// }

$pdf->setSourceFile($templatePdf);

if ($isSupervisor):
    // Map DB column names (ojt_evaluations_supervisor table) → the key
    // names renderSupervisorEvaluationPdf() expects (matches the newer
    // A–J rating scheme used by the preview script).
    $mappedEval = [
        'ls_questions' => $eval['learn_questions'] ?? 0,
        'ls_resources' => $eval['learn_resources'] ?? 0,
        'ls_accountability' => $eval['learn_accountability'] ?? 0,
        'rw_written' => $eval['rw_written'] ?? 0,
        'rw_express' => $eval['rw_communication'] ?? 0,
        'rw_math' => $eval['rw_math'] ?? 0,
        'lv_listens' => $eval['verbal_listens'] ?? 0,
        'lv_meetings' => $eval['verbal_meetings'] ?? 0,
        'lv_verbal' => $eval['verbal_proficiency'] ?? 0,
        'ps_divides' => $eval['creative_divides'] ?? 0,
        'ps_brainstorm' => $eval['creative_brainstorm'] ?? 0,
        'ps_solve' => $eval['creative_solves'] ?? 0,
        'pd_proactive' => $eval['career_proactive'] ?? 0,
        'pd_priorities' => $eval['career_priorities'] ?? 0,
        'pd_demeanor' => $eval['career_demeanor'] ?? 0,
        'it_conflicts' => $eval['team_conflicts'] ?? 0,
        'it_team' => $eval['team_collaborative'] ?? 0,
        'it_assertive' => $eval['team_assertiveness'] ?? 0,
        'oe_endorse' => $eval['org_objectives'] ?? 0,
        'oe_adapts' => $eval['org_standards'] ?? 0,
        'oe_channels' => $eval['org_channels'] ?? 0,
        'wh_punctual' => $eval['work_punctual'] ?? 0,
        'wh_attitude' => $eval['work_attitude'] ?? 0,
        'wh_dress' => $eval['work_dresscode'] ?? 0,
        'ca_ethics' => $eval['char_ethics'] ?? 0,
        'ca_principled' => $eval['char_principled'] ?? 0,
        'ca_diversity' => $eval['char_diversity'] ?? 0,
        'is_proficiency' => $eval['industry_proficiency'] ?? 0,
        'is_willingness' => $eval['industry_willingness'] ?? 0,
        'is_additional' => $eval['industry_additional'] ?? 0,

        'overall_intern' => $eval['overall_intern_rating'] ?? -1,
        'overall_experience' => $eval['overall_internship_rating'] ?? -1,

        'impact' => $eval['comment_impact'] ?? '',
        'strengths' => $eval['comment_strengths'] ?? '',
        'improvements' => $eval['comment_improvements'] ?? '',
        'suggestions' => $eval['suggestions'] ?? '',

        'supervise_future_yes' => (bool) ($eval['would_supervise_again'] ?? false),
        'supervise_future_no' => !(bool) ($eval['would_supervise_again'] ?? true),
        'supervise_future_reason' => $eval['would_supervise_reason'] ?? '',
    ];

    $mappedMeta = [
        'intern_name' => $studentName,
        'student_no' => $studentNo,
        'company_name' => $companyName,
        'supervisor_name' => $supervisorName,
        'title_position' => $eval['title_position'] ?? '',
        'contact_details' => $eval['contact_details'] ?? '',
        'eval_date' => $submittedAt,
    ];

    renderSupervisorEvaluationPdf($pdf, $templatePdf, $mappedEval, $mappedMeta);
    drawSupervisorSummaryPage($pdf, $mappedEval, $mappedMeta);
else:
    renderStudentEvaluationPdf($pdf, $templatePdf, $eval, [
        'student_name' => $studentName,
        'student_no' => $studentNo,
        'program' => $program,
        'company_name' => $companyName,
        'supervisor_name' => $supervisorName,
        'coord_name' => $coordName,
        'submitted_at' => $submittedAt,
    ]);
endif;

// ── Output ────────────────────────────────────────────────────────────────
header('Content-Type: application/pdf');
$safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $studentName);
$filename = $filenamePrefix . '_' . $safeName . '.pdf';
$pdf->Output($filename, 'D');
exit;