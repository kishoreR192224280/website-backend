<?php

/**
 * Stateless token-based authentication helper.
 *
 * Token format (base64url): <payload_json>.<hmac_sha256_signature>
 * Payload: { "admin_id": int, "username": string, "exp": unix_timestamp }
 *
 * This replaces PHP session auth, which does not work reliably across
 * cross-origin deployments (Vercel frontend → Render PHP backend).
 */

define('TOKEN_TTL_SECONDS', 60 * 60 * 24 * 7); // 7 days

function get_token_secret(): string
{
    $secret = getenv('TOKEN_SECRET');
    if (!$secret) {
        // Fallback — set TOKEN_SECRET in Render env vars for production!
        $secret = 'quiz-admin-secret-key-change-me-in-production';
    }
    return $secret;
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

/**
 * Generate a signed auth token for an admin user.
 */
function generate_auth_token(int $adminId, string $username, string $name): string
{
    $payload = json_encode([
        'admin_id' => $adminId,
        'username' => $username,
        'name'     => $name,
        'exp'      => time() + TOKEN_TTL_SECONDS,
    ]);

    $encodedPayload = base64url_encode($payload);
    $signature = hash_hmac('sha256', $encodedPayload, get_token_secret(), true);
    $encodedSig = base64url_encode($signature);

    return $encodedPayload . '.' . $encodedSig;
}

/**
 * Verify a token from the Authorization header and return the payload.
 * Returns null if the token is invalid or expired.
 */
function verify_auth_token(string $token): ?array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$encodedPayload, $encodedSig] = $parts;

    // Verify signature
    $expectedSig = base64url_encode(hash_hmac('sha256', $encodedPayload, get_token_secret(), true));
    if (!hash_equals($expectedSig, $encodedSig)) {
        return null;
    }

    // Decode payload
    $payload = json_decode(base64url_decode($encodedPayload), true);
    if (!is_array($payload)) {
        return null;
    }

    // Check expiry
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

/**
 * Read token from the Authorization header (Bearer scheme).
 */
function get_bearer_token(): ?string
{
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        foreach ($requestHeaders as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $header = trim($value);
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Verify the request's Bearer token and return the admin payload.
 * Sends a 401 JSON response and exits if invalid.
 */
function require_admin_auth(): array
{
    $token = get_bearer_token();
    if ($token === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $payload = verify_auth_token($token);
    if ($payload === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    return $payload;
}
