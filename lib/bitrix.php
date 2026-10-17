<?php
declare(strict_types=1);

/**
 * Битрикс24 (облако и коробка): бот-консультант в открытых линиях, параллельно с оператором.
 *
 * Схема:
 *  - «Приёмщик» (bitrix_router_bot_id) — штатный чат-бот линии. Получает новое обращение,
 *    добавляет в чат «Консультанта» и сразу отдаёт диалог в обычную очередь операторов.
 *  - «Консультант» (bitrix_bot_id) — бот-супервизор, участник чата. Видит реплики клиента и оператора,
 *    отвечает клиенту (молчит N минут после реплики оператора), пишет сведения в лид/сделку CRM.
 *  - Оператор управляет ботом командами в чате: /бот стоп, /бот старт, /бот <поручение>.
 *
 * Работает через входящий вебхук портала (права: imbot, imopenlines, im, crm).
 * API ботов — imbot.v2; на старых коробках автоматически используется imbot (v1).
 */

const B24_LEAD_FIELDS = [
    // поле лида => [код пользовательского поля, подпись]
    'country'        => ['VISA_COUNTRY', 'Виза: страна / направление'],
    'purpose'        => ['VISA_PURPOSE', 'Виза: цель поездки, тип'],
    'dates'          => ['VISA_DATES', 'Виза: предполагаемые даты'],
    'applicants'     => ['VISA_APPLICANTS', 'Виза: количество заявителей'],
    'city'           => ['VISA_CITY', 'Виза: город подачи'],
    'previous_visas' => ['VISA_PREVIOUS', 'Виза: предыдущие визы'],
    'circumstances'  => ['VISA_CIRCUMSTANCES', 'Виза: доп. обстоятельства'],
    'ready'          => ['VISA_READY', 'Виза: клиент готов оформлять'],
];

/** Журнал интеграции (data/bitrix.log) — для диагностики на сервере. */
function b24_log(string $msg): void
{
    $file = cfg('data_dir', BOT_ROOT . '/data') . '/bitrix.log';
    if (is_file($file) && filesize($file) > 2_000_000) @rename($file, $file . '.1');
    @file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

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
        throw new RuntimeException("$method (HTTP $code): $msg");
    }
    return $data;
}

/** Адрес портала (из адреса вебхука) — для ссылок на лиды. */
function b24_portal(): string
{
    $u = parse_url((string)cfg('bitrix_webhook', ''));
    return isset($u['host']) ? ($u['scheme'] ?? 'https') . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') : '';
}

function b24_crm_url(string $type, int $id): string
{
    return b24_portal() . '/crm/' . strtolower($type) . '/details/' . $id . '/';
}

/** Markdown-подобная разметка ответа → BB-коды Битрикс-чата. */
function b24_format(string $text): string
{
    $text = preg_replace('/\*\*(.+?)\*\*/u', '[b]$1[/b]', $text);
    return preg_replace('/^\s*-\s+/mu', '• ', $text);
}

/** Версия API ботов: 'v2' или 'v1' (определяется при регистрации, хранится в config). */
function b24_api(): string
{
    return cfg('bitrix_api', 'v2') === 'v1' ? 'v1' : 'v2';
}

/** Сообщение от имени бота. $bot = 'consultant' | 'router'. */
function b24_send(string $dialogId, string $text, string $bot = 'consultant'): void
{
    [$id, $token] = b24_bot($bot);
    $text = b24_format($text);
    if (b24_api() === 'v1') {
        b24('imbot.message.add', ['BOT_ID' => $id, 'CLIENT_ID' => $token, 'DIALOG_ID' => $dialogId, 'MESSAGE' => $text]);
    } else {
        b24('imbot.v2.Chat.Message.send', ['botId' => $id, 'botToken' => $token, 'dialogId' => $dialogId, 'fields' => ['message' => $text]]);
    }
}

function b24_typing(string $dialogId): void
{
    [$id, $token] = b24_bot('consultant');
    try {
        if (b24_api() === 'v1') b24('imbot.chat.sendTyping', ['BOT_ID' => $id, 'CLIENT_ID' => $token, 'DIALOG_ID' => $dialogId]);
        else b24('imbot.v2.Chat.InputAction.notify', ['botId' => $id, 'botToken' => $token, 'dialogId' => $dialogId]);
    } catch (Throwable $e) { /* не критично */ }
}

/** [id, token] бота. У приёмщика свой токен: основной + «_r». */
function b24_bot(string $bot): array
{
    $token = (string)cfg('bitrix_bot_token', '');
    return $bot === 'router'
        ? [(int)cfg('bitrix_router_bot_id', 0), substr($token, 0, 38) . '_r']
        : [(int)cfg('bitrix_bot_id', 0), $token];
}

/** Добавить консультанта в чат линии (от имени приёмщика, запасной путь — от имени вебхука). */
function b24_add_consultant(int $chatId): bool
{
    [$routerId, $routerToken] = b24_bot('router');
    [$botId] = b24_bot('consultant');
    $tries = [
        ['imbot.chat.user.add', ['BOT_ID' => $routerId, 'CLIENT_ID' => $routerToken, 'CHAT_ID' => $chatId, 'USERS' => [$botId]]],
        ['im.chat.user.add', ['CHAT_ID' => $chatId, 'USERS' => [$botId], 'HIDE_HISTORY' => 'N']],
    ];
    foreach ($tries as [$m, $p]) {
        try {
            b24($m, $p);
            b24_log("chat $chatId: консультант добавлен ($m)");
            return true;
        } catch (Throwable $e) {
            b24_log("chat $chatId: $m не сработал: " . $e->getMessage());
        }
    }
    return false;
}

/** Отдать диалог в обычную очередь операторов (приёмщик выходит из чата). */
function b24_router_handoff(int $chatId): void
{
    [, $token] = b24_bot('router');
    $queue = (int)cfg('bitrix_transfer_queue_id', 0);
    try {
        if ($queue > 0) b24('imopenlines.bot.session.transfer', ['CHAT_ID' => $chatId, 'QUEUE_ID' => $queue, 'LEAVE' => 'Y', 'CLIENT_ID' => $token]);
        else b24('imopenlines.bot.session.operator', ['CHAT_ID' => $chatId, 'CLIENT_ID' => $token]);
        b24_log("chat $chatId: диалог передан в очередь операторов");
    } catch (Throwable $e) {
        b24_log("chat $chatId: передача в очередь не удалась: " . $e->getMessage());
    }
}

/** Мессенджер клиента по коду коннектора открытой линии (entityId «коннектор|линия|чат|клиент»). */
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
 * Привязка чата линии к CRM: ['LEAD', 123] / ['DEAL', 45] или null.
 * Берётся из entityData1 события, при отсутствии — из imopenlines.dialog.get.
 */
function b24_crm_binding(int $chatId, string $entityData = ''): ?array
{
    if ($entityData === '' && $chatId > 0) {
        try {
            $r = b24('imopenlines.dialog.get', ['CHAT_ID' => $chatId]);
            $entityData = (string)($r['result']['entity_data_1'] ?? $r['result']['ENTITY_DATA_1'] ?? '');
        } catch (Throwable $e) {
            b24_log("chat $chatId: dialog.get: " . $e->getMessage());
        }
    }
    $found = [];
    if (preg_match_all('/\b(LEAD|DEAL)\|(\d+)/', $entityData, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) if ((int)$x[2] > 0) $found[$x[1]] = (int)$x[2];
    }
    // приоритет: лид (так настроены линии), затем сделка
    foreach (['LEAD', 'DEAL'] as $t) if (isset($found[$t])) return [$t, $found[$t]];
    return null;
}

/** Создаёт пользовательские поля «Виза: …» в лидах и сделках (если их ещё нет). */
function b24_install_fields(callable $log): void
{
    foreach (['lead', 'deal'] as $entity) {
        $existing = [];
        try {
            $r = b24("crm.$entity.userfield.list", ['filter' => ['%FIELD_NAME' => 'VISA_']]);
            foreach ($r['result'] ?? [] as $f) $existing[$f['FIELD_NAME']] = true;
        } catch (Throwable $e) { $log("crm.$entity.userfield.list: " . $e->getMessage()); }
        foreach (B24_LEAD_FIELDS as [$code, $label]) {
            if (isset($existing['UF_CRM_' . $code])) { $log("  $entity UF_CRM_$code — уже есть"); continue; }
            try {
                b24("crm.$entity.userfield.add", ['fields' => [
                    'FIELD_NAME' => $code, 'USER_TYPE_ID' => 'string',
                    'EDIT_FORM_LABEL' => $label, 'LIST_COLUMN_LABEL' => $label, 'LIST_FILTER_LABEL' => $label,
                    'SETTINGS' => ['ROWS' => $code === 'VISA_CIRCUMSTANCES' ? 3 : 1],
                ]]);
                $log("  $entity UF_CRM_$code — создано");
            } catch (Throwable $e) { $log("  $entity UF_CRM_$code — ошибка: " . $e->getMessage()); }
        }
    }
}

/**
 * Записывает сведения лида бота в лид/сделку Битрикс24. Пишет только при изменениях.
 * Имя и телефон клиента — в лид (у сделки они живут в контакте, их не трогаем).
 */
function b24_sync_crm(array $request, array $client, int $chatId, string $entityData = ''): void
{
    if (!$request['crm_id']) {
        $bind = b24_crm_binding($chatId, $entityData);
        if (!$bind) { b24_log("chat $chatId: к чату не привязан лид/сделка — пропускаю запись в CRM"); return; }
        db()->prepare('UPDATE requests SET crm_type = ?, crm_id = ?, b24_chat_id = ? WHERE id = ?')->execute([$bind[0], $bind[1], $chatId, $request['id']]);
        $request['crm_type'] = $bind[0];
        $request['crm_id'] = $bind[1];
    }
    $fields = [];
    foreach (B24_LEAD_FIELDS as $f => [$code]) {
        $v = $f === 'ready' ? ($request['status'] !== 'draft' || $request['ready'] ? 'да' : '') : (string)$request[$f];
        if ($v !== '') $fields['UF_CRM_' . $code] = $v;
    }
    if ($request['crm_type'] === 'LEAD') {
        if ($client['first_name'] !== '') $fields['NAME'] = $client['first_name'];
        if ($client['last_name'] !== '') $fields['LAST_NAME'] = $client['last_name'];
    }
    $hash = md5(json_encode($fields, JSON_UNESCAPED_UNICODE) . '|' . $client['phone']);
    if ($hash === $request['crm_hash']) return;

    $type = strtolower($request['crm_type']);
    try {
        b24("crm.$type.update", ['id' => $request['crm_id'], 'fields' => $fields, 'params' => ['REGISTER_SONET_EVENT' => 'N']]);
        // телефон добавляем в лид, только если такого номера ещё нет
        if ($type === 'lead' && $client['phone'] !== '') {
            $lead = b24('crm.lead.get', ['id' => $request['crm_id']])['result'] ?? [];
            $have = array_map(fn($p) => preg_replace('/\D/', '', (string)$p['VALUE']), $lead['PHONE'] ?? []);
            if (!in_array(preg_replace('/\D/', '', $client['phone']), $have, true)) {
                b24('crm.lead.update', ['id' => $request['crm_id'], 'fields' => ['PHONE' => [['VALUE' => $client['phone'], 'VALUE_TYPE' => 'MOBILE']]]]);
            }
        }
        db()->prepare('UPDATE requests SET crm_hash = ? WHERE id = ?')->execute([$hash, $request['id']]);
        b24_log("CRM {$request['crm_type']} {$request['crm_id']}: обновлено (" . implode(', ', array_keys($fields)) . ')');
    } catch (Throwable $e) {
        b24_log("CRM {$request['crm_type']} {$request['crm_id']}: ошибка обновления: " . $e->getMessage());
    }
}

/**
 * Разбор события (form-urlencoded, как присылает Битрикс24) в единый вид.
 * Поддерживает imbot.v2 (ONIMBOTV2*) и старый формат (ONIMBOT*).
 */
function b24_parse_event(array $p): array
{
    $event = strtoupper((string)($p['event'] ?? ''));
    $d = $p['data'] ?? [];
    if (str_contains($event, 'V2')) {
        $chat = $d['chat'] ?? [];
        $user = $d['user'] ?? [];
        return [
            'api'         => 'v2',
            'event'       => str_replace('V2', '', $event),
            'bot_id'      => (int)($d['bot']['id'] ?? 0),
            'text'        => (string)($d['message']['text'] ?? ''),
            'dialog_id'   => (string)($chat['dialogId'] ?? $d['dialogId'] ?? ''),
            'chat_id'     => (int)($chat['id'] ?? 0),
            'entity_type' => strtoupper((string)($chat['entityType'] ?? '')),
            'entity_id'   => (string)($chat['entityId'] ?? ''),
            'entity_data' => (string)($chat['entityData1'] ?? ''),
            'user_id'     => (int)($user['id'] ?? $d['message']['authorId'] ?? 0),
            'first_name'  => (string)($user['firstName'] ?? ''),
            'last_name'   => (string)($user['lastName'] ?? ''),
            'is_bot'      => !empty($user['bot']) && $user['bot'] !== '0',
            'connector'   => !empty($user['connector']) && $user['connector'] !== '0',
        ];
    }
    $par = $d['PARAMS'] ?? [];
    $user = $d['USER'] ?? [];
    $dialog = (string)($par['DIALOG_ID'] ?? '');
    $botId = 0;
    foreach ((array)($d['BOT'] ?? []) as $k => $b) { $botId = (int)($b['BOT_ID'] ?? $k); break; }
    return [
        'api'         => 'v1',
        'event'       => $event,
        'bot_id'      => $botId,
        'text'        => (string)($par['MESSAGE'] ?? ''),
        'dialog_id'   => $dialog,
        'chat_id'     => (int)($par['TO_CHAT_ID'] ?? $par['CHAT_ID'] ?? (str_starts_with($dialog, 'chat') ? substr($dialog, 4) : 0)),
        'entity_type' => strtoupper((string)($par['CHAT_ENTITY_TYPE'] ?? '')),
        'entity_id'   => (string)($par['CHAT_ENTITY_ID'] ?? ''),
        'entity_data' => (string)($par['CHAT_ENTITY_DATA_1'] ?? ''),
        'user_id'     => (int)($par['FROM_USER_ID'] ?? $user['ID'] ?? 0),
        'first_name'  => (string)($user['FIRST_NAME'] ?? ''),
        'last_name'   => (string)($user['LAST_NAME'] ?? ''),
        'is_bot'      => ($user['IS_BOT'] ?? 'N') === 'Y',
        'connector'   => ($user['IS_CONNECTOR'] ?? 'N') === 'Y',
    ];
}

/** Кто написал в чате линии: 'client', 'operator' или 'bot'. */
function b24_author_role(array $e): string
{
    if ($e['is_bot']) return 'bot';
    if ($e['user_id'] && in_array($e['user_id'], [(int)cfg('bitrix_bot_id'), (int)cfg('bitrix_router_bot_id')], true)) return 'bot';
    if ($e['connector']) return 'client';
    // entityId линии: «коннектор|линия|чат|id клиента»
    $parts = explode('|', $e['entity_id']);
    if (count($parts) >= 4 && (int)end($parts) === $e['user_id']) return 'client';
    if ($e['entity_type'] !== 'LINES' || $e['entity_id'] === '') return 'client'; // не линия — тестовый чат
    return 'operator';
}
