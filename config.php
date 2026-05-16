<?php
// api/bots.php — Deploy, list, pause, stop bots
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once 'config.php';
require_once 'helpers.php';

$user   = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

$pdo = get_db();

// ── GET: list user's deployments ──────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $stmt = $pdo->prepare('
        SELECT bd.*, b.name AS bot_name, b.market_label, b.slug AS bot_slug
        FROM bot_deployments bd
        JOIN bots b ON b.id = bd.bot_id
        WHERE bd.user_id = ?
        ORDER BY bd.created_at DESC
    ');
    $stmt->execute([$user['id']]);
    json_success($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ── GET: available bots catalogue ────────────────────────────
elseif ($method === 'GET' && $action === 'catalogue') {
    $planOrder = ['recruit' => 1, 'operative' => 2, 'apex' => 3];
    $userPlanLevel = $planOrder[$user['plan']] ?? 1;

    $stmt = $pdo->query('SELECT * FROM bots WHERE is_active = 1 ORDER BY market, name');
    $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($bots as &$b) {
        $b['unlocked'] = ($planOrder[$b['min_plan']] ?? 1) <= $userPlanLevel;
    }
    json_success($bots);
}

// ── POST: deploy a bot ────────────────────────────────────────
elseif ($method === 'POST' && $action === 'deploy') {
    $data       = json_decode(file_get_contents('php://input'), true);
    $botSlug    = $data['bot_slug'] ?? '';
    $stake      = (float)($data['stake'] ?? 0);
    $stopLoss   = isset($data['stop_loss'])   ? (float)$data['stop_loss']   : null;
    $takeProfit = isset($data['take_profit']) ? (float)$data['take_profit'] : null;
    $martingale = (float)($data['martingale'] ?? 2.0);

    if (!$botSlug || $stake <= 0) json_error('Bot slug and stake are required.');

    // Load bot
    $botStmt = $pdo->prepare('SELECT * FROM bots WHERE slug = ? AND is_active = 1');
    $botStmt->execute([$botSlug]);
    $bot = $botStmt->fetch(PDO::FETCH_ASSOC);
    if (!$bot) json_error('Bot not found.', 404);

    // Plan check
    $planOrder = ['trial' => 0, 'recruit' => 1, 'operative' => 2, 'apex' => 3];
    if (($planOrder[$user['plan']] ?? 0) < ($planOrder[$bot['min_plan']] ?? 1)) {
        json_error('Upgrade your plan to deploy this bot.', 403);
    }

    // Stake limits
    if ($stake < $bot['min_stake'] || $stake > $bot['max_stake']) {
        json_error("Stake must be between \${$bot['min_stake']} and \${$bot['max_stake']}.");
    }

    // Get Deriv connection
    $connStmt = $pdo->prepare('SELECT id FROM deriv_connections WHERE user_id = ? AND is_active = 1 LIMIT 1');
    $connStmt->execute([$user['id']]);
    $conn = $connStmt->fetch(PDO::FETCH_ASSOC);
    if (!$conn) json_error('No active Deriv connection found. Please link your Deriv account.', 409);

    // Create deployment
    $pdo->prepare('
        INSERT INTO bot_deployments (user_id, bot_id, deriv_conn_id, stake_amount, stop_loss, take_profit, martingale)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([$user['id'], $bot['id'], $conn['id'], $stake, $stopLoss, $takeProfit, $martingale]);
    $deployId = $pdo->lastInsertId();

    // Notify
    $pdo->prepare('
        INSERT INTO notifications (user_id, type, title, body, action_url)
        VALUES (?, \'bot_alert\', ?, ?, \'/pages/dashboard.html\')
    ')->execute([$user['id'], $bot['name'] . ' Deployed', "{$bot['name']} is now running on {$bot['market_label']} with a \${$stake} stake."]);

    json_success(['deployment_id' => $deployId, 'message' => 'Bot deployed successfully.'], 201);
}

// ── PATCH: pause / stop a deployment ─────────────────────────
elseif ($method === 'PATCH') {
    $data   = json_decode(file_get_contents('php://input'), true);
    $depId  = (int)($data['deployment_id'] ?? 0);
    $newStatus = $data['status'] ?? ''; // paused | running | stopped

    if (!$depId || !in_array($newStatus, ['paused','running','stopped'])) {
        json_error('deployment_id and valid status required.');
    }

    $stmt = $pdo->prepare('SELECT id FROM bot_deployments WHERE id = ? AND user_id = ?');
    $stmt->execute([$depId, $user['id']]);
    if (!$stmt->fetch()) json_error('Deployment not found.', 404);

    $stoppedAt = $newStatus === 'stopped' ? ', stopped_at = NOW()' : '';
    $pdo->prepare("UPDATE bot_deployments SET status = ? $stoppedAt WHERE id = ?")
        ->execute([$newStatus, $depId]);

    json_success(['message' => "Bot $newStatus."]);
}

else {
    json_error('Unknown action.', 400);
}
