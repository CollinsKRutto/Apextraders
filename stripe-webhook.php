<?php
// api/dashboard.php — Returns all stats for the member dashboard
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once 'config.php';
require_once 'helpers.php';

$user = require_auth();
$pdo  = get_db();
$uid  = $user['id'];

// ── Overview stats ────────────────────────────────────────────
$statsStmt = $pdo->prepare('
    SELECT
        COALESCE(SUM(total_profit), 0)   AS total_profit,
        COALESCE(SUM(trades_count), 0)   AS total_trades,
        COALESCE(SUM(win_count), 0)      AS total_wins,
        COUNT(CASE WHEN status = \'running\' THEN 1 END) AS active_bots,
        COUNT(CASE WHEN status = \'paused\'  THEN 1 END) AS paused_bots
    FROM bot_deployments WHERE user_id = ?
');
$statsStmt->execute([$uid]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
$winRate = $stats['total_trades'] > 0
    ? round($stats['total_wins'] / $stats['total_trades'] * 100, 1)
    : 0;

// ── Today's trades ────────────────────────────────────────────
$todayStmt = $pdo->prepare('
    SELECT
        COUNT(*) AS today_trades,
        SUM(CASE WHEN result = \'win\'  THEN 1 ELSE 0 END) AS today_wins,
        SUM(CASE WHEN result = \'loss\' THEN 1 ELSE 0 END) AS today_losses,
        COALESCE(SUM(profit), 0) AS today_profit
    FROM trade_log
    WHERE user_id = ? AND DATE(opened_at) = CURDATE()
');
$todayStmt->execute([$uid]);
$today = $todayStmt->fetch(PDO::FETCH_ASSOC);

// ── Active bots detail ────────────────────────────────────────
$botsStmt = $pdo->prepare('
    SELECT bd.id, bd.stake_amount, bd.total_profit, bd.trades_count,
           bd.win_count, bd.status, bd.started_at,
           b.name AS bot_name, b.market_label, b.slug
    FROM bot_deployments bd
    JOIN bots b ON b.id = bd.bot_id
    WHERE bd.user_id = ? AND bd.status IN (\'running\', \'paused\')
    ORDER BY bd.total_profit DESC
');
$botsStmt->execute([$uid]);
$activeBots = $botsStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Performance chart (last 30 days) ─────────────────────────
$chartStmt = $pdo->prepare('
    SELECT DATE(opened_at) AS trade_date, COALESCE(SUM(profit), 0) AS daily_profit
    FROM trade_log
    WHERE user_id = ? AND opened_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND result != \'open\'
    GROUP BY DATE(opened_at)
    ORDER BY trade_date ASC
');
$chartStmt->execute([$uid]);
$chartRaw = $chartStmt->fetchAll(PDO::FETCH_ASSOC);
// Build running cumulative total
$cumulative = 0;
$chartData  = [];
foreach ($chartRaw as $row) {
    $cumulative += (float)$row['daily_profit'];
    $chartData[] = ['date' => $row['trade_date'], 'profit' => round($cumulative, 2)];
}

// ── Latest signals (plan-gated) ───────────────────────────────
$planOrder = ['trial' => 0, 'recruit' => 1, 'operative' => 2, 'apex' => 3];
$userLevel = $planOrder[$user['plan']] ?? 0;
$planLevels = array_keys(array_filter($planOrder, fn($v) => $v <= $userLevel));
$inClause   = implode(',', array_fill(0, count($planLevels), '?'));
$sigStmt    = $pdo->prepare("
    SELECT market, direction, entry_price, stop_loss, take_profit, confidence, created_at
    FROM signals
    WHERE min_plan IN ($inClause) AND result = 'pending' AND (expires_at IS NULL OR expires_at > NOW())
    ORDER BY created_at DESC LIMIT 8
");
$sigStmt->execute($planLevels);
$signals = $sigStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Upcoming mentorship sessions ──────────────────────────────
$sessStmt = $pdo->prepare("
    SELECT ms.id, ms.title, ms.type, ms.starts_at, ms.duration_mins, ms.meeting_link, ms.min_plan,
           u.full_name AS mentor_name
    FROM mentorship_sessions ms
    LEFT JOIN users u ON u.id = ms.mentor_id
    WHERE ms.starts_at > NOW() AND ms.status = 'scheduled'
    ORDER BY ms.starts_at ASC LIMIT 5
");
$sessStmt->execute();
$sessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Unread notifications ──────────────────────────────────────
$notifStmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10');
$notifStmt->execute([$uid]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

// ── User info ─────────────────────────────────────────────────
$userStmt = $pdo->prepare('SELECT id, full_name, email, plan, plan_expires_at, trial_ends_at, created_at FROM users WHERE id = ?');
$userStmt->execute([$uid]);
$userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);

json_success([
    'user'          => $userInfo,
    'stats'         => array_merge($stats, ['win_rate' => $winRate]),
    'today'         => $today,
    'active_bots'   => $activeBots,
    'chart_data'    => $chartData,
    'signals'       => $signals,
    'sessions'      => $sessions,
    'notifications' => $notifications,
]);
