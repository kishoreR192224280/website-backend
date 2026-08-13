<?php
require_once 'config.php';
require_once 'auth_helper.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_admin_auth();

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Image file is required']);
    exit;
}

$file = $_FILES['image'];

if (!isset($file['error']) || is_array($file['error'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid upload payload']);
    exit;
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Upload failed']);
    exit;
}

$maxSizeBytes = 8 * 1024 * 1024;
if (($file['size'] ?? 0) > $maxSizeBytes) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Image must be 8 MB or smaller']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

if (!isset($allowedMimeTypes[$mimeType])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, WEBP, and GIF images are allowed']);
    exit;
}

$extension = $allowedMimeTypes[$mimeType];
$projectRoot = __DIR__;
$uploadDir = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'label-images';

if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to create upload directory']);
    exit;
}

$baseName = pathinfo((string) ($file['name'] ?? 'label-image'), PATHINFO_FILENAME);
$baseName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $baseName ?: 'label-image');
$baseName = trim((string) $baseName, '-');
if ($baseName === '') {
    $baseName = 'label-image';
}

$uniqueName = $baseName . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
$destination = $uploadDir . DIRECTORY_SEPARATOR . $uniqueName;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded image']);
    exit;
}

/**
 * Prefer PUBLIC_BASE_URL from env (.env / hosting).
 * Otherwise build from the current request host so local Apache works.
 */
function resolve_public_base_url(): string
{
    $configured = getenv('PUBLIC_BASE_URL');
    if (is_string($configured) && trim($configured) !== '') {
        return rtrim(trim($configured), '/');
    }

    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

$relativePrefix = getenv('PUBLIC_UPLOAD_PREFIX');
if (!is_string($relativePrefix) || trim($relativePrefix) === '') {
    // Local XAMPP serves this project under /WEBSITE-backend.
    // Production (Render Docker) serves from the web root.
    $relativePrefix = '/WEBSITE-backend/uploads';
}
$relativePrefix = '/' . trim(str_replace('\\', '/', $relativePrefix), '/');
$relativePath = $relativePrefix . '/label-images/' . $uniqueName;
$publicUrl = resolve_public_base_url() . $relativePath;

http_response_code(201);
echo json_encode([
    'success' => true,
    'message' => 'Image uploaded successfully',
    'url' => $publicUrl,
    'path' => $relativePath,
]);
