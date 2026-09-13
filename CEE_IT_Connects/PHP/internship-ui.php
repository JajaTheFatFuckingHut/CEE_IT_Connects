<?php
session_start();
require 'db.php';
require_once 'auth.php';

// Second code's real queries
$applicantsStmt = $pdo->query("
    SELECT 
        s.id AS student_id,
        s.full_name,
        s.program,
        i.title AS internship_title,
        i.company,
        CASE
            WHEN sp_ojt.is_done = TRUE THEN 'Internship Confirmed'
            WHEN sp_docs.is_done = TRUE THEN 'Documents Submitted'
            WHEN sp_app.is_done = TRUE THEN 'Application Submitted'
            WHEN sd.student_id IS NOT NULL THEN 'Resume Uploaded'
            ELSE 'No Progress'
        END AS current_phase,
        CASE 
            WHEN sd.student_id IS NOT NULL AND sc.student_id IS NOT NULL THEN 'Complete'
            ELSE 'Incomplete'
        END AS requirements,
        ib.created_at
    FROM internship_bookmarks ib
    JOIN students s ON s.id = ib.student_id
    JOIN internships i ON i.id = ib.internship_id
    LEFT JOIN student_documents sd ON sd.student_id = s.id
    LEFT JOIN (SELECT DISTINCT student_id FROM student_credentials) sc ON sc.student_id = s.id
    LEFT JOIN student_progress sp_app ON sp_app.student_id = s.id AND sp_app.step_key = 'application'
    LEFT JOIN student_progress sp_docs ON sp_docs.student_id = s.id AND sp_docs.step_key = 'documents'
    LEFT JOIN student_progress sp_ojt ON sp_ojt.student_id = s.id AND sp_ojt.step_key = 'ojt_accepted'
    ORDER BY ib.created_at DESC
");
$applicants = $applicantsStmt->fetchAll(PDO::FETCH_ASSOC);

$resumeStmt = $pdo->query("
    SELECT sd.id, sd.resume_path, sd.uploaded_at, s.full_name, s.program, s.student_id AS student_number
    FROM student_documents sd
    JOIN students s ON s.id = sd.student_id
    ORDER BY sd.uploaded_at DESC
");
$resumes = $resumeStmt->fetchAll(PDO::FETCH_ASSOC);

$credentialStmt = $pdo->query("
    SELECT sc.id, sc.credential_path, sc.uploaded_at, s.full_name, s.program, s.student_id AS student_number
    FROM student_credentials sc
    JOIN students s ON s.id = sc.student_id
    ORDER BY sc.uploaded_at DESC
");
$credentials = $credentialStmt->fetchAll(PDO::FETCH_ASSOC);

$stmtinterest = $pdo->prepare("
    SELECT oa.id AS interest_id, oa.student_id, oa.submitted_at,
           s.full_name, s.email, i.company, i.title
    FROM ojt_applications oa
    JOIN students s ON s.id = oa.student_id
    JOIN internships i ON i.id = oa.internship_id
    ORDER BY oa.submitted_at DESC
");
$stmtinterest->execute();
$interests = $stmtinterest->fetchAll(PDO::FETCH_ASSOC);

$stmtannouncement = $pdo->prepare("
    SELECT id, title, message, created_at, category 
    FROM announcements ORDER BY created_at DESC
");
$stmtannouncement->execute();
$announcements = $stmtannouncement->fetchAll(PDO::FETCH_ASSOC);

$mouStmt = $pdo->query("
    SELECT mu.id, mu.file_path, mu.updated_at, mu.internship_id,
           i.company, i.title
    FROM mou_uploads mu
    LEFT JOIN internships i ON i.id = mu.internship_id
    ORDER BY mu.updated_at DESC
");
$mouUploads = $mouStmt->fetchAll(PDO::FETCH_ASSOC);

$internshipStmt = $pdo->query("
    SELECT id, company, title, location, program FROM internships ORDER BY company ASC, title ASC
");
$internships = $internshipStmt->fetchAll(PDO::FETCH_ASSOC);

$statsStmt = $pdo->query("SELECT COUNT(*) AS total FROM internships");
$totalInternships = $statsStmt->fetchColumn();

$interestedStmt = $pdo->query("SELECT COUNT(*) AS total FROM internship_bookmarks");
$totalInterested = $interestedStmt->fetchColumn();

$applicationsStmt = $pdo->query("SELECT COUNT(*) AS total FROM ojt_applications");
$totalApplications = $applicationsStmt->fetchColumn();
$documentsStmt = $pdo->query("SELECT COUNT(*) AS total FROM student_documents");
$totalDocuments = $documentsStmt->fetchColumn();

$announcementsStmt = $pdo->query("SELECT COUNT(*) AS total FROM announcements");
$totalAnnouncements = $announcementsStmt->fetchColumn();

$recentInternshipsStmt = $pdo->query("
    SELECT title, company, location, created_at FROM internships ORDER BY created_at DESC LIMIT 5
");
$recentInternships = $recentInternshipsStmt->fetchAll(PDO::FETCH_ASSOC);

$recentInterestedStmt = $pdo->query("
    SELECT s.full_name, s.email, i.title AS internship_title, i.company, ib.created_at
    FROM internship_bookmarks ib
    JOIN students s ON s.id = ib.student_id
    JOIN internships i ON i.id = ib.internship_id
    ORDER BY ib.created_at DESC LIMIT 5
");
$recentInterested = $recentInterestedStmt->fetchAll(PDO::FETCH_ASSOC);

$recentAnnouncementsStmt = $pdo->query("
    SELECT title, category, created_at FROM announcements ORDER BY created_at DESC LIMIT 5
");
$recentAnnouncements = $recentAnnouncementsStmt->fetchAll(PDO::FETCH_ASSOC);

$docAvailStmt = $pdo->query("
    SELECT ida.internship_id, ida.mou_available, ida.recommendation_letter_available,
           ida.waiver_available, ida.updated_at, i.title, i.company
    FROM internship_document_availability ida
    JOIN internships i ON i.id = ida.internship_id
    ORDER BY ida.updated_at DESC
");
$docAvailability = $docAvailStmt->fetchAll(PDO::FETCH_ASSOC);


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>CEE IT Connects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../CSS/intern-admin.css" />

    <style>
        body {
            margin: 0;
            overflow: hidden;
        }

        .main-content {
            margin-left: 220px;
            flex: 1;
            overflow-y: scroll;
            scrollbar-width: none;
            height: calc(100vh - 70px);
            padding: 40px;
            background: #f5f7ff;
        }

        .sysAdm-header--danger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, #f7dddd 7%, #fbdad9 50%, #f1cecd 100%);
            border-radius: 14px 14px 0 0;
            padding: 22px 28px;
            /* margin-bottom: 20px; */
            margin: -24px -24px 20px -24px;
            width: calc(100% + 48px);

        }

        .sysAdm-header--danger h2 {
            color: var(--primary-dark-blue);
            font-size: 26px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .sysAdm-header--danger p {
            margin-bottom: 0 !important;
        }

        .sysAdm-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .sysAdm-header-icon {
            background-color: #f6c3bd;
            color: var(--gradient-end);
            width: 64px;
            height: 64px;
            min-width: 64px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        .sysAdm-header-deco {
            width: 100px;
            height: 100px;
            /* height: auto; */
        }

        .sysAdm-header--blue {
            background: linear-gradient(135deg, #dce2ef 0%, #dde3f0 50%, #c0cfef 100%);
        }

        .sysAdm-header--blue .sysAdm-header-icon {
            background-color: #c7d2e8;
            color: var(--primary-dark-blue);
        }

        .sysAdm-header-text {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* end of added section for delete account feature */

        .sysAdm-header h2 {
            font-size: 26px;
            font-weight: 700;
            color: var(--primary-dark-blue);
            margin-bottom: 4px !important;
            overflow-x: hidden;
        }

        .sysAdm-header p {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 0px !important;
            overflow-x: hidden;
        }

        .sysAdm-section {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            width: 100%;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            /* overflow-x: auto; */

            /* puts content inside the table */
            overflow: hidden;
        }

        .sysAdm-table {
            width: 100%;
            /* added */
            min-width: 720px;
            border-collapse: collapse;
            /* overflow: hidden; */
            /* overflow-x: auto; */
        }

        .sysAdm-table-wrapper {
            overflow-x: auto;
            width: 100%;
            -webkit-overflow-scrolling: touch;
        }

        .sysAdm-table thead {
            background: #eaedef;
        }

        .sysAdm-table th {
            text-align: left;
            padding: 14px 18px;
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            border-bottom: 2px solid #dbe1ea;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .sysAdm-table td {
            padding: 14px 18px;
            font-size: 14px;
            color: #334155;
            border-bottom: 1px solid #edf2f7;
        }

        .sysAdm-table tbody tr {
            transition: background 0.2s ease;
        }

        .sysAdm-table tbody tr:hover {
            background: #f8fafc;
        }

        .internship-form {
            max-width: 800px;
            margin: auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-card {
            background: #fff;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 8px 20px rgba(67, 67, 67, 0.08);
        }

        .form-card h3 {
            margin-bottom: 15px;
            color: #272f54;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .sidebar {
            width: 220px;
            background: #272f54;
            color: white;
            padding: 20px;
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 70px;
        }

        .sidebar h3 {
            margin-bottom: 20px;
            color: #000;
        }

        .sidebar a {
            text-decoration: none;
            color: white;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 5px;
            transition: 0.3s;
            font-weight: 400;
        }

        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #FFB62F;
        }

        .sidebar a:hover i {
            color: #FFB62F;
        }

        .sidebar a.active {
            background: #e35f36;
            /* color: #272f54; */
            font-weight: 600;

            color: #fff;
        }

        .sidebar a.active i {
            /* color: #272f54; */
            color: #fff;
        }

        .btn-button {
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            border: none;
            background: #4f51a8;
            color: #fff;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-button:hover {
            background: #3A3B7B;
        }

        .section {
            display: none;
            width: 100%;
        }

        .section.active {
            display: block;
        }

        label {
            font-size: 13px;
            font-weight: 600;
            color: #555;
        }

        input,
        select,
        textarea {
            /* width: 100%; */
            padding: 10px;
            margin-top: 5px;
            border-radius: 8px;
            border: 1px solid #ddd;
            font-size: 14px;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #FFB62F;
            box-shadow: 0 0 5px rgba(255, 182, 47, 0.5);
        }

        .submit-btn {
            background: linear-gradient(135deg, #FFB62F, #E4572E);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 10px;
            font-weight: bold;
            cursor: pointer;
            font-size: 16px;
        }

        .submit-btn:hover {
            opacity: 0.9;
        }

        /* ADDED: stat card shell — rounded corners + hover lift, background set per-card
                       below via the card-tint-* classes (kept separate from ojtc-stat-card so any
                       stat card can reuse the shell and just swap its tint/icon color). */
        .ojtc-stat-card {
            border-radius: 16px;
            transition: transform .15s ease, box-shadow .15s ease;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .ojtc-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
        }

        /* ADDED: solid colored icon box (icon left of label/count), same pattern as the
                       System Admin dashboard's Internships/Accounts/Programs cards. */
        .ojtc-stat-icon {
            width: 44px;
            height: 44px;
            min-width: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #fff;
        }

        /* ADDED: one tint (card bg) + one solid accent (icon bg) per stat, matching the
                       System Admin dashboard's navy/amber/red-orange palette. */
        .card-tint-applications {
            background: #FFF6E3;
        }

        .icon-applications {
            background: #FFB62F;
        }

        .card-tint-internships {
            background: #EEF3FF;
        }

        .icon-internships {
            background: #272F54;
        }

        .card-tint-announcements {
            background: #EAF3DE;
        }

        .icon-announcements {
            background: #3E8E58;
        }

        .card-tint-documents {
            background: #FDEEE8;
        }

        .icon-documents {
            background: #E4572E;
        }

        /* ADDED: panel card hover-lift + rounded corners, reused by Application List,
                       Announcements, Internship Postings, and Documents cards below. */
        .ojtc-panel-card {
            border-radius: 16px !important;
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .ojtc-panel-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
        }

        /* ADDED: subtle blue "tab" header background for the plain <table> headers —
                       same class name/style as the System Admin dashboard table, for consistency. */
        .ojtc-th-tab th {
            background: rgba(39, 111, 255, 0.08) !important;
            color: #272f54 !important;
        }

        /* ADDED: row hover highlight — works for <tr> rows and the flex-row divs used in
                       Announcements / Documents. */
        .ojtc-row-hover:hover {
            background: #f8f9ff;
            border-radius: 8px;
        }

        /* ADDED: same button style used on the System Admin dashboard — subtle yellow by
                       default, solid orange on hover. Reuse this class on any button added to this
                       tab or others (Add/Import/Save/etc.) for consistency. */
        .btn-update {
            background: #FFE7B3 !important;
            color: #7a5200 !important;
            /* border: none !important;
            border-radius: 10px !important;
            padding: 8px 18px !important;
            font-weight: 600 !important; */
            transition: background-color .15s ease, color .15s ease;
        }

        .btn-update:hover {
            background: #E4572E !important;
            color: #fff !important;
        }

        .btn-create {
            padding: 14px;
            border-radius: 12px;
            border: none;
            background: linear-gradient(135deg, var(--gradient-start), var(--gradient-end));
            color: white;
            font-weight: 700;
            cursor: pointer;
            font-size: 15px;
            transition: box-shadow 0.2s;
        }

        .btn-create:hover {
            box-shadow: 0 8px 20px rgba(228, 87, 46, 0.3);
        }

        .btn-delete {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 8px;
            border: 1px solid #a32d2d;
            background: #fcebeb;
            color: #a32d2d;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-delete:hover {
            background: #f09595;
            color: #791f1f;
        }

        @media (max-width: 768px) {
            .layout {
                flex-direction: row;
            }

            /* added ulit dahil sa top header cardZ */
            .sysAdm-section {
                padding: 20px !important;
            }

            .sysAdm-header--danger {
                margin: -20px -20px 20px -20px;
                width: calc(100% + 40px);
                border-radius: 0;
                /* optional: square off entirely on mobile if you want */
            }

            .sidebar {
                top: 60px;
                width: 60px !important;
                padding: 10px 0 !important;
                align-items: center;
                overflow: visible !important;
                z-index: 1050;
            }

            .sidebar h3 {
                display: none !important;
            }

            .sidebar a {
                width: 44px !important;
                height: 44px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                border-radius: 12px !important;
                margin: 0 auto 8px auto !important;
                padding: 0 !important;
                position: relative;
            }

            .sidebar a i {
                margin: 0 !important;
            }

            /* Hide text labels */
            .sidebar a .nav-label {
                display: none !important;
            }

            /* Tooltip */
            .sidebar a::after {
                content: attr(data-tooltip);
                position: absolute;
                left: 56px;
                top: 50%;
                transform: translateY(-50%);
                background: #1a1a2e;
                color: #fff;
                font-size: 12px;
                font-weight: 500;
                padding: 5px 10px;
                border-radius: 6px;
                white-space: nowrap;
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.2s ease;
            }

            .sidebar a:hover::after {
                opacity: 1;
            }

            .main-content {
                margin-left: 60px !important;
                padding: 10px !important;
            }

            /* STAT CARDS — 1 row, 3 columns */
            .row.g-3.mb-4 {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 4px !important;
            }

            .row.g-3.mb-4 .col-md-4 {
                width: 100% !important;
                padding: 0 !important;
            }

            .row.g-3.mb-4 .card {
                border-radius: 10px !important;
            }

            .row.g-3.mb-4 .card-body {
                padding: 8px !important;
                font-size: 0.8rem !important;
            }

            .row.g-3.mb-4 .card-body p {
                font-size: 10px !important;
                margin-bottom: 4px !important;
                letter-spacing: 0 !important;
                font-size: 0.5rem !important;
            }

            .row.g-3.mb-4 .card-body .d-flex {
                flex-wrap: nowrap !important;
                align-items: flex-start !important;
                justify-content: space-between !important;
                gap: 4px !important;
            }

            .row.g-3.mb-4 .card-body .d-flex>div:first-child:not(.rounded-3) {
                flex: 1 !important;
            }

            .row.g-3.mb-4 .card-body h2 {
                font-size: 0.7rem !important;
                margin-bottom: 0 !important;
            }

            /* Hide icon box to save space */
            .row.g-3.mb-4 .card-body .rounded-3 {
                width: 24px !important;
                height: 24px !important;
                flex-shrink: 0;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                align-self: flex-start !important;
                margin-top: 4px !important;
            }

            .row.g-3.mb-4 .card-body .rounded-3 i {
                font-size: 10px !important;
            }

            /* TABLES — allow horizontal scroll */
            .sysAdm-table {
                font-size: 12px !important;
            }

            .sysAdm-table th,
            .sysAdm-table td {
                padding: 10px 10px !important;
                font-size: 12px !important;
            }

            /* DASHBOARD CONTAINER (forms) */
            .dashboard-container {
                padding: 20px !important;
                max-width: 100% !important;
            }

            /* SECTION HEADERS */
            .sysAdm-header h2 {
                font-size: 20px !important;
            }

            /* DOWNLOAD BUTTONS */
            .d-flex.justify-content-end.gap-2.mt-4 {
                flex-direction: row !important;
                align-items: center !important;
                justify-content: center;
            }

            .d-flex.justify-content-end.gap-2.mt-4 button {
                flex: 1;
                font-size: 12px;
                padding: 8px;
                justify-content: center !important;
            }

            .stat-card-title {
                font-size: 9px !important;
                letter-spacing: 0 !important;
                margin-bottom: 4px !important;
                white-space: normal !important;
                line-height: 1.1 !important;
                word-break: break-word !important;
            }

            .stat-card-number {
                font-size: 0.7rem !important;
            }
        }

        @media (max-width: 480px) {
            .row.g-3.mb-4 {
                gap: 3px !important;
            }

            .row.g-3.mb-4 .card-body {
                padding: 6px !important;
            }

            .row.g-3.mb-4 .card-body p {
                font-size: 8px !important;
                letter-spacing: 0 !important;
                margin-bottom: 2px !important;
                line-height: 1.2 !important;
                word-break: break-word !important;
            }

            .row.g-3.mb-4 .card-body h2 {
                font-size: 1rem !important;
                margin-bottom: 0 !important;
            }

            .row.g-3.mb-4 .card-body .rounded-3 {
                width: 24px !important;
                height: 24px !important;
            }

            .row.g-3.mb-4 .card-body .rounded-3 i {
                font-size: 10px !important;
            }

            .stat-card-title {
                font-size: 7px !important;
                line-height: 1.1 !important;
                word-break: break-word !important;
            }

            .stat-card-number {
                font-size: 1rem !important;
            }
        }
    </style>
</head>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function showSection(sectionID) {
        document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
        document.getElementById(sectionID).classList.add('active');
        document.querySelectorAll('.sidebar a').forEach(l => l.classList.remove('active'));
        event.target.classList.add('active');
    }
</script>

<body data-page="rooms">

    <?php include 'navbar.php'; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['warning'])): ?>
        <div class="alert alert-warning alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:400px;" role="alert" id="flashAlert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['warning'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['warning']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['info'])): ?>
        <div class="alert alert-info alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="bi bi-info-circle-fill me-2"></i><?= $_SESSION['info'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['info']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3"
            style="z-index:9999; min-width:350px;" role="alert" id="flashAlert">
            <i class="bi bi-x-circle-fill me-2"></i><?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="page-body">
        <!-- SIDEBAR -->
        <aside class="sidebar" style="padding-bottom:70px;">
            <a href="#" class="active" onclick="showSection('dashboard')" data-tooltip="Home">
                <i class="bi bi-person-fill-lock"></i>
                <span class="nav-label">Home</span>
            </a>
            <a href="#" onclick="showSection('postings')" data-tooltip="Internship Postings">
                <i class="bi bi-pencil-fill"></i>
                <span class="nav-label">Postings</span>
            </a>
            <a href="#" onclick="showSection('interns')" data-tooltip="Interns">
                <i class="bi bi-people-fill"></i>
                <span class="nav-label">Interns</span>
            </a>
            <a href="#" onclick="showSection('documents')" data-tooltip="Documents">
                <i class="bi bi-file-earmark-text-fill"></i>
                <span class="nav-label">Documents</span>
            </a>
            <!-- <a href="#" onclick="showSection('interested')" data-tooltip="Interested">
                <i class="bi bi-bookmarks-fill"></i>
                <span class="nav-label">Interested</span>
            </a> -->
            <a href="#" onclick="showSection('docu_availability')" data-tooltip="Document Availability">
                <i class="bi bi-bookmark-fill"></i>
                <span class="nav-label">Document Availability</span>
            </a>
            <a href="#" onclick="showSection('manage_announcement')" data-tooltip="Manage Announcements">
                <i class="bi bi-bell-fill"></i>
                <span class="nav-label">Manage Announcements</span>
            </a>
            <a href="#" onclick="showSection('upload_mou')" data-tooltip="Upload MOU">
                <i class="bi bi-file-text-fill"></i>
                <span class="nav-label">Upload MOU</span>
            </a>
        </aside>

        <div class="main-content">

            <!-- ── DASHBOARD ── -->
            <div id="dashboard" class="section active sysAdm-section">

                <!-- CHANGED: header now uses the same .card/.card-header banner as the System Admin
                     dashboard, reusing the sysAdm-header--danger/sysAdm-header--blue/sysAdm-header-icon/
                     sysAdm-header-text classes so it looks identical. Original "sysAdm-header" class
                     kept alongside in case other rules still target it. -->
                <div class="mb-4 sysAdm-header--danger sysAdm-header--blue">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Internship Administrator Overview</h2>
                            <p>A centralized overview of status, pending tasks, and real-time administrative insights.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- <div id="dashboard" class="section active sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue mb-4">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>System Admin Overview</h2>
                            <p>Live summary from the internship admin panel</p>
                        </div>
                    </div>
                </div> -->




                <!-- SUMMARY CARDS -->
                <!-- CHANGED: replaced the custom .summary-container/.summary-card/.gold-icon flex
                     layout with a bootstrap grid of card tiles (icon box left, label+count right),
                     matching the System Admin dashboard's stat cards. Every PHP value below
                     ($totalApplications, $totalInternships, $totalAnnouncements, $totalDocuments)
                     is untouched — only the surrounding markup changed. -->
                <div class="row g-3 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="ojtc-stat-card card-tint-applications">
                            <div class="ojtc-stat-icon icon-applications">
                                <i class="bi bi-file-earmark-fill"></i>
                            </div>
                            <div>
                                <p class="small mb-1 fw-semibold text-uppercase"
                                    style="letter-spacing:.05em; font-size:11px; color:#7a5200;">Applications</p>
                                <h2 class="fw-bold mb-0" style="color:#3b2600;"><?= $totalApplications ?></h2>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <div class="ojtc-stat-card card-tint-internships">
                            <div class="ojtc-stat-icon icon-internships">
                                <i class="bi bi-briefcase-fill"></i>
                            </div>
                            <div>
                                <p class="small mb-1 fw-semibold text-uppercase"
                                    style="letter-spacing:.05em; font-size:11px; color:#272f54;">Internship Postings</p>
                                <h2 class="fw-bold mb-0" style="color:#272f54;"><?= $totalInternships ?></h2>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <div class="ojtc-stat-card card-tint-announcements">
                            <div class="ojtc-stat-icon icon-announcements">
                                <i class="bi bi-bookmark-fill"></i>
                            </div>
                            <div>
                                <p class="small mb-1 fw-semibold text-uppercase"
                                    style="letter-spacing:.05em; font-size:11px; color:#27500a;">Announcements</p>
                                <h2 class="fw-bold mb-0" style="color:#27500a;"><?= $totalAnnouncements ?></h2>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <div class="ojtc-stat-card card-tint-documents">
                            <div class="ojtc-stat-icon icon-documents">
                                <i class="bi bi-file-earmark-text-fill"></i>
                            </div>
                            <div>
                                <p class="small mb-1 fw-semibold text-uppercase"
                                    style="letter-spacing:.05em; font-size:11px; color:#a13d1f;">Documents</p>
                                <h2 class="fw-bold mb-0" style="color:#a13d1f;"><?= $totalDocuments ?></h2>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Application List -->
                    <div class="col-lg-7">
                        <!-- CHANGED: added ojtc-panel-card for rounded corners + hover-lift. -->
                        <div class="card border-0 shadow-sm h-100 ojtc-panel-card">
                            <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                                <i class="bi bi-file-earmark-text" style="color:#272f54;"></i>
                                <h6 class="fw-bold mb-0" style="color:#272f54;">Application List</h6>
                            </div>
                            <div class="card-body px-4 pb-4 pt-2">
                                <?php if (empty($recentInterested)): ?>
                                    <p class="text-muted small mb-0">No applications yet.</p>
                                <?php else: ?>
                                    <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                        <thead>
                                            <!-- CHANGED: renamed to ojtc-th-tab so this table header matches
                                                 the System Admin dashboard table header class/style exactly. -->
                                            <tr class="ojtc-th-tab"
                                                style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:.04em;">
                                                <th
                                                    style="padding:8px 10px; border-bottom:1px solid #f0f2f7; font-weight:600;">
                                                    Student</th>
                                                <th
                                                    style="padding:8px 10px; border-bottom:1px solid #f0f2f7; font-weight:600;">
                                                    Company</th>
                                                <th
                                                    style="padding:8px 10px; border-bottom:1px solid #f0f2f7; font-weight:600;">
                                                    Status</th>
                                                <th
                                                    style="padding:8px 10px; border-bottom:1px solid #f0f2f7; font-weight:600;">
                                                    Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentInterested as $ri): ?>
                                                <!-- CHANGED: renamed to ojtc-row-hover (same rename, no behavior change). -->
                                                <tr class="ojtc-row-hover">
                                                    <td style="padding:10px; border-bottom:1px solid #f0f2f7;">
                                                        <div style="display:flex; align-items:center; gap:8px;">
                                                            <div
                                                                style="width:30px;height:30px;min-width:30px;border-radius:50%;background:#eef1ff;color:#272f54;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;">
                                                                <?= strtoupper(substr($ri['full_name'], 0, 1)) ?>
                                                            </div>
                                                            <span
                                                                style="font-weight:500;color:#272f54;"><?= htmlspecialchars($ri['full_name']) ?></span>
                                                        </div>
                                                    </td>
                                                    <td
                                                        style="padding:10px; border-bottom:1px solid #f0f2f7; color:#888; font-size:12px;">
                                                        <?= htmlspecialchars($ri['company']) ?>
                                                    </td>
                                                    <td style="padding:10px; border-bottom:1px solid #f0f2f7;">
                                                        <span
                                                            style="background:#fff8e1;color:#633806;font-size:11px;padding:3px 10px;border-radius:6px;font-weight:500;">Interested</span>
                                                    </td>
                                                    <td
                                                        style="padding:10px; border-bottom:1px solid #f0f2f7; color:#aaa; font-size:12px;">
                                                        <?= date("M d", strtotime($ri['created_at'])) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Announcements -->
                    <div class="col-lg-5">
                        <!-- CHANGED: added ojtc-panel-card for rounded corners + hover-lift. -->
                        <div class="card border-0 shadow-sm h-100 ojtc-panel-card">
                            <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                                <i class="bi bi-bell" style="color:#272f54;"></i>
                                <h6 class="fw-bold mb-0" style="color:#272f54;">Recent Announcements</h6>
                            </div>
                            <div class="card-body px-4 pb-4 pt-2">
                                <?php if (empty($recentAnnouncements)): ?>
                                    <p class="text-muted small mb-0">No announcements yet.</p>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-3">
                                        <?php foreach ($recentAnnouncements as $a):
                                            $catColors = [
                                                'news' => ['bg' => '#e6f1fb', 'color' => '#0c447c'],
                                                'updates' => ['bg' => '#eaf3de', 'color' => '#27500a'],
                                                'FAQs' => ['bg' => '#faeeda', 'color' => '#633806'],
                                            ];
                                            $c = $catColors[$a['category']] ?? ['bg' => '#f0f0f0', 'color' => '#444'];
                                            ?>
                                            <!-- CHANGED: renamed to ojtc-row-hover (same rename, no behavior change). -->
                                            <div class="d-flex align-items-start gap-3 ojtc-row-hover" style="padding:6px;">

                                                <div style="flex:1;min-width:0;">
                                                    <p
                                                        style="font-weight:600;margin:0;color:#272f54;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                        <?= htmlspecialchars($a['title']) ?>
                                                    </p>
                                                    <p style="color:#aaa;margin:0;font-size:11px;">
                                                        <?= date("M d, Y", strtotime($a['created_at'])) ?>
                                                    </p>
                                                </div>
                                                <span
                                                    style="background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>;font-size:11px;padding:3px 10px;border-radius:6px;font-weight:600;white-space:nowrap;">
                                                    <?= htmlspecialchars(ucfirst($a['category'])) ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Internship Postings -->
                    <div class="col-lg-7">
                        <!-- CHANGED: added ojtc-panel-card for rounded corners + hover-lift. -->
                        <div class="card border-0 shadow-sm h-100 ojtc-panel-card">
                            <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                                <i class="bi bi-briefcase" style="color:#272f54;"></i>
                                <h6 class="fw-bold mb-0" style="color:#272f54;">Recent Internship Postings</h6>
                            </div>
                            <div class="card-body px-4 pb-4 pt-2">
                                <?php if (empty($recentInternships)): ?>
                                    <p class="text-muted small mb-0">No internships posted yet.</p>
                                <?php else: ?>
                                    <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                        <thead>
                                            <!-- CHANGED: renamed to ojtc-th-tab (same rename as above). -->
                                            <tr class="ojtc-th-tab"
                                                style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:.04em;">
                                                <th
                                                    style="padding:8px 10px; border-bottom:1px solid #f0f2f7; font-weight:600;">
                                                    Title</th>
                                                <th
                                                    style="padding:8px 10px; border-bottom:1px solid #f0f2f7; font-weight:600;">
                                                    Company</th>
                                                <th
                                                    style="padding:8px 10px; border-bottom:1px solid #f0f2f7; font-weight:600;">
                                                    Location</th>
                                                <th
                                                    style="padding:8px 10px; border-bottom:1px solid #f0f2f7; font-weight:600;">
                                                    Posted</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentInternships as $ri): ?>
                                                <!-- CHANGED: renamed to ojtc-row-hover (same rename, no behavior change). -->
                                                <tr class="ojtc-row-hover">
                                                    <td
                                                        style="padding:10px; border-bottom:1px solid #f0f2f7; font-weight:500; color:#272f54;">
                                                        <?= htmlspecialchars($ri['title']) ?>
                                                    </td>
                                                    <td
                                                        style="padding:10px; border-bottom:1px solid #f0f2f7; color:#888; font-size:12px;">
                                                        <?= htmlspecialchars($ri['company']) ?>
                                                    </td>
                                                    <td
                                                        style="padding:10px; border-bottom:1px solid #f0f2f7; color:#888; font-size:12px;">
                                                        <i
                                                            class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($ri['location']) ?>
                                                    </td>
                                                    <td style="padding:10px; border-bottom:1px solid #f0f2f7;">
                                                        <span
                                                            style="color:#888;font-size:11px;padding:3px 10px;border-radius:6px;font-weight:500;">
                                                            <?= date("M d, Y", strtotime($ri['created_at'])) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recently Uploaded Documents (static placeholder from first code) -->
                    <div class="col-lg-5">
                        <!-- CHANGED: added ojtc-panel-card for rounded corners + hover-lift. -->
                        <div class="card border-0 shadow-sm h-100 ojtc-panel-card">
                            <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex align-items-center gap-2">
                                <i class="bi bi-file-earmark-arrow-up" style="color:#272f54;"></i>
                                <h6 class="fw-bold mb-0" style="color:#272f54;">Recently Uploaded Documents</h6>
                            </div>
                            <div class="card-body px-4 pb-4 pt-2">
                                <p class="text-muted small mb-3">Latest student document submissions.</p>
                                <div class="d-flex flex-column gap-3">
                                    <?php
                                    $allDocs = [];
                                    foreach ($resumes as $r) {
                                        $allDocs[] = [
                                            'name' => $r['full_name'],
                                            'type' => 'Resume',
                                            'date' => $r['uploaded_at'],
                                            'bg' => '#EAF3DE',
                                            'color' => '#27500A'
                                        ];
                                    }
                                    foreach ($credentials as $c) {
                                        $allDocs[] = [
                                            'name' => $c['full_name'],
                                            'type' => 'Credential',
                                            'date' => $c['uploaded_at'],
                                            'bg' => '#E6F1FB',
                                            'color' => '#0C447C'
                                        ];
                                    }
                                    usort($allDocs, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
                                    $recentDocs = array_slice($allDocs, 0, 3);
                                    ?>
                                    <?php if (empty($recentDocs)): ?>
                                        <p class="text-muted small">No documents uploaded yet.</p>
                                    <?php else: ?>
                                        <?php foreach ($recentDocs as $doc): ?>
                                            <!-- CHANGED: renamed to ojtc-row-hover (same rename, no behavior change). -->
                                            <div class="ojtc-row-hover"
                                                style="display:flex;align-items:center;gap:10px;padding:6px 6px 12px 6px;border-bottom:1px solid #f0f2f7;">
                                                <div
                                                    style="width:32px;height:32px;min-width:32px;border-radius:50%;background:<?= $doc['bg'] ?>;color:<?= $doc['color'] ?>;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;">
                                                    <?= strtoupper(substr($doc['name'], 0, 1)) ?>
                                                </div>
                                                <div style="flex:1;min-width:0;">
                                                    <p style="margin:0;font-size:13px;font-weight:600;color:#272f54;">
                                                        <?= htmlspecialchars($doc['name']) ?>
                                                    </p>
                                                    <p style="margin:0;font-size:11px;color:#888;"><?= $doc['type'] ?></p>
                                                </div>
                                                <span
                                                    style="font-size:11px;color:#aaa;white-space:nowrap;"><?= date("M d", strtotime($doc['date'])) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── POSTINGS  ── -->
            <div id="postings" class="section sysAdm-section">

                <!-- CHANGED: header now uses your existing .sysAdm-header--danger / .sysAdm-header-left /
                     .sysAdm-header-icon / .sysAdm-header-text classes (already defined in your global
                     stylesheet — same ones Account Deletion/Account Management use) instead of the
                     plain, unstyled .sysAdm-header div. Wrapped h2+p in .sysAdm-header-text (also
                     already in your CSS) so the title stacks above the subtitle like Account
                     Management, instead of sitting inline like Account Deletion currently does. -->
                <div class="sysAdm-header--danger sysAdm-header--blue mb-4">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-pencil-fill"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Internship Postings</h2>
                            <p>The administrative module for publishing, modifying, and monitoring active internship
                                listings.</p>
                        </div>
                    </div>
                </div>

                <!-- CHANGED: switched from Bootstrap "d-flex" utility classes to explicit inline
                     flex styles. Those classes only work if Bootstrap's CSS is actually loaded on
                     this page — since the row was stacking instead of aligning, it likely isn't, so
                     this no longer depends on that. -->
                <div
                    style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                    <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div class="search-box">
                            <input type="text" id="search-postings"
                                style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:220px;"
                                placeholder="Search by company or title..." oninput="filterPostings()">
                            <!-- CHANGED: search icon color overridden to blue (#272f54, same navy
                                 used elsewhere in the app) — couldn't find the actual rule you
                                 mentioned, so this is a direct override. Swap the color value if you
                                 track down the real one. -->
                            <i class="bi bi-search" style="color:#272f54 !important;"></i>
                        </div>
                        <!-- CHANGED: added the 4 combo options (IT & CE, IT & EE, CE & EE, IT/CE/EE)
                             matching the exact values used in the "Add Internship Post" form's
                             Program select, so filtering matches how postings are actually saved. -->
                        <select class="filter-select"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:200px;"
                            id="postings-program-filter" onchange="filterPostings()">
                            <option value="All">Program</option>
                            <option value="Information Technology">IT</option>
                            <option value="Civil Engineering">CE</option>
                            <option value="Electrical Engineering">EE</option>
                            <option value="Information Technology, Civil Engineering">IT &amp; CE</option>
                            <option value="Information Technology, Electrical Engineering">IT &amp; EE</option>
                            <option value="Civil Engineering, Electrical Engineering">CE &amp; EE</option>
                            <option value="Information Technology, Civil Engineering, Electrical Engineering">IT, CE
                                &amp; EE</option>
                        </select>
                    </div>
                    <!-- CHANGED: swapped "btn-button" for "btn-update" (already in your CSS) — same
                         style as Account Management's "Add Admin"/"Add Adviser" buttons. -->
                    <button class="btn-update" onclick="showPostingForm()">
                        <i class="bi bi-plus-circle me-1"></i> Add Internship Post
                    </button>
                </div>

                <!-- CHANGED: swapped the unstyled .table-container/.custom-table for your existing
                     .sysAdm-table-wrapper/.sysAdm-table classes (same ones Account Deletion uses) —
                     this is what gives the grey header row + row hover seen in your screenshots. -->
                <div class="sysAdm-table-wrapper">
                    <table class="sysAdm-table" id="postings-table">
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>Job Title</th>
                                <th>Program</th>
                                <th>Location</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="postings-tbody">
                            <?php foreach ($internships as $p): ?>
                                <!-- CHANGED: data-program now reads $p['program'] instead of
                                     $p['company'] (looked like a bug — the attribute is named
                                     data-program). Also added data-company/data-title so the search
                                     box has something to actually filter against. -->
                                <tr data-company="<?= htmlspecialchars(strtolower($p['company'])) ?>"
                                    data-title="<?= htmlspecialchars(strtolower($p['title'])) ?>"
                                    data-program="<?= htmlspecialchars($p['program'] ?? '') ?>">
                                    <td><?= htmlspecialchars($p['company']) ?></td>
                                    <td><?= htmlspecialchars($p['title']) ?></td>
                                    <!-- CHANGED: now reads $p['program'] / $p['location'] instead of
                                         a hardcoded "—". This only shows real data if the query that
                                         populates $internships actually selects those columns —
                                         check that on your end if these still come up blank. -->
                                    <td><?= !empty($p['program']) ? htmlspecialchars($p['program']) : '—' ?></td>
                                    <td><?= !empty($p['location']) ? htmlspecialchars($p['location']) : '—' ?></td>
                                    <td>
                                        <!-- CHANGED  -->
                                        <button class="btn-delete" title="Delete" tooltip="Delete"
                                            onclick="deleteRow(this)">
                                            <i class="bi bi-trash-fill"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ADD FORM / POPUP -->
                <div id="posting-form-panel" class="ojtc-modal-backdrop" style="display:none;"
                    onclick="if(event.target===this) hidePostingForm()">
                    <div class="form-card ojtc-modal-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 style="margin:0;">New Internship Posting</h3>
                            <button type="button" onclick="hidePostingForm()"
                                style="background:none;border:none;font-size:20px;cursor:pointer;color:#888;">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <form method="POST" action="internship-db.php"
                            onsubmit="return confirm('Create this internship posting?');">
                            <input type="hidden" name="form_type" value="internship_posting">
                            <div class="form-grid">
                                <div><label>Title</label><input type="text" name="title" placeholder="Job Title"
                                        style="width:100%;" required></div>
                                <div><label>Company</label><input type="text" name="company" placeholder="Company Name"
                                        style="width:100%;" required></div>
                                <div><label>Location</label><input type="text" name="location" placeholder="Location"
                                        style="width:100%;" required></div>
                                <div>
                                    <label>Program</label>
                                    <select name="program" required style="width:100%;">
                                        <option value="" disabled selected>Select Program</option>
                                        <option value="Information Technology">IT</option>
                                        <option value="Civil Engineering">CE</option>
                                        <option value="Electrical Engineering">EE</option>
                                        <option value="Information Technology, Civil Engineering">IT, CE</option>
                                        <option value="Information Technology, Electrical Engineering">IT, EE</option>
                                        <option value="Civil Engineering, Electrical Engineering">CE, EE</option>
                                        <option
                                            value="Information Technology, Civil Engineering, Electrical Engineering">
                                            IT, CE, EE</option>
                                    </select>
                                </div>
                                <!-- <div><label>Deadline</label><input type="date" name="deadline"></div> -->
                                <div><label>Contact Email</label><input type="email" name="email"
                                        placeholder="Contact Email" style="width:100%;"></div>
                                <div><label>Contact Number</label><input type="tel" name="phonenumber"
                                        placeholder="Contact Number" style="width:100%;"></div>
                                <div>
                                    <!-- <label>Contract Duration</label>
                                    <select name="year">
                                        <option value="" disabled selected>Select Duration</option>
                                        <option value="1">1 year</option>
                                        <option value="2">2 years</option>
                                        <option value="3">3 years</option>
                                        <option value="4">4 years</option>
                                        <option value="5">5 years</option>
                                    </select> -->
                                    <label>Company Classification</label>
                                    <select name="company_classification" required style="width:100%;">
                                        <option value="" disabled selected>Select Classification</option>
                                        <option value="private">Private Sector</option>
                                        <option value="public">Public / Government Sector</option>
                                        <option value="academic">Academic & Research</option>
                                        <option value="nonprofit">Nonprofit & Civil Society</option>
                                        <option value="international">International Organizations</option>
                                        <option value="creative">Creative & Media</option>
                                        <option value="technology">Technology & Innovation</option>
                                        <option value="healthcare">Healthcare & Social Services</option>
                                        <option value="industrial">Industrial & Manufacturing</option>
                                        <option value="financial">Financial & Business</option>
                                        <option value="hospitality">Hospitality & Tourism</option>
                                        <option value="freelance">Freelance / Gig-Based</option>
                                        <option value="religious">Religious & Faith-Based</option>
                                        <option value="public-private">Public-Private Sector</option>
                                    </select>
                                </div>
                                <div style="grid-column:span 2;">
                                    <label>Description</label>
                                    <textarea name="description" placeholder="Description" required
                                        style="width:100%;"></textarea>
                                </div>
                                <div><label>Opening Time</label><input type="time" name="openTime" style="width:100%;">
                                </div>
                                <div><label>Closing Time</label><input type="time" name="closeTime" style="width:100%;">
                                </div>
                            </div>

                            <!-- Map Pin -->
                            <div class="mt-3">
                                <label>Pin Location on Map</label>
                                <p class="text-muted" style="font-size:13px;">Click on the map to pin the internship
                                    location.</p>
                                <div id="posting-map"
                                    style="width:100%;height:350px;border-radius:10px;border:1px solid #dee2e6;"></div>
                                <div class="row g-3 mt-2">
                                    <div class="col-md-6">
                                        <!-- <label>Latitude</label> -->
                                        <input type="hidden" name="latitude" id="post-lat"
                                            placeholder="Click map to set" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <!-- <label>Longitude</label> -->
                                        <input type="hidden" name="longitude" id="post-lng"
                                            placeholder="Click map to set" readonly>
                                    </div>
                                </div>
                                <!-- <div class="row g-3 mt-2">
                                    <div type="text" name="latitude" id="post-lat" placeholder="Click map to set"
                                        readonly>
                                    </div>
                                    <div type="text" name="longitude" id="post-lng" placeholder="Click map to set"
                                        readonly>
                                    </div>
                                </div> -->
                                <div id="pin-label" class="d-none mt-2">
                                    <span class="p-1 rounded text-bg-success">
                                        <i class="bi bi-geo-alt-fill"></i> Location pinned — drag or click to adjust
                                    </span>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" onclick="hidePostingForm()"
                                    style="background:#888;color:white;border:none;padding:11px 24px;border-radius:8px;font-weight:600;cursor:pointer;">
                                    Cancel
                                </button>
                                <!-- CHANGED: swapped "submit-btn" for "btn-create" — already in your
                                     CSS, unused anywhere else, and literally named for this action. -->
                                <button type="submit" class="btn-update" style="width:auto;padding:11px 24px;">
                                    Create Posting
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- <script>
                function filterPostings() {
                    const searchVal = document.getElementById('search-postings').value.trim().toLowerCase();
                    const programVal = document.getElementById('postings-program-filter').value;
                    const rows = document.querySelectorAll('#postings-tbody tr');

                    rows.forEach(function (row) {
                        const company = row.getAttribute('data-company') || '';
                        const title = row.getAttribute('data-title') || '';
                        const program = row.getAttribute('data-program') || '';

                        const matchesSearch = !searchVal || company.includes(searchVal) || title.includes(searchVal);
                        const matchesProgram = programVal === 'All' || program === programVal;

                        row.style.display = (matchesSearch && matchesProgram) ? '' : 'none';
                    });
                }
            </script> -->

            <!-- ADDED -->
            <style>
                .ojtc-modal-backdrop {
                    position: fixed;
                    inset: 0;
                    background: rgba(15, 23, 42, 0.5);
                    z-index: 1000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                    overflow-y: auto;
                }

                .ojtc-modal-card {
                    max-width: 800px;
                    width: 100%;
                    max-height: 90vh;
                    overflow-y: auto;
                    margin: auto;
                }
            </style>

            <!-- ── APPLICANTS ── -->
            <div id="interns" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue sysAdm-header mb-4">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Interns</h2>
                            <p>A place to review student credentials and track candidate progress through the hiring
                                pipeline.</p>
                        </div>
                    </div>
                </div>

                <!-- <div class="table-controls">
                    <div class="filters">
                        <select class="filter-select" id="app-phase-filter" onchange="filterApplicants()">
                            <option value="all">Status</option>
                            <option value="Internship Confirmed">Internship Confirmed</option>
                            <option value="Documents Submitted">Documents Submitted</option>
                            <option value="Application Submitted">Application Submitted</option>
                            <option value="Resume Uploaded">Resume Uploaded</option>
                            <option value="No Progress">No Progress</option>
                        </select>
                        <select class="filter-select" id="app-req-filter" onchange="filterApplicants()">
                            <option value="all">Requirements</option>
                            <option value="Complete">Complete</option>
                            <option value="Incomplete">Incomplete</option>
                        </select>
                        <select class="filter-select" id="app-program-filter" onchange="filterApplicants()">
                            <option value="all">Programs</option>
                            <option value="Information Technology">Information Technology</option>
                            <option value="Civil Engineering">Civil Engineering</option>
                            <option value="Electrical Engineering">Electrical Engineering</option>
                        </select>
                    </div>
                    <div class="search-box">
                        <input type="text" id="search-applicants" oninput="filterApplicants()" placeholder="Search">
                        <i class="bi bi-search"></i>
                    </div>
                </div> -->

                <!-- TEST -->

                <div
                    style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                    <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div class="search-box">
                            <input type="text" id="search-applicants"
                                style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:220px;"
                                placeholder="Search by company or title..." oninput="filterApplicants()">

                            <i class="bi bi-search" style="color:#272f54 !important;"></i>
                        </div>

                        <select class="filter-select"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:200px;"
                            id="app-phase-filter" onchange="filterApplicants()">
                            <option value="all">Status</option>
                            <option value="Internship Confirmed">Internship Confirmed</option>
                            <option value="Documents Submitted">Documents Submitted</option>
                            <option value="Application Submitted">Application Submitted</option>
                            <option value="Resume Uploaded">Resume Uploaded</option>
                            <option value="No Progress">No Progress</option>
                        </select>
                        <select class="filter-select"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:200px;"
                            id="app-phase-filter" onchange="filterApplicants()">
                            <option value="all">Requirements</option>
                            <option value="Complete">Complete</option>
                            <option value="Incomplete">Incomplete</option>
                        </select>
                        <select class="filter-select"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:200px;"
                            id="app-phase-filter" onchange="filterApplicants()">
                            <option value="all">Programs</option>
                            <option value="Information Technology">Information Technology</option>
                            <option value="Civil Engineering">Civil Engineering</option>
                            <option value="Electrical Engineering">Electrical Engineering</option>
                        </select>
                    </div>

                </div>

                <div class="sysAdm-table-wrapper">
                    <table class="sysAdm-table" id="applicants-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Program</th>
                                <th>Internship</th>
                                <th>Company</th>
                                <th>Phase</th>
                                <th>Requirements</th>
                            </tr>
                        </thead>
                        <tbody id="applicants-tbody">
                            <?php if (empty($applicants)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No applicants yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($applicants as $a):
                                    $phaseColors = [
                                        'Internship Confirmed' => ['bg' => '#d1fae5', 'color' => '#065f46'],
                                        'Documents Submitted' => ['bg' => '#dbeafe', 'color' => '#1e40af'],
                                        'Application Submitted' => ['bg' => '#fef9c3', 'color' => '#854d0e'],
                                        'Resume Uploaded' => ['bg' => '#fce7f3', 'color' => '#9d174d'],
                                        'No Progress' => ['bg' => '#f3f4f6', 'color' => '#6b7280'],
                                    ];
                                    $pc = $phaseColors[$a['current_phase']] ?? ['bg' => '#f3f4f6', 'color' => '#6b7280'];
                                    ?>
                                    <tr data-name="<?= strtolower(htmlspecialchars($a['full_name'])) ?>"
                                        data-program="<?= htmlspecialchars($a['program']) ?>"
                                        data-phase="<?= htmlspecialchars($a['current_phase']) ?>"
                                        data-req="<?= htmlspecialchars($a['requirements']) ?>">
                                        <td><?= htmlspecialchars($a['full_name']) ?></td>
                                        <td><?= htmlspecialchars($a['program']) ?></td>
                                        <td><?= htmlspecialchars($a['internship_title']) ?></td>
                                        <td><?= htmlspecialchars($a['company']) ?></td>
                                        <td>
                                            <span
                                                style="background:<?= $pc['bg'] ?>;color:<?= $pc['color'] ?>;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:600;">
                                                <?= htmlspecialchars($a['current_phase']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span
                                                style="color:<?= $a['requirements'] === 'Complete' ? '#16a34a' : '#dc2626' ?>;font-weight:600;font-size:13px;">
                                                <?= htmlspecialchars($a['requirements']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── DOCUMENTS ── -->
            <div id="documents" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue mb-4">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-file-earmark-text-fill"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Documents</h2>
                            <p>A secure repository for managing, verifying, and storing mandatory internship
                                documentation.</p>
                        </div>
                    </div>
                </div>

                <div
                    style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                    <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div class="search-box">
                            <input type="text" id="search-documents"
                                style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:220px;"
                                placeholder="Search by student name..." oninput="filterDocs()">

                            <i class="bi bi-search" style="color:#272f54 !important;"></i>
                        </div>

                        <select class="filter-select"
                            style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:200px;"
                            id="doc-type-filter" onchange="filterDocs()">
                            <option value="all">Document Type</option>
                            <option value="resume">Resume</option>
                            <option value="credential">Credential</option>
                        </select>
                        <select class="filter-select" id="doc-program-filter" onchange="filterDocs()">
                            <option value="programs">Programs</option>
                            <option value="Information Technology">Information Technology</option>
                            <option value="Civil Engineering">Civil Engineering</option>
                            <option value="Electrical Engineering">Electrical Engineering</option>
                        </select>
                    </div>

                </div>

                <div class="sysAdm-table-wrapper">
                    <table class="sysAdm-table" id="documents-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Student No.</th>
                                <th>Program</th>
                                <th>Document Type</th>
                                <th>Submission Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resumes as $doc): ?>
                                <tr data-type="resume" data-name="<?= strtolower(htmlspecialchars($doc['full_name'])) ?>"
                                    data-program="<?= strtolower(htmlspecialchars($doc['program'])) ?>">
                                    <td><?= htmlspecialchars($doc['full_name']) ?></td>
                                    <td><?= htmlspecialchars($doc['student_number']) ?></td>
                                    <td><?= htmlspecialchars($doc['program']) ?></td>
                                    <td><span>Resume</span></td>
                                    <td><?= date("M d, Y", strtotime($doc['uploaded_at'])) ?></td>
                                    <td style="text-align:center;">
                                        <a href="../uploads/resumes/<?= htmlspecialchars($doc['resume_path']) ?>"
                                            target="_blank" target="_blank" class="btn-delete" tooltip="View MOU"
                                            title="View MOU"
                                            style="text-decoration: none; background: #FFE7B3;
                                            color: #7a5200; border:1px solid #7a5200; background-color: #FFE7B3; transition: background-color 0.2s ease;"
                                            onmouseover="this.style.backgroundColor='#dbbe83';"
                                            onmouseout="this.style.backgroundColor='#FFE7B3';"><i class="bi bi-eye"></i>
                                        </a>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($credentials as $doc): ?>
                                <tr data-type="credential"
                                    data-name="<?= strtolower(htmlspecialchars($doc['full_name'])) ?>"
                                    data-program="<?= strtolower(htmlspecialchars($doc['program'])) ?>">
                                    <td><?= htmlspecialchars($doc['full_name']) ?></td>
                                    <td><?= htmlspecialchars($doc['student_number']) ?></td>
                                    <td><?= htmlspecialchars($doc['program']) ?></td>
                                    <td><span>Credentials</span></td>
                                    <td><?= date("M d, Y", strtotime($doc['uploaded_at'])) ?></td>
                                    <td style="text-align:center;">
                                        <a href="../uploads/credentials/<?= htmlspecialchars($doc['credential_path']) ?>"
                                            target="_blank" target="_blank" class="btn-delete" tooltip="View MOU"
                                            title="View MOU"
                                            style="text-decoration: none; background: #FFE7B3;
                                            color: #7a5200; border:1px solid #7a5200; background-color: #FFE7B3; transition: background-color 0.2s ease;"
                                            onmouseover="this.style.backgroundColor='#dbbe83';"
                                            onmouseout="this.style.backgroundColor='#FFE7B3';"><i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── INTERESTED ── -->
            <div id="interested" class="section sysAdm-header">
                <h2>Interested</h2>
                <p>A tracking section for monitoring student engagement and preliminary interest in companies and
                    internships.</p>

                <div class="table-controls">
                    <div class="filters"></div>
                    <div class="search-box">
                        <input type="text" id="search-interested" placeholder="Search by name, email, company...">
                        <i class="bi bi-search"></i>
                    </div>
                </div>

                <div class="table-container">
                    <table class="custom-table" id="interested-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Email</th>
                                <th>Company</th>
                                <th>Internship</th>
                                <th style="text-align:center; width:180px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="interested-tbody">
                            <?php foreach ($interests as $i): ?>
                                <tr>
                                    <td style="padding:14px 15px;"><?= htmlspecialchars($i['full_name']) ?></td>
                                    <td style="padding:14px 15px;"><?= htmlspecialchars($i['email']) ?></td>
                                    <td style="padding:14px 15px;"><?= htmlspecialchars($i['company']) ?></td>
                                    <td style="padding:14px 15px;"><?= htmlspecialchars($i['title']) ?></td>
                                    <td style="text-align:center;">
                                        <button type="button" class="btn btn-sm btn-danger"
                                            onclick="openFeedbackModal(<?= $i['interest_id'] ?>)"
                                            style="padding:4px 8px;border-radius:16px;font-size:12px;">
                                            Return with Feedback
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── DOCUMENT AVAILABILITY ── -->
            <div id="docu_availability" class="section sysAdm-section">

                <div class="sysAdm-header--danger sysAdm-header--blue mb-4">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-bookmark-fill"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Post Document Availability</h2>
                            <p>Let students know which documents are ready for a given internship.</p>
                        </div>
                    </div>
                </div>



                <!-- copy from here -->
                <!-- <div class="form-card mt-4">
                    <h3>Announce Document Availability</h3>
                    <p class="text-muted" style="font-size:13px;">
                        Let students know which documents are ready for a given internship.
                    </p>
                    
                    
                    <form action="internship-db.php" method="POST">
                        <input type="hidden" name="form_type" value="document_availability">

                        <div class="mb-3">
                            <label>Internship</label>
                            <select name="internship_id" required>
                                <option value="" disabled selected>Select Internship</option>
                                <?php foreach ($internships as $intn): ?>
                                    <option value="<?= $intn['id'] ?>">
                                        <?= htmlspecialchars($intn['company']) ?> —
                                        <?= htmlspecialchars($intn['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3 d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="mou_available" id="mouCheck">
                                <label class="form-check-label" for="mouCheck">MOU</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="recommendation_letter_available"
                                    id="rlCheck">
                                <label class="form-check-label" for="rlCheck">Recommendation Letter</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="waiver_available"
                                    id="waiverCheck">
                                <label class="form-check-label" for="waiverCheck">Waiver</label>
                            </div>
                        </div>

                        <button type="submit" class="submit-btn">Save Availability</button>
                    </form>
                </div> -->
                <!-- editing to make the announce document availability popup instead na nakalabas -->
                <div id="document-availability-form-panel" class="ojtc-modal-backdrop" style="display:none;"
                    onclick="if(event.target===this) hideDocAvailForm()">
                    <div class="form-card ojtc-modal-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 style="margin:0;">Announce Document Availability</h3>
                            <button type="button" onclick="hideDocAvailForm()"
                                style="background:none;border:none;font-size:20px;cursor:pointer;color:#888;">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <form method="POST" action="internship-db.php"
                            onsubmit="return confirm('Post this document availability?');">
                            <input type="hidden" name="form_type" value="document_availability">
                            <div class="mb-3">
                                <label>Internship</label>
                                <select name="internship_id" required>
                                    <option value="" disabled selected>Select Internship</option>
                                    <?php foreach ($internships as $intn): ?>
                                        <option value="<?= $intn['id'] ?>">
                                            <?= htmlspecialchars($intn['company']) ?> —
                                            <?= htmlspecialchars($intn['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3 d-flex gap-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="mou_available" id="mouCheck">
                                    <label class="form-check-label" for="mouCheck">MOU</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                        name="recommendation_letter_available" id="rlCheck">
                                    <label class="form-check-label" for="rlCheck">Recommendation Letter</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="waiver_available"
                                        id="waiverCheck">
                                    <label class="form-check-label" for="waiverCheck">Waiver</label>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" onclick="hideDocAvailForm()"
                                    style="background:#888;color:white;border:none;padding:11px 24px;border-radius:8px;font-weight:600;cursor:pointer;">
                                    Cancel
                                </button>
                                <button type="submit" class="btn-update" style="width:auto;padding:11px 24px;">
                                    Post Document Availability
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div
                    style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                    <div
                        style="display:flex; align-items:center; flex-wrap:wrap; gap:10px; justify-content:space-between; width:100%;">
                        <div class="search-box">
                            <input type="text" id="search-postings"
                                style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:220px;"
                                placeholder="Search by company..." oninput="filterPostings()">

                            <i class="bi bi-search" style="color:#272f54 !important;"></i>
                        </div>
                        <button class="btn-update" onclick="showDocAvailForm()">
                            <i class="bi bi-plus-circle me-1"></i> Post Document Availability
                        </button>
                    </div>
                </div>
                <div class="sysAdm-table-wrapper">
                    <table class="sysAdm-table" id="docuAvail-table">
                        <thead>
                            <tr>
                                <th>Internship</th>
                                <th>Company</th>
                                <th>MOU</th>
                                <th>Recommendation Letter</th>
                                <th>Waiver</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($docAvailability)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No document availability announced yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($docAvailability as $d): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($d['title']) ?></td>
                                        <td><?= htmlspecialchars($d['company']) ?></td>
                                        <td><?= $d['mou_available'] ? 'Yes' : 'X' ?></td>
                                        <td><?= $d['recommendation_letter_available'] ? 'Yes' : 'X' ?></td>
                                        <td><?= $d['waiver_available'] ? 'Yes' : 'X' ?></td>
                                        <td><?= date("M d, Y", strtotime($d['updated_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>


            <!-- ── MANAGE ANNOUNCEMENTS ── -->
            <div id="manage_announcement" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue mb-4">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-bell-fill"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Manage Announcements</h2>
                            <p>The content management utility for drafting, scheduling, and distributing official
                                notifications.</p>
                        </div>
                    </div>
                </div>

                <!-- <div class="table-controls">
                    <div class="filters">
                        <select class="filter-select" id="category-filter" onchange="filterAnnouncements()">
                            <option value="">All Categories</option>
                            <option value="news">News</option>
                            <option value="updates">Updates</option>
                            <option value="FAQs">FAQs</option>
                        </select>
                    </div>
                    <div class="search-box">
                        <input type="text" id="search-announcements" placeholder="Search announcements..."
                            oninput="filterAnnouncements()">
                        <i class="bi bi-search"></i>
                    </div>
                </div> -->



                <div
                    style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                    <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div class="search-box">
                            <input type="text" id="search-announcements"
                                style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:220px;"
                                placeholder="Search announcements..." oninput="filterAnnouncements()">

                            <i class="bi bi-search" style="color:#272f54 !important;"></i>
                        </div>
                    </div>
                    <button class="btn-update" onclick="showAnnouncementForm()">
                        <i class="bi bi-plus-circle me-1"></i> Post Announcement
                    </button>
                    <!-- currently editing -->
                    <div id="announcement-form-panel" class="ojtc-modal-backdrop" style="display:none;"
                        onclick="if(event.target===this) hideAnnouncementForm()">
                        <div class="form-card ojtc-modal-card">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h3 style="margin:0;">New Announcement</h3>
                                <button type="button" onclick="hideAnnouncementForm()"
                                    style="background:none;border:none;font-size:20px;cursor:pointer;color:#888;">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <form method="POST" action="internship-db.php"
                                onsubmit="return confirm('Post this announcement?');">
                                <input type="hidden" name="form_type" value="announcement_posting">
                                <div class="mb-3">
                                    <label>Title</label>
                                    <input type="text" name="title" placeholder="Title" style="width:100%;" required>
                                </div>
                                <div class="mb-3">
                                    <label>Message</label>
                                    <textarea name="message" placeholder="Message" style="width:100%;"
                                        required></textarea>
                                </div>
                                <div class="mb-3">
                                    <label>Category</label>
                                    <select name="category" required style="width:100%;">
                                        <option value="" disabled selected>Select Category</option>
                                        <option value="news">News</option>
                                        <option value="updates">Updates</option>
                                        <option value="FAQs">FAQs</option>
                                    </select>
                                </div>

                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button type="button" onclick="hideAnnouncementForm()"
                                        style="background:#888;color:white;border:none;padding:11px 24px;border-radius:8px;font-weight:600;cursor:pointer;">
                                        Cancel
                                    </button>
                                    <button type="submit" class="btn-update" style="width:auto;padding:11px 24px;">
                                        Post Announcement
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>


                    <!-- <div id="announcement-form-panel" class="ojtc-modal-backdrop" style="display:none;"
                    onclick="if(event.target===this) hideAnnouncementForm()">
                    <div class="form-card ojtc-modal-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 style="margin:0;">New Announcement</h3>
                            <button type="button" onclick="hideAnnouncementForm()"
                                style="background:none;border:none;font-size:20px;cursor:pointer;color:#888;">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <form method="POST" action="internship-db.php"
                            onsubmit="return confirm('Create this announcement?');">
                            <input type="hidden" name="form_type" value="announcement">
                            <div class="form-grid">
                                <div><label>Title</label><input type="text" name="title" placeholder="Job Title" style="width:100%;"
                                        required></div>
                                <div><label>Company</label><input type="text" name="company" placeholder="Company Name"style="width:100%;"
                                        required></div>
                                <div><label>Location</label><input type="text" name="location" placeholder="Location"style="width:100%;"
                                        required></div>
                                <div>
                                    <label>Program</label>
                                    <select name="program" required style="width:100%;">
                                        <option value="" disabled selected>Select Program</option>
                                        <option value="Information Technology">IT</option>
                                        <option value="Civil Engineering">CE</option>
                                        <option value="Electrical Engineering">EE</option>
                                        <option value="Information Technology, Civil Engineering">IT, CE</option>
                                        <option value="Information Technology, Electrical Engineering">IT, EE</option>
                                        <option value="Civil Engineering, Electrical Engineering">CE, EE</option>
                                        <option
                                            value="Information Technology, Civil Engineering, Electrical Engineering">
                                            IT, CE, EE</option>
                                    </select>
                                </div>
                                <div><label>Contact Email</label><input type="email" name="email"
                                        placeholder="Contact Email" style="width:100%;"></div>
                                <div><label>Contact Number</label><input type="tel" name="phonenumber"
                                        placeholder="Contact Number" style="width:100%;"></div>
                                <div>
                                    <label>Company Classification</label>
                                    <select name="company_classification" required style="width:100%;">
                                        <option value="" disabled selected>Select Classification</option>
                                        <option value="private">Private Sector</option>
                                        <option value="public">Public / Government Sector</option>
                                        <option value="academic">Academic & Research</option>
                                        <option value="nonprofit">Nonprofit & Civil Society</option>
                                        <option value="international">International Organizations</option>
                                        <option value="creative">Creative & Media</option>
                                        <option value="technology">Technology & Innovation</option>
                                        <option value="healthcare">Healthcare & Social Services</option>
                                        <option value="industrial">Industrial & Manufacturing</option>
                                        <option value="financial">Financial & Business</option>
                                        <option value="hospitality">Hospitality & Tourism</option>
                                        <option value="freelance">Freelance / Gig-Based</option>
                                        <option value="religious">Religious & Faith-Based</option>
                                        <option value="public-private">Public-Private Sector</option>
                                    </select>
                                </div>
                                <div style="grid-column:span 2;">
                                    <label>Description</label>
                                    <textarea name="description" placeholder="Description" required style="width:100%;"></textarea>
                                </div>
                                <div><label>Opening Time</label><input type="time" name="openTime" style="width:100%;"></div>
                                <div><label>Closing Time</label><input type="time" name="closeTime" style="width:100%;"></div>
                            </div>

                            <div class="mt-3">
                                <label>Pin Location on Map</label>
                                <p class="text-muted" style="font-size:13px;">Click on the map to pin the internship
                                    location.</p>
                                <div id="posting-map"
                                    style="width:100%;height:350px;border-radius:10px;border:1px solid #dee2e6;"></div>
                                <div class="row g-3 mt-2">
                                    <div class="col-md-6">
                                        <input type="hidden" name="latitude" id="post-lat"
                                            placeholder="Click map to set" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <input type="hidden" name="longitude" id="post-lng"
                                            placeholder="Click map to set" readonly>
                                    </div>
                                </div>
                                <div id="pin-label" class="d-none mt-2">
                                    <span class="p-1 rounded text-bg-success">
                                        <i class="bi bi-geo-alt-fill"></i> Location pinned — drag or click to adjust
                                    </span>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" onclick="hidePostingForm()"
                                    style="background:#888;color:white;border:none;padding:11px 24px;border-radius:8px;font-weight:600;cursor:pointer;">
                                    Cancel
                                </button>
                                <button type="submit" class="btn-update" style="width:auto;padding:11px 24px;">
                                    Create Posting
                                </button>
                            </div>
                        </form>
                    </div>
                </div> -->
                </div>

                <div class="sysAdm-table-wrapper">
                    <table class="sysAdm-table" id="manage-announcements-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Message</th>
                                <th>Category</th>
                                <th>Date</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="manage-announcements-tbody">
                            <?php if (empty($announcements)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">No announcements yet.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($announcements as $a): ?>
                                <tr data-category="<?= strtolower($a['category']) ?>">
                                    <form method="POST" action="internship-db.php">
                                        <td class="fw-bold" style="border:none;padding:14px 15px;">
                                            <input type="text" name="title" value="<?= htmlspecialchars($a['title']) ?>"
                                                required>
                                        </td>
                                        <td>
                                            <input type="text" name="message" value="<?= htmlspecialchars($a['message']) ?>"
                                                required>
                                        </td>
                                        <td>
                                            <select name="category" required>
                                                <option value="news" <?= $a['category'] === 'news' ? 'selected' : '' ?>>News
                                                </option>
                                                <option value="updates" <?= $a['category'] === 'updates' ? 'selected' : '' ?>>
                                                    Updates</option>
                                                <option value="FAQs" <?= $a['category'] === 'FAQs' ? 'selected' : '' ?>>FAQs
                                                </option>
                                            </select>
                                        </td>
                                        <td><?= date("M d, Y", strtotime($a['created_at'])) ?></td>
                                        <td class="text-center">
                                            <input type="hidden" name="announcement_id" value="<?= $a['id'] ?>">
                                            <button type="submit" name="edit_announcement" class="btn-delete"
                                                style="color: #384887; border:1px solid #5766a68a; background-color: #dfe4f8; transition: background-color 0.2s ease;"
                                                onmouseover="this.style.backgroundColor='#adbbe6';"
                                                onmouseout="this.style.backgroundColor='#dfe4f8';">
                                                <i class="bi bi-floppy2"></i>
                                            </button>
                                            <button type="submit" name="delete_announcement" class="btn-delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </form>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- UPLOAD MOOU -->
            <div id="upload_mou" class="section sysAdm-section">
                <div class="sysAdm-header--danger sysAdm-header--blue mb-4">
                    <div class="sysAdm-header-left">
                        <div class="sysAdm-header-icon">
                            <i class="bi bi-file-text-fill"></i>
                        </div>
                        <div class="sysAdm-header-text">
                            <h2>Upload MOU</h2>
                            <p>Upload signed Memorandums of Understanding and link them to an internship posting.</p>
                        </div>
                    </div>
                </div>

                <div
                    style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                    <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div class="search-box">
                            <input type="text" id="search-mou"
                                style="padding:8px 14px; border-radius:10px; border:1px solid #ddd; font-size:13px; min-width:220px;"
                                placeholder="Search company..." oninput="filterMOU()">

                            <i class="bi bi-search" style="color:#272f54 !important;"></i>
                        </div>
                    </div>
                    <button class="btn-update" onclick="showMOUupload()">
                        <i class="bi bi-plus-circle me-1"></i> Upload MOU
                    </button>
                    <!-- currently editing -->
                    <div id="mou-form-panel" class="ojtc-modal-backdrop" style="display:none;"
                        onclick="if(event.target===this) hideMOUupload()">
                        <div class="form-card ojtc-modal-card">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h3 style="margin:0;">New MOU</h3>
                                <button type="button" onclick="hideMOUupload()"
                                    style="background:none;border:none;font-size:20px;cursor:pointer;color:#888;">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <form method="POST" action="internship-db.php" enctype="multipart/form-data"
                                class="internship-form" onsubmit="return confirm('Post this MOU?');">
                                <input type="hidden" name="form_type" value="mou_upload">
                                <div class="form-card">
                                    <h3>MOU File</h3>
                                    <div class="mb-3">
                                        <label>Internship Posting</label>
                                        <select name="internship_id" required>
                                            <option value="" disabled selected>Select Internship</option>
                                            <?php foreach ($internships as $p): ?>
                                                <option value="<?= (int) $p['id'] ?>">
                                                    <?= htmlspecialchars($p['company']) ?> —
                                                    <?= htmlspecialchars($p['title']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label>MOU File (PDF, max 5MB)</label>
                                        <input type="file" name="mou_file" accept=".pdf" required>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button type="button" onclick="hideMOUupload()"
                                        style="background:#888;color:white;border:none;padding:11px 24px;border-radius:8px;font-weight:600;cursor:pointer;">
                                        Cancel
                                    </button>
                                    <button type="submit" class="btn-update" style="width:auto;padding:11px 24px;">
                                        Post MOU
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>



                <!-- MOU TABLE -->
                <div class="sysAdm-table-wrapper" style="margin-top:20px;">
                    <table class="sysAdm-table" id="mou-table">
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>Job Title</th>
                                <th>Uploaded</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="mou-tbody">
                            <?php foreach ($mouUploads as $m): ?>
                                <tr>
                                    <td style="padding:14px 15px;"><?= htmlspecialchars($m['company'] ?? '—') ?></td>
                                    <td style="padding:14px 15px;"><?= htmlspecialchars($m['title'] ?? '—') ?></td>
                                    </td>
                                    <td style="padding:14px 15px;">
                                        <?= date("M d, Y", strtotime($m['updated_at'])) ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <form method="POST" action="internship-db.php" style="display:inline;"
                                            onsubmit="return confirm('Delete this MOU?');">
                                            <input type="hidden" name="form_type" value="mou_delete">
                                            <input type="hidden" name="mou_id" value="<?= (int) $m['id'] ?>">
                                            <a href="<?= htmlspecialchars($m['file_path']) ?>" target="_blank"
                                                target="_blank" class="btn-delete" tooltip="View MOU" title="View MOU"
                                                style="text-decoration: none; background: #FFE7B3;
                                            color: #7a5200; border:1px solid #7a5200; background-color: #FFE7B3; transition: background-color 0.2s ease;"
                                                onmouseover="this.style.backgroundColor='#dbbe83';"
                                                onmouseout="this.style.backgroundColor='#FFE7B3';"><i class="bi bi-eye"></i>
                                            </a>
                                            <button type="submit" class="btn-delete" title="Delete" tooltip="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($mouUploads)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:14px;color:#888;">No MOUs uploaded yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div><!-- /.main-content -->
    </div><!-- /.page-body -->


    <!-- FEEDBACK MODAL -->
    <div id="feedbackModal"
        style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
        <div style="background:#29335C;border-radius:5px;padding:30px;width:500px;max-width:90%;">
            <h5 style="color:white;text-align:center;margin-bottom:0;font-weight:400;">Return with Feedback</h5>
            <textarea id="feedbackText" placeholder="Enter feedback here.."
                style="width:100%;height:130px;border-radius:5px;border:none;padding:14px;font-size:14px;resize:none;outline:none;margin-top:15px;"></textarea>
            <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:20px;">
                <button onclick="closeFeedbackModal()"
                    style="background:transparent;color:white;border:1px solid white;padding:8px 20px;border-radius:20px;cursor:pointer;font-size:14px;">
                    Cancel
                </button>
                <button onclick="sendFeedback()"
                    style="background:white;color:#29335C;border:none;padding:8px 20px;border-radius:20px;cursor:pointer;font-size:14px;font-weight:600;">
                    Send Feedback
                </button>
            </div>
        </div>
    </div>

    <script>
        // Flash alert auto-dismiss
        setTimeout(() => {
            const alert = document.getElementById('flashAlert');
            if (alert) { alert.classList.remove('show'); setTimeout(() => alert.remove(), 300); }
        }, 3000);

        // CSV add row
        function addRow() {
            const tbody = document.getElementById('csv-tbody');
            const colCount = parseInt(document.getElementById('col-count').value);
            const rowCount = parseInt(document.getElementById('row-count').value);
            document.getElementById('row-count').value = rowCount + 1;
            const tr = document.createElement('tr');
            for (let col = 0; col < colCount; col++) {
                const td = document.createElement('td');
                const input = document.createElement('input');
                input.type = 'text';
                input.name = `csv[${rowCount}][${col}]`;
                input.className = 'form-control';
                input.placeholder = '—';
                td.appendChild(input);
                tr.appendChild(td);
            }
            tbody.appendChild(tr);
            tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // Feedback modal
        function openFeedbackModal(bookmarkId) {
            document.getElementById('feedbackModal').dataset.id = bookmarkId;
            document.getElementById('feedbackModal').style.display = 'flex';
        }
        function closeFeedbackModal() {
            document.getElementById('feedbackModal').style.display = 'none';
            document.getElementById('feedbackModal').dataset.id = null;
        }
        function sendFeedback() {
            const modal = document.getElementById('feedbackModal');
            const bookmarkId = modal.dataset.id;
            const feedback = document.getElementById('feedbackText').value.trim();
            if (!bookmarkId) { alert("No bookmark selected."); return; }
            if (!feedback) { alert('Please enter feedback before sending.'); return; }

            const formData = new FormData();
            formData.append('bookmark_id', bookmarkId);
            formData.append('feedback', feedback);
            formData.append('send_feedback', 1);
            formData.append('form_type', 'send_feedback');

            fetch('internship-db.php', { method: 'POST', body: formData })
                .then(res => res.text())
                .then(text => {
                    if (text.trim() !== "success") { alert(text); return; }
                    closeFeedbackModal();
                    location.reload();
                })
                .catch(err => { console.error(err); alert('Something went wrong.'); });
        }
        document.getElementById('feedbackModal').addEventListener('click', function (e) {
            if (e.target === this) closeFeedbackModal();
        });

        // Interested search
        document.getElementById('search-interested').addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('#interested-tbody tr').forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
            });
        });

        // Announcements filter
        function filterAnnouncements() {
            const search = document.getElementById('search-announcements').value.toLowerCase();
            const category = document.getElementById('category-filter').value.toLowerCase();
            document.querySelectorAll('#manage-announcements-tbody tr').forEach(row => {
                const rowCat = (row.dataset.category ?? '').toLowerCase();
                const text = Array.from(row.querySelectorAll('input, select, td'))
                    .map(el => el.tagName === 'INPUT' || el.tagName === 'SELECT' ? el.value : el.innerText)
                    .join(' ').toLowerCase();
                row.style.display = (text.includes(search) && (category === '' || rowCat === category)) ? '' : 'none';
            });
        }

        // MOU filter

        // Documents filter
        function filterDocs() {
            const search = document.getElementById('search-documents').value.toLowerCase();
            const type = document.getElementById('doc-type-filter').value.toLowerCase();
            const program = document.getElementById('doc-program-filter').value.toLowerCase();
            document.querySelectorAll('#documents-table tbody tr').forEach(row => {
                const nameMatch = row.dataset.name?.includes(search) ?? true;
                const typeMatch = type === 'all' || row.dataset.type === type;
                const progMatch = program === 'programs' || (row.dataset.program ?? '').toLowerCase() === program;
                row.style.display = (nameMatch && typeMatch && progMatch) ? '' : 'none';
            });
        }

        // Applicants filter
        function filterApplicants() {
            const search = document.getElementById('search-applicants').value.toLowerCase();
            const phase = document.getElementById('app-phase-filter').value;
            const req = document.getElementById('app-req-filter').value;
            const program = document.getElementById('app-program-filter').value;
            document.querySelectorAll('#applicants-tbody tr').forEach(row => {
                const nameMatch = row.dataset.name?.includes(search) ?? true;
                const phaseMatch = phase === 'all' || row.dataset.phase === phase;
                const reqMatch = req === 'all' || row.dataset.req === req;
                const programMatch = program === 'all' || row.dataset.program === program;
                row.style.display = (nameMatch && phaseMatch && reqMatch && programMatch) ? '' : 'none';
            });
        }

        // Postings
        function showPostingForm() {
            document.getElementById('posting-form-panel').style.display = 'block';
            document.getElementById('posting-form-panel').scrollIntoView({ behavior: 'smooth' });
        }
        function hidePostingForm() {
            document.getElementById('posting-form-panel').style.display = 'none';
        }
        function filterPostings() {
            const program = document.getElementById('postings-program-filter').value;
            const search = document.getElementById('search-postings').value.toLowerCase();
            document.querySelectorAll('#postings-tbody tr').forEach(row => {
                const rowProgram = row.dataset.program ?? '';
                const rowText = row.innerText.toLowerCase();
                const matchP = program === 'All' || rowProgram.includes(program);
                const matchS = search === '' || rowText.includes(search);
                row.style.display = (matchP && matchS) ? '' : 'none';
            });
        }
        function deleteRow(btn) {
            if (!confirm('Delete this internship posting?')) return;
            btn.closest('tr').remove();
        }


        // added for manage announcements
        function showAnnouncementForm() {
            document.getElementById('announcement-form-panel').style.display = 'flex';
            document.getElementById('announcement-form-panel').scrollIntoView({ behavior: 'smooth' });
        }
        function hideAnnouncementForm() {
            document.getElementById('announcement-form-panel').style.display = 'none';
        }

        // added for manage document availability
        function showDocAvailForm() {
            document.getElementById('document-availability-form-panel').style.display = 'flex';
            document.getElementById('document-availability-form-panel').scrollIntoView({ behavior: 'smooth' });
        }
        function hideDocAvailForm() {
            document.getElementById('document-availability-form-panel').style.display = 'none';
        }

        //added for manage MOU
        function showMOUupload() {
            document.getElementById('mou-form-panel').style.display = 'flex';
            document.getElementById('mou-form-panel').scrollIntoView({ behavior: 'smooth' });
        }
        function hideMOUupload() {
            document.getElementById('mou-form-panel').style.display = 'none';
        }
        // Map
        let postingMap, postingMarker;
        function initPostingMap() {
            postingMap = new google.maps.Map(document.getElementById('posting-map'), {
                zoom: 12,
                center: { lat: 14.7011, lng: 120.9830 }
            });
            postingMap.addListener('click', function (e) {
                const lat = e.latLng.lat();
                const lng = e.latLng.lng();
                document.getElementById('post-lat').value = lat.toFixed(7);
                document.getElementById('post-lng').value = lng.toFixed(7);
                document.getElementById('pin-label').classList.remove('d-none');
                if (postingMarker) {
                    postingMarker.setPosition(e.latLng);
                } else {
                    postingMarker = new google.maps.Marker({
                        position: e.latLng, map: postingMap,
                        title: 'Internship Location', draggable: true
                    });
                    postingMarker.addListener('dragend', function () {
                        const pos = postingMarker.getPosition();
                        document.getElementById('post-lat').value = pos.lat().toFixed(7);
                        document.getElementById('post-lng').value = pos.lng().toFixed(7);
                    });
                }
            });
        }
    </script>

    <script
        src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDITrnTUmS0AwxqZCE8cfYI3d5kjtzg7RY&callback=initPostingMap"
        async defer></script>
    <script src="../JS/script.js"></script>

</body>

</html>