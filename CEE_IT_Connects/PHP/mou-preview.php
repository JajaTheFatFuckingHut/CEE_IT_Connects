<?php
require 'db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/../vendor/autoload.php';

use setasign\Fpdi\Fpdi;

if (!isset($_SESSION['user_id'])) {
    die("User not logged in.");
}

$student_id = (int) $_SESSION['user_id'];
$id = (int) ($_GET['id'] ?? 0);
$action = $_GET['action'] ?? null;

if (!$action) {
    die("Invalid request.");
}

// Handle approval early — it needs no internship id
if ($action === 'approval') {
    $approvalPdf = __DIR__ . '/Sources/forms/approval.pdf';
    if (!file_exists($approvalPdf)) {
        http_response_code(500);
        exit('Approval PDF not found.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="approval.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($approvalPdf);
    exit;
}

if (!$id) {
    die("Invalid request.");
}

// ── Fetch student ─────────────────────────────────────────────────────────────
$stmtS = $pdo->prepare("
    SELECT full_name, student_id, program, contact_number, email
    FROM students WHERE id = ?
");
$stmtS->execute([$student_id]);
$student = $stmtS->fetch(PDO::FETCH_ASSOC);
if (!$student)
    die("Student not found.");

// ── Fetch internship ──────────────────────────────────────────────────────────
$stmtI = $pdo->prepare("
    SELECT i.*, i.latitude, i.longtitude, a.name AS adviser_name, a.title AS adviser_title
    FROM internships i
    LEFT JOIN admins a ON a.id = i.adviser_id
    WHERE i.id = ?
");
$stmtI->execute([$id]);
$internship = $stmtI->fetch(PDO::FETCH_ASSOC);
if (!$internship)
    die("Internship not found.");

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

function getProgramCode(string $program): string
{
    $codes = ['BSIT', 'BSCS', 'BSCE', 'BSEE', 'BSME', 'BSECE'];
    foreach ($codes as $code) {
        if (stripos($program, $code) !== false)
            return $code;
    }
    return $program;
}

function osmStaticMap(float $lat1, float $lng1, float $lat2, float $lng2, int $w = 900, int $h = 600): ?string
{
    $n2x = fn($lon, $z) => ($lon + 180) / 360 * (1 << $z) * 256;
    $n2y = function ($lat, $z) {
        $r = deg2rad($lat);
        return (1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * (1 << $z) * 256;
    };

    // highest zoom where both points fit inside the image with padding
    $pad = 70;
    for ($z = 17; $z > 2; $z--) {
        $dx = abs($n2x($lng1, $z) - $n2x($lng2, $z));
        $dy = abs($n2y($lat1, $z) - $n2y($lat2, $z));
        if ($dx <= $w - 2 * $pad && $dy <= $h - 2 * $pad)
            break;
    }

    $left = ($n2x($lng1, $z) + $n2x($lng2, $z)) / 2 - $w / 2;
    $top = ($n2y($lat1, $z) + $n2y($lat2, $z)) / 2 - $h / 2;

    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 230, 230, 230));

    $n = 1 << $z;
    for ($ty = (int) floor($top / 256); $ty <= (int) floor(($top + $h) / 256); $ty++) {
        if ($ty < 0 || $ty >= $n)
            continue;
        for ($tx = (int) floor($left / 256); $tx <= (int) floor(($left + $w) / 256); $tx++) {
            $wrapX = (($tx % $n) + $n) % $n;
            $ch = curl_init("https://tile.openstreetmap.org/$z/$wrapX/$ty.png");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'CEE-IT-Connects/1.0 (OJT vicinity map)',
            ]);
            $data = curl_exec($ch);
            $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            curl_close($ch);
            if (!$ok || !$data)
                continue;
            $tile = @imagecreatefromstring($data);
            if (!$tile)
                continue;
            imagecopy($img, $tile, (int) round($tx * 256 - $left), (int) round($ty * 256 - $top), 0, 0, 256, 256);
            imagedestroy($tile);
        }
    }

    $p1 = [(int) round($n2x($lng1, $z) - $left), (int) round($n2y($lat1, $z) - $top)];
    $p2 = [(int) round($n2x($lng2, $z) - $left), (int) round($n2y($lat2, $z) - $top)];

    $blue = imagecolorallocate($img, 37, 99, 235);
    $red = imagecolorallocate($img, 220, 38, 38);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);

    imagesetthickness($img, 4);
    imageline($img, $p1[0], $p1[1], $p2[0], $p2[1], $blue);

    foreach ([[$p1, $red, 'PLV'], [$p2, $blue, 'HTE']] as [$p, $col, $label]) {
        imagefilledellipse($img, $p[0], $p[1], 26, 26, $white);
        imagefilledellipse($img, $p[0], $p[1], 20, 20, $col);
        imagestring($img, 5, $p[0] + 16, $p[1] - 8, $label, $black);
    }

    imagestring($img, 2, 6, $h - 16, '(c) OpenStreetMap contributors', $black);

    $file = tempnam(sys_get_temp_dir(), 'map_');
    imagepng($img, $file);
    imagedestroy($img);
    return $file;
}

$department = getDepartment($studentProg);
$programCode = getProgramCode($studentProg);

$plv_lat = 14.698835;
$plv_lng = 120.979268;

$hte_lat = $internship['latitude'] ?? null;
$hte_lng = $internship['longtitude'] ?? null;

$google_api_key = 'AIzaSyDITrnTUmS0AwxqZCE8cfYI3d5kjtzg7RY&callback=initMa';

// Source Paths
$pdfBase = __DIR__ . '/Sources/forms/';
$formFiles = [
    'hte_info' => $pdfBase . 'CEIT-OJTF-001_HTE_Information_Form.pdf',
    'addendum' => $pdfBase . 'CEIT-OJTF-009_Addendum_for_Student_Intern_Placement.pdf',
    'rl' => $pdfBase . 'OJT_Recommendation_Letter.pdf',
    'waiver' => $pdfBase . 'CEIT-OJTF-008_OJT_Waiver_Form.pdf',
    'vicinity' => $pdfBase . 'CEIT-OJTF-003_OJT_Vicinity_Map.pdf',
    'oath' => $pdfBase . 'CEIT-OJTF-007_OJT_Oath_of_Undertaking.pdf',
    'internship_plan' => $pdfBase . 'CEIT-OJTF-002_Internship_Plan.pdf',
    'approval' => $pdfBase . 'approval.pdf',
];

$sourcePdf = $formFiles[$action];
if (!file_exists($sourcePdf))
    die("Source PDF not found: $sourcePdf");

// preview
$pdf = new Fpdi();
$pdf->SetAutoPageBreak(false);

switch ($action) {

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
        break;
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
        break;

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
        break;

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
        break;

    case 'vicinity':
        $pdf->setSourceFile($sourcePdf);
        $pdf->AddPage('P', 'A4');
        $tpl = $pdf->importPage(1);
        $pdf->useTemplate($tpl, 0, 0, 210, 297);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);

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

        if ($hte_lat && $hte_lng) {
            $mapFile = osmStaticMap((float) $plv_lat, (float) $plv_lng, (float) $hte_lat, (float) $hte_lng);
            if ($mapFile) {
                $pdf->Image($mapFile, 39, 100, 122, 81, 'PNG');   // 900x600 keeps a 3:2 ratio
                unlink($mapFile);
            }
        }
        break;

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
        break;

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
        break;
}

// Stream inline for preview (opens in browser tab)
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="preview.pdf"');
header('Cache-Control: private, max-age=0, must-revalidate');
$pdf->Output('I', 'preview.pdf');
exit;