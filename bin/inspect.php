<?php
// Что бот «думает» о диалоге — из консоли.
//
//   php bin/inspect.php                  — последние диалоги (id, канал, клиент)
//   php bin/inspect.php last             — самый свежий диалог
//   php bin/inspect.php 12               — диалог по номеру (как в админке «Диалог #12»)
//   php bin/inspect.php chat930          — диалог по чату Битрикс24
//   php bin/inspect.php 12 --prompt      — плюс полный текст запроса, который уйдёт в нейросеть на следующую реплику
//   php bin/inspect.php 12 --ask "текст" — прогнать вопрос «как если бы клиент написал» (без отправки клиенту и без записи)
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
if (is_file(__DIR__ . '/../lib/bitrix.php')) require_once __DIR__ . '/../lib/bitrix.php';

$args = array_slice($argv, 1);
$flag = fn($f) => in_array($f, $args, true);
$after = fn($f) => ($i = array_search($f, $args, true)) !== false ? ($args[$i + 1] ?? '') : null;
$names = CHANNEL_NAMES;
$pad = fn(string $s, int $n) => $s . str_repeat(' ', max(1, $n - mb_strlen($s)));
$cut = fn(string $s, int $n = 220) => mb_strimwidth(preg_replace('/\s+/u', ' ', $s), 0, $n, '…');

$target = $args[0] ?? '';
if ($target === '' || str_starts_with($target, '--')) {
    echo "Последние диалоги:\n";
    foreach (recent_dialogs('', 20) as $d) {
        printf("  #%-5d %-26s %-10s %3d сообщ.  %s  %s\n", $d['id'], $names[$d['channel']] ?? $d['channel'], $d['ext_id'],
            $d['messages'], $d['last_at'], client_display_name(array_merge($d, ['id' => $d['client_id']])));
    }
    echo "\nПодробно: php bin/inspect.php <номер>   (или last, или chat123)\n";
    exit;
}

// какой диалог
if ($target === 'last') {
    $id = (int)db()->query("SELECT id FROM dialogs WHERE messages > 0 AND channel != 'admin' ORDER BY last_at DESC LIMIT 1")->fetchColumn();
} elseif (ctype_digit($target)) {
    $id = (int)$target;
} else {
    $st = db()->prepare('SELECT id FROM dialogs WHERE ext_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$target]);
    $id = (int)$st->fetchColumn();
}
$st = db()->prepare('SELECT * FROM dialogs WHERE id = ?');
$st->execute([$id]);
$d = $st->fetch();
if (!$d) { fwrite(STDERR, "Диалог «{$target}» не найден\n"); exit(1); }
$c = get_client((int)$d['client_id']);

echo "=== Диалог #{$d['id']} · " . ($names[$d['channel']] ?? $d['channel']) . ($d['ext_id'] ? " · {$d['ext_id']}" : '') . " · начат {$d['started_at']}, последнее {$d['last_at']}\n\n";

echo "КЛИЕНТ #{$c['id']}: " . client_display_name($c) . "\n";
foreach (['first_name' => 'Имя', 'last_name' => 'Фамилия', 'phone' => 'Телефон', 'messenger' => 'Мессенджер', 'messenger_contact' => 'Контакт', 'notes' => 'Заметки'] as $f => $l) {
    echo '  ', $pad($l . ':', 12), $c[$f] !== '' ? $c[$f] : '—', "\n";
}

$r = open_request((int)$c['id']);
echo "\nЛИД" . ($r ? " #{$r['id']} — " . REQUEST_STATUSES[$r['status']] . ($r['ready'] && $r['status'] === 'draft' ? ' (клиент готов, ждём контакт)' : '') : ': не начат') . "\n";
if ($r) {
    foreach (REQUEST_FIELDS as $f => $l) echo '  ', $pad($l . ':', 32), $r[$f] !== '' ? $r[$f] : '—', "\n";
    if ($r['crm_id']) echo "  CRM: {$r['crm_type']} {$r['crm_id']}" . (function_exists('b24_crm_url') ? ' → ' . b24_crm_url($r['crm_type'], (int)$r['crm_id']) : '') . "\n";
}

// состояние чата Битрикс24: пауза, остановка
if ($d['channel'] === 'bitrix' && $d['ext_id']) {
    db()->exec('CREATE TABLE IF NOT EXISTS b24_state (k TEXT PRIMARY KEY, v TEXT NOT NULL, ts INTEGER NOT NULL)');
    $s = db()->prepare("SELECT v FROM b24_state WHERE k = ? OR k = ?");
    $s->execute(['chat:' . substr($d['ext_id'], 4), 'chat:' . $d['ext_id']]);
    $state = json_decode((string)$s->fetchColumn(), true) ?: [];
    $pause = function_exists('b24_pause_min') ? b24_pause_min() : 10;
    echo "\nСОСТОЯНИЕ БОТА В ЧАТЕ\n";
    if (!empty($state['paused'])) {
        echo "  остановлен оператором (/бот стоп) — молчит, но собирает сведения\n";
    } elseif (!empty($state['op_ts']) && ($left = $pause * 60 - (time() - (int)$state['op_ts'])) > 0) {
        printf("  оператор писал %d мин назад — бот молчит ещё %d мин\n", (time() - (int)$state['op_ts']) / 60, ceil($left / 60));
    } else {
        echo "  отвечает клиенту\n";
    }
}

// переписка + время ответа
echo "\nПЕРЕПИСКА (последние 30)\n";
$label = ['user' => 'Клиент', 'assistant' => 'Бот', 'operator' => 'Менеджер'];
$prevUserTs = null;
foreach (dialog_messages((int)$d['id'], 30) as $m) {
    $ts = strtotime($m['created_at'] . ' UTC');
    $lat = '';
    if ($m['role'] === 'user') $prevUserTs = $ts;
    elseif ($m['role'] === 'assistant' && $prevUserTs) { $lat = ' (+' . ($ts - $prevUserTs) . ' с)'; $prevUserTs = null; }
    echo '  ', substr($m['created_at'], 11, 5), ' ', $pad(($label[$m['role']] ?? $m['role']) . $lat, 16), $cut($m['content']), "\n";
}

// что уйдёт в нейросеть
if ($flag('--prompt') || $after('--ask') !== null) {
    $q = $after('--ask');
    $history = dialog_messages((int)$d['id'], (int)cfg('history_turns', 8) * 2);
    if ($q === null) {
        // показать запрос для последней реплики клиента
        for ($i = count($history) - 1; $i >= 0; $i--) if ($history[$i]['role'] === 'user') { $q = $history[$i]['content']; $history = array_slice($history, 0, $i); break; }
    }
    $q = (string)$q;
    $t0 = microtime(true);
    $chunks = retrieve($q);
    $tSearch = microtime(true) - $t0;
    $msgs = build_messages($q, array_map(fn($h) => ['role' => $h['role'], 'content' => $h['content']], $history), $chunks, $c, $r, $d['channel']);
    echo "\nЗАПРОС В НЕЙРОСЕТЬ для «{$cut($q, 80)}» (поиск по материалам: " . round($tSearch, 1) . " с, фрагментов: " . count($chunks) . ")\n";
    foreach ($msgs as $i => $m) {
        echo "\n--- [$i] {$m['role']} (" . mb_strlen($m['content']) . " симв.)\n";
        echo ($i === 0 && !$flag('--full')) ? $cut($m['content'], 4000) . "\n" : $m['content'] . "\n";
    }
    echo "\nИтого ≈ " . array_sum(array_map(fn($m) => mb_strlen($m['content']), $msgs)) . " символов. Модель: " . cfg('chat_model', 'gpt-5-mini') . "\n";

    if ($after('--ask') !== null) {
        // пробный ответ — в отдельный тестовый диалог, клиенту ничего не уходит
        $tc = client_by_key('admin:inspect');
        update_client((int)$tc['id'], array_intersect_key($c, array_flip(CLIENT_FIELDS)));
        $td = current_dialog((int)$tc['id'], 'admin', true);
        foreach ($history as $h) save_message((int)$tc['id'], $td, 'admin', $h['role'], $h['content']);
        $t0 = microtime(true);
        $reply = answer((int)$tc['id'], $td, 'admin', $q);
        $tm = answer_timing();
        printf("\nОТВЕТ БОТА (%.1f с: поиск %.1f, нейросеть %.1f, %s, %d токенов):\n%s\n", microtime(true) - $t0, $tm['search'] ?? 0, $tm['llm'] ?? 0, $tm['model'] ?? '?', $tm['tokens'] ?? 0, $reply);
        delete_client((int)$tc['id']);
    }
}
