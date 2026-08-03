<?php
require_once 'config.php';

session_start();
header('Content-Type: application/json');

// Destroy session to log out
session_unset();
session_destroy();
echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
