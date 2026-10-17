<?php
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/bitrix.php';
require __DIR__ . '/../lib/bitrix_handler.php';

/*
 * Приём событий ботов Битрикс24 в режиме webhook (bitrix_event_mode = webhook).
 * Для коробок, которые не могут обращаться к нашему серверу, используйте режим fetch (bin/bitrix-poll.php).
 */

$secret = (string)cfg('bitrix_secret', '');
if ($secret === '' || !hash_equals($secret, (string)($_GET['s'] ?? ''))) { http_response_code(403); exit; }

// Отвечаем Битриксу сразу, работаем дальше в фоне
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

b24_handle($_POST);
