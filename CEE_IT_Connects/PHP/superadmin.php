<?php

session_start();

// $_SESSION['user_id'] = 1;
// $_SESSION['user_type'] = 'superadmin';
// ── UI config (labels only) ──
$saPrograms = [
    'civil engineering' => 'CE',
    'electrical engineering' => 'EE',
    'information technology' => 'IT',
];

// ── DATA CONTRACT: variables the UI expects. Empty defaults = empty states. ──
// Header
$adminFullName = $adminFullName ?? 'Superadministrator';

// Term
$schoolYearOptions = $schoolYearOptions ?? [];
$schoolYear = $schoolYear ?? '';

// Cards (arrays keyed by lowercase program name, e.g. 'civil engineering' => 4)
$saStudentsByProg = $saStudentsByProg ?? [];     // active students per program
$saHte = $saHte ?? [];                           // active HTE advisers per department
$saOjt = $saOjt ?? [];                           // active OJT advisers per department
$saInternshipAdmins = $saInternshipAdmins ?? 0;  // active internship admins
$saSeatMax = $saSeatMax ?? 3;                    // max system admins
$saSeatsUsed = $saSeatsUsed ?? 0;                // active system admins
$saSeatsOpen = $saSeatsOpen ?? max(0, $saSeatMax - $saSeatsUsed);

// System administrators table: rows of
//   ['name', 'email', 'department' (lowercase program name), 'role' ('superadmin'), 'is_archived' (bool)]
$saSysAdmins = $saSysAdmins ?? [];

// Sections of the selected term, grouped by the adviser's department:
//   $saSecByProg['civil engineering'][room_id] = ['label' => 'Year 4 - Section 1', 'adviser' => 'Name', 'students' => ['Student A', ...]]
$saSecByProg = $saSecByProg ?? [];

// Admin activity log: rows of ['name', 'roles', 'department', 'activity', 'activity_date']
$saLogs = $saLogs ?? [];

// OJT hours: rows of ['program', 'required_hours']
$programHoursList = $programHoursList ?? [];

// Registration link state: 'show' | 'hide'
$registerVisible = $registerVisible ?? 'show';

// ── Presentation math ──
$saTotalStudents = array_sum($saStudentsByProg);
$saTotalHte = array_sum($saHte);
$saTotalOjt = array_sum($saOjt);

// ── Presentation helpers ──
function saBool($v)
{
    return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
}

function saProgLabel($dept, array $programs)
{
    $dept = strtolower(trim((string) $dept));
    return $programs[$dept] ?? ($dept === '' ? '—' : ucwords($dept));
}

function saBreak(array $map, array $programs)
{
    $parts = [];
    foreach ($programs as $k => $code) {
        $parts[] = $code . ' ' . (int) ($map[$k] ?? 0);
    }
    return implode(' · ', $parts);
}

function saRoleLabel($r)
{
    $r = strtolower(trim((string) $r));
    return [
        'superadmin' => 'System Admin',
        'superadministrator' => 'Superadministrator',
        'internship_admin' => 'Internship Admin',
    ][$r] ?? ucwords(str_replace('_', ' ', $r));
}

function deptCode($department)
{
    $department = strtolower(trim($department));
    $map = [
        'information technology' => 'BSIT',
        'electrical engineering' => 'BSEE',
        'civil engineering' => 'BSCE'
    ];
    if (isset($map[$department])) {
        return $map[$department];
    }
    $words = preg_split('/\s+/', $department);
    $initials = '';
    foreach ($words as $w) {
        if ($w !== '')
            $initials .= strtoupper($w[0]);
    }
    return $initials ?: '—';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Superadministrator | CEE IT Connects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --gradient-start: #FFB62F;
            --gradient-end: #E4572E;
            --primary-dark-blue: #272f54;
        }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif !important;
            background: linear-gradient(135deg, #f5f7ff, #eef1ff);
            min-height: 100vh;
            padding-top: 70px;
        }

        .sa-wrap {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px 40px 40px;
        }

        .sysAdm-section {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            width: 100%;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .home-hero {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #272f54 0%, #33448f 60%, #2c6fff 100%);
            border-radius: 14px;
            padding: 28px 32px;
            color: #fff;
            margin-bottom: 20px;
        }

        .home-hero--flush {
            margin: -24px -24px 20px -24px;
            width: calc(100% + 48px);
            border-radius: 14px 14px 0 0;
        }

        .home-hero::before,
        .home-hero::after {
            content: '';
            position: absolute;
            top: -30%;
            height: 160%;
            width: 60px;
            transform: skewX(-20deg);
        }

        .home-hero::before {
            right: 90px;
            background: rgba(255, 107, 44, 0.85);
        }

        .home-hero::after {
            right: 20px;
            background: rgba(255, 182, 47, 0.85);
        }

        .home-hero-bar {
            border-left: 4px solid #FFB62F;
            padding-left: 14px;
            position: relative;
            z-index: 1;
        }

        .home-hero h2 {
            font-size: 26px;
            font-weight: 800;
            margin: 2px 0 6px;
            color: #fff;
        }

        .home-hero p {
            margin: 0;
            opacity: .85;
            font-size: 14px;
        }

        .ojtc-th-tab {
            background: rgba(39, 111, 255, 0.08) !important;
            color: #272f54 !important;
            padding: 10px 12px !important;
            border-radius: 6px 6px 0 0;
        }

        .ojtc-hours-row {
            padding: 6px 8px;
            border-radius: 10px;
            transition: background-color .15s ease;
        }

        .ojtc-hours-row:hover {
            background: #f7f8fb;
        }

        .ojtc-badge-it {
            background: #e7effe;
            color: #1b4fce;
        }

        .ojtc-badge-ce {
            background: #fff1e0;
            color: #b85c00;
        }

        .ojtc-badge-ee {
            background: #fff8dc;
            color: #8a6d00;
        }

        .ojtc-badge-default {
            background: #f0f4ff;
            color: #272f54;
        }

        .ojtc-stat-card {
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .ojtc-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
        }

        .btn-update {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 40px;
            padding: 0 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            background: #FFE7B3 !important;
            color: #7a5200 !important;
            border: none !important;
            -webkit-tap-highlight-color: transparent;
            transition: background-color .15s ease, color .15s ease;
        }

        .btn-update:hover,
        .btn-update:active {
            background: #E4572E !important;
            color: #fff !important;
        }

        .btn-update:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .admin-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .admin-form input,
        .admin-form select {
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #ddd;
            font-size: 14px;
            outline: none;
            transition: 0.3s;
        }

        .admin-form input:focus,
        .admin-form select:focus {
            border-color: var(--gradient-end);
            box-shadow: 0 0 8px rgba(228, 87, 46, 0.3);
        }

        .sa-field {
            height: 40px;
            padding: 0 14px;
            border-radius: 10px;
            border: 1px solid #ddd;
            background: #fff;
            font-size: 13px;
            min-width: 200px;
            box-sizing: border-box;
        }

        @media (max-width: 768px) {
            .sa-wrap {
                padding: 10px 10px 30px;
            }

            .sysAdm-section {
                padding: 20px;
            }

            .home-hero--flush {
                margin: -20px -20px 20px -20px;
                width: calc(100% + 40px);
                border-radius: 0;
                padding: 20px 110px 20px 20px;
            }

            .sa-field {
                width: 100%;
                min-width: 0;
            }
        }
    </style>
</head>

<body>
    <?php include 'navbar.php'; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['info'])): ?>
        <div class="alert alert-info alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="bi bi-info-circle-fill me-2"></i><?= htmlspecialchars($_SESSION['info']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['info']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="bi bi-x-circle-fill me-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="sa-wrap">
        <div class="sysAdm-section">

            <!-- HEADER -->
            <div class="home-hero home-hero--flush">
                <div class="home-hero-bar">
                    <h2>Welcome back, <?= htmlspecialchars($adminFullName) ?></h2>
                    <p>Oversee the system administrators and every department's sections, activity, and settings —
                        all in one place.</p>
                </div>
            </div>

            <!-- TERM -->
            <form method="GET" class="d-flex align-items-center flex-wrap gap-2 mb-4">
                <label for="saTermSelect" class="fw-semibold small mb-0" style="color:#272f54;">
                    <i class="bi bi-calendar3 me-1"></i> Term
                </label>
                <select id="saTermSelect" name="sy" class="sa-field" onchange="this.form.submit()">
                    <?php foreach ($schoolYearOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $schoolYear ? 'selected' : '' ?>>
                            S.Y. <?= htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <!-- CARDS -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-6 col-xl">
                    <div class="card border-0 rounded-4 h-100 ojtc-stat-card" style="background:#EEF3FF;">
                        <div class="card-body p-4">
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                                    style="width:44px;height:44px;background:#272f54;">
                                    <i class="bi bi-mortarboard-fill text-white fs-5"></i>
                                </div>
                                <div class="flex-grow-1" style="min-width:90px;">
                                    <p class="small mb-1 fw-semibold text-uppercase"
                                        style="letter-spacing:.05em;font-size:11px;color:#272f54;">Students</p>
                                    <h2 class="fw-bold mb-0" style="color:#272f54;"><?= (int) $saTotalStudents ?></h2>
                                    <p class="mb-0 mt-1" style="font-size:11px;color:#272f54;">
                                        <?= htmlspecialchars(saBreak($saStudentsByProg, $saPrograms)) ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl">
                    <div class="card border-0 rounded-4 h-100 ojtc-stat-card" style="background:#EAF7F1;">
                        <div class="card-body p-4">
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                                    style="width:44px;height:44px;background:#1D9E75;">
                                    <i class="bi bi-building-fill text-white fs-5"></i>
                                </div>
                                <div class="flex-grow-1" style="min-width:90px;">
                                    <p class="small mb-1 fw-semibold text-uppercase"
                                        style="letter-spacing:.05em;font-size:11px;color:#14614a;">HTE Advisers</p>
                                    <h2 class="fw-bold mb-0" style="color:#0f4a38;"><?= (int) $saTotalHte ?></h2>
                                    <p class="mb-0 mt-1" style="font-size:11px;color:#14614a;">
                                        <?= htmlspecialchars(saBreak($saHte, $saPrograms)) ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl">
                    <div class="card border-0 rounded-4 h-100 ojtc-stat-card" style="background:#FFF6E3;">
                        <div class="card-body p-4">
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                                    style="width:44px;height:44px;background:#FFB62F;">
                                    <i class="bi bi-person-badge-fill fs-5" style="color:#3b2600;"></i>
                                </div>
                                <div class="flex-grow-1" style="min-width:90px;">
                                    <p class="small mb-1 fw-semibold text-uppercase"
                                        style="letter-spacing:.05em;font-size:11px;color:#7a5200;">OJT Advisers</p>
                                    <h2 class="fw-bold mb-0" style="color:#3b2600;"><?= (int) $saTotalOjt ?></h2>
                                    <p class="mb-0 mt-1" style="font-size:11px;color:#7a5200;">
                                        <?= htmlspecialchars(saBreak($saOjt, $saPrograms)) ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl">
                    <div class="card border-0 rounded-4 h-100 ojtc-stat-card" style="background:#FDEEE8;">
                        <div class="card-body p-4">
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                                    style="width:44px;height:44px;background:#E4572E;">
                                    <i class="bi bi-person-workspace text-white fs-5"></i>
                                </div>
                                <div class="flex-grow-1" style="min-width:90px;">
                                    <p class="small mb-1 fw-semibold text-uppercase"
                                        style="letter-spacing:.05em;font-size:11px;color:#a13d1f;">Internship Admin</p>
                                    <h2 class="fw-bold mb-0" style="color:#a13d1f;"><?= (int) $saInternshipAdmins ?>
                                    </h2>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl">
                    <div class="card border-0 rounded-4 h-100 ojtc-stat-card" style="background:#EFEBFA;">
                        <div class="card-body p-4">
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                                    style="width:44px;height:44px;background:#5b4bb7;">
                                    <i class="bi bi-shield-lock-fill text-white fs-5"></i>
                                </div>
                                <div class="flex-grow-1" style="min-width:90px;">
                                    <p class="small mb-1 fw-semibold text-uppercase"
                                        style="letter-spacing:.05em;font-size:11px;color:#3f3380;">System Admins</p>
                                    <h2 class="fw-bold mb-0" style="color:#2d2461;"><?= (int) $saSeatsUsed ?></h2>
                                    <p class="mb-0 mt-1" style="font-size:11px;color:#3f3380;">
                                        <?= (int) $saSeatsUsed ?> of <?= (int) $saSeatMax ?> seats &middot;
                                        <?= (int) $saSeatsOpen ?> open
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 1. SYSTEM ADMINISTRATORS -->
            <div class="card border-0 rounded-4 shadow-sm mb-4">
                <div
                    class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-shield-lock" style="color:#272f54;"></i>
                        <h6 class="fw-bold mb-0" style="color:#272f54;">System Administrators</h6>
                    </div>
                    <button type="button" class="btn-update" data-bs-toggle="modal"
                        data-bs-target="#addSystemAdminModal" <?= $saSeatsOpen <= 0 ? 'disabled title="All seats are taken"' : '' ?>>
                        <i class="bi bi-person-plus me-1"></i> Create System Admin
                    </button>
                </div>
                <div class="card-body px-4 pb-4 pt-2">
                    <p class="text-muted small mb-3">Accounts created and managed by the superadministrator.</p>
                    <?php if (empty($saSysAdmins)): ?>
                        <p class="text-muted small mb-0">No system administrators yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" style="font-size:14px;">
                                <thead>
                                    <tr style="color:#aaa;font-size:12px;text-transform:uppercase;letter-spacing:.04em;">
                                        <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Name</th>
                                        <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Email</th>
                                        <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Department</th>
                                        <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Role</th>
                                        <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($saSysAdmins as $a):
                                        $archived = saBool($a['is_archived']); ?>
                                        <tr>
                                            <td class="fw-semibold" style="color:#272f54;">
                                                <?= htmlspecialchars($a['name']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($a['email']) ?></td>
                                            <td><?= htmlspecialchars(saProgLabel($a['department'], $saPrograms)) ?></td>
                                            <td><?= htmlspecialchars(saRoleLabel($a['role'])) ?></td>
                                            <td>
                                                <span class="badge rounded-pill px-3"
                                                    style="font-weight:500;font-size:12px;background:<?= $archived ? '#fdeaea' : '#e6f4ea' ?>;color:<?= $archived ? '#a32d2d' : '#1b7a3d' ?>;">
                                                    <?= $archived ? 'Archived' : 'Active' ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2-4. SECTIONS PER PROGRAM -->
            <?php foreach ($saPrograms as $pk => $pc):
                $progSections = $saSecByProg[$pk] ?? []; ?>
                <div class="card border-0 rounded-4 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                        <i class="bi bi-diagram-3" style="color:#272f54;"></i>
                        <h6 class="fw-bold mb-0" style="color:#272f54;"><?= htmlspecialchars($pc) ?> Sections</h6>
                    </div>
                    <div class="card-body px-4 pb-4 pt-2">
                        <p class="text-muted small mb-3">
                            S.Y. <?= htmlspecialchars($schoolYear) ?> &middot;
                            <?= (int) ($saOjt[$pk] ?? 0) ?> OJT advisers &middot;
                            <?= (int) ($saHte[$pk] ?? 0) ?> HTE advisers &middot;
                            <?= (int) ($saStudentsByProg[$pk] ?? 0) ?> students
                        </p>
                        <?php if (empty($progSections)): ?>
                            <p class="text-muted small mb-0">No <?= htmlspecialchars($pc) ?> sections for this term.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0" style="font-size:14px;">
                                    <thead>
                                        <tr style="color:#aaa;font-size:12px;text-transform:uppercase;letter-spacing:.04em;">
                                            <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Section</th>
                                            <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Adviser</th>
                                            <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Students</th>
                                            <th class="border-0 pb-2 fw-semibold ojtc-th-tab"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($progSections as $rid => $sec): ?>
                                            <tr>
                                                <td class="fw-semibold" style="color:#272f54;">
                                                    <?= htmlspecialchars($sec['label']) ?>
                                                </td>
                                                <td><?= htmlspecialchars($sec['adviser']) ?></td>
                                                <td><?= count($sec['students']) ?></td>
                                                <td class="text-end">
                                                    <?php if (!empty($sec['students'])): ?>
                                                        <button type="button" class="btn-update"
                                                            style="height:32px;padding:0 12px;font-size:12px;" data-bs-toggle="collapse"
                                                            data-bs-target="#saSec<?= (int) $rid ?>">
                                                            <i class="bi bi-chevron-down me-1"></i> Students
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if (!empty($sec['students'])): ?>
                                                <tr id="saSec<?= (int) $rid ?>" class="collapse">
                                                    <td colspan="4" class="bg-light">
                                                        <div class="d-flex flex-wrap gap-2 py-1">
                                                            <?php foreach ($sec['students'] as $sn): ?>
                                                                <span class="badge rounded-pill px-3 ojtc-badge-default"
                                                                    style="font-weight:500;font-size:12px;">
                                                                    <?= htmlspecialchars($sn) ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- 5. ADMIN ACTIVITY LOG -->
            <div class="card border-0 rounded-4 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                    <i class="bi bi-activity" style="color:#272f54;"></i>
                    <h6 class="fw-bold mb-0" style="color:#272f54;">Admin Activity Log</h6>
                </div>
                <div class="card-body px-4 pb-4 pt-2">
                    <p class="text-muted small mb-3">Latest actions by administrators.</p>
                    <?php if (empty($saLogs)): ?>
                        <p class="text-muted small mb-0">No admin activity yet.</p>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height:360px;overflow-y:auto;">
                            <table class="table table-hover align-middle mb-0" style="font-size:14px;">
                                <thead>
                                    <tr style="color:#aaa;font-size:12px;text-transform:uppercase;letter-spacing:.04em;">
                                        <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Name</th>
                                        <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Role</th>
                                        <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Department</th>
                                        <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Activity</th>
                                        <th class="border-0 pb-2 fw-semibold ojtc-th-tab">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($saLogs as $log): ?>
                                        <tr>
                                            <td class="fw-semibold" style="color:#272f54;">
                                                <?= htmlspecialchars($log['name']) ?>
                                            </td>
                                            <td><?= htmlspecialchars(saRoleLabel($log['roles'])) ?></td>
                                            <td><?= htmlspecialchars(saProgLabel($log['department'], $saPrograms)) ?></td>
                                            <td><?= htmlspecialchars($log['activity']) ?></td>
                                            <td class="text-muted" style="font-size:13px;white-space:nowrap;">
                                                <?= date('M j, Y • g:i A', strtotime($log['activity_date'])) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 6. OJT HOURS -->
            <div class="card border-0 rounded-4 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                    <i class="bi bi-clock-history" style="color:#272f54;"></i>
                    <h6 class="fw-bold mb-0" style="color:#272f54;">OJT Hours by Program</h6>
                </div>
                <div class="card-body px-4 pb-4 pt-2">
                    <?php if (empty($programHoursList)): ?>
                        <p class="text-muted small mb-0">No programs configured yet.</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($programHoursList as $ph):
                                $ojtcBadgeClass = [
                                    'BSIT' => 'ojtc-badge-it',
                                    'BSCE' => 'ojtc-badge-ce',
                                    'BSEE' => 'ojtc-badge-ee',
                                ][deptCode($ph['program'])] ?? 'ojtc-badge-default';
                                ?>
                                <div class="d-flex align-items-center justify-content-between ojtc-hours-row">
                                    <p class="fw-semibold mb-0" style="color:#272f54;font-size:14px;">
                                        <?= htmlspecialchars($ph['program']) ?>
                                    </p>
                                    <span class="badge rounded-pill px-3 <?= $ojtcBadgeClass ?>"
                                        style="font-weight:500;font-size:12px;">
                                        <?= (int) $ph['required_hours'] ?> hrs
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 7. REGISTRATION LINK -->
            <div id="register-toggle" class="card border-0 rounded-4 shadow-sm mt-2">
                <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                    <i class="bi bi-toggle2-on" style="color:#272f54;"></i>
                    <h6 class="fw-bold mb-0" style="color:#272f54;">Registration Link</h6>
                </div>
                <div class="card-body px-4 pb-4 pt-2">
                    <p class="text-muted small">Control whether the registration link is visible on the login page.
                    </p>
                    <form method="POST" class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <p class="mb-0 d-flex align-items-center gap-2">Current Status:
                            <span class="badge rounded-pill px-3 py-2 d-inline-flex align-items-center gap-1"
                                style="background: <?= $registerVisible === 'show' ? '#e6f4ea' : '#fdeaea' ?>;">
                                <i class="bi <?= $registerVisible === 'show' ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"
                                    style="font-size:11px; color: <?= $registerVisible === 'show' ? 'green' : 'red' ?>;"></i>
                                <strong style="color: <?= $registerVisible === 'show' ? 'green' : 'red' ?>">
                                    <?= $registerVisible === 'show' ? 'Visible' : 'Hidden' ?>
                                </strong>
                            </span>
                        </p>
                        <button type="submit" name="toggle_register" class="btn-update">
                            <?= $registerVisible === 'show' ? 'Hide Registration Link' : 'Show Registration Link' ?>
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <!-- CREATE SYSTEM ADMIN MODAL -->
    <div class="modal fade" id="addSystemAdminModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4">
                <form method="POST" autocomplete="off" onsubmit="return confirm('Create this system admin account?');">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-person-plus me-2"></i><strong>Create System Admin</strong>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body admin-form">
                        <p class="text-muted small mb-0">
                            <?= (int) $saSeatsUsed ?> of <?= (int) $saSeatMax ?> system admin seats used.
                        </p>
                        <input type="text" name="name" placeholder="Full Name" required>
                        <input type="email" name="email" placeholder="Email Address" required>
                        <input type="password" name="password" placeholder="Password" required>
                        <div style="color:#888;font-size:10px;">Password must be at least 8 characters long, contains
                            an uppercase and lowercase letter, a number, and a special character.</div>
                        <select name="department" required>
                            <option value="" disabled selected>Select Department</option>
                            <?php foreach ($saPrograms as $pk => $pc): ?>
                                <option value="<?= htmlspecialchars($pk) ?>"><?= htmlspecialchars(ucwords($pk)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-update" data-bs-dismiss="modal"
                            style="background:#eee !important;color:#555 !important;">Cancel</button>
                        <button type="submit" name="create_system_admin" class="btn-update">Create System Admin</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        setTimeout(() => {
            const alert = document.getElementById('flashAlert');
            if (alert) {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 300);
            }
        }, 3000);
    </script>
</body>

</html>