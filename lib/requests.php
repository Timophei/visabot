<?php
declare(strict_types=1);

/**
 * Запросы (лиды) — то, что передаётся менеджеру.
 *
 * Бот собирает поля запроса по ходу диалога (модель возвращает их вместе с ответом).
 * Пока клиент не готов — запрос в статусе «собирается» (draft).
 * Когда клиент хочет оформлять/связаться и контакт известен — статус «новый» (new),
 * менеджерам уходит уведомление в Telegram. Дополнения после передачи тоже уходят менеджеру.
 */

const REQUEST_FIELDS = [
    'country'        => 'Страна / направление',
    'purpose'        => 'Цель поездки, тип визы',
    'dates'          => 'Предполагаемые даты',
    'applicants'     => 'Количество заявителей',
    'city'           => 'Город подачи',
    'previous_visas' => 'Предыдущие визы',
    'circumstances'  => 'Дополнительные обстоятельства',
];

const REQUEST_STATUSES = [
    'draft'     => 'собирается',
    'new'       => 'новый — передан менеджеру',
    'in_work'   => 'в работе',
    'done'      => 'оформлен',
    'cancelled' => 'отменён',
];

const REQUEST_OPEN = ['draft', 'new', 'in_work'];

function migrate_requests(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
        dialog_id INTEGER,
        channel TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'draft',
        country TEXT NOT NULL DEFAULT '',
        purpose TEXT NOT NULL DEFAULT '',
        dates TEXT NOT NULL DEFAULT '',
        applicants TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        previous_visas TEXT NOT NULL DEFAULT '',
        circumstances TEXT NOT NULL DEFAULT '',
        manager_note TEXT NOT NULL DEFAULT '',
        ready INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        submitted_at TEXT
    );
    CREATE INDEX IF NOT EXISTS requests_client ON requests(client_id, id);
    CREATE INDEX IF NOT EXISTS requests_status ON requests(status, id);
    SQL);
}

function get_request(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM requests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Последний незакрытый запрос клиента (или null). */
function open_request(int $clientId): ?array
{
    $st = db()->prepare("SELECT * FROM requests WHERE client_id = ? AND status IN ('draft','new','in_work') ORDER BY id DESC LIMIT 1");
    $st->execute([$clientId]);
    return $st->fetch() ?: null;
}

function client_requests(int $clientId): array
{
    $st = db()->prepare('SELECT * FROM requests WHERE client_id = ? ORDER BY id DESC');
    $st->execute([$clientId]);
    return $st->fetchAll();
}

function list_requests(string $status = '', int $limit = 300): array
{
    $sql = 'SELECT r.*, c.first_name, c.last_name, c.phone, c.messenger, c.messenger_contact, c.tg_username, c.tg_id
            FROM requests r JOIN clients c ON c.id = r.client_id';
    $args = [];
    $sql .= " WHERE r.channel != 'admin'"; // тестовые запросы из «Проверки» не показываем
    if ($status === 'open') $sql .= " AND r.status IN ('new','in_work')";
    elseif ($status !== '') { $sql .= ' AND r.status = ?'; $args[] = $status; }
    $sql .= " ORDER BY CASE r.status WHEN 'new' THEN 0 WHEN 'in_work' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END, r.updated_at DESC LIMIT " . (int)$limit;
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function create_request(int $clientId, int $dialogId, string $channel): array
{
    db()->prepare('INSERT INTO requests(client_id, dialog_id, channel) VALUES(?,?,?)')->execute([$clientId, $dialogId, $channel]);
    return get_request((int)db()->lastInsertId());
}

/**
 * Записывает поля запроса. «Обстоятельства» дополняются, остальное заменяется
 * (клиент мог уточнить или исправить). Возвращает список изменённых полей.
 */
function update_request(int $id, array $data, bool $replaceAll = false): array
{
    $r = get_request($id);
    $set = [];
    foreach (REQUEST_FIELDS as $f => $_) {
        if (!array_key_exists($f, $data)) continue;
        $v = trim(mb_substr((string)$data[$f], 0, 1000));
        if ($f === 'applicants' && preg_match('/^\d+$/', $v) && (int)$v === 0) $v = '';
        if ($v === $r[$f]) continue;
        if ($v === '' && !$replaceAll) continue;
        if ($f === 'circumstances' && !$replaceAll && $r[$f] !== '') {
            if (mb_stripos($r[$f], $v) !== false) continue;
            $v = $r[$f] . '; ' . $v;
        }
        $set[$f] = $v;
    }
    if (isset($data['manager_note'])) $set['manager_note'] = (string)$data['manager_note'];
    if (isset($data['status']) && isset(REQUEST_STATUSES[$data['status']]) && $data['status'] !== $r['status']) {
        $set['status'] = $data['status'];
        if ($data['status'] === 'new' && !$r['submitted_at']) $set['submitted_at'] = date('Y-m-d H:i:s');
    }
    if ($set) {
        $sql = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($set)));
        db()->prepare("UPDATE requests SET $sql, updated_at = datetime('now') WHERE id = :id")->execute($set + ['id' => $id]);
    }
    return array_values(array_intersect(array_keys($set), array_keys(REQUEST_FIELDS)));
}

/** Есть ли как связаться с клиентом. В Telegram и открытой линии Битрикс24 — сам чат и есть канал связи. */
function client_has_contact(array $c, string $channel = ''): bool
{
    return $c['phone'] !== '' || $c['messenger_contact'] !== '' || !empty($c['tg_id']) || $channel === 'bitrix';
}

const CHANNEL_NAMES = ['web' => 'сайт', 'telegram' => 'Telegram', 'bitrix' => 'открытая линия Битрикс24', 'admin' => 'проверка'];

/** id запроса, переданного менеджеру в текущем ответе (чтобы канал мог, например, позвать оператора). */
function last_submitted_request(?int $set = null): ?int
{
    static $id = null;
    if ($set !== null) $id = $set ?: null;
    return $id;
}

/** Блок для промта: что уже собрано по запросу и чего не хватает. */
function request_block(?array $r): string
{
    $s = "## Запрос клиента (это передаётся менеджеру)\n";
    $known = []; $missing = [];
    foreach (REQUEST_FIELDS as $f => $label) {
        $v = $r[$f] ?? '';
        if ($v !== '') $known[] = "- $label: $v"; else $missing[] = mb_strtolower($label);
    }
    $s .= $known ? implode("\n", $known) . "\n" : "- пока ничего не собрано\n";
    if ($missing) $s .= 'Ещё не известно: ' . implode(', ', $missing) . ".\n";
    if ($r && $r['status'] !== 'draft') $s .= "- Запрос уже передан менеджеру (статус: " . REQUEST_STATUSES[$r['status']] . "). Новые подробности тоже дойдут до него.\n";
    $s .= "- Собирай эти сведения естественно, по ходу консультации: по 1–2 вопроса за раз, только когда это к месту, не устраивай анкету. Известное не переспрашивай.\n"
        . "- «Предыдущие визы» и «обстоятельства» (отказы, дети, работа без официального трудоустройства, пенсионеры, сроки на исходе и т. п.) важны для оформления — уточняй их, когда клиент близок к записи.\n";
    return $s;
}

/** Текст уведомления менеджеру (HTML для Telegram). */
function request_message(array $r, array $c, string $title, array $changed = []): string
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_NOQUOTES, 'UTF-8');
    $lines = ["<b>$title #{$r['id']}</b>", ''];
    $lines[] = '👤 ' . $h(client_display_name($c));
    if ($c['phone']) $lines[] = '📞 ' . $h($c['phone']);
    if ($c['messenger']) $lines[] = '💬 ' . $h((MESSENGERS[$c['messenger']] ?? $c['messenger']) . ' ' . $c['messenger_contact']);
    if ($c['tg_username'] && $c['messenger_contact'] !== '@' . $c['tg_username']) $lines[] = '✈️ Telegram: @' . $h($c['tg_username']);
    $lines[] = '';
    foreach (REQUEST_FIELDS as $f => $label) {
        if ($r[$f] === '') continue;
        $mark = in_array($f, $changed, true) ? ' 🆕' : '';
        $lines[] = "<b>$label:</b> " . $h($r[$f]) . $mark;
    }
    $lines[] = '';
    $lines[] = 'Канал: ' . (CHANNEL_NAMES[$r['channel']] ?? $r['channel']);
    $url = rtrim((string)cfg('public_url', ''), '/');
    if ($url !== '') $lines[] = $url . '/admin/?tab=requests&id=' . $r['id'];
    return implode("\n", $lines);
}

/** Шлёт текст в Telegram-чаты менеджеров (manager_chat_ids в config.php). */
function notify_managers(string $html): void
{
    $ids = (array)cfg('manager_chat_ids', []);
    if (!$ids || !cfg('telegram_token')) return;
    foreach ($ids as $id) {
        $r = tg_api('sendMessage', ['chat_id' => $id, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
        if (!($r['ok'] ?? false)) error_log('[visa-bot] notify ' . $id . ': ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    }
}

/**
 * Обработка того, что модель вытащила из сообщения: обновить/создать запрос,
 * при готовности — передать менеджеру, после передачи — сообщить о дополнениях.
 */
function process_request(int $clientId, int $dialogId, string $channel, array $data, bool $ready): void
{
    $data = array_filter(array_intersect_key($data, REQUEST_FIELDS), fn($v) => trim((string)$v) !== '');
    $r = open_request($clientId);

    // Клиент заговорил о другой стране после передачи запроса — это новый запрос
    if ($r && $r['status'] !== 'draft' && !empty($data['country']) && $r['country'] !== ''
        && mb_stripos($r['country'], (string)$data['country']) === false && mb_stripos((string)$data['country'], $r['country']) === false) {
        $r = null;
    }
    if (!$r) {
        if (!$data && !$ready) return; // нечего сохранять
        $r = create_request($clientId, $dialogId, $channel);
    }

    $changed = update_request((int)$r['id'], $data);
    // «Готов оформлять» запоминаем: если контакта пока нет, запрос уйдёт, как только клиент его даст
    if ($ready && !$r['ready']) db()->prepare('UPDATE requests SET ready = 1 WHERE id = ?')->execute([$r['id']]);
    $r = get_request((int)$r['id']);
    $client = get_client($clientId);

    if ($r['status'] === 'draft' && $r['ready'] && client_has_contact($client, $channel)) {
        update_request((int)$r['id'], ['status' => 'new']);
        $r = get_request((int)$r['id']);
        last_submitted_request((int)$r['id']);
        if ($channel !== 'admin') notify_managers(request_message($r, $client, '🆕 Новый запрос'));
    } elseif (in_array($r['status'], ['new', 'in_work'], true) && $changed && $channel !== 'admin') {
        notify_managers(request_message($r, $client, '✏️ Дополнение к запросу', $changed));
    }
}
