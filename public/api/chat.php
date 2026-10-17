<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/core.php';

header('Content-Type: application/json; charset=utf-8');

// CORS: виджет можно встроить только на разрешённые сайты
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, (array)cfg('allowed_origins', []), true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"error":"POST only"}'; exit; }

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$session = (string)($in['session'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $session)) {
    http_response_code(400); echo json_encode(['error' => 'bad session']); exit;
}
$sid = 'web:' . $session;

try {
    $action = $in['action'] ?? 'message';
    if ($action === 'greeting') {
        echo json_encode(['reply' => setting('greeting')], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($action === 'reset') {
        reset_session($sid);
        echo json_encode(['reply' => setting('greeting')], JSON_UNESCAPED_UNICODE); exit;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (rate_limited('ip:' . $ip)) {
        http_response_code(429);
        echo json_encode(['error' => 'Слишком много сообщений подряд. Подождите минуту.'], JSON_UNESCAPED_UNICODE); exit;
    }

    $reply = answer($sid, 'web', (string)($in['message'] ?? ''));
    echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[visa-bot] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Консультант временно недоступен. Позвоните нам, пожалуйста.'], JSON_UNESCAPED_UNICODE);
}
