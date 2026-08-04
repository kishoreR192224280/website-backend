<?php
require_once 'config.php';

header('Content-Type: application/json');

// Token-based auth is stateless — the client just discards the token.
echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
