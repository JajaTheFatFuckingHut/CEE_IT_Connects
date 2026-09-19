<?php
require 'db.php';
require 'auth.php';

$params = [];
$conditions = [];

$studentstmt = $pdo->prepare("SELECT program FROM students WHERE id = ?");
$studentstmt->execute([$_SESSION['user_id']]);
$student = $studentstmt->fetch(PDO::FETCH_ASSOC);
$studentProgram = $student['program'] ?? '';

if (!empty($_GET['program'])) {
    $conditions[] = "program = :program";
    $params['program'] = $_GET['program'];
}

if (!empty($_GET['year'])) {
    $conditions[] = "year_level = :year";
    $params['year'] = $_GET['year'];
}

if (!empty($_GET['gpa'])) {
    $conditions[] = "min_gpa <= :gpa";
    $params['gpa'] = $_GET['gpa'];
}

if (!empty($_GET['deadline'])) {
    if ($_GET['deadline'] == "week") {
        $conditions[] = "deadline BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '7 days'";
    }
    if ($_GET['deadline'] == "month") {
        $conditions[] = "deadline BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'";
    }
    if ($_GET['deadline'] == "future") {
        $conditions[] = "deadline >= CURRENT_DATE";
    }
}

if (!empty($_GET['internship_type'])) {
    $conditions[] = "internship_type = :internship_type";
    $params['internship_type'] = $_GET['internship_type'];
}

if (!empty($_GET['company_classification'])) {
    $conditions[] = 'company_classification = :company_classification';
    $params['company_classification'] = $_GET['company_classification'];
}

$sql = "SELECT * FROM internships";
if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$internships = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php $page = 'opportunity'; ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internships | CEE IT Connects</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../CSS/index-style.css">
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,300&family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,600;1,9..144,300&display=swap"
        rel="stylesheet">

    <style>
        :root {
            --navy: #1b2240;
            --orange: #f55d1e;
            --orange-lt: #fff1eb;
            --cream: #faf9f7;
            --border: #e4e2de;
            --text: #1b2240;
            --muted: #7a7a8a;
            --card-shadow: 0 2px 12px rgba(27, 34, 64, .07);
            --card-shadow-hover: 0 8px 32px rgba(27, 34, 64, .13);
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            /* background: #e8f1ff !important; */
            background: var(--cream) !important;
            color: var(--text);
            padding-top: 80px;

        }

        /* ── Page header ── */
        .page-header {
            padding: 36px 0 28px;
            opacity: 0.95;
            margin-top: -20px;
        }

        .page-header h1 {
            font-family: 'Geogrotesque', sans-serif;
            font-weight: 600;
            font-size: 2rem;
            color: var(--orange);
            margin: 0;
            letter-spacing: -0.02em;
        }

        .page-header p {
            color: var(--orange);
            margin: 4px 0 0;
            font-size: 1rem;
        }

        /* ── Layout ── */
        .listing-wrapper {
            padding: 32px 0 60px;
        }

        /* ── Sidebar ── */
        .sidebar-card {
            /* background: #fff; */
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 22px 20px;
            position: sticky;
            top: 20px;
            background: var(--cream);
            border: 1px solid #ffd4b8;
        }

        .sidebar-card .sidebar-title {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 16px;

        }

        .search-wrap {
            position: relative;
            margin-bottom: 20px;
        }

        .search-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: .85rem;
        }

        .search-wrap input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 9px 12px 9px 34px;
            font-size: .88rem;
            font-family: inherit;
            background: var(--cream);
            color: var(--text);
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }

        .search-wrap input:focus {
            border-color: var(--orange);
            box-shadow: 0 0 0 3px rgba(245, 93, 30, .12);
        }

        .filter-section {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .filter-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .filter-label {
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--navy);
            margin-bottom: 10px;
            display: block;
        }

        .fc-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 0;
            font-size: .88rem;
            cursor: pointer;
            color: #444;
        }

        .fc-item input[type="radio"] {
            accent-color: var(--orange);
            width: 15px;
            height: 15px;
            flex-shrink: 0;
        }

        .fc-item:hover {
            color: var(--navy);
        }

        .filter-select {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 10px;
            font-size: .88rem;
            font-family: inherit;
            background: var(--cream);
            color: var(--text);
            outline: none;
            cursor: pointer;
            transition: border-color .2s;
        }

        .filter-select:focus {
            border-color: var(--orange);
        }

        .btn-clear-filters {
            display: block;
            width: 100%;
            text-align: center;
            padding: 9px;
            border-radius: 8px;
            background: var(--navy);
            color: #fff;
            font-size: .85rem;
            font-weight: 600;
            text-decoration: none;
            transition: background .2s, opacity .2s;
            margin-bottom: 20px;
        }

        .btn-clear-filters:hover {
            background: #111826;
            color: #fff;
        }

        /* ── Results bar ── */
        .results-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .results-count {
            font-size: .85rem;
            color: var(--muted);
        }

        .results-count strong {
            color: var(--navy);
        }

        /* ── Listing card ── */
        .listing-card {
            background: var(--cream);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 26px 28px;
            margin-bottom: 16px;
            box-shadow: var(--card-shadow);
            transition: transform .22s ease, box-shadow .22s ease;
            overflow: hidden;
            position: relative;
            /* background:#fff5f0;  */
            border: 1px solid #ffd4b8;
        }

        .listing-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 4px;
        }

        .card-title {
            font-family: 'Geogrotesque', sans-serif;
            font-size: 1.35rem;
            font-weight: 600;
            color: var(--navy);
        }

        .card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin: 8px 0 12px;
        }

        .card-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: .78rem;
            color: var(--muted);
        }

        .card-desc {
            font-size: .9rem;
            color: #555;
            line-height: 1.65;
            margin-bottom: 14px;
        }

        .card-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 18px;
        }

        /* action buttons */
        .card-actions {
            display: flex;
            gap: 8px;
            margin-top: 4px;
            flex-wrap: wrap;
        }

        .btn-readmore {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            border-radius: 8px;
            border: 1.5px solid var(--navy);
            background: var(--navy);
            color: #fff;
            font-size: .85rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background .2s, color .2s;
        }

        .btn-readmore:hover,
        .btn-readmore.active {
            background: #171b2c;
            color: #fff;
        }

        .btn-interested {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            border-radius: 8px;
            border: 1.5px solid var(--orange);
            background: var(--orange);
            color: #fff;
            font-size: .85rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background .2s, border-color .2s;
        }

        .btn-interested:hover {
            background: #d94c10;
            border-color: #d94c10;
        }

        /* ── Details panel ── */
        .details-panel {
            display: none;
            margin-top: 20px;
            border-top: 1px solid var(--border);
            padding-top: 20px;
            animation: fadeSlide .2s ease;
        }

        .details-panel.open {
            display: block;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 24px;
        }

        @media (max-width: 640px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        .detail-row {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            font-size: .88rem;
        }

        .detail-label {
            font-weight: 600;
            color: var(--navy);
            min-width: 90px;
            flex-shrink: 0;
        }

        .detail-val {
            color: #555;
        }

        .detail-val a {
            color: var(--orange);
            text-decoration: none;
        }

        .detail-val a:hover {
            text-decoration: underline;
        }

        .details-section-title {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--muted);
            margin: 0 0 12px;
        }

        /* ── File rows inside details panel ── */
        .file-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
            gap: 12px;
            flex-wrap: wrap;
        }

        .file-row:last-child {
            border-bottom: none;
        }

        .file-name {
            font-size: .88rem;
            font-weight: 600;
            color: var(--navy);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .file-btns {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }

        .btn-preview {
            padding: 6px 14px;
            border-radius: 6px;
            border: 1.5px solid var(--navy);
            background: transparent;
            color: var(--navy);
            font-size: .8rem;
            font-weight: 600;
            text-decoration: none;
            transition: background .2s, color .2s;
        }

        .btn-preview:hover {
            background: var(--navy);
            color: #fff;
        }

        .btn-dl {
            padding: 6px 14px;
            border-radius: 6px;
            border: 1.5px solid var(--text);
            color: var(--navy);
            font-size: .8rem;
            font-weight: 600;
            text-decoration: none;
            transition: background .2s;
        }

        .btn-dl:hover {
            background: var(--navy);
            color: #fff;
        }

        /* ── empty state ── */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 14px;
            color: var(--border);
        }

        .empty-state p {
            font-size: .95rem;
            margin: 0;
        }

        @keyframes fadeSlide {
            from {
                opacity: 0;
                transform: translateY(-6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ── Mobile ── */
        .mobile-filter-overlay {
            display: none;
        }

        .internship-mobile-header,
        .mobile-filter-icon-btn {
            display: none;
        }

        @media (max-width: 768px) {
            .listing-wrapper {
                padding: 15px 0 20px;
            }

            /* Header row */
            .internship-mobile-header {
                display: flex !important;
                align-items: center;
                gap: 10px;
                margin-bottom: 12px;
                width: 100%;
            }

            .internship-mobile-header h4 {
                margin: 0 !important;
                white-space: nowrap;
            }

            .internship-mobile-header input {
                flex: 1;
                background: var(--cream);
            }

            /* Filter icon button */
            .mobile-filter-icon-btn {
                display: flex !important;
                align-items: center;
                justify-content: center;
                background: white;
                border: 1px solid #bbb;
                border-radius: 8px;
                width: 42px;
                height: 42px;
                cursor: pointer;
                flex-shrink: 0;
                font-size: 1.1rem;
                color: #333;
            }

            /* Dark overlay behind modal */
            .mobile-filter-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.4);
                z-index: 1050;
                align-items: flex-end;
            }

            .mobile-filter-overlay.open {
                display: flex !important;
            }

            /* Bottom sheet */
            .mobile-filter-sheet {
                background: white;
                width: 100%;
                border-radius: 20px 20px 0 0;
                padding: 24px 20px 32px;
                max-height: 80vh;
                overflow-y: auto;
                animation: slideUp 0.3s ease;
            }

            @keyframes slideUp {
                from {
                    transform: translateY(100%);
                }

                to {
                    transform: translateY(0);
                }
            }

            .mobile-filter-sheet-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }

            .mobile-filter-sheet-header h5 {
                font-weight: 700;
                margin: 0;
                font-size: 1.1rem;
            }

            .mobile-filter-close {
                background: none;
                border: none;
                font-size: 1.2rem;
                cursor: pointer;
                color: #333;
            }

            /* Two-column layout for Deadline + Internship Type */
            .mobile-filter-row {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px;
                margin-bottom: 20px;
            }

            .mobile-filter-group strong {
                display: block;
                font-size: 14px;
                margin-bottom: 8px;
            }

            .mobile-filter-group .form-check {
                margin-bottom: 6px;
                font-size: 14px;
            }

            .mobile-filter-group-full {
                margin-bottom: 16px;
            }

            .mobile-filter-group-full strong {
                display: block;
                font-size: 14px;
                margin-bottom: 8px;
            }

            .col-lg-3 {
                display: none;
            }

            /* Make listing cards full width on mobile */
            .col-lg-9 {
                width: 100%;
                flex: 0 0 100%;
                max-width: 100%;
            }

            .desktop-header {
                display: none;
            }

            .col-lg-9>small.text-muted:first-of-type {
                display: none;
            }

            .container-fluid.px-3 h1 {
                font-size: 25px;
            }

            .container-fluid.px-3 p {
                font-size: 13px;
            }

            .btn-readmore {
                padding: 5px 20px;
                font-size: .85rem;
            }

            .btn-readmore.active {
                background: #171b2c;
                color: #fff;
            }

            .btn-interested {
                padding: 5px 20px;
                font-size: .85rem;
                margin-left: 9px;
            }

            .btn-interested.active {
                background: #d94c10;
                border-color: #d94c10;
            }
        }

        /* Hide mobile elements on desktop */
        .internship-mobile-header,
        .mobile-filter-icon-btn {
            display: none;
        }

        .page-banner {
            background: #e8f1ff;
            border: 1px solid #2563eb;
            border-radius: 12px;
            padding: 24px 28px;
            /* margin-bottom: 24px;
            margin-top: 40px; */
            margin: 24px 48px 0px 48px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .page-banner-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: #e8f1ff;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .page-banner-icon i {
            color: #2563eb;
            font-size: 32px;
        }

        .page-banner-text {
            min-width: 0;
        }

        .page-banner-text h2 {
            color: #2563eb;
            font-weight: 700;
            font-size: 1.8rem;
            margin: 0 0 2px 0;
            word-wrap: break-word;
        }

        .page-banner-text p {
            color: #4a68a8;
            font-size: 20px;
            margin: 0;
        }

        @media (max-width: 480px) {
            .page-banner {
                padding: 16px 18px;
                margin-top: 24px;
                gap: 12px;
            }

            .page-banner-icon {
                width: 38px;
                height: 38px;
            }

            .page-banner-icon i {
                font-size: 20px;
            }

            .page-banner-text h2 {
                font-size: 1.2rem;
            }

            .page-banner-text p {
                font-size: 14px;
            }
        }
    </style>
</head>

<body>

    <?php include 'navbar.php'; ?>

    <!-- Page header -->
    <div class="page-header">
        <!-- <div class="container-fluid px-5">
            <h1>Internship Opportunities</h1>
            <p style="font-family: 'Poppins', sans-serif;">Find and apply for internships matching your interests</p>
        </div> -->
        <!-- <div style="background:linear-gradient(135deg,#272f54,#ff6b2c); border-radius:14px; padding:28px 32px; margin-bottom:24px; color:#fff;">
            <h2 style="font-weight:700; margin:0 0 4px 0; font-size:1.6rem;">Internship Opportunities</h2>
            <p style="margin:0; font-size:14px; opacity:.85;">Find and apply for internships matching your interests</p>
        </div> -->
        <!-- <div style="background:#272f54; border-radius:12px; padding:24px 28px; margin-bottom:24px; position:relative; overflow:hidden;">
            <div style="position:absolute; top:0; right:0; bottom:0; width:6px; background:#ff6b2c;"></div>
            <h2 style="color:#fff; font-weight:700; font-size:1.6rem; margin:0 0 4px 0;">Internship Opportunities</h2>
            <p style="color:#9aa3c7; font-size:14px; margin:0;">Find and apply for internships matching your interests</p>
        </div> -->
        <!-- <div style="background:#fff5f0; border:1px solid #ffd4b8; border-radius:12px; padding:24px; margin: 24px 48px 0px 48px; display:flex; align-items:center; gap:16px;">
            <div style="width:54px; height:54px; border-radius:10px; background:#ff6b2c; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <i class="fa fa-briefcase" style="color:#fff; font-size:20px;"></i>
            </div>
            <div>
                <h2 style="color: #253856; font-weight:700; font-size:1.8rem; margin:0 0 2px 0;">Internship Opportunities</h2>
                <p style="color: #94a3b8; font-size:20px; margin:0;">Find and apply for internships matching your interests</p>
            </div>
        </div> -->

        <div class="page-banner">
            <div class="page-banner-icon"><i class="fa fa-briefcase"></i></div>
            <div class="page-banner-text">
                <h2>Internship Opportunities</h2>
                <p>Find and apply for internships matching your interests.</p>
            </div>
        </div>
    </div>

    <section class="listing-wrapper">
        <div class="container-fluid px-5">
            <div class="row g-4">

                <!-- ── Sidebar ── -->
                <div class="col-lg-3">
                    <form method="GET" id="filter-form">
                        <div class="sidebar-card">

                            <!-- Search -->
                            <div class="search-wrap">
                                <i class="fa fa-search"></i>
                                <input type="text" id="search-internship" placeholder="Search listings…">
                            </div>

                            <a href="applied-Internship-programs.php" class="btn-clear-filters">
                                <i class="fa fa-rotate-left" style="margin-right:6px;font-size:.8rem;"></i>Clear Filters
                            </a>

                            <!-- Program -->
                            <div class="filter-section">
                                <span class="filter-label">Program</span>
                                <?php
                                $programs = ['Information Technology', 'Civil Engineering', 'Electrical Engineering'];
                                foreach ($programs as $p):
                                    $checked = (isset($_GET['program']) && $_GET['program'] == $p) ? 'checked' : '';
                                    ?>
                                    <label class="fc-item">
                                        <input type="radio" name="program" value="<?= htmlspecialchars($p) ?>" <?= $checked ?>>
                                        <?= htmlspecialchars($p) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <!-- Deadline -->
                            <!-- <div class="filter-section">
                                <span class="filter-label">Deadline</span>
                                <?php
                                // $deadlines = ['week' => 'Due this week', 'month' => 'Due this month', 'future' => 'Upcoming'];
                                // foreach ($deadlines as $val => $label):
                                //     $checked = (isset($_GET['deadline']) && $_GET['deadline'] == $val) ? 'checked' : '';
                                ?>
                                    <label class="fc-item">
                                        <input type="radio" name="deadline" value="<?= $val ?>" <?= $checked ?>>
                                        <?= $label ?>
                                    </label>
                                <?php //endforeach; ?>
                            </div> -->

                            <!-- Internship Type -->
                            <!-- <div class="filter-section">
                                <span class="filter-label">Internship Type</span>
                                <?php
                                // $types = ['All' => 'All types', 'paid' => 'With stipend', 'unpaid' => 'Without stipend'];
                                // foreach ($types as $val => $label):
                                //     $checked = (isset($_GET['internship_type']) && $_GET['internship_type'] == $val) ? 'checked' : '';
                                ?>
                                    <label class="fc-item">
                                        <input type="radio" name="internship_type" value="<?= $val ?>" <?= $checked ?>>
                                        <?= $label ?>
                                    </label>
                                <?php //endforeach; ?>
                            </div> -->

                            <!-- Company Classification -->
                            <div class="filter-section">
                                <span class="filter-label">Company Type</span>
                                <?php
                                $classes = [
                                    '' => 'All classifications',
                                    'private' => 'Private Sector',
                                    'public' => 'Public / Government',
                                    'institution' => 'Academic & Research',
                                    'NGO' => 'Nonprofit & Civil Society',
                                    'multilateral_org' => 'International Organizations',
                                    'media' => 'Creative & Media',
                                    'technology' => 'Technology & Innovation',
                                    'healthcare' => 'Healthcare & Social Services',
                                    'industrial' => 'Industrial & Manufacturing',
                                    'financial' => 'Financial & Business',
                                    'tourism' => 'Hospitality & Tourism',
                                    'freelance' => 'Freelance / Gig-Based',
                                    'religious' => 'Religious & Faith-Based',
                                    'hybrid' => 'Public-Private Partnerships',
                                ];
                                $selClass = $_GET['company_classification'] ?? '';
                                ?>
                                <select class="filter-select" name="company_classification"
                                    onchange="document.getElementById('filter-form').submit()">
                                    <?php foreach ($classes as $val => $label): ?>
                                        <option value="<?= htmlspecialchars($val) ?>" <?= ($selClass === $val) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        </div>
                    </form>
                </div>

                <!-- ── Listings ── -->
                <div class="col-lg-9">

                    <!-- Mobile header -->
                    <div class="internship-mobile-header">
                        <div style="position: relative; flex: 1; min-width: 0;">
                            <i class="fa fa-search"
                                style="position: absolute; left:12px; top: 50%; transform: translateY(-50%); color: #aaa; z-index: 1;"></i>
                            <input type="text" id="search-internship-mobile" class="form-control"
                                placeholder="Search listings…" style="padding-left: 36px; width: 100%;">
                        </div>
                        <button class="mobile-filter-icon-btn" onclick="openMobileFilter()">
                            <i class="fa-solid fa-filter"></i>
                        </button>
                    </div>

                    <div class="results-bar">
                        <span class="results-count">
                            Showing <strong id="visible-count"><?= count($internships) ?></strong>
                            of <strong><?= count($internships) ?></strong> listings
                        </span>
                    </div>

                    <?php if (empty($internships)): ?>
                        <div class="empty-state">
                            <i class="fa fa-briefcase"></i>
                            <p>No internship listings match your current filters.</p>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($internships as $internship): ?>
                        <div class="listing-card" data-type="<?= htmlspecialchars($internship['internship_type'] ?? '') ?>"
                            data-classification="<?= htmlspecialchars($internship['company_classification'] ?? '') ?>"
                            data-deadline="<?= htmlspecialchars($internship['deadline'] ?? '') ?>">

                            <div class="card-top">
                                <h5 class="card-title"><?= htmlspecialchars($internship['company']) ?></h5>
                            </div>

                            <div class="card-meta">
                                <span class="card-meta-item">
                                    <i class="fa fa-briefcase"></i>
                                    <?= htmlspecialchars($internship['title']) ?>
                                </span>
                                <span class="card-meta-item">
                                    <i class="fa fa-map-marker-alt"></i>
                                    <?= htmlspecialchars($internship['location']) ?>
                                </span>
                                <?php if (!empty($internship['deadline'])): ?>
                                    <span class="card-meta-item">
                                        <i class="fa fa-calendar"></i>
                                        Deadline: <?= htmlspecialchars($internship['deadline']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($internship['phone_numbers'])): ?>
                                    <span class="card-meta-item">
                                        <i class="fa fa-phone"></i>
                                        <?= htmlspecialchars($internship['phone_numbers']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <p class="card-desc">
                                <?= nl2br(htmlspecialchars($internship['description'])) ?>
                            </p>

                            <div class="card-actions">
                                <button class="btn-readmore" onclick="togglePanel(<?= $internship['id'] ?>, this)">
                                    <i class="fa fa-circle-info"></i> Read More
                                </button>
                                <form method="POST" action="applied-Internship-programs-db.php" style="margin:0;">
                                    <input type="hidden" name="internship_id" value="<?= $internship['id'] ?>">
                                    <!-- <button type="submit" class="btn-interested">
                                        <i class="fa fa-bookmark"></i> Interested
                                    </button> -->
                                </form>
                            </div>

                            <!-- ── Read More / Details Panel ── -->
                            <div class="details-panel" id="details-<?= $internship['id'] ?>">
                                <p class="details-section-title">Company Details</p>
                                <div class="details-grid">

                                    <div class="detail-row">
                                        <span class="detail-label">Company:</span>
                                        <span class="detail-val"><?= htmlspecialchars($internship['company']) ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Location:</span>
                                        <span class="detail-val"><?= htmlspecialchars($internship['location']) ?></span>
                                    </div>

                                    <?php if (!empty($internship['program'])): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Program:</span>
                                            <span class="detail-val"><?= htmlspecialchars($internship['program']) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- <?php //if (!empty($internship['year_level'])): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Year dnjwadnjanjdwhdahLevel:</span>
                                            <span class="detail-val"><?= htmlspecialchars($internship['year_level']) ?></span>
                                        </div>
                                    <?php //endif; ?> -->

                                    <?php if (!empty($internship['internship_type'])): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Type:</span>
                                            <span
                                                class="detail-val"><?= $internship['internship_type'] === 'paid' ? 'With stipend' : 'Without stipend' ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($internship['company_classification'])): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Classification:</span>
                                            <span
                                                class="detail-val"><?= htmlspecialchars($internship['company_classification']) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- <?php // if (!empty($internship['deadline'])): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Deadline:</span>
                                            <span class="detail-val"><?= htmlspecialchars($internship['deadline']) ?></span>
                                        </div>
                                    <?php //endif; ?> -->

                                    <?php if (!empty($internship['phone_numbers'])): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Contact:</span>
                                            <span
                                                class="detail-val"><?= htmlspecialchars($internship['phone_numbers']) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($internship['email'])): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Email:</span>
                                            <span class="detail-val">
                                                <a href="mailto:<?= htmlspecialchars($internship['email']) ?>">
                                                    <?= htmlspecialchars($internship['email']) ?>
                                                </a>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($internship['website'])): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Website:</span>
                                            <span class="detail-val">
                                                <a href="<?= htmlspecialchars($internship['website']) ?>" target="_blank">
                                                    <?= htmlspecialchars($internship['website']) ?>
                                                </a>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($internship['address'])): ?>
                                        <div class="detail-row" style="grid-column: 1 / -1;">
                                            <span class="detail-label">Address:</span>
                                            <span class="detail-val"><?= htmlspecialchars($internship['address']) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($internship['ojt_time_in'])): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">OJT Time In:</span>
                                            <span class="detail-val"><?= htmlspecialchars($internship['ojt_time_in']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($internship['ojt_time_out'])): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">OJT Time Out:</span>
                                            <span class="detail-val"><?= htmlspecialchars($internship['ojt_time_out']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <hr>
                                <!-- <p class="details-section-title">Application Documents</p>

                                <div class="file-row">
                                    <span class="file-name">Memorandum of Understanding (MOU)</span>
                                    <div class="file-btns">
                                        <a href="mou-preview.php?id=<?= $internship['id'] ?>&student_id=<?= $_SESSION['user_id'] ?>&action=mou"
                                            class="btn-preview" target="_blank">Preview</a>
                                        <a href="download-mou.php?id=<?= $internship['id'] ?>&action=mou"
                                            class="btn-dl">Download PDF</a>
                                    </div>
                                </div>

                                <div class="file-row">
                                    <span class="file-name">Recommendation Letter</span>
                                    <div class="file-btns">
                                        <a href="mou-preview.php?id=<?= $internship['id'] ?>&student_id=<?= $_SESSION['user_id'] ?>&action=rl"
                                            class="btn-preview" target="_blank">Preview</a>
                                        <a href="download-mou.php?id=<?= $internship['id'] ?>&action=rl"
                                            class="btn-dl">Download PDF</a>
                                    </div>
                                </div>

                                <div class="file-row">
                                    <span class="file-name">Waiver</span>
                                    <div class="file-btns">
                                        <a href="mou-preview.php?id=<?= $internship['id'] ?>&student_id=<?= $_SESSION['user_id'] ?>&action=waiver"
                                            class="btn-preview" target="_blank">Preview</a>
                                    <a href="download-mou.php?id=<?= $internship['id'] ?>&action=waiver"
                                        class="btn-dl">Download PDF</a>
                                    </div>
                                </div> -->

                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Mobile Filter Modal -->
                    <div class="mobile-filter-overlay" id="mobileFilterOverlay"
                        onclick="closeMobileFilterOnOverlay(event)">
                        <div class="mobile-filter-sheet">
                            <div class="mobile-filter-sheet-header">
                                <h5>Filters</h5>
                                <button class="mobile-filter-close" onclick="closeMobileFilter()">&#x2715;</button>
                            </div>

                            <div class="mobile-filter-row">
                                <div class="mobile-filter-group">
                                    <strong>Deadline</strong>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="m_deadline" value="week">
                                        <label class="form-check-label">Due this week</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="m_deadline" value="month">
                                        <label class="form-check-label">Due this month</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="m_deadline" value="future">
                                        <label class="form-check-label">Upcoming</label>
                                    </div>
                                </div>

                                <div class="mobile-filter-group">
                                    <strong>Internship Type</strong>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="m_internship_type"
                                            value="All">
                                        <label class="form-check-label">All types</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="m_internship_type"
                                            value="paid">
                                        <label class="form-check-label">With stipend</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="m_internship_type"
                                            value="unpaid">
                                        <label class="form-check-label">Without stipend</label>
                                    </div>
                                </div>
                            </div>

                            <div class="mobile-filter-group-full">
                                <strong>Company Classification</strong>
                                <select class="form-select" id="m_company_classification">
                                    <option value="" selected disabled>Select an option</option>
                                    <option value="private">Private Sector</option>
                                    <option value="public">Public / Government</option>
                                    <option value="institution">Academic & Research</option>
                                    <option value="NGO">Nonprofit & Civil Society</option>
                                    <option value="multilateral_org">International Organizations</option>
                                    <option value="media">Creative & Media</option>
                                    <option value="technology">Technology & Innovation</option>
                                    <option value="healthcare">Healthcare & Social Services</option>
                                    <option value="industrial">Industrial & Manufacturing</option>
                                    <option value="financial">Financial & Business</option>
                                    <option value="tourism">Hospitality & Tourism</option>
                                    <option value="freelance">Freelance / Gig-Based</option>
                                    <option value="religious">Religious & Faith-Based</option>
                                    <option value="hybrid">Public-Private Partnerships</option>
                                </select>
                            </div><br>

                            <a href="applied-Internship-programs.php" class="btn-clear-filters">
                                <i class="fa fa-rotate-left" style="margin-right:6px;font-size:.8rem;"></i>Clear Filters
                            </a>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </section>

    <script>
        function togglePanel(id, btn) {
            const card = btn.closest('.listing-card');
            const panel = card.querySelector('.details-panel');
            const isOpen = panel.classList.contains('open');

            document.querySelectorAll('.details-panel.open').forEach(p => p.classList.remove('open'));
            document.querySelectorAll('.btn-readmore.active').forEach(b => b.classList.remove('active'));

            if (!isOpen) {
                panel.classList.add('open');
                btn.classList.add('active');
            }
        }

        function applyFilters() {
            const search = document.getElementById('search-internship').value.toLowerCase();
            const dlRadio = document.querySelector('input[name="deadline"]:checked');
            const tyRadio = document.querySelector('input[name="internship_type"]:checked');
            const ccSel = document.querySelector('select[name="company_classification"]');

            const deadline = dlRadio ? dlRadio.value : '';
            const iType = tyRadio ? tyRadio.value : '';
            const cClass = ccSel ? ccSel.value : '';

            const today = new Date(); today.setHours(0, 0, 0, 0);
            let visible = 0;

            document.querySelectorAll('.listing-card').forEach(card => {
                const text = card.innerText.toLowerCase();

                const matchSearch = !search || text.includes(search);

                let matchDeadline = true;
                if (deadline) {
                    const dlStr = card.dataset.deadline ?? '';
                    const dlDate = dlStr ? new Date(dlStr) : null;
                    if (dlDate) {
                        const week = new Date(today); week.setDate(today.getDate() + 7);
                        const month = new Date(today); month.setDate(today.getDate() + 30);
                        if (deadline === 'week') matchDeadline = dlDate >= today && dlDate <= week;
                        if (deadline === 'month') matchDeadline = dlDate >= today && dlDate <= month;
                        if (deadline === 'future') matchDeadline = dlDate >= today;
                    } else {
                        matchDeadline = false;
                    }
                }

                // type
                const matchType = !iType || iType === 'All' || card.dataset.type === iType;
                // classification
                const matchClass = !cClass || card.dataset.classification === cClass;

                const show = matchSearch && matchDeadline && matchType && matchClass;
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            const vc = document.getElementById('visible-count');
            if (vc) vc.textContent = visible;
        }

        document.getElementById('search-internship').addEventListener('input', applyFilters);
        document.querySelectorAll('input[name="deadline"]').forEach(r => r.addEventListener('change', applyFilters));
        document.querySelectorAll('input[name="internship_type"]').forEach(r => r.addEventListener('change', applyFilters));

        document.querySelectorAll('input[name="program"]').forEach(r => {
            r.addEventListener('change', () => document.getElementById('filter-form').submit());
        });

        function openMobileFilter() {
            document.getElementById('mobileFilterOverlay').classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closeMobileFilter() {
            document.getElementById('mobileFilterOverlay').classList.remove('open');
            document.body.style.overflow = '';
        }
        function closeMobileFilterOnOverlay(e) {
            if (e.target === document.getElementById('mobileFilterOverlay')) closeMobileFilter();
        }

        document.getElementById('search-internship-mobile')?.addEventListener('input', function () {
            document.getElementById('search-internship').value = this.value;
            applyFilters();
        });

        document.querySelectorAll('input[name="m_deadline"]').forEach(r => r.addEventListener('change', function () {
            const desk = document.querySelector(`input[name="deadline"][value="${this.value}"]`);
            if (desk) desk.checked = true;
            applyFilters();
        }));
        document.querySelectorAll('input[name="m_internship_type"]').forEach(r => r.addEventListener('change', function () {
            const desk = document.querySelector(`input[name="internship_type"][value="${this.value}"]`);
            if (desk) desk.checked = true;
            applyFilters();
        }));

        document.getElementById('m_company_classification')?.addEventListener('change', function () {
            const desk = document.querySelector('select[name="company_classification"]');
            if (desk) desk.value = this.value;
            applyFilters();
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../JS/index-script.js"></script>
</body>

</html>