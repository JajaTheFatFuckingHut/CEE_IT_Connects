<?php $page = 'announcements';
require 'db.php';
require 'auth.php';

$stmt = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC");

$announcements = [
    'news' => [],
    'updates' => [],
    'FAQs' => []
];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!isset($announcements[$row['category']])) {
        $announcements[$row['category']] = [];
    }
    $announcements[$row['category']][] = $row;
}

$docAvailStmt = $pdo->query("
    SELECT ida.internship_id, ida.mou_available, ida.recommendation_letter_available,
           ida.waiver_available, ida.updated_at, i.title, i.company
    FROM internship_document_availability ida
    JOIN internships i ON i.id = ida.internship_id
    ORDER BY ida.updated_at DESC
");
$docAvailability = $docAvailStmt->fetchAll(PDO::FETCH_ASSOC);

$sStmt = $pdo->query("SELECT day, month, year, reason FROM calendar_suspensions WHERE is_suspended = TRUE");
$suspensions = [];
foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = sprintf('%04d-%02d-%02d', $row['year'], $row['month'], $row['day']);
    $suspensions[$key] = $row['reason'] ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements | CEE IT Connects</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="../CSS/index-style.css" rel="stylesheet">

    <style>
        body {
            background: #fff5f0 !important;
        }

        .announcement-wrapper {
            min-height: calc(100vh - 70px);
            padding: 28px 25px;
        }

        .section-label {
            font-size: 11px;
            font-weight: 700;
            color: #56585c;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            margin: 24px 0 8px 0;
        }

        /* .section-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .08em;
            color: #94a3b8;
            text-transform: uppercase;
            padding-bottom: 6px;
            border-bottom: 2px solid #ff6b2c;
            display: inline-block;
            margin-bottom: 12px;
        } */

        .section-label:first-of-type {
            margin-top: 0;
        }

        /* PREP BANNER */
        .prep-banner {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #919191;
            margin-bottom: 8px;
        }

        .prep-col {
            padding: 22px 20px;
            border-right: 1px solid #eee;
            background: #fafbfc;
        }

        .prep-col:last-child {
            border-right: none;
        }

        .prep-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #FFE8DC;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .prep-icon i {
            font-size: 16px;
            color: #ff6b2c;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .prep-tip-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin: 0 0 5px 0;
        }

        .prep-tip-desc {
            font-size: 12px;
            color: #555;
            margin: 0;
            line-height: 1.6;
        }

        /* SHARED CARD WRAPPER */
        .ann-card {
            background: #fff;
            border: 1px solid #e8eaf0;
            border-radius: 10px;
            overflow: hidden;
        }

        /* NEWS CARDS */
        .news-card-new {
            padding: 15px 18px;
            border-bottom: 1px solid #f3f4f7;
            border-left: 4px solid #E4572E;
            transition: background 0.15s;
        }

        .news-card-new:last-child {
            border-bottom: none;
        }

        .news-card-new:hover {
            background: #fafbfc;
        }

        .news-card-title {
            font-size: 15px;
            font-weight: 700;
            color: #E4572E;
            margin: 0 0 4px 0;
        }

        .news-card-desc {
            font-size: 13px;
            color: #6b7280;
            margin: 0 0 6px 0;
            line-height: 1.55;
        }

        .news-card-desc.collapsed {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .news-toggle-btn {
            background: none;
            border: 1px solid #e0e4ed;
            border-radius: 6px;
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: border-color 0.15s, color 0.15s;
        }

        .news-toggle-btn:hover {
            border-color: #E4572E;
            color: #E4572E;
        }

        /* UPDATE CARDS */
        .update-card-new {
            padding: 15px 18px;
            border-bottom: 1px solid #f3f4f7;
            border-left: 4px solid transparent;
            transition: background 0.15s;
        }

        .update-card-new:last-child {
            border-bottom: none;
        }

        .update-card-new:nth-child(odd) {
            border-left-color: #E4572E;
        }

        .update-card-new:nth-child(even) {
            border-left-color: #FFB62F;
        }

        .update-card-new:hover {
            background: #fafbfc;
        }

        .update-card-source {
            font-size: 11px;
            font-weight: 700;
            color: #E4572E;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin: 0 0 4px 0;
        }

        .update-card-source span {
            font-weight: 400;
            color: #b0b8cc;
            text-transform: none;
            letter-spacing: 0;
        }

        .update-card-title {
            font-size: 15px;
            font-weight: 700;
            color: #1a1f36;
            margin: 0 0 5px 0;
            line-height: 1.3;
        }

        .update-card-desc {
            font-size: 13px;
            color: #6b7280;
            margin: 0 0 10px 0;
            line-height: 1.55;
        }

        /* FAQ */
        .faq-item {
            border-bottom: 1px solid #f3f4f7;
            border-left: 3px solid transparent;
            cursor: pointer;
            transition: border-color 0.2s;
        }

        .faq-item:last-child {
            border-bottom: none;
        }

        .faq-question {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 13px 18px;
            transition: background 0.15s;
        }

        .faq-item:hover .faq-question {
            background: #fafbfc;
        }

        .faq-item.open .faq-question {
            background: #e8f1ff;
        }

        .faq-q-text {
            font-size: 14px;
            font-weight: 600;
            color: #1a1f36;
            margin: 0;
            transition: color 0.2s;
        }

        .faq-item.open .faq-q-text {
            color: #2563eb;
        }

        .faq-chevron {
            font-size: 12px;
            color: #b0b8cc;
            transition: transform 0.25s ease, color 0.2s;
            flex-shrink: 0;
        }

        .faq-item.open .faq-chevron {
            transform: rotate(180deg);
            color: #2563eb;
        }

        .faq-item.open {
            border-left-color: #2563eb;
        }

        .faq-answer {
            font-size: 13px;
            color: #6b7280;
            line-height: 1.65;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.25s ease;
            padding: 0 18px;
            background: #f5f8ff;
        }

        .faq-item.open .faq-answer {
            max-height: 200px;
            padding: 10px 18px 14px 18px;
        }

        /* Empty state */
        .empty-state {
            padding: 20px 18px;
            font-size: 13px;
            color: #b0b8cc;
        }

        @media (max-width: 768px) {
            .announcement-wrapper {
                padding: 17px 10px;
            }

            .prep-banner {
                grid-template-columns: 1fr;
            }

            .prep-col {
                display: flex;
                align-items: flex-start;
                gap: 16px;
                border-right: none;
                border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            }

            .prep-letter {
                margin: 0;
                flex-shrink: 0;
            }

            .prep-content {
                flex: 1;
            }

            .prep-col:last-child {
                border-bottom: none;
            }
        }

        /* AVAILABLE DOCS TABLE */
        .docs-table-wrap {
            background: #fff;
            border: 1px solid #919191;
            border-radius: 10px;
            overflow: hidden;
        }

        .docs-table {
            width: 100%;
            border-collapse: collapse;
        }

        .docs-table thead th {
            background: #f8f9fc;
            color: #56585c;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 12px 16px;
            border-bottom: 1px solid #e8eaf0;
            text-align: left;
        }

        .docs-table tbody td {
            padding: 13px 16px;
            font-size: 13.5px;
            color: #1a1f36;
            border-bottom: 1px solid #f3f4f7;
            vertical-align: middle;
        }

        .docs-table tbody tr:last-child td {
            border-bottom: none;
        }

        .docs-table tbody tr:hover {
            background: #fafbfc;
        }

        .docs-table .company-cell {
            color: #E4572E;
            font-weight: 600;
            font-size: 12.5px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .doc-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 99px;
        }

        .doc-status.available {
            background: #d1fae5;
            color: #065f46;
        }

        .doc-status.unavailable {
            background: #f3f4f6;
            color: #9ca3af;
        }

        .doc-status i {
            font-size: 9px;
        }

        .docs-table .updated-cell {
            color: #b0b8cc;
            font-size: 12px;
        }

        .docs-empty-state {
            padding: 30px 18px;
            text-align: center;
            font-size: 13px;
            color: #b0b8cc;
        }

        .page-banner {
            background: #e8f1ff;
            border: 1px solid #2563eb;
            border-radius: 12px;
            padding: 24px 28px;
            margin-bottom: 24px;
            margin-top: 40px;
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

        /* Calendar */
        #calendar {
            --c-card: #ffffff;
            --c-line: #e6ebf5;
            --c-text: #1b2a4e;
            --c-dim: #7a869f;
            --c-accent: #ff5a1f;
            --c-accent-soft: #ffece4;
            --c-ok: #10a37f;
            --c-warn: #e5484d;
            --c-warn-soft: #fdecec;
            box-sizing: border-box;
            max-width: 100%;
            min-width: 0;
        }

        #calendar *,
        #calendar *::before,
        #calendar *::after {
            box-sizing: border-box;
        }

        #calendar .tc-panel {
            width: 100%;
            background: var(--c-card);
            border: 1px solid var(--c-line);
            border-radius: 14px;
            padding: 18px;
            color: var(--c-text);
            overflow-x: auto;
        }

        /* toolbar */
        #calendar .tc-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
        }

        #calendar .tc-select,
        #calendar .tc-btn {
            background: #fff;
            color: var(--c-text);
            border: 1px solid var(--c-line);
            border-radius: 8px;
            padding: 7px 12px;
            font: inherit;
            font-size: .85rem;
            cursor: pointer;
            transition: border-color .15s, color .15s, background .15s;
        }

        #calendar .tc-btn-wide {
            font-weight: 600;
        }

        #calendar .tc-select:hover,
        #calendar .tc-btn:hover,
        #calendar .tc-select:focus,
        #calendar .tc-btn:focus-visible {
            outline: none;
            border-color: var(--c-accent);
            color: var(--c-accent);
        }

        #calendar .tc-readout {
            margin-left: auto;
            font-size: .8rem;
            font-weight: 600;
            letter-spacing: .08em;
            color: var(--c-dim);
        }

        /* grid */
        #calendar .tc-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 6px;
        }

        #calendar .tc-head {
            text-align: center;
            padding: 6px 0;
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .1em;
            color: var(--c-dim);
        }

        #calendar .tc-day {
            min-height: 76px;
            padding: 8px 10px;
            background: #fff;
            border: 1px solid var(--c-line);
            border-radius: 10px;
            transition: border-color .15s, background .15s;
        }

        #calendar .tc-day.empty {
            background: transparent;
            border: none;
        }

        #calendar .tc-day:not(.empty):hover {
            border-color: var(--c-accent);
            background: #fffaf7;
            cursor: pointer;
        }

        #calendar .tc-day.weekend {
            background: #f8faff;
        }

        #calendar .tc-day.weekend .tc-num {
            color: var(--c-dim);
        }

        #calendar .tc-num {
            font-size: .85rem;
            font-weight: 600;
        }

        #calendar .tc-day.today {
            background: var(--c-accent-soft);
            border-color: var(--c-accent);
        }

        #calendar .tc-day.today .tc-num {
            color: var(--c-accent);
        }

        #calendar .tc-hrs {
            margin-top: 6px;
            font-size: .72rem;
            font-weight: 600;
            color: var(--c-ok);
        }

        /* suspended dates */
        #calendar .tc-day.suspended {
            background: var(--c-warn-soft);
            border-color: #f5c2c4;
        }

        #calendar .tc-day.suspended .tc-num {
            color: var(--c-warn);
        }

        #calendar .tc-susp {
            margin-top: 4px;
            font-size: .65rem;
            line-height: 1.3;
            color: var(--c-warn);
        }

        #calendar .tc-susp b {
            display: block;
            letter-spacing: .08em;
        }

        #calendar .tc-susp span {
            display: block;
            color: var(--c-text);
            opacity: .8;
            overflow-wrap: anywhere;
        }

        @media (max-width: 576px) {
            #calendar .tc-day {
                min-height: 54px;
                padding: 5px;
            }

            #calendar .tc-readout {
                width: 100%;
                margin-left: 0;
            }
        }
    </style>
</head>

<body>

    <?php include 'navbar.php'; ?>

    <section class="announcement-wrapper">
        <div class="container-fluid">
            <div class="page-banner">
                <div class="page-banner-icon"><i class="fa fa-bullhorn"></i></div>
                <div class="page-banner-text">
                    <h2>Announcements</h2>
                    <p>Stay updated on internship news, requirements, and deadlines.</p>
                </div>
            </div>
            <br>
            <!-- INTERNSHIP TIPS -->
            <p class="section-label">Internship Tips | PREP</p>
            <div class="prep-banner">
                <div class="prep-col">
                    <div class="prep-icon"><i class="fa fa-file-lines"></i></div>
                    <p class="prep-tip-title">Prepare your documents early</p>
                    <p class="prep-tip-desc">Don't wait for deadlines. Have your resume, endorsement letter, and forms
                        ready ahead of time.</p>
                </div>
                <div class="prep-col">
                    <div class="prep-icon"><i class="fa fa-building"></i></div>
                    <p class="prep-tip-title">Research about the company</p>
                    <p class="prep-tip-desc">Know what the company does before your interview. It shows initiative and
                        professionalism.</p>
                </div>
                <div class="prep-col">
                    <div class="prep-icon"><i class="fa fa-envelope"></i></div>
                    <p class="prep-tip-title">Email professionally</p>
                    <p class="prep-tip-desc">Use proper text and a formal tone when communicating with companies.</p>
                </div>
                <div class="prep-col">
                    <div class="prep-icon"><i class="fa fa-clock"></i></div>
                    <p class="prep-tip-title">Punctuality is everything</p>
                    <p class="prep-tip-desc">Being on time reflects your work ethic and attitude.</p>
                </div>
            </div>

            <br>
            <!-- Available Internship Docs -->
            <p class="section-label">Documents Now Available</p>
            <div class="docs-table-wrap">
                <table class="docs-table">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>MOU</th>
                            <th>Recommendation Letter</th>
                            <th>Waiver</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($docAvailability)): ?>
                            <tr>
                                <td colspan="6" class="docs-empty-state">No document availability announced yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($docAvailability as $d): ?>
                                <tr>
                                    <td class="company-cell"><?= htmlspecialchars($d['company']) ?></td>
                                    <td>
                                        <span class="doc-status <?= $d['mou_available'] ? 'available' : 'unavailable' ?>">
                                            <!-- <i class="fas fa-circle"></i> -->
                                            <?= $d['mou_available'] ? 'Available' : 'N/A' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span
                                            class="doc-status <?= $d['recommendation_letter_available'] ? 'available' : 'unavailable' ?>">
                                            <!-- <i class="fas fa-circle"></i> -->
                                            <?= $d['recommendation_letter_available'] ? 'Available' : 'N/A' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="doc-status <?= $d['waiver_available'] ? 'available' : 'unavailable' ?>">
                                            <!-- <i class="fas fa-circle"></i> -->
                                            <?= $d['waiver_available'] ? 'Available' : 'N/A' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <br>
            <p class="section-label">Calendar</p>
            <!-- Calendar Section -->
            <div id="calendar" class="section <?= $current_section === 'calendar' ? 'active' : '' ?>">
                <div class="main">
                    <div class="tc-panel">
                        <div class="tc-toolbar">
                            <button type="button" id="calPrev" class="tc-btn"
                                aria-label="Previous month">&lsaquo;</button>

                            <select id="calMonth" class="tc-select"></select>
                            <select id="calYear" class="tc-select"></select>

                            <button type="button" id="calNext" class="tc-btn" aria-label="Next month">&rsaquo;</button>
                            <button type="button" id="calToday" class="tc-btn tc-btn-wide">TODAY</button>

                            <div class="tc-readout" id="calReadout"></div>
                        </div>

                        <div id="calGrid" class="tc-grid"></div>
                    </div>
                </div>
            </div>
            <br>

            <!-- NEWS — from DB -->
            <p class="section-label">News</p>
            <div class="ann-card">
                <?php if (empty($announcements['news'])): ?>
                    <p class="empty-state">No news posted yet.</p>
                <?php else: ?>
                    <?php foreach ($announcements['news'] as $news):
                        $message = htmlspecialchars($news['message']);
                        $isLong = strlen($message) > 120;
                        ?>
                        <div class="news-card-new">
                            <p class="news-card-title"><?= htmlspecialchars($news['title']) ?></p>
                            <p class="news-card-desc collapsed" id="newsDesc<?= $news['id'] ?>">
                                <?= $message ?>
                            </p>
                            <div class="d-flex align-items-center justify-content-between">
                                <small class="text-muted">
                                    Posted <?= date('F j, Y', strtotime($news['created_at'])) ?>
                                </small>
                                <?php if ($isLong): ?>
                                    <button class="news-toggle-btn" onclick="toggleNews(<?= $news['id'] ?>, this)">
                                        Read more
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <br>
            <!-- UPDATES — from DB -->
            <p class="section-label">Updates</p>
            <div class="ann-card">
                <?php if (empty($announcements['updates'])): ?>
                    <p class="empty-state">No updates posted yet.</p>
                <?php else: ?>
                    <?php foreach ($announcements['updates'] as $update): ?>
                        <div class="update-card-new">
                            <p class="update-card-source">
                                Update &nbsp;·&nbsp;
                                <span><?= date('F j, Y', strtotime($update['created_at'])) ?></span>
                            </p>
                            <p class="update-card-title"><?= htmlspecialchars($update['title']) ?></p>
                            <p class="update-card-desc"><?= htmlspecialchars($update['message']) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <br>
            <!-- FAQs — from DB -->
            <p class="section-label">FAQs</p>
            <div class="ann-card">
                <?php if (empty($announcements['FAQs'])): ?>
                    <p class="empty-state">No FAQs posted yet.</p>
                <?php else: ?>
                    <?php foreach ($announcements['FAQs'] as $faq): ?>
                        <div class="faq-item">
                            <div class="faq-question">
                                <p class="faq-q-text"><?= htmlspecialchars($faq['title']) ?></p>
                                <i class="fas fa-chevron-down faq-chevron"></i>
                            </div>
                            <p class="faq-answer"><?= htmlspecialchars($faq['message']) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../JS/index-script.js"></script>
    <script>
        // FAQ toggle
        document.querySelectorAll('.faq-item').forEach(item => {
            item.querySelector('.faq-question').addEventListener('click', () => {
                const isOpen = item.classList.contains('open');
                document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('open'));
                if (!isOpen) item.classList.add('open');
            });
        });

        // News read more toggle
        function toggleNews(id, btn) {
            const desc = document.getElementById(`newsDesc${id}`);
            const collapsed = desc.classList.contains('collapsed');
            desc.classList.toggle('collapsed');
            btn.textContent = collapsed ? 'Read less' : 'Read more';
        }

        // For the calendar
        const CAL_SUSPENDED = <?= json_encode($suspensions, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        document.addEventListener('DOMContentLoaded', function () {
            const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];
            const DAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

            const monthSel = document.getElementById('calMonth');
            const yearSel = document.getElementById('calYear');
            const calEl = document.getElementById('calGrid');   // not 'calendar'
            const readout = document.getElementById('calReadout');
            if (!monthSel || !yearSel || !calEl) return;


            const esc = s => String(s).replace(/[&<>"']/g, c =>
                ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            const now = new Date();

            // Fill dropdowns
            MONTHS.forEach((m, i) => monthSel.add(new Option(m, i)));
            for (let y = now.getFullYear() - 2; y <= now.getFullYear() + 2; y++) {
                yearSel.add(new Option(y, y));
            }
            monthSel.value = now.getMonth();
            yearSel.value = now.getFullYear();

            function render() {
                const month = parseInt(monthSel.value, 10);
                const year = parseInt(yearSel.value, 10);
                const firstDay = new Date(year, month, 1).getDay();
                const daysInMonth = new Date(year, month + 1, 0).getDate();
                const data = (typeof CAL_DATA !== 'undefined') ? CAL_DATA : {};
                const susp = (typeof CAL_SUSPENDED !== 'undefined' && CAL_SUSPENDED) ? CAL_SUSPENDED : {};

                let html = DAYS.map(d => `<div class="tc-head">${d}</div>`).join('');
                for (let i = 0; i < firstDay; i++) html += '<div class="tc-day empty"></div>';

                for (let d = 1; d <= daysInMonth; d++) {
                    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                    const dow = new Date(year, month, d).getDay();
                    const isToday = d === now.getDate() && month === now.getMonth() && year === now.getFullYear();
                    const hrs = data[dateStr];
                    const isSusp = Object.prototype.hasOwnProperty.call(susp, dateStr);
                    const reason = isSusp ? susp[dateStr] : '';

                    html += `<div class="tc-day${isToday ? ' today' : ''}${dow === 0 || dow === 6 ? ' weekend' : ''}${isSusp ? ' suspended' : ''}"
                        data-date="${dateStr}"${isSusp ? ` title="${esc(reason)}"` : ''}>
                        <span class="tc-num">${String(d).padStart(2, '0')}</span>
                        ${hrs ? `<div class="tc-hrs">${hrs} HRS</div>` : ''}
                        ${isSusp ? `<div class="tc-susp"><b>SUSPENDED</b><span>${esc(reason)}</span></div>` : ''}
                    </div>`;
                }

                calEl.innerHTML = html;
                readout.textContent = `${MONTHS[month].slice(0, 3).toUpperCase()} // ${year}`;
            }

            function shift(delta) {
                let m = parseInt(monthSel.value, 10) + delta;
                let y = parseInt(yearSel.value, 10);
                if (m < 0) { m = 11; y--; }
                if (m > 11) { m = 0; y++; }
                // keep within the year dropdown's range
                if (![...yearSel.options].some(o => parseInt(o.value, 10) === y)) return;
                monthSel.value = m;
                yearSel.value = y;
                render();
            }

            monthSel.addEventListener('change', render);
            yearSel.addEventListener('change', render);
            document.getElementById('calPrev').addEventListener('click', () => shift(-1));
            document.getElementById('calNext').addEventListener('click', () => shift(1));
            document.getElementById('calToday').addEventListener('click', () => {
                monthSel.value = now.getMonth();
                yearSel.value = now.getFullYear();
                render();
            });

            render();
        });
    </script>
</body>

</html>