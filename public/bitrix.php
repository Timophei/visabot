<?php
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/bitrix.php';

/*
 * Обработчик событий чат-бота Битрикс24 (открытые линии).
 * Адрес вида https://visabot.b-integration.ru/bitrix.php?s=<bitrix_secret> прописывается
 * при регистрации бота (php bin/bitrix-register.php). Секрет в адресе защищает от чужих запросов.
 */

$secret = (string)cfg('bitrix_secret', '');
if ($secret === '' || !hash_equals($secret, (string)($_GET['s'] ?? ''))) { http_response_code(403); exit; }

$e = b24_parse_event($_POST);

// Отвечаем Битриксу сразу, работаем дальше в фоне
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

if ($e['dialog_id'] === '') exit;

try {
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS b24_state (k TEXT PRIMARY KEY, v TEXT NOT NULL, ts INTEGER NOT NULL)');
    $pdo->prepare('DELETE FROM b24_state WHERE ts < ?')->execute([time() - 7 * 86400]);
    $state = function (string $k, ?string $v = null) use ($pdo): ?string {
        if ($v !== null) {
            $pdo->prepare('INSERT INTO b24_state(k, v, ts) VALUES(?,?,?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')->execute([$k, $v, time()]);
            return $v;
        }
        $st = $pdo->prepare('SELECT v FROM b24_state WHERE k = ?');
        $st->execute([$k]);
        $r = $st->fetchColumn();
        return $r === false ? null : (string)$r;
    };

    switch ($e['event']) {
        // Бот подключился к диалогу открытой линии — новое обращение
        case 'ONIMBOTJOINCHAT':
            $state('new:' . $e['dialog_id'], '1');
            if (cfg('bitrix_send_greeting', true)) b24_send($e['dialog_id'], setting('greeting'), $e['api']);
            break;

        case 'ONIMBOTMESSAGEADD':
            if ($e['is_bot'] || trim($e['text']) === '') break;
            // В чате открытой линии отвечаем только клиенту (коннектору), а не операторам
            // (entityId открытой линии: «коннектор|линия|чат|id клиента»)
            $parts = explode('|', $e['entity_id']);
            $isClient = $e['connector'] || $e['entity_id'] === '' || (int)end($parts) === $e['user_id'];
            if ($e['entity_type'] === 'LINES' && !$isClient) break;

            $client = client_by_key('b24:' . $e['user_id']);
            $cid = (int)$client['id'];
            $prefill = ['first_name' => $e['first_name'], 'last_name' => $e['last_name']];
            if ($m = b24_connector_messenger($e['entity_id'])) $prefill['messenger'] = $m;
            update_client($cid, $prefill, true);

            if (rate_limited('b24:' . $e['user_id'])) {
                b24_send($e['dialog_id'], 'Слишком много сообщений подряд. Подождите минуту.', $e['api']);
                break;
            }

            // Новое обращение в линию — новый диалог у нас
            $isNew = $state('new:' . $e['dialog_id']) === '1';
            $dialog = current_dialog($cid, 'bitrix', $isNew);
            if ($isNew) $state('new:' . $e['dialog_id'], '0');

            b24_typing($e['dialog_id'], $e['api']);
            $reply = answer($cid, $dialog, 'bitrix', $e['text']);
            b24_send($e['dialog_id'], $reply, $e['api']);

            // Запрос готов → передаём диалог живому оператору линии
            if (last_submitted_request() && $e['chat_id'] > 0 && cfg('bitrix_transfer_on_request', true)) {
                try {
                    b24_to_operator($e['chat_id']);
                } catch (Throwable $t) {
                    error_log('[visa-bot] b24 transfer: ' . $t->getMessage());
                }
            }
            break;
    }
} catch (Throwable $t) {
    error_log('[visa-bot] b24: ' . $t->getMessage());
    try { b24_send($e['dialog_id'], 'Консультант временно недоступен. Оператор скоро подключится.', $e['api']); } catch (Throwable $x) {}
    try { if ($e['chat_id'] > 0) b24_to_operator($e['chat_id']); } catch (Throwable $x) {}
}
