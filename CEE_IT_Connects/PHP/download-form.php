<?php
require 'auth.php';
require 'db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use setasign\Fpdi\Fpdi;

$student_session_id = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$internship_id = (int) ($_GET['id'] ?? 0);

if (!$action) {
    http_response_code(400);
    exit('Missing parameters.');
}

// Handle approval early — static file, no internship id needed
if ($action === 'approval') {
    $approvalPdf = __DIR__ . '/Sources/forms/approval.pdf';
    if (!file_exists($approvalPdf)) {
        http_response_code(500);
        exit('Approval PDF not found.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="approval.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($approvalPdf);
    exit;
}

// All other actions require a valid internship id
if (!$internship_id) {
    http_response_code(400);
    exit('Missing parameters.');
}

// ── Fetch student ─────────────────────────────────────────────────────────────
$stmtS = $pdo->prepare("
    SELECT full_name, student_id, program, contact_number, email
    FROM students WHERE id = ?
");
$stmtS->execute([$student_session_id]);
$student = $stmtS->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    http_response_code(403);
    exit('Student not found.');
}

// ── Fetch internship ──────────────────────────────────────────────────────────
$stmtI = $pdo->prepare("
    SELECT i.*, i.latitude, i.longtitude, a.name AS adviser_name, a.title AS adviser_title
    FROM internships i
    LEFT JOIN admins a ON a.id = i.adviser_id
    WHERE i.id = ?
");
$stmtI->execute([$internship_id]);
$internship = $stmtI->fetch(PDO::FETCH_ASSOC);
if (!$internship) {
    http_response_code(404);
    exit('Internship not found.');
}

// ── Variables ─────────────────────────────────────────────────────────────────
$studentName = $student['full_name'] ?? '';
$studentNo = $student['student_id'] ?? '';
$studentProg = $student['program'] ?? '';
$companyName = $internship['company'] ?? '';
$companyAddr = $internship['location'] ?? '';
$companyPhone = $internship['phone_numbers'] ?? '';
$companyEmail = $internship['email'] ?? '';
$duration = $internship['duration'] ?? '';
$adviserName = $internship['adviser_name'] ?? '';
$today = date('F j, Y');

function getDepartment(string $program): string
{
    $map = [
        'BSIT' => 'Department of Information Technology',
        'BSCS' => 'Department of Computer Science',
        'BSCE' => 'Department of Civil Engineering',
        'BSEE' => 'Department of Electrical Engineering',
        'BSME' => 'Department of Mechanical Engineering',
        'BSECE' => 'Department of Electronics Engineering',
    ];
    foreach ($map as $code => $dept) {
        if (stripos($program, $code) !== false)
            return $dept;
    }
    return $program;
}

// Extract short program code e.g. "BSIT" from full program string
function getProgramCode(string $program): string
{
    $codes = ['BSIT', 'BSCS', 'BSCE', 'BSEE', 'BSME', 'BSECE'];
    foreach ($codes as $code) {
        if (stripos($program, $code) !== false)
            return $code;
    }
    return $program;
}

$department = getDepartment($studentProg);
$programCode = getProgramCode($studentProg);

$plv_lat = 14.7011;
$plv_lng = 120.9830;

$hte_lat = $internship['latitude'] ?? null;
$hte_lng = $internship['longtitude'] ?? null;

$google_api_key = 'YOUR_GOOGLE_MAPS_API_KEY';

// Source paths
$pdfBase = __DIR__ . '/Sources/forms/';
$formFiles = [
    'hte_info' => $pdfBase . 'CEIT-OJTF-001_HTE_Information_Form.pdf',
    'addendum' => $pdfBase . 'CEIT-OJTF-009_Addendum_for_Student_Intern_Placement.pdf',
    'rl' => $pdfBase . 'OJT_Recommendation_Letter.pdf',
    'waiver' => $pdfBase . 'CEIT-OJTF-008_OJT_Waiver_Form.pdf',
    'vicinity' => $pdfBase . 'CEIT-OJTF-003_OJT_Vicinity_Map.pdf',
    'oath' => $pdfBase . 'CEIT-OJTF-007_OJT_Oath_of_Undertaking.pdf',
    'internship_plan' => $pdfBase . 'CEIT-OJTF-002_Internship_Plan.pdf',
];

if (!isset($formFiles[$action])) {
    http_response_code(400);
    exit('Unknown form action.');
}

$sourcePdf = $formFiles[$action];
if (!file_exists($sourcePdf)) {
    http_response_code(500);
    exit('Source PDF not found: ' . $sourcePdf);
}
$pdf = new Fpdi();
$pdf->SetAutoPageBreak(false);

switch ($action) {

    // ── CEIT-OJTF-001: HTE Information Form ──────────────────────────────────
    case 'hte_info':
        $pdf->setSourceFile($sourcePdf);
        $pdf->AddPage('L', 'A4');
        $tpl = $pdf->importPage(1);
        $pdf->useTemplate($tpl, 0, 0, 297, 210);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);

        // Student copy (left column)
        $pdf->SetXY(50, 53);
        $pdf->Cell(60, 5, $studentName, 0, 0, 'L');
        $pdf->SetXY(125, 53);
        $pdf->Cell(35, 5, $studentNo, 0, 0, 'L');
        $pdf->SetXY(73, 76);
        $pdf->Cell(85, 5, $companyName, 0, 0, 'L');
        $pdf->SetXY(73, 85);
        $pdf->Cell(85, 5, $companyAddr, 0, 0, 'L');
        $pdf->SetXY(73, 94);
        $pdf->Cell(63, 5, $companyPhone, 0, 0, 'L');
        // $pdf->SetXY(73, 100);
        // $pdf->Cell(63, 5, $companyEmail, 0, 0, 'L');
        $pdf->SetXY(66, 180);
        $pdf->Cell(60, 5, $studentName, 0, 0, 'L');
        // College copy (right column, offset 148.5mm)
        $o = 148.5;
        $pdf->SetXY(50 + $o, 53);
        $pdf->Cell(60, 5, $studentName, 0, 0, 'L');
        $pdf->SetXY(120 + $o, 53);
        $pdf->Cell(35, 5, $studentNo, 0, 0, 'L');
        $pdf->SetXY(73 + $o, 76);
        $pdf->Cell(85, 5, $companyName, 0, 0, 'L');
        $pdf->SetXY(73 + $o, 85);
        $pdf->Cell(85, 5, $companyAddr, 0, 0, 'L');
        $pdf->SetXY(73 + $o, 94);
        $pdf->Cell(63, 5, $companyPhone, 0, 0, 'L');
        // $pdf->SetXY(73 + $o, 100);
        // $pdf->Cell(63, 5, $companyEmail, 0, 0, 'L');
        $pdf->SetXY(66 + $o, 180);
        $pdf->Cell(60, 5, $studentName, 0, 0, 'L');

        $filename = 'CEIT-OJTF-001_HTE_Information_Form_' . $studentName . '.pdf';
        break;

    // ── CEIT-OJTF-003: Vicinity Map ──────────────────────────────────────────
    case 'vicinity':
        $pdf->setSourceFile($sourcePdf);
        $pdf->AddPage('P', 'A4');
        $tpl = $pdf->importPage(1);
        $pdf->useTemplate($tpl, 0, 0, 210, 297);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);

        // Text fields
        $pdf->SetXY(60, 46);
        $pdf->Cell(80, 5, $studentName, 0, 0, 'L');
        $pdf->SetXY(152, 46);
        $pdf->Cell(45, 5, $studentNo, 0, 0, 'L');
        $pdf->SetXY(60, 50);
        $pdf->Cell(160, 5, $companyName, 0, 0, 'L');
        $pdf->SetXY(60, 55);
        $pdf->Cell(160, 5, $companyAddr, 0, 0, 'L');

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetXY(86, 269);
        $pdf->Cell(80, 5, $studentName, 0, 0, 'L');

        // Embed map image if coordinates are available
        if ($hte_lat && $hte_lng) {
            $mapUrl = 'https://maps.googleapis.com/maps/api/staticmap?'
                . http_build_query([
                    'size' => '600x400',
                    'maptype' => 'roadmap',
                    'markers' => [
                        'color:red|label:PLV|' . $plv_lat . ',' . $plv_lng,
                        'color:blue|label:HTE|' . $hte_lat . ',' . $hte_lng,
                    ],
                    'path' => 'color:0x0000ffaa|weight:4|'
                        . $plv_lat . ',' . $plv_lng . '|'
                        . $hte_lat . ',' . $hte_lng,
                    'key' => $google_api_key,
                ]);

            // Fetch the map image into a temp file
            $mapImage = tempnam(sys_get_temp_dir(), 'map_') . '.png';
            $imageData = @file_get_contents($mapUrl);

            if ($imageData !== false) {
                file_put_contents($mapImage, $imageData);
                // Place map in the PDF: x=14, y=65, width=182, height=120 (adjust as needed)
                $pdf->Image($mapImage, 14, 65, 182, 120, 'PNG');
                unlink($mapImage); // clean up temp file
            }
        }

        $filename = 'CEIT-OJTF-003_OJT_Vicinity_Map_' . $studentName . '.pdf';
        break;
    // ── CEIT-OJTF-007: Oath of Undertaking ───────────────────────────────────
    case 'oath':
        $pdf->setSourceFile($sourcePdf);
        $pdf->AddPage('P', 'A4');
        $tpl = $pdf->importPage(1);
        $pdf->useTemplate($tpl, 0, 0, 210, 297);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetXY(30, 57);
        $pdf->Cell(80, 5, $studentName, 0, 0, 'L');
        $pdf->SetXY(40, 203);
        $pdf->Cell(80, 5, $studentName, 0, 0, 'L');
        $pdf->SetXY(61, 217);
        $pdf->Cell(60, 5, $studentProg, 0, 0, 'L');
        $pdf->SetXY(61, 223);
        $pdf->Cell(60, 5, $today, 0, 0, 'L');

        $filename = 'CEIT-OJTF-007_OJT_Oath_of_Undertaking_' . $studentName . '.pdf';
        break;

    // ── CEIT-OJTF-009: Addendum ───────────────────────────────────────────────
    case 'addendum':
        $pdf->setSourceFile($sourcePdf);
        $pdf->AddPage('L', 'A4');
        $tpl = $pdf->importPage(1);
        $pdf->useTemplate($tpl, 0, 0, 297, 210);
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetXY(70, 42);
        $pdf->Cell(150, 5, 'Department of ' . $department, 0, 0, 'L');
        // $pdf->SetXY(14, 56);
        // $pdf->Cell(8, 6, '1', 0, 0, 'C');
        $pdf->SetXY(40, 68);
        $pdf->Cell(48, 6, $studentName, 0, 0, 'L');
        $pdf->SetXY(95, 68);
        $pdf->Cell(32, 6, $studentNo, 0, 0, 'C');
        $pdf->SetXY(145, 68);
        $pdf->Cell(50, 6, $companyName, 0, 0, 'L');

        $filename = 'CEIT-OJTF-009_Addendum_' . $studentName . '.pdf';
        break;

    // ── CEIT-OJTF-002: Internship Plan ───────────────────────────────────────
    case 'internship_plan':
        $pageCount = $pdf->setSourceFile($sourcePdf);
        $pdf->AddPage('P', 'A4');
        $tpl = $pdf->importPage(1);
        $pdf->useTemplate($tpl, 0, 0, 210, 297);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetXY(55, 38);
        $pdf->Cell(80, 5, $studentName, 0, 0, 'L');
        $pdf->SetXY(152, 38);
        $pdf->Cell(45, 5, $studentNo, 0, 0, 'L');
        $pdf->SetXY(58, 42);
        $pdf->Cell(160, 5, $companyName, 0, 0, 'L');
        $pdf->SetXY(58, 46);
        $pdf->Cell(160, 5, $companyAddr, 0, 0, 'L');

        // Page 2
        $pdf->AddPage('P', 'A4');
        $tpl2 = $pdf->importPage(2);
        $pdf->useTemplate($tpl2, 0, 0, 210, 297);

        $pdf->SetXY(80, 168);
        $pdf->Cell(80, 5, $studentName, 0, 0, 'L');

        $filename = 'CEIT-OJTF-002_Internship_Plan_' . $studentName . '.pdf';
        break;

    // ── Recommendation Letter ─────────────────────────────────────────────────
    case 'rl':
        $pdf->setSourceFile($sourcePdf);
        $pdf->AddPage('P', 'A4');
        $tpl = $pdf->importPage(1);
        $pdf->useTemplate($tpl, 0, 0, 210, 297);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetXY(64, 206);
        $pdf->Cell(70, 6, $programCode, 0, 0, 'L');
        $pdf->SetXY(110, 105);
        $pdf->Cell(20, 6, '486', 0, 0, 'L');
        $pdf->SetXY(23, 95);
        $pdf->Cell(150, 6, $studentName, 0, 0, 'L');
        // $pdf->SetFont('Helvetica', 'B', 11);
        // $pdf->SetXY(25, 135);
        // $pdf->Cell(150, 6, strtoupper($adviserName), 0, 0, 'L');
        // $pdf->SetFont('Helvetica', '', 10);
        // $pdf->SetXY(25, 141);
        // $pdf->Cell(150, 6, 'Chairperson, ' . $department, 0, 0, 'L');

        $filename = 'OJT_Recommendation_Letter_' . $studentName . '.pdf';
        break;

    // ── CEIT-OJTF-008: Waiver ────────────────────────────────────────────────
    case 'waiver':
        $pdf->setSourceFile($sourcePdf);
        $pdf->AddPage('P', 'A4');
        $tpl = $pdf->importPage(1);
        $pdf->useTemplate($tpl, 0, 0, 210, 297);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetXY(90, 74);
        $pdf->Cell(120, 5, $companyName, 0, 0, 'L');
        $pdf->SetXY(90, 78);
        $pdf->Cell(120, 5, $companyAddr, 0, 0, 'L');
        $pdf->SetXY(73, 161);
        $pdf->Cell(100, 5, $studentName, 0, 0, 'L');
        $pdf->SetXY(49, 165);
        $pdf->Cell(20, 5, '486', 0, 0, 'L');
        $pdf->SetXY(43, 191);
        $pdf->Cell(100, 5, $studentName, 0, 0, 'L');

        $filename = 'CEIT-OJTF-008_OJT_Waiver_' . $studentName . '.pdf';
        break;
}

// ── Stream to browser ─────────────────────────────────────────────────────────
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
$pdf->Output('D', $filename);
exit;