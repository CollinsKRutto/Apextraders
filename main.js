<?php
// api/login.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once 'config.php';
require_once 'helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

$data  = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email']    ?? '');
$pass  = $data['password']      ?? '';
$rem   = !empty($data['remember']);

if (!$email || !$pass) json_error('Email and password required.');

try {
    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        sleep(1); // brute-force delay
        json_error('Invalid email or password.', 401);
    }

    // Update last login
    $pdo->prepare('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?')
        ->execute([$_SERVER['REMOTE_ADDR'] ?? null, $user['id']]);

    // Create JWT session token
    $token   = generate_jwt($user['id'], $user['email'], $user['role'], $rem ? 2592000 : 86400);
    $expires = $rem ? time() + 2592000 : time() + 86400;

    // Audit
    $pdo->prepare('INSERT INTO audit_log (user_id, action, ip_address, user_agent) VALUES (?, \'login\', ?, ?)')
        ->execute([$user['id'], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);

    json_success([
        'token'      => $token,
        'expires_at' => date('Y-m-d H:i:s', $expires),
        'user' => [
            'id'       => $user['id'],
            'name'     => $user['full_name'],
            'email'    => $user['email'],
            'plan'     => $user['plan'],
            'role'     => $user['role'],
            'avatar'   => $user['avatar_url'],
        ],
    ]);

} catch (PDOException $e) {
    log_error('login', $e->getMessage());
    json_error('Server error.', 500);
}
