<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

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

$relativePath = '/WEBSITE-backend/uploads/label-images/' . $uniqueName;

http_response_code(201);
echo json_encode([
    'success' => true,
    'message' => 'Image uploaded successfully',
    'url' => 'https://conference-socket.onrender.com' . $relativePath,
    'path' => $relativePath,
]);
