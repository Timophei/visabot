<?php
// Регистрация чат-бота открытых линий в Битрикс24.
//   php bin/bitrix-register.php            — зарегистрировать (imbot.v2, при недоступности — imbot.register)
//   php bin/bitrix-register.php --v1       — сразу старым методом imbot.register
//   php bin/bitrix-register.php --lines    — показать открытые линии портала (id для bitrix_transfer_queue_id)
//
// Перед запуском в config.php: bitrix_webhook, bitrix_bot_token, bitrix_secret, public_url.
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/bitrix.php';

$args = array_slice($argv, 1);
foreach (['bitrix_webhook', 'bitrix_bot_token', 'bitrix_secret', 'public_url'] as $k) {
    if (!cfg($k)) { fwrite(STDERR, "Заполните '$k' в config.php\n"); exit(1); }
}
$url = rtrim((string)cfg('public_url'), '/') . '/bitrix.php?s=' . urlencode((string)cfg('bitrix_secret'));
$name = (string)cfg('bitrix_bot_name', 'Визовый консультант');

if (in_array('--lines', $args, true)) {
    $r = b24('imopenlines.config.list.get', []);
    foreach ($r['result'] as $l) printf("%4s  %s\n", $l['ID'], $l['LINE_NAME']);
    exit;
}

$botId = null;
$api = 'v2';
if (!in_array('--v1', $args, true)) {
    try {
        $r = b24('imbot.v2.Bot.register', ['fields' => [
            'code'              => 'visabot',
            'botToken'          => (string)cfg('bitrix_bot_token'),
            'type'              => 'openline',
            'isSupportOpenline' => true,
            'eventMode'         => 'webhook',
            'webhookUrl'        => $url,
            'properties'        => ['name' => $name, 'workPosition' => 'ИИ-консультант по визам'],
        ]]);
        $botId = $r['result']['bot']['id'] ?? null;
    } catch (Throwable $e) {
        echo "imbot.v2 недоступен или ошибка: {$e->getMessage()}\nПробую старый метод imbot.register…\n";
    }
}
if (!$botId) {
    $api = 'v1';
    $r = b24('imbot.register', [
        'CODE'          => 'visabot',
        'TYPE'          => 'O',
        'OPENLINE'      => 'Y',
        'CLIENT_ID'     => (string)cfg('bitrix_bot_token'),
        'EVENT_HANDLER' => $url,
        'PROPERTIES'    => ['NAME' => $name, 'WORK_POSITION' => 'ИИ-консультант по визам', 'COLOR' => 'AZURE'],
    ]);
    $botId = $r['result'] ?? null;
}
if (!$botId) { fwrite(STDERR, "Не удалось зарегистрировать бота: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }

echo "\nГотово! Бот зарегистрирован ($api), id = $botId\n\n";
echo "1) Впишите в config.php:  'bitrix_bot_id' => $botId,\n";
echo "2) В Битрикс24: Контакт-центр → ваша открытая линия → Настройки → Чат-бот →\n";
echo "   включите «Назначить чат-бота при поступлении нового обращения» и выберите «{$name}».\n";
echo "   Автоперевод на оператора по времени лучше выключить или поставить с запасом: бот сам передаст диалог оператору, когда запрос готов.\n";
echo "3) Напишите в линию как клиент и проверьте ответ.\n";
