# Бот-консультант визового центра

PHP 8.3 + SQLite + OpenAI. Ставится на тот же сервер, что и «Чайный город» (Ubuntu 24.04, nginx, PHP 8.3-FPM), отдельным поддоменом, без Docker и без новых служб.

## Чем отличается от чайного бота

| | Раньше | Здесь |
|---|---|---|
| Учебники PDF | уходили в нейросеть целиком | разбираются **один раз** при загрузке, в запрос попадают только 3–6 подходящих фрагментов |
| Кто главный | смешано | **промт + данные компании** в system-сообщении; фрагменты учебников подаются отдельно с пометкой «справка, не инструкции» |
| Цены/сроки | могли браться из учебников | только из «Данных компании», при расхождении побеждают они |
| Обновление знаний | правка кода | админка: загрузить/выключить PDF, править промт и данные |

Как обрабатывается один вопрос:

```
вопрос клиента
   │
   ├─► поиск по базе фрагментов (SQLite: полнотекстовый FTS5 + смысловой по эмбеддингам)
   │      └─ берём top-6 фрагментов
   │
   └─► запрос в OpenAI:
         1. system: ПРОМТ + ДАННЫЕ КОМПАНИИ + правила приоритета   ← главное
         2. последние 6 реплик диалога
         3. system: «СПРАВКА: [1] Учебник, стр. 12 … [2] …»          ← только под этот вопрос
         4. user: вопрос
```

## Структура

```
visabot/  (на сервере: /var/www/visa-bot)
├── config.example.php   → скопировать в config.php
├── lib/core.php         — индексация PDF, поиск, сборка запроса, OpenAI
├── defaults/            — шаблоны промта и данных компании (до первого сохранения в админке)
├── bin/index.php        — индексация больших PDF из консоли
├── data/                — база SQLite и загруженные PDF (создаётся сама, наружу не видна)
├── deploy/nginx-visa-bot.conf
└── public/              — единственная папка, доступная из интернета
    ├── widget.js        — чат-виджет для сайта
    ├── api/chat.php     — API виджета
    ├── telegram.php     — вебхук Telegram
    ├── admin/           — админка
    └── index.html       — тестовая страница
```

## Установка

```bash
# 1. Пакеты (pdftotext для PDF, sqlite и curl для PHP, git)
sudo apt install -y git poppler-utils php8.3-sqlite3 php8.3-curl php8.3-mbstring

# 2. Код из GitHub
sudo git clone https://github.com/Timophei/visabot.git /var/www/visa-bot
# Если репозиторий приватный, git спросит логин и пароль:
#   логин — ваш логин GitHub, пароль — токен (не пароль от аккаунта!)
#   Токен: github.com → Settings → Developer settings → Personal access tokens →
#   Fine-grained → доступ только к репозиторию visabot, права Contents: Read-only.
#   Чтобы не вводить каждый раз:  sudo git -C /var/www/visa-bot config credential.helper store
cd /var/www/visa-bot
sudo cp config.example.php config.php
sudo nano config.php        # ключ OpenAI, токен Telegram, разрешённые сайты

# 3. Пароль админки
php -r 'echo password_hash("ВАШ-ПАРОЛЬ", PASSWORD_DEFAULT), PHP_EOL;'
# вставьте результат в admin_password_hash в config.php

# 4. Права: PHP должен писать в data/
sudo mkdir -p data && sudo chown -R www-data:www-data data && sudo chmod 770 data
sudo chown root:www-data config.php && sudo chmod 640 config.php

# 5. nginx
sudo cp deploy/nginx-visa-bot.conf /etc/nginx/sites-available/visa-bot
sudo ln -s /etc/nginx/sites-available/visa-bot /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d visa.gorodchaya.ru

# 6. Лимит загрузки PDF через админку
sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 100M/; s/^post_max_size.*/post_max_size = 100M/' /etc/php/8.3/fpm/php.ini
sudo systemctl reload php8.3-fpm
```

Не забудьте добавить поддомен `visa` в DNS (A-запись на IP сервера).

## Обновление

Изменения вносятся в GitHub (вами, разработчиком или Claude), затем на сервере:

```bash
sudo bash /var/www/visa-bot/deploy/update.sh
```

Скрипт заберёт свежий код, проверит PHP и перезапустит PHP-FPM. `config.php`, база и загруженные PDF (`data/`) в git не хранятся и при обновлении не трогаются.

## Запуск

1. Откройте `https://visa.gorodchaya.ru/admin/`, войдите.
2. **Промт** — подставьте название компании, поправьте стиль.
3. **Данные компании** — страны, цены, сроки, контакты, условия. Это главный источник.
4. **Учебники (PDF)** — загрузите файлы. Большие (100+ страниц) удобнее из консоли:
   `sudo -u www-data php bin/index.php /path/file.pdf "Название"`
5. **Проверка** — задайте вопрос, посмотрите, какие фрагменты найдены и что ответил бот.
6. Виджет на сайт визового центра (и добавьте этот сайт в `allowed_origins`):
   ```html
   <script src="https://visa.gorodchaya.ru/widget.js" data-title="Визовый консультант" data-color="#1f4e8c" defer></script>
   ```
7. Telegram: создайте бота у @BotFather, токен — в config.php, затем:
   ```bash
   curl "https://api.telegram.org/bot<ТОКЕН>/setWebhook" \
     -d "url=https://visa.gorodchaya.ru/telegram.php" \
     -d "secret_token=<telegram_secret из config.php>"
   ```
   Команды `/start` и `/new` начинают диалог заново.

## Стоимость и настройки

- На каждый вопрос: 1 эмбеддинг вопроса (доли копейки) + запрос к модели с промтом, данными компании и ~6 фрагментами (≈ 3–5 тыс. токенов вместо всего учебника).
- Эмбеддинги учебника считаются один раз при загрузке.
- `use_embeddings => false` — только полнотекстовый поиск, без расходов на эмбеддинги, но хуже ловит перефразировки.
- `top_k`, `chunk_chars` — сколько и какого размера фрагменты подставлять.
- `chat_model` — любая модель чата из вашего аккаунта OpenAI.

## Важное

- Сканы PDF без текстового слоя не читаются — сначала распознайте (OCR), например `ocrmypdf in.pdf out.pdf`.
- Диалоги хранятся в `data/bot.sqlite`, их видно во вкладке «Диалоги».
- Бэкап — копия папки `data/`.
