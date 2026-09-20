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
            color: #dc3545;
        }

        .active-tab {
            border-bottom: 2px solid #dc3545;
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
            border-color: #d63ba5;
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
            background: #fdf0f9;
        }

        .participant-item input[type="checkbox"] {
            accent-color: #d63ba5;
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .participant-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #e0c4d8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #d63ba5;
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
            background: #f0c6e8;
            color: #c278aa;
        }

        .btn-add-confirm.has-selection {
            background: #d63ba5;
            color: #fff;
            cursor: pointer;
        }

        .btn-add-confirm.has-selection:hover {
            background: #bc2e8e;
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
            background: #e0c4d8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #d63ba5;
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
            color: #c2278e;
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
            background: #e0c4d8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #d63ba5;
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
            border: 1px solid #ecdaea;
            border-bottom-left-radius: 4px;
            color: #222;
        }

        .msg-row.me .msg-bubble {
            background: #d63ba5;
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
            border: 1px solid #ecdaea;
            background: #fff;
            color: #222;
            font-size: 12px;
            border-bottom-left-radius: 4px;
        }

        .msg-row.me .msg-file {
            background: #bc2e8e;
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
            background: #fce8f5;
            border: 1px solid #f0c6e8;
            border-radius: 20px;
            padding: 4px 10px 4px 8px;
            font-size: 12px;
            color: #b5237f;
        }

        .file-chip button {
            background: none;
            border: none;
            color: #b5237f;
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
            border-color: #d63ba5;
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
            background: #f5e6f2;
            color: #d63ba5;
        }

        .chat-attach-btn:hover {
            background: #ecd3e8;
        }

        .chat-send-btn {
            background: #d63ba5;
            color: #fff;
        }

        .chat-send-btn:hover {
            background: #bc2e8e;
        }

        .chat-send-btn:disabled {
            background: #e5b8d8;
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
            background: #d63ba5;
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
    </style>
</head>
<?php if (isset($_SESSION['role']) === 'student'): ?>
    <div class="d-flex justify-content-end mb-2">
        <a href="<?= $backLink ?>" class="text-danger fw-semibold" style="text-decoration:none;">
            <i class="fa-solid fa-arrow-left"></i> Back to rooms
        </a>
    </div>
<?php endif; ?>
<!-- HEADER -->
<div class="p-3 text-white rounded d-flex justify-content-between align-items-center" style="background:#d63ba5;">

    <!-- LEFT SIDE -->
    <div>
        <h5 class="mb-0"><?= htmlspecialchars($room['room_name']) ?></h5>
        <small>
            <?php if (!empty($room['department'])): ?>
                <?= htmlspecialchars(ucwords($room['department'])) ?>
            <?php elseif (!empty($room['section'])): ?>
                Year <?= (int) $room['year_level'] ?> - Section <?= htmlspecialchars($room['section']) ?>
                <?php if (!empty($room['school_year'])): ?>
                    | S.Y. <?= htmlspecialchars($room['school_year']) ?>
                <?php endif; ?>
                <?php if (!empty($room['full_name'])): ?>
                    | <?= htmlspecialchars($room['full_name']) ?>
                <?php endif; ?>
            <?php else: ?>
                <?= htmlspecialchars($room['full_name'] ?? '') ?>
                |
                <?= htmlspecialchars($room['role'] ?? '') ?>
            <?php endif; ?>

        </small>
    </div>

    <!-- RIGHT SIDE -->
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
    <?php endif; ?>
</div>

<!-- TABS -->
<div class="mt-3 border-bottom pb-2">
    <a href="?room_id=<?= $room_id ?>&tab=updates"
        class="me-3 fw-bold tab-link <?= $tab === 'updates' ? 'text-danger active-tab' : 'text-dark' ?>">
        Updates
    </a>
    <a href="?room_id=<?= $room_id ?>&tab=members"
        class="me-3 fw-bold tab-link <?= $tab === 'members' ? 'text-danger active-tab' : 'text-dark' ?>">
        Members
    </a>
    <!-- Chats tab removed -->
</div>

<!-- CONTENT -->
<div class="mt-3">

    <?php if ($tab === 'members'): ?>

        <div class="d-flex align-items-center justify-content-between mb-3">
            <span class="text-muted" style="font-size:14px;" id="memberCount">
                <?= count($members) ?> participant(s)
            </span>
            <button class="btn btn-sm text-white fw-semibold" style="background:#d63ba5;border-radius:8px;font-size:14px;"
                onclick="openModal()">
                <i class="fa-solid fa-user-plus me-1"></i> Add Participant
            </button>
        </div>

        <div id="memberList">
            <?php foreach ($members as $m): ?>
                <div class="member-card">
                    <div class="member-avatar">
                        <?= strtoupper(substr($m['full_name'], 0, 2)) ?>
                    </div>
                    <div class="member-info">
                        <strong><?= htmlspecialchars($m['full_name']) ?></strong>
                    </div>
                    <span class="badge-role badge-student"><?php if ($m['user_type'] === 'adviser') {
                        echo 'Adviser';
                    } elseif ($m['user_type'] === 'admin') {
                        echo 'Admin';
                    } else {
                        echo 'Student';

                    } ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- <?php elseif ($tab === 'chats'): ?>

        <div class="chat-container">

            <div class="chat-messages" id="chatMessages">
                <div class="chat-day-divider">Today, Apr 26</div>

                <div class="msg-row">
                    <div class="msg-avatar">OA</div>
                    <div class="msg-bubble-wrap">
                        <div class="msg-sender">OJT Adviser Name</div>
                        <div class="msg-bubble">Good morning everyone! Please check the announcements tab for the
                            orientation details.</div>
                        <div class="msg-time">9:02 AM</div>
                    </div>
                </div>

                <div class="msg-row me">
                    <div class="msg-avatar" style="background:#d63ba5;color:#fff;">Me</div>
                    <div class="msg-bubble-wrap">
                        <div class="msg-sender">You</div>
                        <div class="msg-bubble">Good morning! Got it, thank you!</div>
                        <div class="msg-time">9:05 AM</div>
                    </div>
                </div>

                <div class="msg-row">
                    <div class="msg-avatar">OA</div>
                    <div class="msg-bubble-wrap">
                        <div class="msg-sender">OJT Adviser Name</div>
                        <div class="msg-bubble">Make sure to also submit your endorsement letter by Friday.</div>
                        <div class="msg-time">9:18 AM</div>
                    </div>
                </div>
            </div>

            <div class="chat-input-bar">
                <div class="file-preview-strip" id="filePreviewStrip" style="display:none;"></div>
                <div class="chat-input-row">
                    <input type="file" id="fileInput" multiple style="display:none;" onchange="handleFiles(this.files)">
                    <button class="chat-attach-btn" title="Attach file"
                        onclick="document.getElementById('fileInput').click()">
                        <i class="fa-solid fa-paperclip"></i>
                    </button>
                    <textarea id="chatTextarea" rows="1" placeholder="Type a message…"
                        oninput="autoResize(this); toggleSend()" onkeydown="handleEnter(event)"></textarea>
                    <button class="chat-send-btn" id="sendBtn" disabled onclick="sendMessage()">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </div>
            </div>

        </div> -->


    <?php else: ?>

        <?php if (
            $_SESSION['role'] === 'internship_adviser' || $_SESSION['role'] === 'hte_adviser'
            || $_SESSION['role'] === 'superadmin' || $_SESSION['role'] === 'internship_admin'
        ): ?>
            <div class="card mb-3 border-0 shadow-sm">
                <div class="card-body">
                    <form action="chat-room-content-db.php" method="POST">
                        <!-- tells the backend WHICH room this post belongs to -->
                        <input type="hidden" name="room_id" value="<?= $room_id ?>">
                        <!-- triggers the post_announcement block in chat-room-content-db.php -->
                        <input type="hidden" name="tab" value="updates">
                        <input type="hidden" name="post_announcement" value="1">

                        <textarea name="content" class="form-control mb-2" rows="3"
                            placeholder="Write an announcement for this room…" required
                            style="resize:none; border-color:#f0c6e8; font-size:14px;"></textarea>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-sm fw-semibold text-white"
                                style="background:#d63ba5; border-radius:8px; font-size:14px;">
                                <i class="fa-solid fa-bullhorn me-1"></i> Post Announcement
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($posts as $post): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <div style="width:35px;height:35px;border-radius:50%;background:#ccc;margin-right:10px;"></div>
                        <div>
                            <strong><?= htmlspecialchars($post['sender_name']) ?></strong><br>
                            <small class="text-muted">
                                <?php if ($post['sender_role'] === 'superadmin') {
                                    echo htmlspecialchars('System Admin');
                                } else {
                                    echo htmlspecialchars($post['sender_role']);
                                } ?> •
                                <?= date("M d, Y", strtotime($post['created_at'])) ?>
                            </small>
                        </div>
                    </div>
                    <p class="mb-0"><?= htmlspecialchars($post['content']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>

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
                    <div class="msg-avatar" style="background:#d63ba5;color:#fff;">Me</div>
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
                    <div class="msg-avatar" style="background:#d63ba5;color:#fff;">Me</div>
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