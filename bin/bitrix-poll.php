<?php
// Сборщик событий Битрикс24 (режим fetch): наш сервер сам забирает новые сообщения из очереди ботов
// и обрабатывает их. Входящий доступ от портала к серверу бота не нужен — подходит для коробок за фаерволом.
//
//   php bin/bitrix-poll.php          — работать постоянно (запускается как служба systemd, см. deploy/visabot-b24.service)
//   php bin/bitrix-poll.php --once   — один проход (для проверки)
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/bitrix.php';
require __DIR__ . '/../lib/bitrix_handler.php';

$once = in_array('--once', $argv, true);
$dataDir = cfg('data_dir', BOT_ROOT . '/data');

// не даём запустить два сборщика одновременно
$lock = fopen($dataDir . '/b24-poll.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Сборщик уже запущен\n"); exit(1); }

if (!cfg('bitrix_bot_id')) { fwrite(STDERR, "Боты не зарегистрированы (bitrix_bot_id пусто)\n"); exit(1); }
b24_log('сборщик событий запущен');
// сообщения, которые обрабатывались в момент прошлой остановки, — обработать заново
b24_state_init();
$n = db()->exec("DELETE FROM b24_state WHERE k LIKE 'msg:%' AND v = 'processing'");
if ($n) b24_log("незавершённых сообщений с прошлого запуска: $n — обработаю заново");

$idle = 0;
do {
    $got = 0;
    foreach (['router', 'consultant'] as $bot) {   // приёмщик первым: он добавляет консультанта в новые чаты
        [$botId, $token] = b24_bot($bot);
        if (!$botId) continue;
        try {
            do {
                $offKey = 'b24_offset_' . $bot;
                $params = ['botId' => $botId, 'botToken' => $token, 'limit' => 50];
                $off = setting($offKey);
                if ($off !== '') $params['offset'] = (int)$off;
                $res = b24('imbot.v2.Event.get', $params)['result'] ?? [];
                $events = $res['events'] ?? [];
                foreach ($events as $ev) {
                    $payload = ['event' => (string)($ev['type'] ?? $ev['event'] ?? ''), 'data' => $ev['data'] ?? []];
                    if (empty($payload['data']['bot']['id'])) $payload['data']['bot']['id'] = $botId;
                    try {
                        b24_handle($payload);
                    } catch (Throwable $t) {
                        b24_log('сборщик: ошибка обработки события: ' . $t->getMessage());
                    }
                    $got++;
                }
                if ($events && isset($res['nextOffset'])) set_setting($offKey, (string)$res['nextOffset']);
            } while (!empty($res['hasMore']));
        } catch (Throwable $e) {
            b24_log("сборщик ($bot): " . $e->getMessage());
            sleep(10);
        }
    }
    @touch($dataDir . '/b24-poll.heartbeat');
    if ($once) break;
    // пока идёт переписка — опрашиваем часто, в тишине — реже
    $idle = $got ? 0 : min($idle + 1, 5);
    sleep($idle < 5 ? 1 : 2);
} while (true);
