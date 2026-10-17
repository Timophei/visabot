<?php
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';

// Telegram присылает секрет в заголовке — так чужие запросы не пройдут
$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!cfg('telegram_token') || !hash_equals((string)cfg('telegram_secret'), $secret)) {
    http_response_code(403); exit;
}

function tg(string $method, array $params): void
{
    $ch = curl_init('https://api.telegram.org/bot' . cfg('telegram_token') . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

$update = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$msg = $update['message'] ?? null;
if (!$msg || !isset($msg['chat']['id'])) { echo 'ok'; exit; }

$chatId = $msg['chat']['id'];
$text = trim((string)($msg['text'] ?? ''));
$sid = 'tg:' . $chatId;

// Отвечаем Telegram сразу, чтобы он не слал повторы, а дальше работаем
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

try {
    if ($text === '' ) {
        tg('sendMessage', ['chat_id' => $chatId, 'text' => 'Пока я понимаю только текст. Напишите, пожалуйста, ваш вопрос.']);
        exit;
    }
    if ($text === '/start' || $text === '/new') {
        reset_session($sid);
        tg('sendMessage', ['chat_id' => $chatId, 'text' => setting('greeting')]);
        exit;
    }
    if (rate_limited($sid)) {
        tg('sendMessage', ['chat_id' => $chatId, 'text' => 'Слишком много сообщений подряд. Подождите минуту.']);
        exit;
    }
    tg('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    $reply = answer($sid, 'telegram', $text);
    foreach (mb_str_split($reply, 4000) as $part) {
        tg('sendMessage', ['chat_id' => $chatId, 'text' => $part]);
    }
} catch (Throwable $e) {
    error_log('[visa-bot] tg: ' . $e->getMessage());
    tg('sendMessage', ['chat_id' => $chatId, 'text' => 'Консультант временно недоступен. Позвоните нам, пожалуйста.']);
}
