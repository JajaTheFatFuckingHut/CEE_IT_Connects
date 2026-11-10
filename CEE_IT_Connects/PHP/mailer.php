<?php
function brevoSend(string $email, string $name, string $subject, string $html): bool
{
    $payload = json_encode([
        'sender' => ['name' => 'CEE IT Connects', 'email' => 'jamesherold25@gmail.com'],
        'to' => [['email' => $email, 'name' => $name]],
        'subject' => $subject,
        'htmlContent' => $html,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'api-key: ' . getenv('BREVO_API_KEY'),
            'Content-Type: application/json',
            'accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("Brevo cURL error: $curlError");
        return false;
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }
    error_log("Brevo API error ($httpCode) for $email: $response");
    return false;
}

function sendVerificationCode(string $email, string $full_name, string $code): bool
{
    return brevoSend($email, $full_name, 'Your CEE IT Connects verification code', "
        <h2>Verify your email</h2>
        <p>Hello <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
        <p>Use this code to finish creating your account:</p>
        <p style='font-size:28px;letter-spacing:6px;font-weight:bold;'>" . htmlspecialchars($code) . "</p>
        <p>This code expires in 10 minutes. If you didn't register, ignore this email.</p>
        <p>Regards,<br><strong>CEE IT Connects</strong></p>
    ");
}

function sendStudentCredentials(string $email, string $full_name, string $student_id): bool
{
    return brevoSend($email, $full_name, 'Your CEE IT Connects Account', "
        <h2>Welcome to CEE IT Connects!</h2>
        <p>Hello <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
        <p>Your email is verified and your student account has been created.</p>
        <h3>Your Login Details</h3>
        <p><strong>Student ID:</strong> " . htmlspecialchars($student_id) . "</p>
        <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
        <p>Sign in with the password you created during registration.</p>
        <p>Regards,<br><strong>CEE IT Connects</strong></p>
    ");
}