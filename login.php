<?php
// api/register.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once 'config.php';
require_once 'helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$data = json_decode(file_get_contents('php://input'), true);

// ── Validate ──
$name  = trim($data['full_name']  ?? '');
$email = trim($data['email']      ?? '');
$phone = trim($data['phone']      ?? '');
$pass  = $data['password']        ?? '';
$token = trim($data['deriv_token'] ?? '');
$plan  = $data['plan']            ?? 'operative';

if (!$name)                        json_error('Full name is required.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Valid email required.');
if (strlen($pass) < 8)             json_error('Password must be at least 8 characters.');
if (!$token)                       json_error('Deriv API token is required.');
if (!in_array($plan, ['recruit','operative','apex'])) $plan = 'operative';

try {
    $pdo = get_db();

    // Check duplicate email
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) json_error('An account with this email already exists.', 409);

    // Hash password & encrypt token
    $hash       = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $encToken   = encrypt_token($token, ENCRYPTION_KEY);
    $verifyTok  = bin2hex(random_bytes(32));
    $trialEnd   = date('Y-m-d H:i:s', strtotime('+7 days'));

    $pdo->beginTransaction();

    // Insert user
    $stmt = $pdo->prepare('
        INSERT INTO users (full_name, email, phone, password_hash, plan, trial_ends_at, email_verify_token)
        VALUES (?, ?, ?, ?, \'trial\', ?, ?)
    ');
    $stmt->execute([$name, $email, $phone ?: null, $hash, $trialEnd, $verifyTok]);
    $userId = $pdo->lastInsertId();

    // Insert Deriv connection
    $stmt = $pdo->prepare('
        INSERT INTO deriv_connections (user_id, api_token_enc, account_type)
        VALUES (?, ?, \'demo\')
    ');
    $stmt->execute([$userId, $encToken]);

    // Create trial subscription
    $planStmt = $pdo->prepare('SELECT id FROM plans WHERE slug = ?');
    $planStmt->execute([$plan]);
    $planRow = $planStmt->fetch(PDO::FETCH_ASSOC);
    if ($planRow) {
        $pdo->prepare('
            INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end)
            VALUES (?, ?, \'trialing\', NOW(), ?)
        ')->execute([$userId, $planRow['id'], $trialEnd]);
    }

    // Audit
    $pdo->prepare('INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, \'register\', ?)')
        ->execute([$userId, $_SERVER['REMOTE_ADDR'] ?? null]);

    $pdo->commit();

    // Send verification email (non-blocking)
    send_verification_email($email, $name, $verifyTok);

    json_success([
        'user_id' => $userId,
        'message' => 'Account created. Check your email to verify.',
        'trial_ends_at' => $trialEnd,
    ], 201);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    log_error('register', $e->getMessage());
    json_error('Server error. Please try again.', 500);
}
