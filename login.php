<?php
require_once 'config.php';
require_once 'auth_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}
$username = trim($data['username'] ?? $_POST['username'] ?? '');
$password = (string) ($data['password'] ?? $_POST['password'] ?? '');

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username and password required']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM admin WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch();

if ($user && hash_equals((string) $user['password'], $password)) {
    $adminId   = (int) $user['id'];
    $adminName = $user['name'] ?? $user['username'];

    $token = generate_auth_token($adminId, (string) $user['username'], (string) $adminName);

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'token'   => $token,
        'user'    => [
            'id'       => $adminId,
            'username' => $user['username'],
            'name'     => $adminName,
        ],
    ]);
    exit;
}

http_response_code(401);
echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
