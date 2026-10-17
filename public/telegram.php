<?php
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';

// Telegram присылает секрет в заголовке — чужие запросы не пройдут
$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!cfg('telegram_token') || !hash_equals((string)cfg('telegram_secret'), $secret)) {
    http_response_code(403); exit;
}

$update = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$msg = $update['message'] ?? null;
if (!$msg || !isset($msg['chat']['id'], $msg['from']['id']) || ($msg['chat']['type'] ?? '') !== 'private') { echo 'ok'; exit; }

// Отвечаем Telegram сразу, чтобы он не слал повторы, а дальше работаем
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$chatId = $msg['chat']['id'];
$from = $msg['from'];
$text = trim((string)($msg['text'] ?? ''));

/** Текст модели → HTML для Telegram (жирный **…**, остальное как есть). */
function tg_html(string $s): string
{
    $s = htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
    return preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $s);
}
function send(int|string $chatId, string $text, ?array $markup = null): void
{
    foreach (mb_str_split($text, 3800) as $part) {
        $p = ['chat_id' => $chatId, 'text' => tg_html($part), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($markup) { $p['reply_markup'] = $markup; $markup = null; }
        $r = tg_api('sendMessage', $p);
        if (!($r['ok'] ?? false)) { unset($p['parse_mode']); $p['text'] = $part; tg_api('sendMessage', $p); } // на случай кривой разметки
    }
}
$phoneKeyboard = ['keyboard' => [[['text' => '📱 Отправить мой номер', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
$removeKeyboard = ['remove_keyboard' => true];

try {
    // Клиент по Telegram id; имя и @username из профиля Telegram подставляются в пустые поля
    $client = client_by_key('tg:' . $from['id']);
    $cid = attach_telegram((int)$client['id'], $from);
    $client = get_client($cid);

    // Клиент поделился своим номером кнопкой
    if (isset($msg['contact'])) {
        $c = $msg['contact'];
        if ((int)($c['user_id'] ?? 0) === (int)$from['id']) {
            $client = update_client($cid, ['phone' => (string)$c['phone_number']]);
            send($chatId, 'Спасибо! Номер ' . $client['phone'] . ' сохранил — менеджер сможет с вами связаться.', $removeKeyboard);
        } else {
            send($chatId, 'Пожалуйста, отправьте свой номер кнопкой ниже или напишите его сообщением.', $phoneKeyboard);
        }
        exit;
    }

    if ($text === '') {
        send($chatId, 'Пока я понимаю только текст. Напишите, пожалуйста, ваш вопрос.');
        exit;
    }
    if ($text === '/start' || str_starts_with($text, '/start ') || $text === '/new') {
        current_dialog($cid, 'telegram', $text === '/new');
        send($chatId, greeting_for($client), $client['phone'] === '' ? $phoneKeyboard : $removeKeyboard);
        exit;
    }
    if (rate_limited('tg:' . $from['id'])) {
        send($chatId, 'Слишком много сообщений подряд. Подождите минуту.');
        exit;
    }

    tg_api('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    $dialog = current_dialog($cid, 'telegram');
    $reply = answer($cid, $dialog, 'telegram', $text);

    // Если бот просит телефон, а его ещё нет — показываем кнопку «Отправить мой номер»
    $client = get_client($cid);
    $askPhone = $client['phone'] === '' && preg_match('/телефон|номер/ui', $reply);
    send($chatId, $reply, $askPhone ? $phoneKeyboard : null);
} catch (Throwable $e) {
    error_log('[visa-bot] tg: ' . $e->getMessage());
    send($chatId, 'Консультант временно недоступен. Попробуйте чуть позже.');
}
