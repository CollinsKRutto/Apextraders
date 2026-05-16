<?php
// api/mpesa-callback.php
// Registered as CallBackURL with Safaricom Daraja
header('Content-Type: application/json');
require_once 'config.php';
require_once 'helpers.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
log_raw('mpesa_callback', $raw); // save to logs/mpesa.log

$body = $data['Body']['stkCallback'] ?? null;
if (!$body) { echo '{}'; exit; }

$resultCode = $body['ResultCode'] ?? -1;
$checkoutId = $body['CheckoutRequestID'] ?? '';

$pdo = get_db();

// Find pending payment by checkout_request_id in metadata
$stmt = $pdo->prepare("SELECT * FROM payments WHERE method = 'mpesa' AND status = 'pending' AND JSON_EXTRACT(metadata, '$.checkout_request_id') = ?");
$stmt->execute([$checkoutId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$payment) { echo '{}'; exit; }

if ($resultCode === 0) {
    // ── Success ──
    $items = $body['CallbackMetadata']['Item'] ?? [];
    $meta  = [];
    foreach ($items as $item) $meta[$item['Name']] = $item['Value'] ?? null;

    $txId    = $meta['MpesaReceiptNumber'] ?? null;
    $amount  = $meta['Amount'] ?? $payment['amount'];
    $planMeta = json_decode($payment['metadata'], true);
    $planSlug = $planMeta['plan'] ?? 'operative';

    // Mark payment succeeded
    $pdo->prepare('UPDATE payments SET status = \'succeeded\', mpesa_transaction_id = ?, paid_at = NOW() WHERE id = ?')
        ->execute([$txId, $payment['id']]);

    // Activate subscription
    $planStmt = $pdo->prepare('SELECT * FROM plans WHERE slug = ?');
    $planStmt->execute([$planSlug]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    $expiry = date('Y-m-d H:i:s', strtotime('+1 month'));

    if ($plan) {
        // Upsert subscription
        $pdo->prepare('
            INSERT INTO subscriptions (user_id, plan_id, status, billing_cycle, current_period_start, current_period_end)
            VALUES (?, ?, \'active\', \'monthly\', NOW(), ?)
            ON DUPLICATE KEY UPDATE status = \'active\', current_period_end = ?
        ')->execute([$payment['user_id'], $plan['id'], $expiry, $expiry]);
    }

    $pdo->prepare('UPDATE users SET plan = ?, plan_expires_at = ? WHERE id = ?')
        ->execute([$planSlug, $expiry, $payment['user_id']]);

    // Notify user
    $pdo->prepare('
        INSERT INTO notifications (user_id, type, title, body, action_url)
        VALUES (?, \'payment\', \'Payment Confirmed\', \'Your M-Pesa payment was received. Your plan is now active!\', \'/pages/dashboard.html\')
    ')->execute([$payment['user_id']]);

} else {
    // ── Failed / Cancelled ──
    $pdo->prepare('UPDATE payments SET status = \'failed\' WHERE id = ?')
        ->execute([$payment['id']]);

    $reason = $body['ResultDesc'] ?? 'Payment was not completed.';
    $pdo->prepare('
        INSERT INTO notifications (user_id, type, title, body)
        VALUES (?, \'payment\', \'M-Pesa Payment Failed\', ?)
    ')->execute([$payment['user_id'], $reason]);
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
