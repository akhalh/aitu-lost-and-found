<?php
// Database connection for the Lost & Found website.
// On the real server the site uses the limited account lf_app (see security_setup.sql).
// For the local XAMPP demo we use root.
$DB_HOST = 'localhost';
$DB_NAME = 'lost_and_found';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
        ]
    );
} catch (PDOException $e) {
    // Technical details go only to the log, the user sees a general message
    error_log(date('c') . ' DB error: ' . $e->getMessage() . PHP_EOL, 3, __DIR__ . '/security.log');
    die('Sorry, the service is not available right now.');
}

// Write a line to security.log (date, event, user ID or e-mail hash, IP address)
function security_log($event, $who) {
    $line = date('Y-m-d H:i:s') . " | $event | $who | " . ($_SERVER['REMOTE_ADDR'] ?? '-') . PHP_EOL;
    file_put_contents(__DIR__ . '/security.log', $line, FILE_APPEND);
}
