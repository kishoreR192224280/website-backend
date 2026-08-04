<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Destroy session to log out
session_destroy();
echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
