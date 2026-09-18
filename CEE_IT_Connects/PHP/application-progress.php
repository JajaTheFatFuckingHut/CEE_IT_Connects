<?php
require_once 'auth.php';
require_once 'db.php';

$student_id = (int) $_SESSION['user_id'];

// ── Fetch bookmarked internship ───────────────────────────────────────────────
$appStmt = $pdo->prepare("
    SELECT
        oa.id AS application_id,
        i.id AS internship_id,
        i.title,
        i.company,
        i.company_classification,
        i.is_plv_internal,
        i.is_valenzuela_lgu
    FROM ojt_applications oa
    JOIN internships i ON i.id = oa.internship_id
    WHERE oa.student_id = ?
    ORDER BY oa.submitted_at DESC LIMIT 1
");
$appStmt->execute([$student_id]);
$selectedInternship = $appStmt->fetch(PDO::FETCH_ASSOC);
$internship_id = $selectedInternship['internship_id'] ?? null;

// ── Fetch HTE supervisor submission ──────────────────────────────────────────
try {
    $supStmt = $pdo->prepare("
        SELECT * FROM student_hte_supervisor_submissions
        WHERE student_id = ?
    ");
    $supStmt->execute([$student_id]);
    $supSubmission = $supStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $supSubmission = null;
}

// ── Derive document requirements from classification ─────────────────────────
$classification = $selectedInternship['company_classification'] ?? 'private';
$is_plv = filter_var($selectedInternship['is_plv_internal'] ?? false, FILTER_VALIDATE_BOOLEAN);
$is_val_lgu = filter_var($selectedInternship['is_valenzuela_lgu'] ?? false, FILTER_VALIDATE_BOOLEAN);
$is_public = ($classification === 'public');

$needs_bir_dti_sec = !$is_public;
$needs_waiver = !$is_plv;
$needs_reco_letter = !$is_plv && !$is_val_lgu;

// ── Fetch all checklist rows ──────────────────────────────────────────────────
$progress = [];
if ($internship_id) {
    $stmt = $pdo->prepare("
        SELECT step_key, is_done, file_path, updated_at
        FROM student_progress
        WHERE student_id = :sid AND internship_id = :iid
    ");
    $stmt->execute([':sid' => $student_id, ':iid' => $internship_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $progress[$row['step_key']] = [
            'done' => filter_var($row['is_done'], FILTER_VALIDATE_BOOLEAN),
            'file_path' => $row['file_path'],
            'updated_at' => $row['updated_at'],
        ];
    }
}

// ── Fetch uploaded resume ─────────────────────────────────────────────────────
$resumeStmt = $pdo->prepare("
    SELECT resume_path, uploaded_at FROM student_documents
    WHERE student_id = ? ORDER BY uploaded_at DESC LIMIT 1
");
$resumeStmt->execute([$student_id]);
$uploadedResume = $resumeStmt->fetch(PDO::FETCH_ASSOC);

// ── Fetch uploaded credentials ────────────────────────────────────────────────
$credStmt = $pdo->prepare("
    SELECT credential_path, uploaded_at FROM student_credentials
    WHERE student_id = ? ORDER BY uploaded_at DESC
");
$credStmt->execute([$student_id]);
$uploadedCredentials = $credStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Flash messages ────────────────────────────────────────────────────────────
$flashSuccess = $_SESSION['success'] ?? null;
unset($_SESSION['success']);
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['error']);

// ── Helper functions ──────────────────────────────────────────────────────────
function isDone(array $p, string $k): bool
{
    if (!isset($p[$k]))
        return false;
    return !empty($p[$k]['done']);
}
function stepDate(array $p, string $k): string
{
    if (!isset($p[$k]) || empty($p[$k]['updated_at']))
        return '';
    return date("M d, Y", strtotime($p[$k]['updated_at']));
}

// ── Per-step done flags ───────────────────────────────────────────────────────
// Named $checklist (not $steps) to avoid collision with navbar.php's foreach variable
$checklist = [
    'internship' => false,
    // 'hte_form' => false,
    'resume' => false,
    'addendum' => false,
    'reco_letter' => false,
    'waiver' => false,
    'internship_plan' => false,
    'vicinity_map' => false,
    'oath' => false,
];

$checklist['internship'] = is_array($selectedInternship) && !empty($selectedInternship['internship_id']);
// $checklist['acceptance_letter'] = isDone($progress, 'acceptance_letter');
$checklist['company_profile'] = isDone($progress, 'company_profile');
// $checklist['hte_form'] = isDone($progress, 'hte_form');
$checklist['resume'] = isDone($progress, 'resume');
$checklist['addendum'] = isDone($progress, 'addendum');
$checklist['reco_letter'] = !$needs_reco_letter || isDone($progress, 'reco_letter');
$checklist['waiver'] = !$needs_waiver || isDone($progress, 'waiver');
$checklist['medical_cert'] = isDone($progress, 'medical_cert');
$checklist['internship_plan'] = isDone($progress, 'internship_plan');
$checklist['vicinity_map'] = isDone($progress, 'vicinity_map');
$checklist['oath'] = isDone($progress, 'oath');

$total = count($checklist);
$doneCount = count(array_filter($checklist));
$pct = round(($doneCount / $total) * 100);

function uploadBlock(string $step_key, string $label, array $progress, ?int $internship_id): string
{
    $file_path = $progress[$step_key]['file_path'] ?? null;
    $updated_at = $progress[$step_key]['updated_at'] ?? null;
    $is_done = !empty($progress[$step_key]['done']);

    ob_start(); ?>
    <div class="mark-done-block">
        <form action="student-progress.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="mark_step">
            <input type="hidden" name="step_key" value="<?= htmlspecialchars($step_key) ?>">
            <input type="hidden" name="internship_id" value="<?= (int) $internship_id ?>">

            <?php if ($file_path): ?>
                <!-- Already uploaded — show current file -->
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;
                            padding:8px 12px; background:#f0fdf4; border:1px solid #bbf7d0;
                            border-radius:8px; font-size:12.5px; color:#16a34a;">
                    <i class="fa fa-file-circle-check"></i>
                    <span style="flex:1;">Proof uploaded</span>
                    <a href="<?= htmlspecialchars($file_path) ?>" target="_blank"
                        style="color:#2563eb; font-weight:600; text-decoration:none; font-size:12px;">
                        <i class="fa fa-eye"></i> View
                    </a>
                </div>
            <?php endif; ?>

            <!-- File upload input -->
            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#57534e;
                               display:block; margin-bottom:6px;">
                    <?= $is_done ? 'Replace proof (PNG, JPG, or PDF · max 5 MB)' : 'Attach proof (PNG, JPG, or PDF · max 5 MB) *' ?>
                </label>
                <input type="file" name="proof_file" accept=".png,.jpg,.jpeg,.pdf" <?= $is_done ? '' : 'required' ?> style="width:100%; padding:7px 10px; border:1.5px dashed #d4d0cc;
                              border-radius:8px; font-size:12.5px; background:#fafaf9;
                              cursor:pointer;">
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="chk_<?= $step_key ?>" <?= $is_done ? '' : 'required' ?>>
                <label class="form-check-label" for="chk_<?= $step_key ?>">
                    <?= htmlspecialchars($label) ?>
                </label>
            </div>

            <button type="submit" class="btn-action <?= $is_done ? 'btn-outline-action' : 'btn-green-action' ?>">
                <i class="fa fa-<?= $is_done ? 'rotate' : 'check' ?>"></i>
                <?= $is_done ? 'Replace & Re-save' : 'Mark as Done' ?>
            </button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OJT Application Checklist</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --brand: #f97316;
            --brand-dark: #c2570c;
            --brand-light: #fff7ed;
            --brand-border: #fed7aa;
            --green: #16a34a;
            --green-light: #f0fdf4;
            --green-border: #bbf7d0;
            --gray-100: #f5f5f4;
            --gray-200: #e7e5e4;
            --gray-400: #a8a29e;
            --gray-600: #57534e;
            --gray-800: #292524;
            --amber: #d97706;
            --amber-light: #fffbeb;
            --amber-border: #fde68a;
            --blue: #2563eb;
            --blue-light: #eff6ff;
            --blue-border: #bfdbfe;
            --radius: 12px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, .08), 0 1px 2px rgba(0, 0, 0, .06);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, .08);
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: #f0ede9;
            color: var(--gray-800);
            min-height: 100vh;
        }

        .page-wrap {
            max-width: 700px;
            margin: 0 auto;
            padding: 90px 16px 64px;
        }

        .page-header {
            margin-bottom: 24px;
        }

        .page-header h1 {
            font-family: 'Syne', sans-serif;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -.3px;
            color: var(--gray-800);
            margin-bottom: 4px;
        }

        .page-header p {
            font-size: 13px;
            color: var(--gray-600);
        }

        .progress-summary {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .progress-ring-wrap {
            position: relative;
            width: 54px;
            height: 54px;
            flex-shrink: 0;
        }

        .progress-ring-wrap svg {
            transform: rotate(-90deg);
        }

        .progress-ring-bg {
            fill: none;
            stroke: var(--gray-200);
            stroke-width: 5;
        }

        .progress-ring-val {
            fill: none;
            stroke: var(--brand);
            stroke-width: 5;
            stroke-linecap: round;
            transition: stroke-dashoffset .5s ease;
        }

        .ring-pct {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-800);
        }

        .progress-text h3 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .progress-text p {
            font-size: 12px;
            color: var(--gray-600);
        }

        .progress-bar-thin {
            flex: 1;
            height: 6px;
            background: var(--gray-200);
            border-radius: 99px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--brand), var(--brand-dark));
            border-radius: 99px;
            transition: width .5s ease;
        }

        .checklist-note {
            background: var(--amber-light);
            border: 1px solid var(--amber-border);
            border-radius: var(--radius);
            padding: 12px 16px;
            font-size: 12.5px;
            color: var(--amber);
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .checklist-note i {
            margin-top: 2px;
            flex-shrink: 0;
        }

        .checklist {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .step-card {
            background: white;
            border: 1.5px solid var(--gray-200);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: border-color .2s, box-shadow .2s;
        }

        .step-card.is-done {
            border-color: var(--green-border);
        }

        .step-card.is-active {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, .08);
        }

        .step-card.is-open {
            box-shadow: var(--shadow-md);
        }

        .step-card.is-na {
            border-color: var(--green-border);
            opacity: .75;
        }

        .step-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            cursor: pointer;
            user-select: none;
        }

        .step-header:hover {
            background: var(--gray-100);
        }

        .step-num {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            flex-shrink: 0;
        }

        .sn-done {
            background: var(--green);
            color: white;
        }

        .sn-active {
            background: var(--brand);
            color: white;
        }

        .sn-na {
            background: var(--gray-200);
            color: var(--gray-600);
        }

        .step-meta {
            flex: 1;
            min-width: 0;
        }

        .step-meta h3 {
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 1px;
        }

        .step-meta p {
            font-size: 11.5px;
            color: var(--gray-600);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin: 0;
        }

        .step-card.is-done .step-meta h3,
        .step-card.is-na .step-meta h3 {
            color: var(--green);
        }

        .step-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .pill {
            padding: 3px 9px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .pill-done {
            background: var(--green);
            color: white;
        }

        .pill-active {
            background: var(--brand-light);
            color: var(--brand);
            border: 1px solid var(--brand-border);
        }

        .pill-na {
            background: var(--green-light);
            color: var(--green);
            border: 1px solid var(--green-border);
        }

        .pill-idle {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .chevron {
            font-size: 13px;
            color: var(--gray-400);
            transition: transform .2s;
        }

        .step-card.is-open .chevron {
            transform: rotate(180deg);
        }

        .step-body {
            display: none;
            border-top: 1px solid var(--gray-200);
            padding: 16px;
            background: #fdfcfb;
        }

        .step-card.is-open .step-body {
            display: block;
        }

        .info-box {
            border-radius: 8px;
            padding: 11px 14px;
            margin-bottom: 12px;
            font-size: 13px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .info-box i {
            margin-top: 2px;
            flex-shrink: 0;
        }

        .info-box.green {
            background: var(--green-light);
            border: 1px solid var(--green-border);
            color: var(--green);
        }

        .info-box.amber {
            background: var(--amber-light);
            border: 1px solid var(--amber-border);
            color: var(--amber);
        }

        .info-box.blue {
            background: var(--blue-light);
            border: 1px solid var(--blue-border);
            color: var(--blue);
        }

        .info-box.orange {
            background: var(--brand-light);
            border: 1px solid var(--brand-border);
            color: var(--brand-dark);
        }

        .info-box p {
            font-weight: 600;
            margin-bottom: 2px;
            font-size: 13px;
        }

        .info-box span {
            font-size: 12px;
            color: var(--gray-600);
        }

        .body-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--gray-400);
            margin-bottom: 8px;
        }

        .action-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: filter .15s, transform .1s;
            font-family: 'DM Sans', sans-serif;
        }

        .btn-action:hover {
            filter: brightness(.95);
            transform: translateY(-1px);
        }

        .btn-action:active {
            transform: translateY(0);
        }

        .btn-primary-action {
            background: var(--brand);
            color: white;
        }

        .btn-outline-action {
            background: white;
            color: var(--gray-800);
            border: 1.5px solid var(--gray-200);
        }

        .btn-green-action {
            background: var(--green);
            color: white;
        }

        .mark-done-block {
            border-top: 1px dashed var(--gray-200);
            padding-top: 12px;
            margin-top: 12px;
        }

        .mark-done-block .form-check-label {
            font-size: 13px;
        }

        .doc-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            background: white;
            margin-bottom: 6px;
            font-size: 13px;
        }

        .doc-row .doc-name {
            flex: 1;
        }

        .confirmed {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 12.5px;
            color: var(--green);
            margin-bottom: 8px;
        }

        .hte-card {
            border: 1px solid var(--blue-border);
            background: var(--blue-light);
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 12px;
            font-size: 13px;
        }

        .hte-card strong {
            display: block;
            margin-bottom: 2px;
        }

        .hte-card span {
            font-size: 12px;
            color: var(--gray-600);
        }

        /* Supervisor modal */
        .sup-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0, 0, 0, 0.4);
            align-items: center;
            justify-content: center;
        }

        .sup-modal-overlay.is-open {
            display: flex;
        }

        .sup-modal-box {
            background: white;
            border-radius: 16px;
            padding: 28px;
            width: 100%;
            max-width: 460px;
            margin: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }

        @media (max-width: 480px) {
            .step-meta p {
                display: none;
            }

            .progress-bar-thin {
                display: none;
            }
        }
    </style>
</head>

<body>

    <?php include_once 'navbar.php'; ?>

    <div class="page-wrap">

        <?php if ($flashSuccess): ?>
            <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                <i class="fa fa-check-circle me-2"></i><?= htmlspecialchars($flashSuccess) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <i class="fa fa-exclamation-circle me-2"></i><?= htmlspecialchars($flashError) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1>OJT Application Checklist</h1>
            <p>Complete each item below. Following the order is recommended, but you may work on them in any sequence.
            </p>
        </div>

        <!-- Progress summary -->
        <div class="progress-summary">
            <div class="progress-ring-wrap">
                <svg width="54" height="54" viewBox="0 0 54 54">
                    <?php
                    $r = 22;
                    $circ = 2 * M_PI * $r;
                    $offset = $circ - ($pct / 100) * $circ;
                    ?>
                    <circle class="progress-ring-bg" cx="27" cy="27" r="<?= $r ?>" />
                    <circle class="progress-ring-val" cx="27" cy="27" r="<?= $r ?>"
                        stroke-dasharray="<?= round($circ, 2) ?>" stroke-dashoffset="<?= round($offset, 2) ?>" />
                </svg>
                <div class="ring-pct"><?= $pct ?>%</div>
            </div>
            <div class="progress-text">
                <h3><?= $doneCount ?> of <?= $total ?> completed</h3>
                <p>Submit all items before starting your OJT</p>
            </div>
            <div class="progress-bar-thin">
                <div class="progress-bar-fill" style="width:<?= $pct ?>%"></div>
            </div>
        </div>

        <div class="checklist-note">
            <i class="fa fa-triangle-exclamation"></i>
            <div>
                <strong>Note on ordering:</strong> Steps are recommended to be completed in sequence, but you may work
                on them out of order. Especially when waiting for forms or signatures from your HTE or PLV offices.
                Mark each item done as you complete it.
            </div>
        </div>
        <div class="checklist">
            <!-- STEP 2: Apply for Internship / Choose Company -->
            <?php $s2done = $checklist['internship'] ?? false; ?>
            <div class="step-card <?= $s2done ? 'is-done' : 'is-active' ?>">
                <div class="step-header" onclick="toggle(this)">
                    <div class="step-num <?= $s2done ? 'sn-done' : 'sn-active' ?>">
                        <?= $s2done ? '<i class="fa fa-check"></i>' : 'x' ?>
                    </div>
                    <div class="step-meta">
                        <h3>Apply for Internship</h3>
                        <p><?= $s2done
                            ? htmlspecialchars(($selectedInternship['title'] ?? '') . ' — ' . ($selectedInternship['company'] ?? ''))
                            : 'Browse listings and apply to an HTE' ?>
                        </p>
                    </div>
                    <div class="step-right">
                        <span class="pill <?= $s2done ? 'pill-done' : 'pill-active' ?>">
                            <?= $s2done ? '<i class="fa fa-check"></i> Done' : 'Pending' ?>
                        </span>
                        <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                    </div>
                </div>
                <div class="step-body">
                    <?php if ($s2done): ?>
                        <div class="hte-card">
                            <strong><?= htmlspecialchars($selectedInternship['title'] ?? '') ?></strong>
                            <span><i
                                    class="fa fa-building me-1"></i><?= htmlspecialchars($selectedInternship['company'] ?? '') ?></span>
                        </div>
                        <div class="info-box green">
                            <i class="fa fa-circle-check"></i>
                            <div>
                                <p>Internship selected</p>
                                <span>You can change your selection in the listings page before proceeding.</span>
                            </div>
                        </div>
                        <div class="action-row">
                            <a href="applied-Internship-programs.php" class="btn-action btn-outline-action">
                                <i class="fa fa-arrow-right-arrow-left"></i> Change selection
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="info-box blue">
                            <i class="fa fa-circle-info"></i>
                            <div>
                                <p>Find and apply to an HTE</p>
                                <span>Browse the internship listings and click <strong>Interested</strong> on a posting to
                                    begin.</span>
                            </div>
                        </div>
                        <div class="action-row">
                            <a href="applied-Internship-programs.php" class="btn-action btn-primary-action">
                                <i class="fa fa-magnifying-glass"></i> Browse Internships
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STEP 3: Resume Upload -->
            <?php $s3done = $checklist['resume'] ?? false; ?>
            <div class="step-card <?= $s3done ? 'is-done' : 'is-active' ?>">
                <div class="step-header" onclick="toggle(this)">
                    <div class="step-num <?= $s3done ? 'sn-done' : 'sn-active' ?>">
                        <?= $s3done ? '<i class="fa fa-check"></i>' : 'X' ?>
                    </div>
                    <div class="step-meta">
                        <h3>Resume/Curriculum Vitae</h3>
                        <p>Upload your resume/curriculim vitae</p>
                    </div>
                    <div class="step-right">
                        <span class="pill <?= $s3done ? 'pill-done' : 'pill-active' ?>">
                            <?= $s3done ? '<i class="fa fa-check"></i> Done' : 'Pending' ?>
                        </span>
                        <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                    </div>
                </div>
                <div class="step-body">
                    <?php if ($s3done): ?>
                        <div class="confirmed">
                            <i class="fa fa-check-circle"></i> Marked complete &middot;
                            <?= stepDate($progress, 'resume') ?>
                        </div>
                    <?php endif; ?>
                    <div class="info-box blue">
                        <i class="fa fa-circle-info"></i>
                        <div>
                            <p>About this requirement</p>
                            <span>
                                Your Resume/Curriculum Vitae is a summary of your academic background, skills,
                                and relevant experience that your Host Training Establishment (HTE) will use to
                                get to know you before you start your internship. It should be updated and
                                accurate at the time of submission.
                                <br><br>
                                Prepare your resume ahead of time, then wait for your OJT Coordinator to confirm
                                that pre-deployment requirements are ready for pickup at the college secretary's
                                office. Once confirmed, go to the college secretary to pick it up.
                                <br><br>
                                Submit a photocopy of your resume to your OJT Coordinator for their records, and
                                give the original copy directly to your HTE upon deployment.
                            </span>
                        </div>
                    </div>
                    <!-- <div class="doc-row">
                        <i class="fa fa-file-lines" style="color:var(--brand);"></i>
                        <span class="doc-name">Addendum for Student Intern Placement</span>
                        <?php if ($internship_id): ?>
                            <a href="mou-preview.php?id=<?= $internship_id ?>&student_id=<?= $student_id ?>&action=addendum"
                                target="_blank" class="btn-action btn-outline-action"
                                style="padding:5px 10px;font-size:11.5px;">
                                <i class="fa fa-eye"></i> Preview
                            </a>
                            <a href="download-form.php?id=<?= $internship_id ?>&action=resume"
                                class="btn-action btn-outline-action" style="padding:5px 10px;font-size:11.5px;">
                                <i class="fa fa-download"></i> Download
                            </a>
                        <?php endif; ?>
                    </div> -->
                    <?php if (!$s3done): ?>
                        <?= uploadBlock('resume', 'I have picked up the MOU/Partnership Letter and submitted the Addendum to the college.', $progress, $internship_id) ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STEP 6: Pre-deployment Requirements Pickup (MOU / Partnership Letter) -->
            <?php $s6done = $checklist['addendum'] ?? false; ?>
            <div class="step-card <?= $s6done ? 'is-done' : 'is-active' ?>">
                <div class="step-header" onclick="toggle(this)">
                    <div class="step-num <?= $s6done ? 'sn-done' : 'sn-active' ?>">
                        <?= $s6done ? '<i class="fa fa-check"></i>' : 'X' ?>
                    </div>
                    <div class="step-meta">
                        <h3>MOU / Partnership Letter &amp; Addendum</h3>
                        <p>Pick up from college secretary — submit photocopy to OJT Coordinator, original to HTE</p>
                    </div>
                    <div class="step-right">
                        <span class="pill <?= $s6done ? 'pill-done' : 'pill-active' ?>">
                            <?= $s6done ? '<i class="fa fa-check"></i> Done' : 'Pending' ?>
                        </span>
                        <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                    </div>
                </div>
                <div class="step-body">
                    <?php if ($s6done): ?>
                        <div class="confirmed">
                            <i class="fa fa-check-circle"></i> Marked complete &middot;
                            <?= stepDate($progress, 'addendum') ?>
                        </div>
                    <?php endif; ?>
                    <div class="info-box amber">
                        <i class="fa fa-triangle-exclamation"></i>
                        <div>
                            <p>Wait for confirmation before pickup</p>
                            <span>Wait for your OJT Coordinator to confirm that your pre-deployment requirements
                                are ready for pickup at the college secretary's office.</span>
                        </div>
                    </div>
                    <div class="info-box blue">
                        <i class="fa fa-circle-info"></i>
                        <div>
                            <p>What to pick up</p>
                            <span>
                                <strong>MOU / Partnership Letter</strong> — photocopy submit to OJT coordinator,
                                return original to college secretary<br>
                                <strong>Addendum</strong> — auto-generated, download below
                            </span>
                        </div>
                    </div>
                    <div class="doc-row">
                        <i class="fa fa-file-lines" style="color:var(--brand);"></i>
                        <span class="doc-name">Addendum for Student Intern Placement</span>
                        <?php if ($internship_id): ?>
                            <a href="mou-preview.php?id=<?= $internship_id ?>&student_id=<?= $student_id ?>&action=addendum"
                                target="_blank" class="btn-action btn-outline-action"
                                style="padding:5px 10px;font-size:11.5px;">
                                <i class="fa fa-eye"></i> Preview
                            </a>
                            <a href="download-form.php?id=<?= $internship_id ?>&action=addendum"
                                class="btn-action btn-outline-action" style="padding:5px 10px;font-size:11.5px;">
                                <i class="fa fa-download"></i> Download
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php if (!$s6done): ?>
                        <?= uploadBlock('addendum', 'I have picked up the MOU/Partnership Letter and submitted the Addendum to the college.', $progress, $internship_id) ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STEP 4: Company Profile -->
            <?php $s4done = $checklist['company_profile'] ?? false; ?>
            <div class="step-card <?= $s4done ? 'is-done' : 'is-active' ?>">
                <div class="step-header" onclick="toggle(this)">
                    <div class="step-num <?= $s4done ? 'sn-done' : 'sn-active' ?>">
                        <?= $s4done ? '<i class="fa fa-check"></i>' : 'X' ?>
                    </div>
                    <div class="step-meta">
                        <h3>Company Profile</h3>
                        <p>Draft and submit to OJT Coordinator together with Acceptance Letter</p>
                    </div>
                    <div class="step-right">
                        <span class="pill <?= $s4done ? 'pill-done' : 'pill-active' ?>">
                            <?= $s4done ? '<i class="fa fa-check"></i> Done' : 'Pending' ?>
                        </span>
                        <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                    </div>
                </div>
                <div class="step-body">
                    <?php if ($s4done): ?>
                        <div class="confirmed">
                            <i class="fa fa-check-circle"></i> Marked complete &middot;
                            <?= stepDate($progress, 'company_profile') ?>
                        </div>
                    <?php endif; ?>
                    <div class="info-box blue">
                        <i class="fa fa-building"></i>
                        <div>
                            <p>Draft a Company Profile of your HTE</p>
                            <span>Prepare a brief Company Profile of your HTE. Submit it to your OJT Coordinator
                                together with your Acceptance Letter so the coordinator can acknowledge the HTE.</span>
                        </div>
                    </div>
                    <?php if (!$s4done): ?>
                        <?= uploadBlock('company_profile', 'I have drafted the Company Profile and submitted it to my OJT Coordinator.', $progress, $internship_id) ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STEP 7: Recommendation Letter -->
            <?php
            $s7done = $checklist['reco_letter'] ?? false;
            $s7na = !$needs_reco_letter;
            $s7cls = $s7na ? 'is-na' : ($s7done ? 'is-done' : 'is-active');
            ?>
            <div class="step-card <?= $s7cls ?>">
                <div class="step-header" onclick="toggle(this)">
                    <div class="step-num <?= ($s7done || $s7na) ? 'sn-done' : 'sn-active' ?>">
                        <?= ($s7done || $s7na) ? '<i class="fa fa-check"></i>' : 'X' ?>
                    </div>
                    <div class="step-meta">
                        <h3>Recommendation Letter</h3>
                        <p><?= $s7na ? 'Not required for your HTE type'
                            : ($s7done ? 'Photocopy submitted to OJT Coordinator, original to HTE'
                                : 'Photocopy to OJT Coordinator — original submit to HTE') ?></p>
                    </div>
                    <div class="step-right">
                        <span class="pill <?= $s7na ? 'pill-na' : ($s7done ? 'pill-done' : 'pill-active') ?>">
                            <?= $s7na ? '<i class="fa fa-minus"></i> N/A'
                                : ($s7done ? '<i class="fa fa-check"></i> Done' : 'Pending') ?>
                        </span>
                        <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                    </div>
                </div>
                <div class="step-body">
                    <?php if ($s7na): ?>
                        <div class="info-box green">
                            <i class="fa fa-circle-check"></i>
                            <div>
                                <p>Not required for your HTE type</p>
                                <span><?= $is_val_lgu
                                    ? 'Valenzuela LGU offices do not require a Recommendation Letter.'
                                    : 'PLV internal offices do not require a Recommendation Letter.' ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php if ($s7done): ?>
                            <div class="confirmed">
                                <i class="fa fa-check-circle"></i> Marked complete &middot;
                                <?= stepDate($progress, 'reco_letter') ?>
                            </div>
                        <?php endif; ?>
                        <div class="info-box amber">
                            <i class="fa fa-signature"></i>
                            <div>
                                <p>Picked up from college secretary</p>
                                <span>Submit a <strong>photocopy</strong> to your OJT Coordinator,
                                    then submit the <strong>original</strong> to your HTE.</span>
                            </div>
                        </div>
                        <div class="action-row">
                            <?php if ($internship_id): ?>
                                <a href="mou-preview.php?id=<?= $internship_id ?>&student_id=<?= $student_id ?>&action=rl"
                                    target="_blank" class="btn-action btn-outline-action">
                                    <i class="fa fa-eye"></i> Preview
                                </a>
                                <a href="download-form.php?id=<?= $internship_id ?>&action=rl"
                                    class="btn-action btn-primary-action">
                                    <i class="fa fa-download"></i> Download Letter
                                </a>
                            <?php else: ?>
                                <span class="pill pill-idle"><i class="fa fa-lock"></i> Select an internship first</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$s7done): ?>
                            <?= uploadBlock('reco_letter', 'I have submitted a photocopy of the Recommendation Letter to my OJT Coordinator and the original to my HTE.', $progress, $internship_id) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STEP 8: Waiver -->
            <?php
            $s8done = $checklist['waiver'] ?? false;
            $s8na = !$needs_waiver;
            $s8cls = $s8na ? 'is-na' : ($s8done ? 'is-done' : 'is-active');
            ?>
            <div class="step-card <?= $s8cls ?>">
                <div class="step-header" onclick="toggle(this)">
                    <div class="step-num <?= ($s8done || $s8na) ? 'sn-done' : 'sn-active' ?>">
                        <?= ($s8done || $s8na) ? '<i class="fa fa-check"></i>' : 'X' ?>
                    </div>
                    <div class="step-meta">
                        <h3>OJT Waiver</h3>
                        <p><?= $s8na ? 'Not required for PLV internal offices'
                            : ($s8done ? 'Signed by guardian, notarized &amp; submitted'
                                : 'Signed by guardian, notarized — submit to OJT Coordinator') ?></p>
                    </div>
                    <div class="step-right">
                        <span class="pill <?= $s8na ? 'pill-na' : ($s8done ? 'pill-done' : 'pill-active') ?>">
                            <?= $s8na ? '<i class="fa fa-minus"></i> N/A'
                                : ($s8done ? '<i class="fa fa-check"></i> Done' : 'Pending') ?>
                        </span>
                        <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                    </div>
                </div>
                <div class="step-body">
                    <?php if ($s8na): ?>
                        <div class="info-box green">
                            <i class="fa fa-circle-check"></i>
                            <div>
                                <p>Not required for PLV internal offices</p>
                                <span>Students interning within PLV do not need to submit a Waiver Form.</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php if ($s8done): ?>
                            <div class="confirmed">
                                <i class="fa fa-check-circle"></i> Marked complete &middot; <?= stepDate($progress, 'waiver') ?>
                            </div>
                        <?php endif; ?>
                        <div class="info-box amber">
                            <i class="fa fa-signature"></i>
                            <div>
                                <p>Must be signed by guardian and notarized</p>
                                <span>Have your parent/guardian sign the waiver, get it notarized,
                                    then submit to your OJT Coordinator.</span>
                            </div>
                        </div>
                        <div class="info-box orange">
                            <i class="fa fa-id-card"></i>
                            <div>
                                <p>Attach a copy of Parent's / Guardian's ID</p>
                                <span>Include a photocopy of your parent's or legal guardian's valid government-issued
                                    ID.</span>
                            </div>
                        </div>
                        <div class="action-row">
                            <?php if ($internship_id): ?>
                                <a href="mou-preview.php?id=<?= $internship_id ?>&student_id=<?= $student_id ?>&action=waiver"
                                    target="_blank" class="btn-action btn-outline-action">
                                    <i class="fa fa-eye"></i> Preview
                                </a>
                                <a href="download-form.php?id=<?= $internship_id ?>&action=waiver"
                                    class="btn-action btn-primary-action">
                                    <i class="fa fa-download"></i> Download Waiver
                                </a>
                            <?php else: ?>
                                <span class="pill pill-idle"><i class="fa fa-lock"></i> Select an internship first</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$s8done): ?>
                            <?= uploadBlock('waiver', 'The Waiver has been signed by my guardian, notarized, and submitted to my OJT Coordinator.', $progress, $internship_id) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STEP 1: Medical Certificate -->
            <?php $s1done = $checklist['medical_cert'] ?? false; ?>
            <div class="step-card <?= $s1done ? 'is-done' : 'is-active' ?>">
                <div class=" step-header" onclick="toggle(this)">
                <div class="step-num <?= $s1done ? 'sn-done' : 'sn-active' ?>">
                    <?= $s1done ? '<i class="fa fa-check"></i>' : 'X' ?>
                </div>
                <div class="step-meta">
                    <h3>Medical Certificate</h3>
                    <p>Fit-to-work cert from a DOH-accredited clinic — submit to OJT Coordinator</p>
                </div>
                <div class="step-right">
                    <span class="pill <?= $s1done ? 'pill-done' : 'pill-active' ?>">
                        <?= $s1done ? '<i class="fa fa-check"></i> Done' : 'Pending' ?>
                    </span>
                    <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                </div>
            </div>
            <div class="step-body">
                <?php if ($s1done): ?>
                    <div class="confirmed">
                        <i class="fa fa-check-circle"></i> Marked complete &middot;
                        <?= stepDate($progress, 'medical_cert') ?>
                    </div>
                <?php endif; ?>
                <div class="info-box blue">
                    <i class="fa fa-stethoscope"></i>
                    <div>
                        <p>Complete this first before applying to any HTE</p>
                        <span>Obtain a medical certificate tagged <strong>"fit to work / fit for OJT"</strong>
                            signed by a licensed physician from a DOH-accredited clinic.
                            Submit to your OJT Coordinator before anything else.</span>
                    </div>
                </div>
                <div class="info-box amber">
                    <i class="fa fa-triangle-exclamation"></i>
                    <div>
                        <p>Physical submission required</p>
                        <span>You must personally submit a photocopy to the PLV CEIT office — this cannot be
                            submitted online.</span>
                    </div>
                </div>
                <?php if (!$s1done): ?>
                    <?= uploadBlock('medical_cert', 'I have obtained my medical certificate (fit to work/OJT) and submitted a photocopy to my OJT Coordinator.', $progress, $internship_id) ?>
                <?php endif; ?>
            </div>
        </div>


        <!-- STEP 11: Internship Plan -->
        <?php $s11done = $checklist['internship_plan'] ?? false; ?>
        <div class="step-card <?= $s11done ? 'is-done' : 'is-active' ?>">
            <div class="step-header" onclick="toggle(this)">
                <div class="step-num <?= $s11done ? 'sn-done' : 'sn-active' ?>">
                    <?= $s11done ? '<i class="fa fa-check"></i>' : 'X' ?>
                </div>
                <div class="step-meta">
                    <h3>Internship Plan</h3>
                    <p>Fill out at HTE site — signed by Coordinator, Supervisor &amp; you</p>
                </div>
                <div class="step-right">
                    <span class="pill <?= $s11done ? 'pill-done' : 'pill-active' ?>">
                        <?= $s11done ? '<i class="fa fa-check"></i> Done' : 'Pending' ?>
                    </span>
                    <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                </div>
            </div>
            <div class="step-body">
                <?php if ($s11done): ?>
                    <div class="confirmed">
                        <i class="fa fa-check-circle"></i> Marked complete &middot;
                        <?= stepDate($progress, 'internship_plan') ?>
                    </div>
                <?php endif; ?>
                <div class="info-box blue">
                    <i class="fa fa-clipboard-list"></i>
                    <div>
                        <p>Complete this form at the HTE site</p>
                        <span>Fill in the Internship Plan (CEIT-OJTF-002) at your HTE.
                            It must be signed by your <strong>OJT Coordinator</strong>,
                            your <strong>HTE Supervisor</strong>, and <strong>yourself</strong>.
                            Submit before completing 30% of your required OJT hours.</span>
                    </div>
                </div>
                <div class="action-row">
                    <?php if ($internship_id): ?>
                        <a href="mou-preview.php?id=<?= $internship_id ?>&student_id=<?= $student_id ?>&action=internship_plan"
                            target="_blank" class="btn-action btn-outline-action">
                            <i class="fa fa-eye"></i> Preview
                        </a>
                        <a href="download-form.php?id=<?= $internship_id ?>&action=internship_plan"
                            class="btn-action btn-primary-action">
                            <i class="fa fa-download"></i> Download Internship Plan
                        </a>
                    <?php else: ?>
                        <span class="pill pill-idle"><i class="fa fa-lock"></i> Select an internship first</span>
                    <?php endif; ?>
                </div>
                <?php if (!$s11done): ?>
                    <?= uploadBlock('internship_plan', 'The Internship Plan has been completed, signed by all required parties, and submitted to my OJT Coordinator.', $progress, $internship_id) ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- STEP 10: Vicinity Map -->
        <?php $s10done = $checklist['vicinity_map'] ?? false; ?>
        <div class="step-card <?= $s10done ? 'is-done' : 'is-active' ?>">
            <div class="step-header" onclick="toggle(this)">
                <div class="step-num <?= $s10done ? 'sn-done' : 'sn-active' ?>">
                    <?= $s10done ? '<i class="fa fa-check"></i>' : 'X' ?>
                </div>
                <div class="step-meta">
                    <h3>Vicinity Map</h3>
                    <p>Auto-generated PDF showing route to your HTE — submit to OJT Coordinator</p>
                </div>
                <div class="step-right">
                    <span class="pill <?= $s10done ? 'pill-done' : 'pill-active' ?>">
                        <?= $s10done ? '<i class="fa fa-check"></i> Done' : 'Pending' ?>
                    </span>
                    <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                </div>
            </div>
            <div class="step-body">
                <?php if ($s10done): ?>
                    <div class="confirmed">
                        <i class="fa fa-check-circle"></i> Marked complete &middot;
                        <?= stepDate($progress, 'vicinity_map') ?>
                    </div>
                <?php endif; ?>
                <div class="info-box blue">
                    <i class="fa fa-map-location-dot"></i>
                    <div>
                        <p>Automatically generated from your HTE address</p>
                        <span>Download and submit to your OJT Coordinator.</span>
                    </div>
                </div>
                <div class="action-row">
                    <?php if ($internship_id): ?>
                        <a href="mou-preview.php?id=<?= $internship_id ?>&student_id=<?= $student_id ?>&action=vicinity"
                            target="_blank" class="btn-action btn-outline-action">
                            <i class="fa fa-eye"></i> Preview
                        </a>
                        <a href="download-form.php?id=<?= $internship_id ?>&action=vicinity"
                            class="btn-action btn-primary-action">
                            <i class="fa fa-download"></i> Download Vicinity Map
                        </a>
                    <?php else: ?>
                        <span class="pill pill-idle"><i class="fa fa-lock"></i> Select an internship first</span>
                    <?php endif; ?>
                </div>
                <?php if (!$s10done): ?>
                    <?= uploadBlock('vicinity_map', 'I have downloaded the Vicinity Map and submitted it to my OJT Coordinator.', $progress, $internship_id) ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- STEP 9: Oath of Undertaking -->
        <?php $s9done = $checklist['oath'] ?? false; ?>
        <div class="step-card <?= $s9done ? 'is-done' : 'is-active' ?>">
            <div class="step-header" onclick="toggle(this)">
                <div class="step-num <?= $s9done ? 'sn-done' : 'sn-active' ?>">
                    <?= $s9done ? '<i class="fa fa-check"></i>' : 'X' ?>
                </div>
                <div class="step-meta">
                    <h3>Oath of Undertaking</h3>
                    <p>Submit to OJT Coordinator — typically done during OJT Orientation</p>
                </div>
                <div class="step-right">
                    <span class="pill <?= $s9done ? 'pill-done' : 'pill-active' ?>">
                        <?= $s9done ? '<i class="fa fa-check"></i> Done' : 'Pending' ?>
                    </span>
                    <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                </div>
            </div>
            <div class="step-body">
                <?php if ($s9done): ?>
                    <div class="confirmed">
                        <i class="fa fa-check-circle"></i> Marked complete &middot; <?= stepDate($progress, 'oath') ?>
                    </div>
                <?php endif; ?>
                <div class="info-box blue">
                    <i class="fa fa-file-signature"></i>
                    <div>
                        <p>Automatically generated from your student profile</p>
                        <span>Download, sign, and submit the original to your OJT Coordinator.
                            This is typically done during the OJT Orientation.</span>
                    </div>
                </div>
                <div class="action-row">
                    <?php if ($internship_id): ?>
                        <a href="mou-preview.php?id=<?= $internship_id ?>&student_id=<?= $student_id ?>&action=oath"
                            target="_blank" class="btn-action btn-outline-action">
                            <i class="fa fa-eye"></i> Preview
                        </a>
                        <a href="download-form.php?id=<?= $internship_id ?>&action=oath"
                            class="btn-action btn-primary-action">
                            <i class="fa fa-download"></i> Download Oath
                        </a>
                    <?php else: ?>
                        <span class="pill pill-idle"><i class="fa fa-lock"></i> Select an internship first</span>
                    <?php endif; ?>
                </div>
                <?php if (!$s9done): ?>
                    <?= uploadBlock('oath', 'I have signed the Oath of Undertaking and submitted the original to my OJT Coordinator.', $progress, $internship_id) ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- STEP 12: Start OJT -->


    </div><!-- /.checklist -->
    <?php if ($selectedInternship): ?>
        <div class="step-card" style="margin-top:28px; border-color:#fecaca;">
            <div class="step-header" onclick="toggle(this)" style="cursor:pointer;">
                <div class="step-num" style="background:#fef2f2; color:#dc2626;">
                    <i class="fa fa-xmark"></i>
                </div>
                <div class="step-meta">
                    <h3 style="color:#dc2626;">Cancel Application</h3>
                    <p>Withdraw from this internship and reset your progress</p>
                </div>
                <div class="step-right">
                    <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                </div>
            </div>
            <div class="step-body">

                <div class="info-box amber">
                    <i class="fa fa-triangle-exclamation"></i>
                    <div>
                        <p>This cannot be undone</p>
                        <span>Cancelling will permanently delete all uploaded documents and reset
                            checklist progress for this internship. You can apply again afterward,
                            but you will need to redo the checklist from the start.</span>
                    </div>
                </div>

                <!-- Documents that will be deleted -->
                <div class="body-label" style="margin-top:14px;">Documents that will be removed</div>
                <?php
                $anyDocs = false;
                foreach ($checklist as $key => $done) {
                    if (!empty($progress[$key]['file_path'])) {
                        $anyDocs = true;
                        ?>
                        <div class="doc-row">
                            <i class="fa fa-file" style="color:var(--gray-400);"></i>
                            <span class="doc-name">
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) ?>
                            </span>
                            <a href="<?= htmlspecialchars($progress[$key]['file_path']) ?>" target="_blank"
                                style="font-size:11.5px; color:var(--blue); text-decoration:none;">
                                <i class="fa fa-eye"></i> View
                            </a>
                        </div>
                        <?php
                    }
                }
                if (!$anyDocs): ?>
                    <p style="font-size:12.5px; color:var(--gray-400);">No documents uploaded yet.</p>
                <?php endif; ?>

                <form action="student-progress.php" method="POST"
                    onsubmit="return confirm('Are you sure you want to cancel this application? All uploaded documents and progress for this internship will be permanently deleted. This cannot be undone.');"
                    style="margin-top:16px;">
                    <input type="hidden" name="action" value="cancel_application">
                    <input type="hidden" name="application_id"
                        value="<?= (int) ($selectedInternship['application_id'] ?? 0) ?>">
                    <button type="submit" class="btn-action" style="background:#dc2626; color:white;">
                        <i class="fa fa-trash-can"></i> Cancel Application
                    </button>
                </form>

            </div>
        </div>
    <?php endif; ?>
    </div><!-- /.page-wrap -->

    <!-- HTE Supervisor Modal -->
    <div id="supModal" class="sup-modal-overlay">
        <div class="sup-modal-box">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 style="font-family:'Syne',sans-serif; font-size:17px; font-weight:700; margin:0;">
                    HTE Supervisor Details
                </h2>
                <button onclick="closeSupModal()"
                    style="background:none; border:none; font-size:1.3rem; cursor:pointer; color:#888; line-height:1;">
                    &times;
                </button>
            </div>

            <form action="student-progress.php" method="POST">
                <input type="hidden" name="action" value="submit_hte_supervisor">
                <input type="hidden" name="internship_id" value="<?= $internship_id ?>">

                <div style="display:flex; flex-direction:column; gap:14px;">

                    <div>
                        <label
                            style="font-size:12px; font-weight:600; color:var(--gray-600); display:block; margin-bottom:5px;">
                            Full Name <span style="color:red;">*</span>
                        </label>
                        <input type="text" name="sup_full_name" required
                            value="<?= htmlspecialchars($supSubmission['full_name'] ?? '') ?>"
                            placeholder="e.g. Juan dela Cruz" style="width:100%; padding:9px 12px; border:1.5px solid var(--gray-200);
                                border-radius:8px; font-size:13px; font-family:'DM Sans',sans-serif;
                                outline:none; transition:border-color .2s;"
                            onfocus="this.style.borderColor='var(--brand)'"
                            onblur="this.style.borderColor='var(--gray-200)'">
                    </div>

                    <div>
                        <label
                            style="font-size:12px; font-weight:600; color:var(--gray-600); display:block; margin-bottom:5px;">
                            Email Address
                        </label>
                        <input type="email" name="sup_email"
                            value="<?= htmlspecialchars($supSubmission['email'] ?? '') ?>"
                            placeholder="e.g. supervisor@company.com" style="width:100%; padding:9px 12px; border:1.5px solid var(--gray-200);
                                border-radius:8px; font-size:13px; font-family:'DM Sans',sans-serif;
                                outline:none; transition:border-color .2s;"
                            onfocus="this.style.borderColor='var(--brand)'"
                            onblur="this.style.borderColor='var(--gray-200)'">
                    </div>

                    <div>
                        <label
                            style="font-size:12px; font-weight:600; color:var(--gray-600); display:block; margin-bottom:5px;">
                            Contact Number
                        </label>
                        <input type="text" name="sup_contact"
                            value="<?= htmlspecialchars($supSubmission['contact_number'] ?? '') ?>"
                            placeholder="e.g. 09XX-XXX-XXXX" style="width:100%; padding:9px 12px; border:1.5px solid var(--gray-200);
                                border-radius:8px; font-size:13px; font-family:'DM Sans',sans-serif;
                                outline:none; transition:border-color .2s;"
                            onfocus="this.style.borderColor='var(--brand)'"
                            onblur="this.style.borderColor='var(--gray-200)'">
                    </div>

                    <div>
                        <label
                            style="font-size:12px; font-weight:600; color:var(--gray-600); display:block; margin-bottom:5px;">
                            Company / HTE Name
                        </label>
                        <input type="text" name="sup_company" readonly
                            value="<?= htmlspecialchars($selectedInternship['company'] ?? '') ?>" style="width:100%; padding:9px 12px; border:1.5px solid var(--gray-200);
                                border-radius:8px; font-size:13px; font-family:'DM Sans',sans-serif;
                                background:var(--gray-100); color:var(--gray-600); outline:none;">
                        <small style="font-size:11px; color:var(--gray-400); margin-top:3px; display:block;">
                            Auto-filled from your selected internship
                        </small>
                    </div>

                    <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:4px;">
                        <button type="button" onclick="closeSupModal()" class="btn-action btn-outline-action">
                            Cancel
                        </button>
                        <button type="submit" class="btn-action btn-green-action">
                            <i class="fa fa-paper-plane"></i> Submit
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggle(header) {
            header.closest('.step-card').classList.toggle('is-open');
        }

        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(a => {
                a.classList.remove('show');
                setTimeout(() => a.remove(), 300);
            });
        }, 4000);

        function openSupModal() {
            document.getElementById('supModal').classList.add('is-open');
            document.body.style.overflow = 'hidden';
        }
        function closeSupModal() {
            document.getElementById('supModal').classList.remove('is-open');
            document.body.style.overflow = '';
        }
        document.getElementById('supModal').addEventListener('click', function (e) {
            if (e.target === this) closeSupModal();
        });

        <?php if ($supSubmission && $supSubmission['status'] === 'rejected'): ?>
            document.addEventListener('DOMContentLoaded', openSupModal);
        <?php endif; ?>


    </script>
</body>

</html>