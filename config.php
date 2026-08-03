<?php

date_default_timezone_set('UTC');

// =========================
// CORS – allow old production frontend
// =========================
$allowedOrigins = [
    'https://conference-socket.onrender.com',   // production frontend
    'http://localhost:5173',         // local dev
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// IMPORTANT: handle preflight first
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json");

// =========================
// DATABASE CONFIG
// =========================
$host = getenv('DB_HOST') ?: 'dpg-d9o5d3u417fc73eiuod0-a.oregon-postgres.render.com';
$port = getenv('DB_PORT') ?: '5432';
$db = getenv('DB_NAME') ?: 'websitedb_uo8p';
$user = getenv('DB_USER') ?: 'website_user';
$pass = getenv('DB_PASS') ?: 'XcNr3mG9DdAu7dvULa7zUoJEZcG5adyA';

// =========================
// DSN (PostgreSQL)
// =========================
// Note: sslmode=require is necessary for external Render DB connections
$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";

// =========================
// PDO OPTIONS
// =========================
$options = [

    // Throw exceptions
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

    // Fetch associative arrays
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

    // Reuse DB connections
    PDO::ATTR_PERSISTENT => true,

    // Timeout protection
    PDO::ATTR_TIMEOUT => 5,
];

try {

    $pdo = new PDO($dsn, $user, $pass, $options);

    // Force UTC timezone
    $pdo->exec("SET timezone = 'UTC'");

} catch (PDOException $e) {

    ob_clean();

    // Never expose raw DB errors in production
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);

    exit();
}