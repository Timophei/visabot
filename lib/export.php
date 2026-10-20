<?php
// Выгрузка диалогов в читаемом виде: HTML (с вёрсткой, открывается в браузере и печатается) или TXT.
declare(strict_types=1);

const ROLE_NAMES = ['user' => 'Клиент', 'assistant' => 'Бот', 'operator' => 'Менеджер'];

/** Время из базы (UTC) → местное время компании (timezone в config.php). */
function fmt_dt(?string $utc, string $format = 'd.m.Y H:i'): string
{
    if (!$utc) return '';
    try {
        $tz = new DateTimeZone((string)cfg('timezone', 'Europe/Moscow'));
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format($format);
    } catch (Throwable $e) {
        return $utc;
    }
}

function tz_label(): string
{
    try {
        $tz = new DateTimeZone((string)cfg('timezone', 'Europe/Moscow'));
        return $tz->getName() . ', UTC' . (new DateTimeImmutable('now', $tz))->format('P');
    } catch (Throwable $e) {
        return 'UTC';
    }
}

/** Диалоги с клиентом и сообщениями, в порядке переданных id. */
function export_load(array $ids): array
{
    $out = [];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        if ($id <= 0) continue;
        $st = db()->prepare('SELECT * FROM dialogs WHERE id = ?');
        $st->execute([$id]);
        if (!$d = $st->fetch()) continue;
        $c = get_client((int)$d['client_id']) ?? ['id' => $d['client_id'], 'first_name' => '', 'last_name' => '', 'phone' => '', 'tg_username' => '', 'messenger' => '', 'messenger_contact' => ''];
        $out[] = ['dialog' => $d, 'client' => $c, 'messages' => dialog_messages($id)];
    }
    return $out;
}

function export_client_line(array $c): array
{
    $parts = [client_display_name($c)];
    if ($c['phone']) $parts[] = $c['phone'];
    if ($c['messenger'] ?? '') $parts[] = (MESSENGERS[$c['messenger']] ?? $c['messenger']) . ($c['messenger_contact'] ? ' ' . $c['messenger_contact'] : '');
    if (($c['tg_username'] ?? '') && ($c['messenger_contact'] ?? '') !== '@' . $c['tg_username']) $parts[] = 'Telegram @' . $c['tg_username'];
    return $parts;
}

function export_author(string $role, array $c): string
{
    $name = ROLE_NAMES[$role] ?? $role;
    if ($role === 'user') {
        $n = trim($c['first_name'] . ' ' . $c['last_name']);
        if ($n !== '') $name .= " ($n)";
    }
    return $name;
}

function export_txt(array $items): string
{
    $L = ['Выгрузка диалогов — ' . fmt_dt(gmdate('Y-m-d H:i:s')) . ' (время: ' . tz_label() . ')', ''];
    foreach ($items as ['dialog' => $d, 'client' => $c, 'messages' => $ms]) {
        $L[] = str_repeat('=', 70);
        $L[] = "Диалог #{$d['id']} · " . (CHANNEL_NAMES[$d['channel']] ?? $d['channel']);
        $L[] = 'Клиент: ' . implode(' · ', export_client_line($c));
        $L[] = 'Период: ' . fmt_dt($d['started_at']) . ' — ' . fmt_dt($d['last_at']) . " · сообщений: " . count($ms);
        $L[] = str_repeat('=', 70);
        $day = '';
        foreach ($ms as $m) {
            $dd = fmt_dt($m['created_at'], 'd.m.Y');
            if ($dd !== $day) { $L[] = ''; $L[] = "—— $dd ——"; $day = $dd; }
            $L[] = '';
            $L[] = '[' . fmt_dt($m['created_at'], 'H:i') . '] ' . export_author($m['role'], $c) . ':';
            foreach (explode("\n", str_replace("\r", '', $m['content'])) as $line) $L[] = '    ' . $line;
        }
        $L[] = ''; $L[] = '';
    }
    return implode("\r\n", $L);
}

function export_html(array $items): string
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $title = count($items) === 1 ? 'Диалог #' . $items[0]['dialog']['id'] . ' — ' . client_display_name($items[0]['client']) : 'Диалоги (' . count($items) . ')';
    ob_start(); ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($title) ?></title>
<style>
body{margin:0;background:#f3f5f9;color:#1d2330;font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{max-width:820px;margin:0 auto;padding:24px 16px 48px}
.top{color:#6b7587;font-size:13px;margin-bottom:16px}
section{background:#fff;border:1px solid #dde3ec;border-radius:12px;padding:18px 20px;margin-bottom:24px;page-break-after:always}
section:last-child{page-break-after:auto}
h2{margin:0 0 4px;font-size:18px}
.meta{color:#6b7587;font-size:13px;margin:0 0 14px}
.day{text-align:center;color:#6b7587;font-size:12px;margin:16px 0 8px}
.msg{margin:8px 0;padding:8px 12px;border-radius:10px;max-width:85%;white-space:pre-wrap;word-wrap:break-word}
.msg .who{display:block;font-size:12px;color:#6b7587;white-space:normal}
.msg .who b{color:#1d2330;font-weight:600}
.user{margin-left:auto;background:#e8eef8}
.assistant{background:#f4f6fa;border:1px solid #dde3ec}
.operator{background:#fff5e0;border:1px solid #f0dcb0}
@media print{body{background:#fff}main{padding:0}section{border:0;padding:0}.msg{max-width:100%}}
</style></head><body><main>
<p class="top">Выгрузка от <?= $h(fmt_dt(gmdate('Y-m-d H:i:s'))) ?> · время указано по <?= $h(tz_label()) ?> · диалогов: <?= count($items) ?></p>
<?php foreach ($items as ['dialog' => $d, 'client' => $c, 'messages' => $ms]): $day = ''; ?>
<section>
  <h2>Диалог #<?= (int)$d['id'] ?> · <?= $h(client_display_name($c)) ?></h2>
  <p class="meta"><?= $h(implode(' · ', array_slice(export_client_line($c), 1))) ?><?= count(export_client_line($c)) > 1 ? '<br>' : '' ?>
    Канал: <?= $h(CHANNEL_NAMES[$d['channel']] ?? $d['channel']) ?> · <?= $h(fmt_dt($d['started_at'])) ?> — <?= $h(fmt_dt($d['last_at'])) ?> · сообщений: <?= count($ms) ?></p>
  <?php foreach ($ms as $m): $dd = fmt_dt($m['created_at'], 'd.m.Y'); ?>
    <?php if ($dd !== $day): $day = $dd; ?><div class="day"><?= $h($dd) ?></div><?php endif; ?>
    <div class="msg <?= $h($m['role']) ?>"><span class="who"><b><?= $h(export_author($m['role'], $c)) ?></b> · <?= $h(fmt_dt($m['created_at'], 'H:i')) ?></span><?= $h(trim($m['content'])) ?></div>
  <?php endforeach; ?>
  <?php if (!$ms): ?><p class="meta">Сообщений нет.</p><?php endif; ?>
</section>
<?php endforeach; ?>
</main></body></html>
<?php
    return (string)ob_get_clean();
}

/** Отдаёт файл выгрузки браузеру и завершает работу. */
function export_send(array $ids, string $format): never
{
    $items = export_load($ids);
    if (!$items) { http_response_code(404); exit('Диалоги не найдены.'); }
    $txt = $format === 'txt';
    $name = (count($items) === 1 ? 'dialog_' . $items[0]['dialog']['id'] : 'dialogs_' . count($items)) . '_' . fmt_dt(gmdate('Y-m-d H:i:s'), 'Y-m-d_H-i') . ($txt ? '.txt' : '.html');
    header('Content-Type: ' . ($txt ? 'text/plain' : 'text/html') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    echo $txt ? "\xEF\xBB\xBF" . export_txt($items) : export_html($items);
    exit;
}
