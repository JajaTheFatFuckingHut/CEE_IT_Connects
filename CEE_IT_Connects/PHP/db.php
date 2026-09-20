<?php

$databaseUrl = getenv('DATABASE_URL');

if (!$databaseUrl) {
    die("DATABASE_URL is not configured.");
}

try {
    $db = parse_url($databaseUrl);

    if (!$db || !isset($db['host'], $db['user'], $db['pass'], $db['path'])) {
        throw new Exception("Invalid DATABASE_URL format.");
    }

    $host = $db['host'];
    $port = $db['port'] ?? 5432;
    $dbname = ltrim($db['path'], '/');
    $user = $db['user'];
    $password = $db['pass'];

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);

} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>