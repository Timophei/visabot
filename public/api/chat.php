<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $d, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- CORS: виджет на других сайтах из allowed_origins ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
$crossSite = $origin !== '' && parse_url($origin, PHP_URL_HOST) !== explode(':', $host)[0];
if ($crossSite) {
    if (!in_array($origin, (array)cfg('allowed_origins', []), true)) out(['error' => 'origin not allowed'], 403);
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'POST only'], 405);

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

// --- Кто это: cookie (основной способ) или id из localStorage (если браузер режет сторонние cookie) ---
$valid = fn($v) => is_string($v) && preg_match('/^[a-f0-9]{32}$/', $v);
$uid = $valid($_COOKIE['vb_uid'] ?? null) ? $_COOKIE['vb_uid'] : ($valid($in['uid'] ?? null) ? $in['uid'] : bin2hex(random_bytes(16)));
setcookie('vb_uid', $uid, [
    'expires'  => time() + 365 * 86400,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) || $crossSite,
    'httponly' => true,
    'samesite' => $crossSite ? 'None' : 'Lax',
]);

try {
    $client = client_by_key('web:' . $uid);
    $cid = (int)$client['id'];
    $action = $in['action'] ?? 'message';

    $clientInfo = fn(array $c) => [
        'name'     => $c['first_name'],
        'telegram' => $c['tg_id'] ? ($c['tg_username'] ? '@' . $c['tg_username'] : $c['first_name']) : null,
    ];

    switch ($action) {
        case 'init':
            $dialog = current_dialog($cid, 'web');
            $msgs = array_map(fn($m) => ['role' => $m['role'], 'text' => $m['content']], dialog_messages($dialog, 100));
            if (!$msgs) $msgs = [['role' => 'assistant', 'text' => greeting_for($client)]];
            out([
                'uid'      => $uid,
                'client'   => $clientInfo($client),
                'messages' => $msgs,
                'tg_bot'   => cfg('telegram_token') ? (string)cfg('telegram_bot_username', '') : '',
                'privacy'  => (string)cfg('privacy_url', ''),
            ]);

        case 'new':
            current_dialog($cid, 'web', true);
            out(['uid' => $uid, 'messages' => [['role' => 'assistant', 'text' => greeting_for($client)]]]);

        case 'tg_login':
            $tg = verify_telegram_login((array)($in['data'] ?? []));
            if (!$tg) out(['error' => 'Не удалось подтвердить вход через Telegram'], 400);
            $cid = attach_telegram($cid, $tg);
            out(['uid' => $uid, 'client' => $clientInfo(get_client($cid))]);

        case 'message':
            if (rate_limited('ip:' . ($_SERVER['REMOTE_ADDR'] ?? '?'))) {
                out(['error' => 'Слишком много сообщений подряд. Подождите минуту.'], 429);
            }
            $dialog = current_dialog($cid, 'web');
            $reply = answer($cid, $dialog, 'web', (string)($in['message'] ?? ''));
            out(['uid' => $uid, 'reply' => $reply, 'client' => $clientInfo(get_client($cid))]);

        default:
            out(['error' => 'unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('[visa-bot] ' . $e->getMessage());
    out(['error' => 'Консультант временно недоступен. Попробуйте чуть позже или позвоните нам.'], 500);
}
