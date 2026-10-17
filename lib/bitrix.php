<?php
declare(strict_types=1);

/**
 * Битрикс24: чат-бот для открытых линий.
 *
 * Работает через входящий вебхук портала (права: imbot, imopenlines, im).
 * Основной API — imbot.v2 (Chatbots 2.0); для старых коробочных порталов — imbot.register (v1).
 */

/** Вызов REST-метода через входящий вебхук. */
function b24(string $method, array $params): array
{
    $base = rtrim((string)cfg('bitrix_webhook', ''), '/');
    if ($base === '') throw new RuntimeException('Не задан bitrix_webhook в config.php');
    $ch = curl_init($base . '/' . $method . '.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException("Битрикс24 $method: нет соединения: $err");
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || isset($data['error'])) {
        $msg = is_array($data) ? ($data['error'] . ': ' . ($data['error_description'] ?? '')) : substr((string)$raw, 0, 300);
        throw new RuntimeException("Битрикс24 $method (HTTP $code): $msg");
    }
    return $data;
}

/** Markdown-подобная разметка ответа → BB-коды Битрикс-чата. */
function b24_format(string $text): string
{
    $text = preg_replace('/\*\*(.+?)\*\*/u', '[b]$1[/b]', $text);
    return preg_replace('/^\s*-\s+/mu', '• ', $text);
}

/** Отправка сообщения от имени бота (v2 или v1 — по тому, как бот зарегистрирован). */
function b24_send(string $dialogId, string $text, string $api): void
{
    $text = b24_format($text);
    if ($api === 'v1') {
        b24('imbot.message.add', [
            'BOT_ID' => (int)cfg('bitrix_bot_id'), 'CLIENT_ID' => (string)cfg('bitrix_bot_token'),
            'DIALOG_ID' => $dialogId, 'MESSAGE' => $text,
        ]);
    } else {
        b24('imbot.v2.Chat.Message.send', [
            'botId' => (int)cfg('bitrix_bot_id'), 'botToken' => (string)cfg('bitrix_bot_token'),
            'dialogId' => $dialogId, 'fields' => ['message' => $text],
        ]);
    }
}

/** «Печатает…» — не обязательно, ошибки игнорируем. */
function b24_typing(string $dialogId, string $api): void
{
    try {
        if ($api === 'v1') {
            b24('imbot.chat.sendTyping', ['BOT_ID' => (int)cfg('bitrix_bot_id'), 'CLIENT_ID' => (string)cfg('bitrix_bot_token'), 'DIALOG_ID' => $dialogId]);
        } else {
            b24('imbot.v2.Chat.InputAction.notify', ['botId' => (int)cfg('bitrix_bot_id'), 'botToken' => (string)cfg('bitrix_bot_token'), 'dialogId' => $dialogId]);
        }
    } catch (Throwable $e) { /* не критично */ }
}

/** Передать диалог открытой линии свободному оператору (или в очередь из bitrix_transfer_queue_id). */
function b24_to_operator(int $chatId): void
{
    $token = (string)cfg('bitrix_bot_token');
    $queue = (int)cfg('bitrix_transfer_queue_id', 0);
    if ($queue > 0) {
        b24('imopenlines.bot.session.transfer', ['CHAT_ID' => $chatId, 'QUEUE_ID' => $queue, 'LEAVE' => 'Y', 'CLIENT_ID' => $token]);
    } else {
        b24('imopenlines.bot.session.operator', ['CHAT_ID' => $chatId, 'CLIENT_ID' => $token]);
    }
}

/** Мессенджер клиента по коду коннектора открытой линии (entityId вида «telegrambot|1|123|45»). */
function b24_connector_messenger(string $entityId): string
{
    $connector = strtolower(explode('|', $entityId)[0] ?? '');
    return match (true) {
        str_contains($connector, 'telegram') => 'telegram',
        str_contains($connector, 'whatsapp'), str_contains($connector, 'wz_') => 'whatsapp',
        str_contains($connector, 'viber') => 'viber',
        str_contains($connector, 'vk') => 'vk',
        str_contains($connector, 'max') => 'max',
        default => '',
    };
}

/**
 * Разбор события (form-urlencoded, как присылает Битрикс24) в единый вид.
 * Поддерживает imbot.v2 (ONIMBOTV2*) и старый формат (ONIMBOT*).
 */
function b24_parse_event(array $p): array
{
    $event = strtoupper((string)($p['event'] ?? ''));
    $d = $p['data'] ?? [];
    $isV2 = str_contains($event, 'V2');
    if ($isV2) {
        $chat = $d['chat'] ?? [];
        $user = $d['user'] ?? [];
        return [
            'api'        => 'v2',
            'event'      => str_replace('V2', '', $event),
            'text'       => (string)($d['message']['text'] ?? ''),
            'dialog_id'  => (string)($chat['dialogId'] ?? $d['dialogId'] ?? ''),
            'chat_id'    => (int)($chat['id'] ?? 0),
            'entity_type'=> strtoupper((string)($chat['entityType'] ?? '')),
            'entity_id'  => (string)($chat['entityId'] ?? ''),
            'user_id'    => (int)($user['id'] ?? 0),
            'first_name' => (string)($user['firstName'] ?? ''),
            'last_name'  => (string)($user['lastName'] ?? ''),
            'is_bot'     => !empty($user['bot']) && $user['bot'] !== '0',
            'connector'  => !empty($user['connector']) && $user['connector'] !== '0',
        ];
    }
    $par = $d['PARAMS'] ?? [];
    $user = $d['USER'] ?? [];
    $dialog = (string)($par['DIALOG_ID'] ?? '');
    return [
        'api'        => 'v1',
        'event'      => $event,
        'text'       => (string)($par['MESSAGE'] ?? ''),
        'dialog_id'  => $dialog,
        'chat_id'    => (int)($par['TO_CHAT_ID'] ?? $par['CHAT_ID'] ?? (str_starts_with($dialog, 'chat') ? substr($dialog, 4) : 0)),
        'entity_type'=> strtoupper((string)($par['CHAT_ENTITY_TYPE'] ?? '')),
        'entity_id'  => (string)($par['CHAT_ENTITY_ID'] ?? ''),
        'user_id'    => (int)($par['FROM_USER_ID'] ?? $user['ID'] ?? 0),
        'first_name' => (string)($user['FIRST_NAME'] ?? ''),
        'last_name'  => (string)($user['LAST_NAME'] ?? ''),
        'is_bot'     => ($user['IS_BOT'] ?? 'N') === 'Y',
        'connector'  => ($user['IS_CONNECTOR'] ?? 'N') === 'Y',
    ];
}
