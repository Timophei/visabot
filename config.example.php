<?php
// Скопируйте этот файл в config.php и заполните.
// config.php в git не попадает (.gitignore) — ключи остаются только на сервере.
// Новые параметры можно дописывать в уже существующий config.php: всё, чего нет, берётся по умолчанию.

return [
    // --- OpenAI ---
    'openai_api_key'   => 'sk-...',
    'openai_base_url'  => 'https://api.openai.com/v1', // если сервер в РФ и OpenAI блокирует — адрес вашего прокси/шлюза
    'chat_model'       => 'gpt-5-mini',            // модель для ответов
    'reasoning_effort' => 'low',   // для моделей gpt-5*/o*: low = быстрее и дешевле; null — не передавать (для gpt-4o и т.п.)
    'embedding_model'  => 'text-embedding-3-small', // модель для смыслового поиска по учебникам
    'use_embeddings'   => true,   // false = только полнотекстовый поиск
    'temperature'      => null,   // null = по умолчанию модели
    'max_output_tokens'=> 4000,   // у «думающих» моделей сюда входят и токены размышлений — меньше 2000 не ставьте

    // --- Поиск по учебникам ---
    'top_k'            => 6,
    'chunk_chars'      => 1200,
    'chunk_overlap'    => 200,
    'history_turns'    => 8,      // сколько последних пар реплик диалога помнить

    // --- Telegram ---
    'telegram_token'    => '',     // токен от @BotFather
    'telegram_bot_username' => '', // имя бота без @ — для кнопки «Войти через Telegram» на сайте
    'telegram_secret'   => 'придумайте-длинную-строку', // проверяется в заголовке вебхука

    // --- Менеджеры ---
    // Куда слать новые запросы: id Telegram-чатов менеджеров или рабочей группы.
    // Узнать id: написать боту /id (в личке или в группе, куда добавлен бот).
    'manager_chat_ids' => [],
    'public_url'       => 'https://visabot.b-integration.ru', // для ссылки на запрос в уведомлении

    // --- Битрикс24: бот-консультант в открытых линиях (параллельно с оператором) ---
    // Входящий вебхук портала с правами imbot, imopenlines, im, crm
    'bitrix_webhook'   => '',      // https://портал/rest/1/xxxxxxxxxxxx/
    'bitrix_bot_token' => '',      // придумайте строку до 38 символов (ключ ботов)
    'bitrix_secret'    => '',      // длинная случайная строка — защищает адрес обработчика
    'bitrix_bot_name'  => 'Визовый консультант',
    // ↓ заполнит вывод php bin/bitrix-register.php
    'bitrix_bot_id'        => 0,   // «Консультант» — участник чатов
    'bitrix_router_bot_id' => 0,   // «Приёмщик» — чат-бот линии
    'bitrix_api'           => 'v2',
    'bitrix_operator_pause_min' => 10, // бот молчит N минут после реплики оператора (меняется и в админке)
    'bitrix_crm_mode'  => 'comments',  // 'comments' — сведения текстом в комментарий лида; 'fields' — в поля «Виза: …»
    'bitrix_transfer_queue_id'  => 0,  // 0 — обычная очередь этой линии; иначе id линии/очереди

    // --- Сайт и виджет ---
    'site_title'       => 'Визовый консультант',
    'accent_color'     => '#1f4e8c',
    'privacy_url'      => '',     // ссылка на политику обработки персональных данных (152-ФЗ)
    'allowed_origins'  => ['https://visa-example.ru'], // другие сайты, где стоит виджет
    'rate_limit_per_min' => 20,

    // --- Админка ---
    // Хеш пароля: php -r 'echo password_hash("ваш-пароль", PASSWORD_DEFAULT), PHP_EOL;'
    // Вставлять в ОДИНАРНЫХ кавычках.
    'admin_password_hash' => '',

    // --- Пути ---
    'data_dir' => __DIR__ . '/data',
];
