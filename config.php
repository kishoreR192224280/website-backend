<?php

date_default_timezone_set('UTC');

// =========================
// Load local .env (Apache PHP does not read it automatically)
// Existing process/Apache env vars win, so production stays unchanged.
// =========================
$envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_readable($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES);
    if ($envLines !== false) {
        foreach ($envLines as $envLine) {
            $envLine = trim($envLine);
            if ($envLine === '' || str_starts_with($envLine, '#') || !str_contains($envLine, '=')) {
                continue;
            }

            [$envKey, $envValue] = explode('=', $envLine, 2);
            $envKey = trim($envKey);
            $envValue = trim($envValue);
            if ($envKey === '') {
                continue;
            }

            $existing = getenv($envKey);
            if ($existing !== false && $existing !== '') {
                continue;
            }

            if (
                (strlen($envValue) >= 2) &&
                (
                    (str_starts_with($envValue, '"') && str_ends_with($envValue, '"')) ||
                    (str_starts_with($envValue, "'") && str_ends_with($envValue, "'"))
                )
            ) {
                $envValue = substr($envValue, 1, -1);
            }

            putenv("{$envKey}={$envValue}");
            $_ENV[$envKey] = $envValue;
            $_SERVER[$envKey] = $envValue;
        }
    }
}

// =========================
// SESSION CONFIG (Cross-Origin Auth)
// Secure + SameSite=None is required for HTTPS cross-origin cookies.
// Local HTTP (localhost:5173 → Apache :80) cannot use Secure cookies.
// =========================
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
);
if ($isHttps) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
} else {
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', '0');
}

// =========================
// CORS – allow production and dev frontends
// =========================
$envOrigins = getenv('ALLOWED_ORIGINS');
if ($envOrigins) {
    // e.g. "https://europe-conference.vercel.app,http://localhost:5173"
    $allowedOrigins = array_map('trim', explode(',', $envOrigins));
} else {
    $allowedOrigins = [
        'https://europe-conference.vercel.app',     // production frontend
        'https://europe-conference.onrender.com',   // socket server
        'http://localhost:5173',                    // local dev default
        'http://localhost:8011',                    // local dev (current frontend)
    ];
}

if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    $isAllowed = in_array($origin, $allowedOrigins);

    // Also allow any Vercel preview deployments for this project
    if (!$isAllowed && preg_match('#^https://europe-conference-[a-z0-9]+-[a-z0-9]+\.vercel\.app$#', $origin)) {
        $isAllowed = true;
    }

    if ($isAllowed) {
        header("Access-Control-Allow-Origin: " . $origin);
    }
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
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '5432';
$db = getenv('DB_NAME') ?: 'website-db';
$user = getenv('DB_USER') ?: 'website_user';
$pass = getenv('DB_PASS') ?: 'Ambi**tion21';
$sslmode = getenv('DB_SSLMODE') ?: (in_array($host, ['localhost', '127.0.0.1'], true) ? 'disable' : 'require');

// =========================
// DSN (PostgreSQL)
// =========================
// sslmode=require is needed for Render; local Postgres typically needs disable.
$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=$sslmode";

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