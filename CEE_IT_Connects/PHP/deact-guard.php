<?php
// deact-guard.php
// Include on every student page, AFTER session_start() and require 'db.php',
// and BEFORE any HTML output.

$deactSecs = null;   // seconds left until deactivation (null = no deadline set)

if (($_SESSION['role'] ?? '') === 'student' && !empty($_SESSION['user_id'])) {

    $chk = $pdo->prepare("
        SELECT is_archived,
               CEIL(EXTRACT(EPOCH FROM (deactivate_at - NOW()))) AS secs
        FROM students
        WHERE id = ?
    ");
    $chk->execute([$_SESSION['user_id']]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);

    // Deadline exists and has already passed
    $expired = $row && $row['secs'] !== null && (int) $row['secs'] <= 0;

    // Account missing, already archived, or deadline passed -> log out
    if (!$row || $row['is_archived'] || $expired) {

        // Deadline passed but not archived yet: archive now and write the audit row
        if ($row && !$row['is_archived']) {
            $a = $pdo->prepare("UPDATE students SET is_archived = :a WHERE id = :id");
            $a->bindValue(':a', true, PDO::PARAM_BOOL);
            $a->bindValue(':id', $_SESSION['user_id'], PDO::PARAM_INT);
            $a->execute();

            $au = $pdo->prepare("
                INSERT INTO audits (user_id, roles, activity, activity_date)
                VALUES (?, 'student', ?, NOW())
            ");
            $au->execute([
                $_SESSION['user_id'],
                'Account auto-deactivated after completing required OJT hours'
            ]);
        }

        session_destroy();
        header('Location: login.php?deactivated=1');
        exit;
    }

    // Still within the countdown: pass the remaining seconds to deact-banner.php
    if ($row['secs'] !== null) {
        $deactSecs = (int) $row['secs'];
    }
}