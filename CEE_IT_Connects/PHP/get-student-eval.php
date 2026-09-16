<?php
require 'db.php'; // your $pdo connection

$studentId = $_GET['student_id'] ?? null;
if (!$studentId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing id']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        s.id AS student_id,
        s.full_name AS intern_name,
        s.student_id,
        s.program,
        oa.internship_id,
        i.company AS company_name,
        a.id AS supervisor_id,
        a.full_name AS supervisor_name
    FROM students s
    JOIN ojt_applications oa ON s.id = oa.student_id
    JOIN internships i ON oa.internship_id = i.id
    LEFT JOIN advisers a ON a.internship_id = i.id AND a.role = 'HTE_adviser' AND a.department = s.program
    WHERE s.id = ?
");
$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode($student ?: []);