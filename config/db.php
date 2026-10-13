<?php
// db connection, every page includes this
// change these if your mysql user is different
define('DB_HOST', 'localhost');
define('DB_USER', 'kalasag');
define('DB_PASS', 'kalasag123');
define('DB_NAME', 'kalasag_db');

date_default_timezone_set('Asia/Manila');

// make mysqli throw exceptions so we can catch errors properly
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+08:00'");
} catch (mysqli_sql_exception $ex) {
    // dont show the raw error to users, just log it
    error_log('DB connect failed: ' . $ex->getMessage());
    http_response_code(500);
    exit('Can\'t connect to the database right now. Check if MySQL is running and the login in config/db.php is right.');
}
