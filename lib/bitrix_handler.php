<?php
declare(strict_types=1);

/*
 * Обработка одного события ботов Битрикс24 (см. схему в lib/bitrix.php).
 * Вызывается и из public/bitrix.php (режим webhook — Битрикс шлёт события нам),
 * и из bin/bitrix-poll.php (режим fetch — наш сервер сам забирает события из Битрикса).
 */

/* ---------- состояние чатов (какой клиент, диалог, пауза) ---------- */
function b24_state_init(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec('CREATE TABLE IF NOT EXISTS b24_state (k TEXT PRIMARY KEY, v TEXT NOT NULL, ts INTEGER NOT NULL)');
    db()->prepare('DELETE FROM b24_state WHERE ts < ?')->execute([time() - 60 * 86400]);
}
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
/**
 * Защита от повторной обработки одного сообщения (его могут прислать оба бота).
 * Сообщение «занимается» на время обработки и помечается готовым только в конце.
 * Если обработка оборвалась (перезапуск службы, сбой) — через 2 минуты сообщение можно взять снова.
 */
function msg_claim(string $msgId): bool
{
    if ($msgId === '') return true;
    $k = 'msg:' . $msgId;
    $s = db()->prepare('INSERT OR IGNORE INTO b24_state(k, v, ts) VALUES(?, ?, ?)');
    $s->execute([$k, 'processing', time()]);
    if ($s->rowCount() === 1) return true;
    // уже есть: готово или ещё обрабатывается — пропускаем; зависло больше 2 минут — забираем
    $u = db()->prepare("UPDATE b24_state SET ts = ? WHERE k = ? AND v = 'processing' AND ts < ?");
    $u->execute([time(), $k, time() - 120]);
    return $u->rowCount() === 1;
}
function msg_done(string $msgId): void
{
    if ($msgId !== '') db()->prepare("UPDATE b24_state SET v = 'done', ts = ? WHERE k = ?")->execute([time(), 'msg:' . $msgId]);
}


/** $payload — событие в виде ['event' => 'ONIMBOTV2MESSAGEADD', 'data' => [...]] (как POST вебхука или элемент Event.get). */
function b24_handle(array $payload): void
{
    b24_state_init();
    $e = b24_parse_event($payload);
    $msgId = (string)($payload['data']['message']['id'] ?? $payload['data']['PARAMS']['MESSAGE_ID'] ?? '');
    b24_raw_log($payload);
    if ($e['event'] === '' || $e['dialog_id'] === '') {
        b24_log('событие без чата/типа — пропущено: ' . mb_substr(json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 300));
        return;
    }

    $chatKey = 'chat:' . ($e['chat_id'] ?: $e['dialog_id']);
    $isRouter = $e['bot_id'] && $e['bot_id'] === (int)cfg('bitrix_router_bot_id');

    try {
        b24_log(sprintf('%s bot=%d chat=%d user=%d %s', $e['event'], $e['bot_id'], $e['chat_id'], $e['user_id'],
            $e['text'] !== '' ? '«' . mb_substr($e['text'], 0, 60) . '»' : ''));

        // Бот работает только в линиях, включённых в админке (Битрикс24 → линии)
        $lineId = b24_line_id($e['entity_id']);
        if ($e['entity_type'] === 'LINES' && $lineId && !b24_line_enabled($lineId)) {
            if ($isRouter && $e['chat_id'] && $e['event'] === 'ONIMBOTMESSAGEADD') b24_router_handoff($e['chat_id']); // не задерживаем клиента
            b24_log("линия $lineId выключена в админке — пропускаю");
            return;
        }

        /* ---------- новое обращение ---------- */
        if ($e['event'] === 'ONIMBOTJOINCHAT') {
            // новое обращение начинается, только когда в чат входит Приёмщик (штатный бот линии);
            // вход Консультанта — следствие этого же обращения, новый диалог не начинаем
            if ($isRouter) {
                if ($e['chat_id']) b24_add_consultant($e['chat_id']);
                $st = st_get($chatKey);
                $st['new'] = true;
                st_set($chatKey, $st);
            }
            return;
        }
        if ($e['event'] !== 'ONIMBOTMESSAGEADD') return;
        if (!msg_claim($msgId)) { b24_log("сообщение $msgId уже обработано — пропускаю"); return; }
        $claimed = true;

        $role = b24_author_role($e);
        if ($role === 'bot') return;
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
                    return;
                }
                if (preg_match('/^(старт|start|вкл)/iu', $cmd)) {
                    $st['paused'] = false; $st['op_ts'] = 0; st_set($chatKey, $st);
                    b24_log("chat {$e['chat_id']}: бот включён оператором");
                    return;
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
                return;
            }
            // обычная реплика менеджера: запоминаем как контекст и включаем паузу
            $st['op_ts'] = time();
            st_set($chatKey, $st);
            if (!empty($st['client_id']) && $text !== '') {
                save_message((int)$st['client_id'], current_dialog((int)$st['client_id'], 'bitrix'), 'bitrix', 'operator', $text);
            }
            return;
        }

        /* ---------- сообщение клиента ---------- */
        if ($text === '') return;
        $client = client_by_key('b24:' . $e['user_id']);
        $cid = (int)$client['id'];
        $prefill = ['first_name' => $e['first_name'], 'last_name' => $e['last_name']];
        if ($m = b24_connector_messenger($e['entity_id'])) $prefill['messenger'] = $m;
        update_client($cid, $prefill, true);

        $dialog = current_dialog($cid, 'bitrix', !empty($st['new']));
        db()->prepare("UPDATE dialogs SET ext_id = ? WHERE id = ? AND ext_id = ''")->execute([$e['dialog_id'], $dialog]);
        $st['new'] = false;
        $st['client_id'] = $cid;
        st_set($chatKey, $st);

        $pauseMin = b24_pause_min();
        $silent = !empty($st['paused']) || (!empty($st['op_ts']) && time() - (int)$st['op_ts'] < $pauseMin * 60);
        if (!$silent && rate_limited('b24:' . $e['user_id'])) $silent = true;

        if (!$silent) b24_typing($e['dialog_id']);
        $tStart = microtime(true);
        $reply = answer($cid, $dialog, 'bitrix', $text, null, $silent);
        $tm = answer_timing();
        b24_log(sprintf('chat %d: ответ за %.1f с (поиск %.1f с, нейросеть %.1f с, %s, %d токенов, фрагментов %d)',
            $e['chat_id'], microtime(true) - $tStart, $tm['search'] ?? 0, $tm['llm'] ?? 0, $tm['model'] ?? '?', $tm['tokens'] ?? 0, $tm['chunks'] ?? 0));
        if (!$silent) b24_send($e['dialog_id'], $reply);
        else b24_log("chat {$e['chat_id']}: бот молчит (" . (!empty($st['paused']) ? 'остановлен' : 'пишет оператор') . '), сведения сохранены');

        if ($r = open_request($cid)) b24_sync_crm($r, get_client($cid), $e['chat_id'], $e['entity_data']);
    } catch (Throwable $t) {
        b24_log('ОШИБКА: ' . $t->getMessage() . ' (' . basename($t->getFile()) . ':' . $t->getLine() . ')');
        error_log('[visa-bot] b24: ' . $t->getMessage());
    } finally {
        if (!empty($claimed)) msg_done($msgId);
    }
}
