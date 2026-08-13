<?php
// Shared helper function to notify the Socket.IO server of state changes
//
// Configure via defines (set in config.php or a .env loader):
//   SOCKET_SERVER_URL  – full base URL of the socket server (e.g. https://your-app.onrender.com)
//   INTERNAL_SECRET    – shared secret that the socket server checks on /internal/* routes

if (!defined('SOCKET_SERVER_URL')) {
    define('SOCKET_SERVER_URL', getenv('SOCKET_SERVER_URL') ?: 'https://europe-conference.onrender.com');
}
if (!defined('INTERNAL_SECRET')) {
    define('INTERNAL_SECRET', getenv('INTERNAL_SECRET') ?: '');
}

function notify_socket_server(string $path, array $payload): void
{
    $url = rtrim(SOCKET_SERVER_URL, '/') . '/internal/' . ltrim($path, '/');
    $ch = curl_init($url);

    $headers = ['Content-Type: application/json'];
    if (INTERNAL_SECRET !== '') {
        $headers[] = 'Authorization: Bearer ' . INTERNAL_SECRET;
    }

    // Fire-and-forget — timeouts increased for cross-network calls to Render.
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 3000,
        CURLOPT_CONNECTTIMEOUT_MS => 2000,
    ]);

    curl_exec($ch);
    curl_close($ch);
}
