<?php
require 'db.php';
require_once 'auth.php';

$room_id = $current_room_id;
$student_id = $_SESSION['user_id'];

// ROOM INFO
$stmt = $pdo->prepare("
    SELECT r.*, a.full_name, a.role
    FROM rooms r
    LEFT JOIN advisers a ON r.adviser_id = a.id
    WHERE r.id = ?
");
$stmt->execute([$room_id]);
$room = $stmt->fetch();

// ROOM POSTS (UPDATES)
$stmt = $pdo->prepare("
    SELECT * FROM room_posts
    WHERE room_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$room_id]);
$posts = $stmt->fetchAll();

$mouStmt = $pdo->query("
    SELECT mu.id, mu.file_path, mu.updated_at, mu.internship_id,
           i.company, i.title
    FROM mou_uploads mu
    LEFT JOIN internships i ON i.id = mu.internship_id
    ORDER BY mu.updated_at DESC
");
$mouUploads = $mouStmt->fetchAll(PDO::FETCH_ASSOC);

// ROOM MEMBERS
$stmt = $pdo->prepare("
    SELECT rm.user_id, rm.user_type, 
       COALESCE(s.full_name, a.full_name, ad.name) AS full_name
FROM room_members rm
LEFT JOIN students s ON rm.user_type = 'student' AND rm.user_id = s.id
LEFT JOIN advisers a ON rm.user_type = 'adviser' AND rm.user_id = a.id
LEFT JOIN admins ad ON rm.user_type = 'admin' AND rm.user_id = ad.id
WHERE rm.room_id = ?
");
$stmt->execute([$room_id]);
$members = $stmt->fetchAll();

$tab = $_GET['tab'] ?? 'updates';

$stmt = $pdo->query("
    SELECT id, full_name, email, 'student' AS role, 'students' AS source FROM students
    UNION ALL
    SELECT id, full_name, email, 'adviser' AS role, 'advisers' AS source FROM advisers  
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);


$stmt = $pdo->prepare("
    SELECT user_id, user_type FROM room_members WHERE room_id = ?
");
$stmt->execute([$room_id]);
$alreadyexisting = $stmt->fetchAll(PDO::FETCH_ASSOC);

$alreadyExistingMap = [];
foreach ($alreadyexisting as $e) {
    $alreadyExistingMap[$e['user_type'] . '_' . $e['user_id']] = true;
}

$stmt = $pdo->prepare("
    SELECT 
        s.id,
        s.full_name,
        r.room_name,
        i.company,
        COALESCE((
            SELECT ROUND(SUM(
                GREATEST(0, CASE 
                    WHEN h.m_in IS NOT NULL AND h.m_out IS NOT NULL 
                    THEN EXTRACT(EPOCH FROM (h.m_out - h.m_in)) / 3600 
                    ELSE 0 
                END) +
                GREATEST(0, CASE 
                    WHEN h.a_in IS NOT NULL AND h.a_out IS NOT NULL 
                    THEN EXTRACT(EPOCH FROM (h.a_out - h.a_in)) / 3600 
                    ELSE 0 
                END)
            )::numeric, 2)
            FROM ojt_hours h
            WHERE h.user_id = s.id 
            AND h.user_type = 'student'
        ), 0) AS total_hours,
        MAX(m.remarks) AS latest_remarks
    FROM students s
    JOIN room_members rm ON s.id = rm.user_id AND rm.user_type = 'student'
    JOIN rooms r ON rm.room_id = r.id
    LEFT JOIN student_internships si ON s.id = si.student_id
    LEFT JOIN internships i ON si.internship_id = i.id
    LEFT JOIN (
        SELECT DISTINCT ON (student_id)
            student_id, remarks
        FROM ojt_remarks
        ORDER BY student_id, updated_at DESC
    ) m ON s.id = m.student_id
    WHERE s.id = ?
    GROUP BY s.id, s.full_name, r.room_name, i.company
");
$stmt->execute([$student_id]);
$status = $stmt->fetch(PDO::FETCH_ASSOC);

$rhStmt = $pdo->prepare("
    SELECT COALESCE(i.required_hours, 486)
    FROM ojt_applications oa
    JOIN internships i ON i.id = oa.internship_id
    WHERE oa.student_id = ?
    LIMIT 1
");
$rhStmt->execute([$_SESSION['user_id']]);
$requiredHours = $rhStmt->fetchColumn() ?: 486;

$backLink = getDashboardByRole($_SESSION['role']);
?>

<head>
    <style>
        .tab-link {
            text-decoration: none;
            padding-bottom: 5px;
        }

        .tab-link:hover {
            color: #ff6b2c;
        }

        .active-tab {
            border-bottom: 2px solid #ff6b2c;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            z-index: 1050;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-box {
            background: #fff;
            border-radius: 12px;
            width: 100%;
            max-width: 460px;
            overflow: hidden;
            animation: modalIn .2s ease;
        }

        @keyframes modalIn {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 14px 18px;
            display: flex;
        }

        .modal-header h6 {
            margin: 0;
            font-weight: 700;
            font-size: 14px;
        }

        .modal-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 14px;
            cursor: pointer;
            line-height: 1;
        }

        .modal-body {
            padding: 18px;
        }

        .modal-footer {
            padding: 10px 18px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .search-input-wrap {
            position: relative;
            margin-bottom: 12px;
        }

        .search-input-wrap i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
        }

        .search-input-wrap input {
            width: 100%;
            padding: 8px 12px 8px 32px;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            font-size: .875rem;
            outline: none;
            box-sizing: border-box;
        }

        .search-input-wrap input:focus {
            border-color: #272f54;
        }

        .progress-bar-bg {
            width: 250px;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            transition: width .3s ease;
            border-radius: 4px;
            background: #ff6b2c;
        }

        .participant-list {
            max-height: 240px;
            overflow-y: auto;
        }

        .participant-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 6px;
            border-radius: 8px;
            cursor: pointer;
        }

        .participant-item:hover {
            background: #fff7ed;
        }

        .participant-item input[type="checkbox"] {
            accent-color: #272f54;
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .participant-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #ffe7b3;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #272f54;
            font-size: 14px;
        }

        .participant-info strong {
            display: block;
            font-size: 16px;
            line-height: 1.2;
        }

        .participant-info small {
            color: #888;
            font-size: 14px;
        }

        /* add btn states */
        .btn-add-confirm {
            border: none;
            border-radius: 6px;
            padding: 6px 14px;
            font-size: 14px;
            font-weight: 600;
            cursor: not-allowed;
            background: #ffe0cc;
            color: #c278aa;
        }

        .btn-add-confirm.has-selection {
            background: #272f54;
            color: #fff;
            cursor: pointer;
        }

        .btn-add-confirm.has-selection:hover {
            background: #e4572e;
        }

        .btn-update {
            background: #FFE7B3 !important;
            color: #7a5200 !important;
            /* border: none !important;
            border-radius: 10px !important;
            padding: 8px 18px !important;
            font-weight: 600 !important; */
            padding: 8px 18px !important;
            transition: background-color .15s ease, color .15s ease;
        }

        .btn-update:hover {
            background: #E4572E !important;
            color: #fff !important;
        }

        /* ── MEMBER CARD ── */
        .member-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border: 1px solid #f0e0ea;
            border-radius: 10px;
            margin-bottom: 8px;
            background: #fff;
        }

        .member-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #ffe7b3;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #272f54;
            font-size: 14px;
            flex-shrink: 0;
        }

        .member-info strong {
            display: block;
            font-size: 16px;
        }

        .member-info small {
            color: #888;
            font-size: 14px;
        }

        .badge-role {
            margin-left: auto;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-student {
            color: #1e40af;
            font-size: 14px;
        }

        .badge-hte {
            color: #2756c2;
            font-size: 14px;
        }

        .chat-container {
            display: flex;
            flex-direction: column;
            height: 520px;
            border: 1px solid #eee;
            border-radius: 12px;
            overflow: hidden;
            background: #fafafa;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .msg-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }

        .msg-row.me {
            flex-direction: row-reverse;
        }

        .msg-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #ffe7b3;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #272f54;
            font-size: .7rem;
            flex-shrink: 0;
        }

        .msg-bubble-wrap {
            max-width: 68%;
        }

        .msg-sender {
            font-size: .72rem;
            font-weight: 600;
            color: #888;
            margin-bottom: 3px;
            padding-left: 2px;
        }

        .me .msg-sender {
            text-align: right;
            padding-right: 2px;
            padding-left: 0;
        }

        .msg-bubble {
            padding: 9px 13px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.45;
            word-break: break-word;
        }

        .msg-row:not(.me) .msg-bubble {
            background: #fff;
            border: 1px solid #ffe0cc;
            border-bottom-left-radius: 4px;
            color: #222;
        }

        .msg-row.me .msg-bubble {
            background: #272f54;
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .msg-time {
            font-size: 12px;
            color: #bbb;
            margin-top: 3px;
            padding-left: 2px;
        }

        .me .msg-time {
            text-align: right;
            padding-right: 2px;
            padding-left: 0;
        }

        .msg-file {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 12px;
            border: 1px solid #ffe0cc;
            background: #fff;
            color: #222;
            font-size: 12px;
            border-bottom-left-radius: 4px;
        }

        .msg-row.me .msg-file {
            background: #e4572e;
            color: #fff;
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 4px;
        }

        .msg-file-info strong {
            display: block;
            font-size: 12px;
        }

        .msg-file-info span {
            font-size: 12px;
            opacity: .7;
        }

        .chat-day-divider {
            text-align: center;
            font-size: 12px;
            color: #bbb;
            position: relative;
            margin: 4px 0;
        }

        .chat-day-divider::before,
        .chat-day-divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 38%;
            height: 1px;
            background: #eee;
        }

        .chat-day-divider::before {
            left: 0;
        }

        .chat-day-divider::after {
            right: 0;
        }

        .chat-input-bar {
            border-top: 1px solid #eee;
            background: #fff;
            padding: 10px 12px;
        }

        .file-preview-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 8px;
        }

        .file-chip {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #ffe0cc;
            border: 1px solid #ffe0cc;
            border-radius: 20px;
            padding: 4px 10px 4px 8px;
            font-size: 12px;
            color: #c05621;
        }

        .file-chip button {
            background: none;
            border: none;
            color: #c05621;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            font-size: 12px;
        }

        .chat-input-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }

        .chat-input-row textarea {
            flex: 1;
            border: 1.5px solid #e8d0e3;
            border-radius: 22px;
            padding: 9px 16px;
            font-size: 14px;
            line-height: 1.4;
            max-height: 110px;
            overflow-y: auto;
            font-family: inherit;
        }

        .chat-input-row textarea:focus {
            border-color: #272f54;
        }

        .chat-attach-btn,
        .chat-send-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .chat-attach-btn {
            background: #eef1fb;
            color: #272f54;
        }

        .chat-attach-btn:hover {
            background: #ecd3e8;
        }

        .chat-send-btn {
            background: #272f54;
            color: #fff;
        }

        .chat-send-btn:hover {
            background: #e4572e;
        }

        .chat-send-btn:disabled {
            background: ##ffcfa8;
            cursor: not-allowed;
        }

        .circle-progress {
            --pct: 0;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: conic-gradient(#fff calc(var(--pct) * 1%), rgba(255, 255, 255, 0.3) 0);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 0 6px auto;
        }

        .circle-progress span {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: #272f54;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /*susu - DESKTOP CHAT SCROLL*/
        /* @media (min-width: 769px) {

            html,
            body {
                height: 100vh;
                overflow: hidden;
            }

            .main-content {
                height: 100vh;
                overflow: hidden;
                display: flex;
                flex-direction: column;
            }

            .chat-container {
                display: flex;
                flex-direction: column;
                height: calc(100vh - 320px);
                position: relative;
            }

            .chat-messages {
                flex: 1 1 auto;
                overflow-y: auto !important;
                max-height: 100%;
            }

            .chat-input-bar {
                flex-shrink: 0;
                background: #ffffff;
            }
        } */

        @media (max-width: 768px) {
            #roomChatBackBar {
                display: flex !important;
                padding-top: 10px;
                padding-left: 10px;
            }

            .p-3.text-white.rounded.d-flex.justify-content-between.align-items-center {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 12px;
            }

            .p-3.text-white.rounded .text-end {
                width: 100% !important;
                text-align: left !important;
                display: flex !important;
                flex-direction: row !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 15px;
            }

            .progress-bar-bg {
                flex-grow: 1 !important;
                margin-bottom: 0 !important;
                width: 100% !important;
            }

            .p-3.text-white.rounded .text-end small {
                white-space: nowrap !important;
            }

            .chat-container {
                height: calc(100vh - 90px) !important;
                border-radius: 0;
            }

            .chat-messages {
                flex: 1 1 auto;
            }
        }
        /* addtl s */
        /* ── ROOM PAGE REDESIGN ── */
        .rm-hero { background:#272f54; color:#fff; border-radius:12px; padding:20px 24px; border-left:4px solid #FFB62F;
            display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
        .rm-hero h5 { font-size:22px; font-weight:700; margin:0 0 10px; }
        .rm-chips { display:flex; gap:8px; flex-wrap:wrap; }
        .rm-chip { display:inline-flex; align-items:center; gap:6px; font-size:13px; padding:4px 12px; border-radius:999px; background:rgba(255,255,255,.14); }
        .rm-hero-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .rm-count { background:rgba(255,255,255,.12); border-radius:10px; padding:8px 16px; text-align:center; min-width:76px; }
        .rm-count b { display:block; font-size:20px; line-height:1.2; }
        .rm-count small { font-size:11px; opacity:.8; }

        .rm-tabs { display:flex; gap:24px; border-bottom:1px solid #e5e7eb; margin:16px 0; }
        .rm-tab { display:inline-flex; align-items:center; gap:8px; padding-bottom:10px; font-weight:700; font-size:14px; color:#1e293b; text-decoration:none; }
        .rm-tab:hover { color:#ff6b2c; }
        .rm-tab.on { color:#ff6b2c; box-shadow:inset 0 -2px 0 #ff6b2c; }
        .rm-cnt { font-size:11px; font-weight:600; padding:1px 8px; border-radius:999px; background:#eef1fb; color:#64748b; }
        .rm-tab.on .rm-cnt { background:#ffe5d9; color:#a13d1f; }

        .rm-grid { display:grid; grid-template-columns:minmax(0,2fr) minmax(0,1fr); gap:16px; align-items:start; }
        .rm-col { display:flex; flex-direction:column; gap:16px; }
        .rm-card { background:#fff; border-radius:12px; padding:16px 18px; box-shadow:0 2px 10px rgba(0,0,0,.05); }
        .rm-avatar { width:38px; height:38px; min-width:38px; border-radius:50%; display:flex; align-items:center; justify-content:center;
            color:#fff; font-weight:700; font-size:14px; }

        .rm-composer-row { display:flex; gap:12px; align-items:flex-start; }
        .rm-composer textarea { flex:1; border:1.5px solid #ffe0cc; border-radius:10px; padding:10px 14px; font-size:14px; font-family:inherit;
            resize:none; outline:none; background:#fafbfc; transition:border-color .2s; }
        .rm-composer textarea:focus { border-color:#ff6b2c; background:#fff; }
        .rm-composer-foot { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:12px; padding-left:50px; flex-wrap:wrap; }
        .rm-hint { font-size:12px; color:#94a3b8; }
        .rm-post-btn { background:#272f54; color:#fff; border:none; border-radius:8px; padding:8px 16px; font-size:14px; font-weight:600; cursor:pointer; transition:background .15s; }
        .rm-post-btn:hover { background:#e4572e; }

        .rm-post-head { display:flex; align-items:center; gap:12px; margin-bottom:10px; }
        .rm-post-who strong { font-size:15px; color:#272f54; margin-right:6px; }
        .rm-post-who small { display:block; color:#94a3b8; font-size:12px; }
        .rm-post-body { margin:0; font-size:14px; line-height:1.6; color:#334155; white-space:pre-line; word-break:break-word; }
        .rm-pill { font-size:11px; font-weight:600; padding:2px 8px; border-radius:6px; vertical-align:middle; white-space:nowrap; }
        .rm-pill-adviser { background:#dbeafe; color:#1e40af; }
        .rm-pill-hte     { background:#d1fae5; color:#065f46; }
        .rm-pill-admin   { background:#fff4d6; color:#7a5200; }
        .rm-pill-student { background:#eef1fb; color:#272f54; }

        .rm-empty { text-align:center; padding:36px 20px; color:#64748b; }
        .rm-empty-ic { width:56px; height:56px; border-radius:14px; background:#ffe5d9; color:#ff6b2c; display:flex;
            align-items:center; justify-content:center; font-size:22px; margin:0 auto 12px; }
        .rm-empty strong { display:block; color:#272f54; font-size:15px; margin-bottom:4px; }
        .rm-empty p { margin:0; font-size:13px; }

        .rm-side-title { font-weight:700; font-size:14px; color:#272f54; margin:0 0 6px; }
        .rm-dr { display:flex; justify-content:space-between; gap:12px; padding:9px 0; border-top:1px solid #f0f2f7; font-size:13px; }
        .rm-dl .rm-dr:first-child { border-top:none; }
        .rm-dr span:first-child { color:#94a3b8; }
        .rm-dr span:last-child { color:#272f54; font-weight:600; text-align:right; }
        .rm-side-sub { display:flex; align-items:center; gap:8px; margin:14px 0 8px; padding-top:12px; border-top:1px solid #f0f2f7;
            font-weight:700; font-size:13px; color:#272f54; }
        .rm-admin-row { display:flex; align-items:center; gap:10px; padding:5px 0; font-size:13px; color:#272f54; }
        .rm-admin-row .rm-avatar { width:28px; height:28px; min-width:28px; font-size:11px; }
        .rm-stack { display:flex; }
        .rm-stack .rm-avatar { width:32px; height:32px; min-width:32px; font-size:12px; border:2px solid #fff; margin-left:-8px; }
        .rm-stack .rm-avatar:first-child { margin-left:0; }
        .rm-stack .rm-more { background:#eef1fb; color:#64748b; }
        .rm-link { font-size:12px; font-weight:600; color:#ff6b2c; text-decoration:none; }

        .rm-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
        .rm-search { position:relative; flex:1; min-width:200px; max-width:340px; }
        .rm-search i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#f97316; font-size:13px; }
        .rm-search input { width:100%; padding:8px 12px 8px 34px; border:1.5px solid #aeaeae; border-radius:22px; font-size:13px; outline:none; font-family:inherit; }
        .rm-search input:focus { border-color:#f97316; }
        .rm-filters { display:flex; gap:6px; flex-wrap:wrap; }
        .rm-filter { border:1px solid #e5e7eb; background:#fff; color:#475569; border-radius:999px; padding:5px 12px; font-size:12px; font-weight:600; cursor:pointer; }
        .rm-filter.on { background:#272f54; border-color:#272f54; color:#fff; }
        .rm-group { margin-bottom:20px; }
        .rm-group-title { display:flex; align-items:center; gap:8px; font-size:12px; font-weight:700; letter-spacing:.06em;
            text-transform:uppercase; color:#64748b; margin-bottom:10px; }
        .rm-mgrid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:12px; }
        .rm-mcard { display:flex; align-items:center; gap:12px; background:#fff; border-radius:12px; padding:12px 14px; box-shadow:0 2px 10px rgba(0,0,0,.05); }
        .rm-minfo { flex:1; min-width:0; }
        .rm-minfo strong { display:block; font-size:14px; color:#272f54; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .rm-minfo small { display:block; font-size:12px; color:#94a3b8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

        @media (max-width: 992px) { .rm-grid { grid-template-columns:1fr; } }
        @media (max-width: 768px) {
            .rm-hero { flex-direction:column; align-items:flex-start; }
            .rm-hero-right { width:100%; }
            .rm-composer-foot { padding-left:0; }
        }
        /* addtl e */
    </style>
</head>

<?php
// ═══ UI-ONLY additions: read-only queries and display helpers ═══
$rmColors   = ['#ff2c8f', '#2c6fff', '#1abc9c', '#9b59b6', '#e67e22', '#e74c3c', '#16a085'];
$rmColor    = fn($name) => $rmColors[crc32((string) $name) % count($rmColors)];
$rmInitials = function ($name): string {
    $i = strtoupper(substr(trim((string) $name), 0, 1));
    return $i !== '' ? $i : '?';
};
$rmMeName  = $userFullName ?? 'You';
$rmCanPost = in_array($_SESSION['role'] ?? '', ['internship_adviser', 'hte_adviser', 'superadmin', 'internship_admin'], true);

// Role label + pill color for a post's sender_role
$rmPostRole = function (string $role): array {
    $map = [
        'superadmin'         => ['System Admin', 'rm-pill-admin'],
        'internship_admin'   => ['Internship Admin', 'rm-pill-admin'],
        'internship_adviser' => ['OJT Adviser', 'rm-pill-adviser'],
        'hte_adviser'        => ['HTE Adviser', 'rm-pill-hte'],
    ];
    return $map[$role] ?? [ucfirst(str_replace('_', ' ', $role)), 'rm-pill-adviser'];
};

// Program: rooms.program if that column exists, otherwise the most common students.program in this room
$rmProgram = trim((string) ($room['program'] ?? ''));
if ($rmProgram === '') {
    $rmProgStmt = $pdo->prepare("
        SELECT s.program
        FROM students s
        JOIN room_members rm ON rm.user_id = s.id AND rm.user_type = 'student'
        WHERE rm.room_id = ? AND s.program IS NOT NULL AND s.program <> ''
        GROUP BY s.program
        ORDER BY COUNT(*) DESC
        LIMIT 1
    ");
    $rmProgStmt->execute([$room_id]);
    $rmProgram = (string) $rmProgStmt->fetchColumn();
}
$rmYearSection = (!empty($room['year_level']) && !empty($room['section']))
    ? (int) $room['year_level'] . '-' . $room['section'] : '';
$rmClassLabel = ($rmProgram !== '' && $rmYearSection !== '')
    ? $rmProgram . ' ' . $rmYearSection
    : 'Year ' . (int) ($room['year_level'] ?? 0) . ' - Section ' . ($room['section'] ?? '');

// Extra details for member cards (student no. + program, adviser role + title)
$rmInfoStmt = $pdo->prepare("
    SELECT s.id, s.student_id AS student_no, s.program
    FROM students s
    JOIN room_members rm ON rm.user_id = s.id AND rm.user_type = 'student'
    WHERE rm.room_id = ?
");
$rmInfoStmt->execute([$room_id]);
$rmStudentInfo = [];
foreach ($rmInfoStmt->fetchAll(PDO::FETCH_ASSOC) as $rmRow) {
    $rmStudentInfo[$rmRow['id']] = $rmRow;
}
$rmAdvStmt = $pdo->prepare("
    SELECT a.id, a.role, a.title
    FROM advisers a
    JOIN room_members rm ON rm.user_id = a.id AND rm.user_type = 'adviser'
    WHERE rm.room_id = ?
");
$rmAdvStmt->execute([$room_id]);
$rmAdvInfo = [];
foreach ($rmAdvStmt->fetchAll(PDO::FETCH_ASSOC) as $rmRow) {
    $rmAdvInfo[$rmRow['id']] = $rmRow;
}

// Group members by type (uses your existing $members)
$rmGroups = ['adviser' => [], 'admin' => [], 'student' => []];
foreach ($members as $rmMember) {
    $rmGroups[$rmMember['user_type']][] = $rmMember;
}
$rmGroupMeta = [
    'adviser' => ['Advisers', 'fa-user-tie'],
    'admin'   => ['Admins', 'fa-shield-halved'],
    'student' => ['Students', 'fa-user-graduate'],
];
$rmMemberCount = count($members);
$rmPostCount   = count($posts);
?>

<?php if (isset($_SESSION['role']) === 'student'): ?>
    <div class="d-flex justify-content-end mb-2">
        <a href="<?= $backLink ?>" class="text-danger fw-semibold" style="text-decoration:none;">
            <i class="fa-solid fa-arrow-left"></i> Back to rooms
        </a>
    </div>
<?php endif; ?>

<!-- HEADER -->
<div class="rm-hero">
    <div>
        <h5><?= htmlspecialchars($room['room_name']) ?></h5>
        <div class="rm-chips">
            <?php if (!empty($room['department'])): ?>
                <span class="rm-chip"><i class="fa-solid fa-building-columns"></i> <?= htmlspecialchars(ucwords($room['department'])) ?></span>
            <?php elseif (!empty($room['section'])): ?>
                <span class="rm-chip"><i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars($rmClassLabel) ?></span>
                <?php if (!empty($room['school_year'])): ?>
                    <span class="rm-chip"><i class="fa-solid fa-calendar"></i> S.Y. <?= htmlspecialchars($room['school_year']) ?></span>
                <?php endif; ?>
                <?php if (!empty($room['full_name'])): ?>
                    <span class="rm-chip"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($room['full_name']) ?></span>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!empty($room['full_name'])): ?>
                    <span class="rm-chip"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($room['full_name']) ?></span>
                <?php endif; ?>
                <?php if (!empty($room['role'])): ?>
                    <span class="rm-chip"><?= htmlspecialchars($room['role']) ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="rm-hero-right">
        <?php if ($_SESSION['role'] === 'hte_adviser'): ?>
            <?php foreach ($mouUploads as $m): ?>
                <a href="<?= htmlspecialchars($m['file_path']) ?>" target="_blank" class="btn-update"
                    style="font-size: 15px;font-weight:bold;color:#ffffff;display:inline-block;width:auto;padding:11px 24px;text-align:center;text-decoration:none;">
                    <i class="bi bi-file-earmark-pdf me-1"></i> View MOU
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
        if ($_SESSION['role'] === 'student'):
            if ($status):
                $progressWidth = min(round(($status['total_hours'] / $requiredHours) * 100, 2), 100);
                ?>
                <div class="text-end">
                    <div class="text-end">
                        <div class="circle-progress" style="--pct: <?= $progressWidth ?>;">
                            <span><?= $progressWidth ?>%</span>
                        </div>
                        <small><?= $status['total_hours'] ?> / <?= $requiredHours ?> hours</small>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="rm-count"><b><?= $rmMemberCount ?></b><small>Members</small></div>
            <div class="rm-count"><b><?= $rmPostCount ?></b><small>Posts</small></div>
        <?php endif; ?>
    </div>
</div>

<!-- TABS -->
<div class="rm-tabs">
    <a href="?room_id=<?= $room_id ?>&tab=updates" class="rm-tab <?= $tab === 'updates' ? 'on' : '' ?>">
        <i class="fa-solid fa-bullhorn"></i> Updates <span class="rm-cnt"><?= $rmPostCount ?></span>
    </a>
    <a href="?room_id=<?= $room_id ?>&tab=members" class="rm-tab <?= $tab === 'members' ? 'on' : '' ?>">
        <i class="fa-solid fa-users"></i> Members <span class="rm-cnt"><?= $rmMemberCount ?></span>
    </a>
</div>

<!-- CONTENT -->
<div class="mt-3">

    <?php if ($tab === 'members'): ?>

        <div class="rm-toolbar">
            <div class="rm-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="rmMemberSearch" placeholder="Search by name, student no., or program…"
                    oninput="rmApplyMembers()">
            </div>
            <div class="rm-filters">
                <button type="button" class="rm-filter on" onclick="rmSetFilter('all', this)">All <?= $rmMemberCount ?></button>
                <?php foreach ($rmGroupMeta as $rmType => [$rmTitle, $rmIcon]): ?>
                    <?php if (empty($rmGroups[$rmType]))
                        continue; ?>
                    <button type="button" class="rm-filter" onclick="rmSetFilter('<?= $rmType ?>', this)">
                        <?= $rmTitle ?> <?= count($rmGroups[$rmType]) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <span class="text-muted ms-auto" style="font-size:14px;" id="memberCount">
                <?= count($members) ?> participant(s)
            </span>
            <button class="btn btn-sm text-white fw-semibold" style="background:#272f54;border-radius:8px;font-size:14px;"
                onclick="openModal()">
                <i class="fa-solid fa-user-plus me-1"></i> Add Participant
            </button>
        </div>

        <div id="memberList">
            <?php if (empty($members)): ?>
                <div class="rm-card rm-empty">
                    <div class="rm-empty-ic"><i class="fa-solid fa-users"></i></div>
                    <strong>No members yet</strong>
                    <p>Add participants to start building this room.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($rmGroupMeta as $rmType => [$rmTitle, $rmIcon]): ?>
                <?php if (empty($rmGroups[$rmType]))
                    continue; ?>
                <div class="rm-group">
                    <div class="rm-group-title">
                        <i class="fa-solid <?= $rmIcon ?>"></i> <?= $rmTitle ?>
                        <span class="rm-cnt"><?= count($rmGroups[$rmType]) ?></span>
                    </div>
                    <div class="rm-mgrid">
                        <?php foreach ($rmGroups[$rmType] as $rmMember):
                            $rmName = (string) ($rmMember['full_name'] ?? 'Unknown');
                            $rmId = $rmMember['user_id'];

                            if ($rmType === 'student') {
                                $rmBadge = ['Student', 'rm-pill-student'];
                                $rmSub = implode(' · ', array_filter([
                                    $rmStudentInfo[$rmId]['student_no'] ?? '',
                                    $rmStudentInfo[$rmId]['program'] ?? '',
                                ]));
                            } elseif ($rmType === 'adviser') {
                                $rmIsHte = stripos($rmAdvInfo[$rmId]['role'] ?? '', 'hte') !== false;
                                $rmBadge = $rmIsHte ? ['HTE Adviser', 'rm-pill-hte'] : ['OJT Adviser', 'rm-pill-adviser'];
                                $rmSub = (string) ($rmAdvInfo[$rmId]['title'] ?? '');
                            } else {
                                $rmBadge = ['Admin', 'rm-pill-admin'];
                                $rmSub = '';
                            }
                            ?>
                            <div class="rm-mcard" data-type="<?= $rmType ?>"
                                data-name="<?= htmlspecialchars(strtolower($rmName . ' ' . $rmSub), ENT_QUOTES) ?>">
                                <div class="rm-avatar" style="background:<?= $rmColor($rmName) ?>;"><?= $rmInitials($rmName) ?></div>
                                <div class="rm-minfo">
                                    <strong><?= htmlspecialchars($rmName) ?></strong>
                                    <?php if ($rmSub !== ''): ?><small><?= htmlspecialchars($rmSub) ?></small><?php endif; ?>
                                </div>
                                <span class="rm-pill <?= $rmBadge[1] ?>"><?= $rmBadge[0] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div id="rmNoMatch" class="rm-card rm-empty" style="display:none;">
                <strong>No members match your search</strong>
                <p>Try a different name or filter.</p>
            </div>
        </div>

        <script>
            let rmRoleFilter = 'all';
            function rmApplyMembers() {
                const q = document.getElementById('rmMemberSearch').value.toLowerCase().trim();
                let shown = 0;
                document.querySelectorAll('.rm-mcard').forEach(card => {
                    const ok = (rmRoleFilter === 'all' || card.dataset.type === rmRoleFilter) && card.dataset.name.includes(q);
                    card.style.display = ok ? '' : 'none';
                    if (ok) shown++;
                });
                document.querySelectorAll('.rm-group').forEach(g => {
                    g.style.display = [...g.querySelectorAll('.rm-mcard')].some(c => c.style.display !== 'none') ? '' : 'none';
                });
                const none = document.getElementById('rmNoMatch');
                if (none) none.style.display = (shown === 0 && document.querySelector('.rm-mcard')) ? 'block' : 'none';
            }
            function rmSetFilter(type, btn) {
                rmRoleFilter = type;
                document.querySelectorAll('.rm-filter').forEach(b => b.classList.toggle('on', b === btn));
                rmApplyMembers();
            }
        </script>

    <?php else: ?>

        <div class="rm-grid">
            <!-- LEFT: composer + live announcements -->
            <div class="rm-col">

                <?php if ($rmCanPost): ?>
                    <div class="rm-card rm-composer">
                        <form action="chat-room-content-db.php" method="POST">
                            <!-- tells the backend WHICH room this post belongs to -->
                            <input type="hidden" name="room_id" value="<?= $room_id ?>">
                            <!-- triggers the post_announcement block in chat-room-content-db.php -->
                            <input type="hidden" name="tab" value="updates">
                            <input type="hidden" name="post_announcement" value="1">

                            <div class="rm-composer-row">
                                <div class="rm-avatar" style="background:#272f54;"><?= $rmInitials($rmMeName) ?></div>
                                <textarea name="content" rows="3" placeholder="Share an update with your room…" required></textarea>
                            </div>
                            <div class="rm-composer-foot">
                                <span class="rm-hint"><i class="fa-solid fa-eye me-1"></i>Visible to everyone in this room</span>
                                <button type="submit" class="rm-post-btn">
                                    <i class="fa-solid fa-bullhorn me-1"></i> Post Announcement
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (empty($posts)): ?>
                    <div class="rm-card rm-empty">
                        <div class="rm-empty-ic"><i class="fa-solid fa-bullhorn"></i></div>
                        <strong>No announcements yet</strong>
                        <p><?= $rmCanPost
                            ? 'Post your first update to keep everyone in this room informed.'
                            : 'When your adviser posts an update, it will show up here.' ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($posts as $post):
                        $rmSender = (string) ($post['sender_name'] ?? 'Unknown');
                        [$rmRoleText, $rmRoleClass] = $rmPostRole(strtolower((string) ($post['sender_role'] ?? '')));
                        ?>
                        <div class="rm-card">
                            <div class="rm-post-head">
                                <div class="rm-avatar" style="background:<?= $rmColor($rmSender) ?>;"><?= $rmInitials($rmSender) ?></div>
                                <div class="rm-post-who">
                                    <div>
                                        <strong><?= htmlspecialchars($rmSender) ?></strong>
                                        <span class="rm-pill <?= $rmRoleClass ?>"><?= htmlspecialchars($rmRoleText) ?></span>
                                    </div>
                                    <small><?= date("M d, Y · g:i A", strtotime($post['created_at'])) ?></small>
                                </div>
                            </div>
                            <p class="rm-post-body"><?= htmlspecialchars($post['content']) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- RIGHT: room details + members preview -->
            <div class="rm-col">
                <div class="rm-card">
                    <h6 class="rm-side-title">Room details</h6>
                    <div class="rm-dl">
                        <?php if ($rmProgram !== ''): ?>
                            <div class="rm-dr"><span>Program</span><span><?= htmlspecialchars($rmProgram) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($room['year_level'])): ?>
                            <div class="rm-dr"><span>Year level</span><span><?= (int) $room['year_level'] ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($room['section'])): ?>
                            <div class="rm-dr"><span>Section</span><span><?= htmlspecialchars($room['section']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($room['department'])): ?>
                            <div class="rm-dr"><span>Department</span><span><?= htmlspecialchars(ucwords($room['department'])) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($room['school_year'])): ?>
                            <div class="rm-dr"><span>School year</span><span><?= htmlspecialchars($room['school_year']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($room['full_name'])): ?>
                            <div class="rm-dr"><span>Adviser</span><span><?= htmlspecialchars($room['full_name']) ?></span></div>
                        <?php endif; ?>
                        <div class="rm-dr"><span>Members</span><span><?= $rmMemberCount ?></span></div>
                    </div>

                    <?php if (!empty($rmGroups['admin'])): ?>
                        <div class="rm-side-sub">
                            <i class="fa-solid fa-shield-halved" style="color:#ff6b2c;"></i> Admins
                            <span class="rm-cnt"><?= count($rmGroups['admin']) ?></span>
                        </div>
                        <?php foreach ($rmGroups['admin'] as $rmAdmin):
                            $rmAdminName = (string) ($rmAdmin['full_name'] ?? 'Unknown'); ?>
                            <div class="rm-admin-row">
                                <div class="rm-avatar" style="background:<?= $rmColor($rmAdminName) ?>;"><?= $rmInitials($rmAdminName) ?></div>
                                <span><?= htmlspecialchars($rmAdminName) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="rm-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="rm-side-title mb-0">Members</h6>
                        <a href="?room_id=<?= $room_id ?>&tab=members" class="rm-link">View all</a>
                    </div>
                    <?php if (empty($members)): ?>
                        <div class="text-muted" style="font-size:13px;">No members yet.</div>
                    <?php else: ?>
                        <?php $rmMore = max(0, $rmMemberCount - 5); ?>
                        <div class="rm-stack">
                            <?php foreach (array_slice($members, 0, 5) as $rmMember):
                                $rmName = (string) ($rmMember['full_name'] ?? 'Unknown'); ?>
                                <div class="rm-avatar" title="<?= htmlspecialchars($rmName) ?>"
                                    style="background:<?= $rmColor($rmName) ?>;"><?= $rmInitials($rmName) ?></div>
                            <?php endforeach; ?>
                            <?php if ($rmMore > 0): ?>
                                <div class="rm-avatar rm-more">+<?= $rmMore ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- MODAL FOR ADD PARTICIPANT -->
<div class="modal-overlay" id="addParticipantModal" onclick="if(event.target===this) closeModal()">
    <div class="modal-box">

        <div class="modal-header">
            <h6><i class="fa-solid fa-user-plus me-2"></i>Add Participant</h6>
            <button class="modal-close" name="add_participant" onclick="closeModal()">&times;</button>
        </div>

        <div class="modal-body">
            <div class="search-input-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="participantSearch" placeholder="Search by name or email…"
                    oninput="filterParticipants(this.value)">
            </div>

            <div class="participant-list" id="participantList">
                <?php foreach ($users as $user): ?>
                    <?php $key = $user['role'] . '_' . $user['id']; ?>
                    <?php if (isset($alreadyExistingMap[$key]))
                        continue; ?>
                    <div class="participant-item" data-id="<?= htmlspecialchars($user['id']) ?>"
                        data-type="<?= $user['role'] ?>" data-name="<?= htmlspecialchars($user['full_name']) ?>"
                        onclick="toggleCheck(this)">
                        <input type="checkbox">
                        <div class="participant-avatar"><?= strtoupper(substr($user['full_name'], 0, 2)) ?></div>
                        <div class="participant-info">
                            <strong>
                                <?= htmlspecialchars($user['full_name']) ?>
                            </strong>
                            <small>
                                <?= htmlspecialchars($user['email']) ?> ·
                                <?= htmlspecialchars($user['role']) ?>
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
                <!--
                <div class="participant-item" data-name="Ben Torres" onclick="toggleCheck(this)">
                    <input type="checkbox">
                    <div class="participant-avatar">BT</div>
                    <div class="participant-info">
                        <strong>Ben Torres</strong>
                        <small>torres.ben28@student.edu · Student</small>
                    </div>
                </div>
                <div class="participant-item" data-name="Carla Mendoza" onclick="toggleCheck(this)">
                    <input type="checkbox">
                    <div class="participant-avatar">CM</div>
                    <div class="participant-info">
                        <strong>Carla Mendoza</strong>
                        <small>carlamendoza05@student.edu · Student</small>
                    </div>
                </div>
                <div class="participant-item" data-name="Diego Flores" onclick="toggleCheck(this)">
                    <input type="checkbox">
                    <div class="participant-avatar">DF</div>
                    <div class="participant-info">
                        <strong>Diego Flores</strong>
                        <small>diego.flores@student.edu · Student</small>
                    </div>
                </div>
                <div class="participant-item" data-name="Engr. Linda Cruz" onclick="toggleCheck(this)">
                    <input type="checkbox">
                    <div class="participant-avatar" style="background:#d0deff;color:#2756c2;">LC</div>
                    <div class="participant-info">
                        <strong>Engr. Linda Cruz</strong>
                        <small>l.cruz@techcorp.com · HTE Adviser</small>
                    </div>
                </div>
                <div class="participant-item" data-name="Mr. Ryan Go" onclick="toggleCheck(this)">
                    <input type="checkbox">
                    <div class="participant-avatar" style="background:#d0deff;color:#2756c2;">RG</div>
                    <div class="participant-info">
                        <strong>Mr. Ryan Go</strong>
                        <small>r.go@innovate.ph · HTE Adviser</small>
                    </div>
                </div>
                <div class="participant-item" data-name="Ms. Patricia Tan" onclick="toggleCheck(this)">
                    <input type="checkbox">
                    <div class="participant-avatar" style="background:#d0deff;color:#2756c2;">PT</div>
                    <div class="participant-info">
                        <strong>Ms. Patricia Tan</strong>
                        <small>p.tan@globalfirm.com · HTE Adviser</small>
                    </div>
                </div>
                -->
            </div>
        </div>

        <div class="modal-footer">
            <button class="btn btn-sm btn-light" onclick="closeModal()">Cancel</button>
            <button class="btn-add-confirm" id="addSelectedBtn" onclick="addSelected()">
                Add Selected
            </button>
        </div>

    </div>
</div>


<script>
    function openModal() {
        document.getElementById('participantSearch').value = '';
        filterParticipants('');
        document.getElementById('addParticipantModal').classList.add('show');
    }

    function closeModal() {
        document.querySelectorAll('.participant-item input[type="checkbox"]').forEach(cb => cb.checked = false);
        updateAddBtn();
        document.getElementById('addParticipantModal').classList.remove('show');
    }

    function toggleCheck(item) {
        const cb = item.querySelector('input[type="checkbox"]');
        cb.checked = !cb.checked;
        updateAddBtn();
    }

    function updateAddBtn() {
        const anyChecked = [...document.querySelectorAll('.participant-item input[type="checkbox"]')].some(cb => cb.checked);
        document.getElementById('addSelectedBtn').classList.toggle('has-selection', anyChecked);
    }

    function filterParticipants(q) {
        q = q.toLowerCase();
        document.querySelectorAll('.participant-item').forEach(el => {
            el.style.display = el.dataset.name.toLowerCase().includes(q) ? 'flex' : 'none';
        });
    }

    function addSelected() {
        const btn = document.getElementById('addSelectedBtn');
        if (!btn.classList.contains('has-selection')) return;

        const selected = [];

        document.querySelectorAll('.participant-item').forEach(item => {
            const cb = item.querySelector('input[type="checkbox"]');
            if (cb.checked) {
                selected.push({
                    id: item.dataset.id,
                    type: item.dataset.type
                });
            }
        });

        if (selected.length === 0) return;

        fetch('chat-room-content-db.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                room_id: <?= $room_id ?>,
                users: JSON.stringify(selected)
            })
        })
            .then(res => res.text())
            .then(res => {
                if (res === "success") {
                    location.reload(); // refresh members list
                } else {
                    alert("Failed to add members");
                }
            });
    }


    /* ── CHAT ── */
    function autoResize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 110) + 'px';
    }

    function toggleSend() {
        const ta = document.getElementById('chatTextarea');
        const strip = document.getElementById('filePreviewStrip');
        document.getElementById('sendBtn').disabled =
            ta.value.trim() === '' && strip.children.length === 0;
    }

    function handleEnter(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    }

    const attachedFiles = [];

    function handleFiles(files) {
        Array.from(files).forEach(f => { attachedFiles.push(f); renderFileChip(f); });
        document.getElementById('filePreviewStrip').style.display = 'flex';
        toggleSend();
        document.getElementById('fileInput').value = '';
    }

    function renderFileChip(file) {
        const strip = document.getElementById('filePreviewStrip');
        const chip = document.createElement('div');
        chip.className = 'file-chip';
        chip.dataset.name = file.name;
        chip.innerHTML = `
            <i class="fa-solid fa-paperclip"></i>
            <span style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(file.name)}</span>
            <button onclick="removeChip(this)" title="Remove">&times;</button>
        `;
        strip.appendChild(chip);
    }

    function removeChip(btn) {
        const chip = btn.closest('.file-chip');
        const idx = attachedFiles.findIndex(f => f.name === chip.dataset.name);
        if (idx > -1) attachedFiles.splice(idx, 1);
        chip.remove();
        const strip = document.getElementById('filePreviewStrip');
        if (!strip.children.length) strip.style.display = 'none';
        toggleSend();
    }

    function sendMessage() {
        const ta = document.getElementById('chatTextarea');
        const text = ta.value.trim();
        if (!text && attachedFiles.length === 0) return;

        const msgs = document.getElementById('chatMessages');
        const now = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        if (text) {
            msgs.innerHTML += `
                <div class="msg-row me">
                    <div class="msg-avatar" style="background:#272f54;color:#fff;">Me</div>
                    <div class="msg-bubble-wrap">
                        <div class="msg-sender">You</div>
                        <div class="msg-bubble">${escHtml(text)}</div>
                        <div class="msg-time">${now}</div>
                    </div>
                </div>`;
        }

        attachedFiles.forEach(f => {
            msgs.innerHTML += `
                <div class="msg-row me">
                    <div class="msg-avatar" style="background:#272f54;color:#fff;">Me</div>
                    <div class="msg-bubble-wrap">
                        <div class="msg-sender">You</div>
                        <div class="msg-file">
                            <i class="${fileIcon(f.name)}"></i>
                            <div class="msg-file-info">
                                <strong>${escHtml(f.name)}</strong>
                                <span>${(f.size / 1024).toFixed(0)} KB</span>
                            </div>
                        </div>
                        <div class="msg-time">${now}</div>
                    </div>
                </div>`;
        });

        ta.value = '';
        ta.style.height = 'auto';
        attachedFiles.length = 0;
        const strip = document.getElementById('filePreviewStrip');
        strip.innerHTML = '';
        strip.style.display = 'none';
        document.getElementById('sendBtn').disabled = true;
        msgs.scrollTop = msgs.scrollHeight;
    }

    function escHtml(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function fileIcon(name) {
        const ext = name.split('.').pop().toLowerCase();
        const map = {
            pdf: 'fa-solid fa-file-pdf',
            doc: 'fa-solid fa-file-word', docx: 'fa-solid fa-file-word',
            xls: 'fa-solid fa-file-excel', xlsx: 'fa-solid fa-file-excel',
            png: 'fa-solid fa-file-image', jpg: 'fa-solid fa-file-image',
            jpeg: 'fa-solid fa-file-image', gif: 'fa-solid fa-file-image',
        };
        return map[ext] || 'fa-solid fa-file';
    }
</script>