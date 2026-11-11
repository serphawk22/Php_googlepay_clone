<?php
declare(strict_types=1);

/**
 * JSON API bootstrap for the Capacitor app (The Keypad–style).
 */
define('SERP_API', true);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Serp-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

function api_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_body(): array
{
    static $parsed = null;
    if ($parsed !== null) {
        return $parsed;
    }
    $raw = file_get_contents('php://input');
    $json = json_decode(is_string($raw) ? $raw : '', true);
    $parsed = is_array($json) ? $json : [];
    return $parsed;
}

function api_in(string $key, $default = '')
{
    $body = api_body();
    if (array_key_exists($key, $body)) {
        return $body[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    return $default;
}

function api_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS api_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            token CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_api_token (token),
            KEY idx_api_user (user_id),
            CONSTRAINT fk_api_token_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function api_bearer_token(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
        return trim($m[1]);
    }
    $custom = (string) ($_SERVER['HTTP_X_SERP_TOKEN'] ?? '');
    if ($custom !== '') {
        return trim($custom);
    }
    return trim((string) api_in('token', ''));
}

function api_issue_token(int $userId): string
{
    api_ensure_schema();
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30);
    db()->prepare('INSERT INTO api_tokens (user_id, token, expires_at) VALUES (?, ?, ?)')
        ->execute([$userId, $token, $expires]);
    return $token;
}

function api_revoke_token(string $token): void
{
    if ($token === '') {
        return;
    }
    api_ensure_schema();
    db()->prepare('DELETE FROM api_tokens WHERE token = ?')->execute([$token]);
}

function api_user(): ?array
{
    api_ensure_schema();
    $token = api_bearer_token();
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT u.*
         FROM api_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND t.expires_at > NOW() AND u.status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    db()->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE token = ?')->execute([$token]);
    return $user;
}

function api_require_user(): array
{
    $user = api_user();
    if (!$user) {
        api_out(['success' => false, 'message' => 'Please sign in again.'], 401);
    }
    return $user;
}

function api_public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'full_name' => (string) $user['full_name'],
        'email' => (string) $user['email'],
        'mobile' => (string) $user['mobile'],
        'role' => (string) $user['role'],
        'wallet_balance' => number_format((float) $user['wallet_balance'], 2, '.', ''),
        'wallet_label' => money($user['wallet_balance']),
        'has_pin' => user_has_pin($user),
    ];
}

function api_bill_row(array $bill): array
{
    $due = amount_due($bill);
    return [
        'id' => (int) $bill['id'],
        'biller_name' => (string) ($bill['biller_name'] ?? ''),
        'category' => (string) ($bill['category'] ?? ''),
        'account_number' => (string) $bill['account_number'],
        'customer_name' => (string) $bill['customer_name'],
        'bill_number' => (string) $bill['bill_number'],
        'billing_period' => (string) $bill['billing_period'],
        'bill_date' => (string) $bill['bill_date'],
        'due_date' => (string) $bill['due_date'],
        'due_label' => due_label((string) $bill['due_date']),
        'amount' => number_format((float) $bill['amount'], 2, '.', ''),
        'late_fee' => number_format((float) $bill['late_fee'], 2, '.', ''),
        'amount_due' => number_format($due, 2, '.', ''),
        'amount_due_label' => money($due),
        'status' => (string) $bill['status'],
        'status_label' => status_label((string) $bill['status']),
        'plan_name' => (string) ($bill['plan_name'] ?? ''),
        'details' => (string) ($bill['details'] ?? ''),
        'bbps_request_id' => (string) ($bill['bbps_request_id'] ?? ''),
    ];
}
