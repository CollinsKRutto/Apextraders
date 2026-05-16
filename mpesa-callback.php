<?php
// api/payment.php  — Handles Stripe card payments, M-Pesa STK, and crypto confirmation
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once 'config.php';
require_once 'helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

$user = require_auth(); // throws 401 if invalid JWT
$data = json_decode(file_get_contents('php://input'), true);
$method = $data['method'] ?? ''; // stripe | mpesa | crypto

try {
    $pdo = get_db();

    // Load plan
    $planSlug = $data['plan'] ?? 'operative';
    $billing  = $data['billing'] ?? 'monthly';
    $stmt = $pdo->prepare('SELECT * FROM plans WHERE slug = ? AND is_active = 1');
    $stmt->execute([$planSlug]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) json_error('Invalid plan.');

    $amount = $billing === 'annual' ? $plan['price_annual'] : $plan['price_monthly'];

    // ── STRIPE ──────────────────────────────────────────────
    if ($method === 'stripe') {
        require_once __DIR__ . '/vendor/autoload.php'; // composer require stripe/stripe-php
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

        $priceId = $billing === 'annual'
            ? $plan['stripe_price_id_annual']
            : $plan['stripe_price_id_monthly'];

        // Get or create Stripe customer
        $stmt = $pdo->prepare('SELECT stripe_customer_id FROM subscriptions WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && $existing['stripe_customer_id']) {
            $customerId = $existing['stripe_customer_id'];
        } else {
            $customer   = \Stripe\Customer::create([
                'email' => $user['email'],
                'name'  => $user['name'],
                'metadata' => ['apex_user_id' => $user['id']],
            ]);
            $customerId = $customer->id;
        }

        // Create Stripe Checkout Session with 7-day trial
        $session = \Stripe\Checkout\Session::create([
            'customer'           => $customerId,
            'mode'               => 'subscription',
            'payment_method_types' => ['card'],
            'line_items'         => [['price' => $priceId, 'quantity' => 1]],
            'subscription_data'  => ['trial_period_days' => 7],
            'success_url'        => SITE_URL . '/pages/dashboard.html?welcome=1&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'         => SITE_URL . '/pages/checkout.html?plan=' . $planSlug,
            'metadata'           => ['apex_user_id' => $user['id'], 'plan' => $planSlug],
            'allow_promotion_codes' => true,
        ]);

        json_success(['checkout_url' => $session->url]);
    }

    // ── M-PESA (Safaricom Daraja API) ────────────────────────
    elseif ($method === 'mpesa') {
        $phone = preg_replace('/[^0-9]/', '', $data['phone'] ?? '');
        if (!$phone) json_error('Phone number required.');
        // Normalize: 07xx -> 2547xx
        if (str_starts_with($phone, '0')) $phone = '254' . substr($phone, 1);
        if (str_starts_with($phone, '+')) $phone = substr($phone, 1);

        // Get access token
        $credentials = base64_encode(MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
        $ch = curl_init('https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Basic $credentials"]]);
        $tokenResp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $accessToken = $tokenResp['access_token'] ?? null;
        if (!$accessToken) json_error('M-Pesa service unavailable. Try another method.', 503);

        // STK Push
        $timestamp  = date('YmdHis');
        $password   = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);
        $kesAmount  = (int) round($amount * USDKES_RATE);
        $accountRef = 'APEX-' . strtoupper($planSlug) . '-' . $user['id'];

        $stkBody = [
            'BusinessShortCode' => MPESA_SHORTCODE,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => $kesAmount,
            'PartyA'            => $phone,
            'PartyB'            => MPESA_SHORTCODE,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => SITE_URL . '/api/mpesa-callback.php',
            'AccountReference'  => $accountRef,
            'TransactionDesc'   => 'APEX TRADERS ' . ucfirst($planSlug) . ' Plan',
        ];

        $ch = curl_init('https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($stkBody),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $accessToken",
                'Content-Type: application/json',
            ],
        ]);
        $stkResp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (($stkResp['ResponseCode'] ?? '') !== '0') {
            json_error('M-Pesa request failed: ' . ($stkResp['errorMessage'] ?? 'Unknown error'), 502);
        }

        // Record pending payment
        $pdo->prepare('
            INSERT INTO payments (user_id, amount, currency, method, status, mpesa_phone, description, metadata)
            VALUES (?, ?, \'KES\', \'mpesa\', \'pending\', ?, ?, ?)
        ')->execute([
            $user['id'], $kesAmount, $phone,
            'APEX ' . ucfirst($planSlug) . ' Plan',
            json_encode(['checkout_request_id' => $stkResp['CheckoutRequestID'], 'plan' => $planSlug]),
        ]);

        json_success([
            'message'             => 'STK push sent. Enter your M-Pesa PIN.',
            'checkout_request_id' => $stkResp['CheckoutRequestID'],
        ]);
    }

    // ── CRYPTO ───────────────────────────────────────────────
    elseif ($method === 'crypto') {
        $currency = strtoupper($data['crypto_currency'] ?? 'USDT');
        $wallets  = [
            'USDT' => CRYPTO_WALLET_USDT_TRC20,
            'BTC'  => CRYPTO_WALLET_BTC,
        ];
        $wallet = $wallets[$currency] ?? $wallets['USDT'];

        $pdo->prepare('
            INSERT INTO payments (user_id, amount, currency, method, status, crypto_currency, crypto_wallet, description)
            VALUES (?, ?, \'USD\', \'crypto\', \'pending\', ?, ?, ?)
        ')->execute([
            $user['id'], $amount, $currency, $wallet,
            'APEX ' . ucfirst($planSlug) . ' Plan',
        ]);

        json_success([
            'wallet'   => $wallet,
            'amount'   => $amount,
            'currency' => $currency,
            'message'  => 'Send exact amount. Confirmation within 30 minutes.',
        ]);
    }

    else {
        json_error('Invalid payment method.');
    }

} catch (Exception $e) {
    log_error('payment', $e->getMessage());
    json_error('Payment processing error. Please try again.', 500);
}
