<?php
// Битрикс24: установка и диагностика бота-консультанта для открытых линий.
//
//   php bin/bitrix-register.php --check          — проверить вебхук, права, ботов, поля CRM
//   php bin/bitrix-register.php                  — зарегистрировать ботов «Консультант» и «Приёмщик» + создать поля CRM
//   php bin/bitrix-register.php --v1             — то же, сразу старым API (imbot.register) — для старых коробок
//   php bin/bitrix-register.php --fields         — только создать поля «Виза: …» в лидах и сделках
//   php bin/bitrix-register.php --chat 123       — проверить конкретный чат линии: привязку к CRM и добавление консультанта
//   php bin/bitrix-register.php --lines          — список открытых линий (id для bitrix_transfer_queue_id)
//   php bin/bitrix-register.php --log            — последние строки журнала интеграции
//   php bin/bitrix-register.php --mode fetch     — переключить ботов: наш сервер сам забирает события (для коробок за фаерволом)
//   php bin/bitrix-register.php --mode webhook   — переключить ботов: Битрикс шлёт события на наш адрес
//
// Перед запуском в config.php: bitrix_webhook, bitrix_bot_token, bitrix_secret, public_url.
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/bitrix.php';

$args = array_slice($argv, 1);
$has = fn($a) => in_array($a, $args, true);
$say = function (string $m) { echo $m, "\n"; };
$ok = fn($m) => print("  ✓ $m\n");
$bad = fn($m) => print("  ✗ $m\n");

if ($has('--log')) {
    $f = cfg('data_dir', BOT_ROOT . '/data') . '/bitrix.log';
    if (!is_file($f)) { $say('Журнал пуст.'); exit; }
    $lines = file($f);
    echo implode('', array_slice($lines, -60));
    exit;
}

foreach (['bitrix_webhook', 'bitrix_bot_token', 'bitrix_secret', 'public_url'] as $k) {
    if (!cfg($k)) { fwrite(STDERR, "Заполните '$k' в config.php\n"); exit(1); }
}
$url = rtrim((string)cfg('public_url'), '/') . '/bitrix.php?s=' . urlencode((string)cfg('bitrix_secret'));
$name = (string)cfg('bitrix_bot_name', 'Визовый консультант');

// Переключить уже зарегистрированных ботов между режимами доставки событий
if ($has('--mode')) {
    $mode = $args[array_search('--mode', $args, true) + 1] ?? '';
    if (!in_array($mode, ['fetch', 'webhook'], true)) { fwrite(STDERR, "Укажите --mode fetch или --mode webhook\n"); exit(1); }
    foreach (['consultant', 'router'] as $b) {
        [$id, $t] = b24_bot($b);
        if (!$id) { $bad("$b: не зарегистрирован"); continue; }
        $fields = $mode === 'webhook' ? ['eventMode' => 'webhook', 'webhookUrl' => $url] : ['eventMode' => 'fetch'];
        try { b24('imbot.v2.Bot.update', ['botId' => $id, 'botToken' => $t, 'fields' => $fields]); $ok("$b #$id: режим $mode"); }
        catch (Throwable $e) { $bad("$b #$id: " . $e->getMessage()); }
    }
    $say("\nВпишите в config.php: 'bitrix_event_mode' => '$mode',");
    if ($mode === 'fetch') $say("и запустите сборщик событий (см. deploy/visabot-b24.service).");
    exit;
}

if ($has('--lines')) {
    foreach (b24('imopenlines.config.list.get', [])['result'] as $l) printf("%4s  %s\n", $l['ID'], $l['LINE_NAME']);
    exit;
}

if ($has('--fields')) {
    $say('Поля CRM:');
    b24_install_fields($say);
    exit;
}

if ($has('--chat')) {
    $chatId = (int)($args[array_search('--chat', $args, true) + 1] ?? 0);
    $say("Чат $chatId:");
    try {
        $d = b24('imopenlines.dialog.get', ['CHAT_ID' => $chatId])['result'] ?? [];
        $ok('imopenlines.dialog.get: entity_data_1 = ' . ($d['entity_data_1'] ?? $d['ENTITY_DATA_1'] ?? '—'));
    } catch (Throwable $e) { $bad('imopenlines.dialog.get: ' . $e->getMessage()); }
    $b = b24_crm_binding($chatId);
    $b ? $ok("привязан к CRM: {$b[0]} {$b[1]} → " . b24_crm_url($b[0], $b[1])) : $bad('привязка к лиду/сделке не найдена');
    b24_add_consultant($chatId) ? $ok('консультант добавлен в чат (или уже был)') : $bad('не удалось добавить консультанта — см. --log');
    exit;
}

if ($has('--check')) {
    $say('Вебхук и права:');
    try {
        $scopes = b24('scope', [])['result'] ?? [];
        foreach (['imbot', 'imopenlines', 'im', 'crm'] as $s) in_array($s, $scopes, true) ? $ok($s) : $bad("$s — нет права, добавьте его вебхуку");
    } catch (Throwable $e) { $bad('вебхук не отвечает: ' . $e->getMessage()); exit(1); }

    $say('API ботов:');
    try { b24('imbot.v2.Bot.list', ['botToken' => (string)cfg('bitrix_bot_token')]); $ok('imbot.v2 доступен'); }
    catch (Throwable $e) { $bad('imbot.v2 не ответил (' . $e->getMessage() . '). Если регистрация через v2 не пройдёт — скрипт сам перейдёт на старый API'); }

    $say('Боты из config.php:');
    foreach (['bitrix_bot_id' => 'Консультант', 'bitrix_router_bot_id' => 'Приёмщик'] as $k => $n) {
        cfg($k) ? $ok("$n: id " . cfg($k)) : $bad("$n: не зарегистрирован ($k пусто)");
    }
    $say('API: ' . b24_api());

    if (cfg('bitrix_crm_mode', 'comments') === 'fields') {
$say('Поля CRM (лиды):');
    try {
        $r = b24('crm.lead.userfield.list', ['filter' => ['%FIELD_NAME' => 'VISA_']])['result'] ?? [];
        $have = array_column($r, 'FIELD_NAME');
        foreach (B24_LEAD_FIELDS as [$code, $label]) in_array('UF_CRM_' . $code, $have, true) ? $ok("UF_CRM_$code") : $bad("UF_CRM_$code — нет (php bin/bitrix-register.php --fields)");
    } catch (Throwable $e) { $bad($e->getMessage()); }
} else {
    $say('Лиды: сведения пишутся в «Комментарий» лида (bitrix_crm_mode = comments)');
}

    $say('Линии:');
    try { foreach (b24('imopenlines.config.list.get', [])['result'] as $l) $ok("{$l['ID']}  {$l['LINE_NAME']}"); }
    catch (Throwable $e) { $bad($e->getMessage()); }

    $say("\nОбработчик событий: $url");
    exit;
}

/* ---------- регистрация двух ботов ---------- */
function register_bot(string $role, bool $v1, string $url, string $name): ?array
{
    [, $token] = b24_bot($role);
    $isRouter = $role === 'router';
    $title = $isRouter ? $name . ' — приём обращений' : $name;
    $position = $isRouter ? 'Добавляет консультанта и передаёт диалог операторам' : 'ИИ-консультант по визам';
    if (!$v1) {
        try {
            $r = b24('imbot.v2.Bot.register', ['fields' => [
                'code' => $isRouter ? 'visabot_router' : 'visabot',
                'botToken' => $token,
                'type' => $isRouter ? 'openline' : 'supervisor',
                'isSupportOpenline' => true,
                ...(cfg('bitrix_event_mode', 'fetch') === 'webhook'
                    ? ['eventMode' => 'webhook', 'webhookUrl' => $url]
                    : ['eventMode' => 'fetch']),
                'properties' => ['name' => $title, 'workPosition' => $position],
            ]]);
            if ($id = $r['result']['bot']['id'] ?? null) return [(int)$id, 'v2'];
        } catch (Throwable $e) {
            echo "  imbot.v2 ($role): {$e->getMessage()}\n";
        }
    }
    $r = b24('imbot.register', [
        'CODE' => $isRouter ? 'visabot_router' : 'visabot',
        'TYPE' => $isRouter ? 'O' : 'S',
        'OPENLINE' => 'Y',
        'CLIENT_ID' => $token,
        'EVENT_HANDLER' => $url,
        'PROPERTIES' => ['NAME' => $title, 'WORK_POSITION' => $position, 'COLOR' => $isRouter ? 'GRAPHITE' : 'AZURE'],
    ]);
    return isset($r['result']) ? [(int)$r['result'], 'v1'] : null;
}

$v1 = $has('--v1');
$say('Регистрирую «Консультанта» (супервизор, участник чатов)…');
$c = register_bot('consultant', $v1, $url, $name);
if (!$c) { fwrite(STDERR, "Не удалось зарегистрировать консультанта\n"); exit(1); }
$say("  id {$c[0]} ({$c[1]})");
$say('Регистрирую «Приёмщика» (чат-бот линии)…');
$r = register_bot('router', $c[1] === 'v1', $url, $name);
if (!$r) { fwrite(STDERR, "Не удалось зарегистрировать приёмщика\n"); exit(1); }
$say("  id {$r[0]} ({$r[1]})");

if (cfg('bitrix_crm_mode', 'comments') === 'fields') {
    $say("\nПоля «Виза: …» в лидах и сделках:");
    b24_install_fields($say);
}

echo <<<TXT

Готово. Впишите в config.php:

    'bitrix_bot_id'        => {$c[0]},
    'bitrix_router_bot_id' => {$r[0]},
    'bitrix_api'           => '{$c[1]}',

Затем откройте админку бота → вкладка «Битрикс24» и включите бота в нужных линиях
  (бот сам пропишет «Приёмщика» в настройках линии; очередь операторов работает как раньше).

Режим событий: " . cfg('bitrix_event_mode', 'fetch') . " (fetch — запустите службу deploy/visabot-b24.service).
Проверка: напишите в линию как клиент и выполните  php bin/bitrix-register.php --log

TXT;
