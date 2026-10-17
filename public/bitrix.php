<?php
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/bitrix.php';

/*
 * Обработчик событий ботов Битрикс24 (см. схему в lib/bitrix.php).
 * Адрес https://…/bitrix.php?s=<bitrix_secret> прописывается при регистрации ботов (bin/bitrix-register.php).
 */

$secret = (string)cfg('bitrix_secret', '');
if ($secret === '' || !hash_equals($secret, (string)($_GET['s'] ?? ''))) { http_response_code(403); exit; }

$e = b24_parse_event($_POST);
$msgId = (string)($_POST['data']['message']['id'] ?? $_POST['data']['PARAMS']['MESSAGE_ID'] ?? '');

// Отвечаем Битриксу сразу, работаем дальше в фоне
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
if ($e['event'] === '' || $e['dialog_id'] === '') exit;

/* ---------- состояние чатов (какой клиент, диалог, пауза) ---------- */
$pdo = db();
$pdo->exec('CREATE TABLE IF NOT EXISTS b24_state (k TEXT PRIMARY KEY, v TEXT NOT NULL, ts INTEGER NOT NULL)');
$pdo->prepare('DELETE FROM b24_state WHERE ts < ?')->execute([time() - 60 * 86400]);
function st_get(string $k): array
{
    $s = db()->prepare('SELECT v FROM b24_state WHERE k = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? [] : (json_decode((string)$v, true) ?: []);
}
function st_set(string $k, array $v): void
{
    db()->prepare('INSERT INTO b24_state(k, v, ts) VALUES(?,?,?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')
        ->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE), time()]);
}
// защита от повторной обработки одного сообщения (его могут прислать оба бота)
function seen(string $msgId): bool
{
    if ($msgId === '') return false;
    $s = db()->prepare('INSERT OR IGNORE INTO b24_state(k, v, ts) VALUES(?, ?, ?)');
    $s->execute(['msg:' . $msgId, '1', time()]);
    return $s->rowCount() === 0;
}

$chatKey = 'chat:' . ($e['chat_id'] ?: $e['dialog_id']);
$isRouter = $e['bot_id'] && $e['bot_id'] === (int)cfg('bitrix_router_bot_id');

try {
    b24_log(sprintf('%s bot=%d chat=%d user=%d %s', $e['event'], $e['bot_id'], $e['chat_id'], $e['user_id'],
        $e['text'] !== '' ? '«' . mb_substr($e['text'], 0, 60) . '»' : ''));

    /* ---------- новое обращение ---------- */
    if ($e['event'] === 'ONIMBOTJOINCHAT') {
        if ($isRouter && $e['chat_id']) b24_add_consultant($e['chat_id']);
        $st = st_get($chatKey);
        $st['new'] = true;
        st_set($chatKey, $st);
        exit;
    }
    if ($e['event'] !== 'ONIMBOTMESSAGEADD' || seen($msgId)) exit;

    $role = b24_author_role($e);
    if ($role === 'bot') exit;
    $st = st_get($chatKey);
    $text = trim($e['text']);

    /* ---------- приёмщик: первое сообщение клиента ---------- */
    if ($isRouter) {
        if ($e['chat_id']) {
            b24_add_consultant($e['chat_id']);
            b24_router_handoff($e['chat_id']);
        }
        // дальше обрабатываем это сообщение как обычное сообщение клиента
    }

    /* ---------- реплика оператора ---------- */
    if ($role === 'operator') {
        if (preg_match('/^\/бот\b\s*(.*)$/isu', $text, $m)) {
            $cmd = trim($m[1]);
            if (preg_match('/^(стоп|stop|выкл)/iu', $cmd)) {
                $st['paused'] = true; st_set($chatKey, $st);
                b24_log("chat {$e['chat_id']}: бот остановлен оператором");
                exit;
            }
            if (preg_match('/^(старт|start|вкл)/iu', $cmd)) {
                $st['paused'] = false; $st['op_ts'] = 0; st_set($chatKey, $st);
                b24_log("chat {$e['chat_id']}: бот включён оператором");
                exit;
            }
            if ($cmd !== '' && !empty($st['client_id'])) {
                // поручение: бот пишет клиенту, пауза не мешает
                $dialog = current_dialog((int)$st['client_id'], 'bitrix');
                save_message((int)$st['client_id'], $dialog, 'bitrix', 'operator', '(поручение боту) ' . $cmd);
                b24_typing($e['dialog_id']);
                $reply = answer((int)$st['client_id'], $dialog, 'bitrix', '', $cmd);
                b24_send($e['dialog_id'], $reply);
                if ($r = open_request((int)$st['client_id'])) b24_sync_crm($r, get_client((int)$st['client_id']), $e['chat_id'], $e['entity_data']);
            }
            exit;
        }
        // обычная реплика менеджера: запоминаем как контекст и включаем паузу
        $st['op_ts'] = time();
        st_set($chatKey, $st);
        if (!empty($st['client_id']) && $text !== '') {
            save_message((int)$st['client_id'], current_dialog((int)$st['client_id'], 'bitrix'), 'bitrix', 'operator', $text);
        }
        exit;
    }

    /* ---------- сообщение клиента ---------- */
    if ($text === '') exit;
    $client = client_by_key('b24:' . $e['user_id']);
    $cid = (int)$client['id'];
    $prefill = ['first_name' => $e['first_name'], 'last_name' => $e['last_name']];
    if ($m = b24_connector_messenger($e['entity_id'])) $prefill['messenger'] = $m;
    update_client($cid, $prefill, true);

    $dialog = current_dialog($cid, 'bitrix', !empty($st['new']));
    $st['new'] = false;
    $st['client_id'] = $cid;
    st_set($chatKey, $st);

    $pauseMin = (int)cfg('bitrix_operator_pause_min', 10);
    $silent = !empty($st['paused']) || (!empty($st['op_ts']) && time() - (int)$st['op_ts'] < $pauseMin * 60);
    if (!$silent && rate_limited('b24:' . $e['user_id'])) $silent = true;

    if (!$silent) b24_typing($e['dialog_id']);
    $reply = answer($cid, $dialog, 'bitrix', $text, null, $silent);
    if (!$silent) b24_send($e['dialog_id'], $reply);
    else b24_log("chat {$e['chat_id']}: бот молчит (" . (!empty($st['paused']) ? 'остановлен' : 'пишет оператор') . '), сведения сохранены');

    if ($r = open_request($cid)) b24_sync_crm($r, get_client($cid), $e['chat_id'], $e['entity_data']);
} catch (Throwable $t) {
    b24_log('ОШИБКА: ' . $t->getMessage());
    error_log('[visa-bot] b24: ' . $t->getMessage());
}
