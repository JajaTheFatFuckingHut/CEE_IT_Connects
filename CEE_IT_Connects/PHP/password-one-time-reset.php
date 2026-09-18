<?php
require 'db.php';

// $stmt = $pdo->query("SELECT id, password_hash FROM advisers");
// $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// foreach ($users as $user) {

//     $plainPassword = $user['password_hash'];

//     // Skip only if already hashed
//     if (strpos($plainPassword, '$2y$') === 0) {
//         continue;
//     }

//     $hashed = password_hash($plainPassword, PASSWORD_DEFAULT);

//     $update = $pdo->prepare("UPDATE advisers SET password_hash = ? WHERE id = ?");
//     $update->execute([$hashed, $user['id']]);
// }

// // echo "Passwords updated!";

$id = 1;
$newPassword = "12345";

$hashed = password_hash($newPassword, PASSWORD_DEFAULT);

$update = $pdo->prepare("
    UPDATE students
    SET password_hash = ?
    WHERE id = ?
");

$update->execute([$hashed, $id]);

echo "Passwords updated!";
echo "<p>back<p><a href='login-ui.php'>Back to Home</a>";