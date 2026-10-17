<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/core.php';
require __DIR__ . '/../../lib/bitrix.php';

session_name('visabot_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function back(string $tab, string $flash = ''): never {
    if ($flash) $_SESSION['flash'] = $flash;
    header('Location: ?tab=' . urlencode($tab)); exit;
}

/* ---------- вход ---------- */
if (isset($_GET['logout'])) { session_destroy(); header('Location: ./'); exit; }
if (empty($_SESSION['ok'])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        usleep(400000); // замедляем перебор паролей
        if (cfg('admin_password_hash') && password_verify((string)($_POST['password'] ?? ''), cfg('admin_password_hash'))) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            header('Location: ./'); exit;
        }
        $err = cfg('admin_password_hash') ? 'Неверный пароль' : 'Пароль не задан в config.php (admin_password_hash)';
    }
    ?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Вход — визовый бот</title><link rel="stylesheet" href="admin.css">
    <body class="login"><form method="post" class="card"><h1>Визовый бот</h1>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <label>Пароль<input type="password" name="password" autofocus required></label><button>Войти</button></form></body></html><?php
    exit;
}

/* ---------- действия ---------- */
$tab = $_GET['tab'] ?? 'requests';
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$test = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('CSRF'); }
    $do = $_POST['do'] ?? '';
    try {
        switch ($do) {
            case 'save_settings':
                foreach (['system_prompt', 'company_data', 'greeting'] as $k) {
                    if (isset($_POST[$k])) set_setting($k, str_replace("\r\n", "\n", (string)$_POST[$k]));
                }
                back($_POST['tab'] ?? 'prompt', 'Сохранено. Бот уже отвечает по новой версии.');
            case 'upload':
                set_time_limit(0);
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Файл не загрузился (код ' . ($f['error'] ?? '?') . '). Проверьте лимиты upload_max_filesize / client_max_body_size.');
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, SUPPORTED_EXT, true)) throw new RuntimeException("Формат .$ext не поддерживается. Можно: " . implode(', ', SUPPORTED_EXT));
                $dir = cfg('data_dir') . '/files';
                if (!is_dir($dir)) mkdir($dir, 0770, true);
                $dest = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                move_uploaded_file($f['tmp_name'], $dest);
                $title = trim((string)($_POST['title'] ?? '')) ?: pathinfo($f['name'], PATHINFO_FILENAME);
                $info = '';
                try {
                    index_file($dest, $f['name'], $title, (string)($_POST['trust'] ?? 'main'), function ($m) use (&$info) { $info = $m; });
                } catch (Throwable $e) { @unlink($dest); throw $e; }
                back('docs', "«{$title}» проиндексирован: {$info}.");
            case 'toggle':
                db()->prepare('UPDATE documents SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['id']]);
                back('docs');
            case 'trust':
                db()->prepare("UPDATE documents SET trust = CASE trust WHEN 'aux' THEN 'main' ELSE 'aux' END WHERE id = ?")->execute([(int)$_POST['id']]);
                back('docs');
            case 'delete':
                $st = db()->prepare('SELECT filename FROM documents WHERE id = ?'); $st->execute([(int)$_POST['id']]);
                if ($fn = $st->fetchColumn()) { @unlink(cfg('data_dir') . '/files/' . basename($fn)); @unlink(cfg('data_dir') . '/pdf/' . basename($fn)); }
                delete_document((int)$_POST['id']);
                back('docs', 'Удалено.');
            case 'test':
                $tab = 'test';
                $q = trim((string)$_POST['q']);
                $test = ['q' => $q, 'chunks' => retrieve($q)];
                if (!empty($_POST['with_answer'])) {
                    $tc = client_by_key('admin:test');
                    $test['answer'] = answer((int)$tc['id'], current_dialog((int)$tc['id'], 'admin'), 'admin', $q);
                    $test['client'] = get_client((int)$tc['id']);
                }
                break;
            case 'reset_test':
                delete_client((int)client_by_key('admin:test')['id']);
                back('test', 'Тестовый диалог и тестовый клиент очищены.');
            case 'save_client':
                $id = (int)$_POST['id'];
                $fields = array_intersect_key($_POST, array_flip(CLIENT_FIELDS));
                $client = get_client($id);
                // Ручная правка: разрешаем и очищать поля
                foreach (CLIENT_FIELDS as $f) {
                    if (isset($fields[$f]) && trim((string)$fields[$f]) === '' && $client[$f] !== '') {
                        db()->prepare("UPDATE clients SET $f = '' WHERE id = ?")->execute([$id]);
                    }
                }
                update_client($id, $fields + ['notes' => str_replace("\r\n", "\n", (string)($_POST['notes'] ?? ''))]);
                header('Location: ?tab=clients&id=' . $id); $_SESSION['flash'] = 'Карточка сохранена.'; exit;
            case 'save_request':
                $id = (int)$_POST['id'];
                $data = array_intersect_key($_POST, REQUEST_FIELDS + ['status' => 1]);
                $data['manager_note'] = str_replace("\r\n", "\n", (string)($_POST['manager_note'] ?? ''));
                update_request($id, array_map(fn($v) => str_replace("\r\n", "\n", (string)$v), $data), true);
                $_SESSION['flash'] = 'Лид сохранён.';
                header('Location: ?tab=requests&id=' . $id); exit;
            case 'b24_line':
                b24_line_set((int)$_POST['line'], $_POST['on'] === '1');
                back('bitrix', 'Линия ' . (int)$_POST['line'] . ': бот ' . ($_POST['on'] === '1' ? 'включён' : 'выключен') . '.');
            case 'b24_pause':
                set_setting('b24_pause', (string)max(0, (int)$_POST['pause']));
                back('bitrix', 'Пауза сохранена.');
            case 'delete_client':
                delete_client((int)$_POST['id']);
                back('clients', 'Клиент и все его диалоги удалены.');
        }
    } catch (Throwable $e) {
        if ($do === 'test') { $test['error'] = $e->getMessage(); }
        else back($_POST['tab'] ?? $tab, 'Ошибка: ' . $e->getMessage());
    }
}

$docs = db()->query('SELECT * FROM documents ORDER BY id DESC')->fetchAll();
$tabs = ['requests' => 'Лиды', 'clients' => 'Клиенты', 'prompt' => 'Промт', 'company' => 'Данные компании', 'docs' => 'Материалы', 'test' => 'Проверка'];
$channelName = CHANNEL_NAMES;
if (cfg('bitrix_webhook')) $tabs['bitrix'] = 'Битрикс24';
?><!doctype html>
<html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Админка — визовый бот</title><link rel="stylesheet" href="admin.css">
<body>
<header><b>Визовый бот</b>
  <nav><?php foreach ($tabs as $k => $v): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $v ?></a><?php endforeach; ?></nav>
  <a href="?logout=1" class="out">Выйти</a>
</header>
<main>
<?php if ($flash): ?><p class="<?= str_starts_with($flash, 'Ошибка') ? 'err' : 'ok' ?>"><?= h($flash) ?></p><?php endif; ?>

<?php if ($tab === 'prompt'): ?>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="save_settings"><input type="hidden" name="tab" value="prompt">
    <h2>Промт — роль и правила бота</h2>
    <p class="hint">Главная инструкция. Всё, что найдено в материалах, подчиняется этому промту и данным компании.</p>
    <textarea name="system_prompt" rows="22"><?= h(setting('system_prompt')) ?></textarea>
    <h2>Приветствие</h2>
    <textarea name="greeting" rows="3"><?= h(setting('greeting')) ?></textarea>
    <button>Сохранить</button>
  </form>

<?php elseif ($tab === 'company'): ?>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="save_settings"><input type="hidden" name="tab" value="company">
    <h2>Данные компании</h2>
    <p class="hint">Цены, сроки, страны, контакты, условия. Отправляются в каждом запросе и важнее материалов: если в них другие цифры, бот назовёт эти. Держите текст компактным — до 2–3 страниц.</p>
    <textarea name="company_data" rows="28"><?= h(setting('company_data')) ?></textarea>
    <p class="hint">≈ <?= number_format(mb_strlen(setting('company_data'))) ?> символов</p>
    <button>Сохранить</button>
  </form>

<?php elseif ($tab === 'docs'): ?>
  <form method="post" enctype="multipart/form-data" class="card" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Индексирую… не закрывайте страницу'">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="upload">
    <h2>Загрузить материал</h2>
    <p class="hint">Файл разбирается один раз: текст режется на фрагменты и индексируется. В запросы к нейросети попадают только фрагменты, подходящие к вопросу.</p>
    <ul class="hint formats">
      <li><b>PDF, Word (.docx), TXT, Markdown</b> — учебники, регламенты, инструкции. Сканы PDF без текста нужно сначала распознать.</li>
      <li><b>HTML</b> — сохранённые страницы сайтов и баз знаний.</li>
      <li><b>JSON / JSONL</b> — пары «вопрос–ответ», выгрузки чатов ChatGPT/Claude, любые структуры.</li>
      <li><b>Excel (.xlsx), CSV</b> — таблицы: каждая строка читается как «Колонка: значение».</li>
      <li><b>ZIP</b> — архив с любыми файлами выше, например выгрузка базы знаний Битрикс24 целиком.</li>
    </ul>
    <label>Название (как бот будет понимать источник)<input name="title" placeholder="Напр.: База знаний Битрикс24, октябрь 2026"></label>
    <label>Файл<input type="file" name="file" accept=".<?= implode(',.', SUPPORTED_EXT) ?>" required></label>
    <label>Насколько доверять
      <select name="trust">
        <option value="main">Проверенный — учебники, база знаний компании, регламенты</option>
        <option value="aux">Вспомогательный — ответы других нейросетей, черновики, непроверенное</option>
      </select>
    </label>
    <button>Загрузить и проиндексировать</button>
  </form>
  <div class="card">
    <h2>Материалы в базе</h2>
    <?php if (!$docs): ?><p class="hint">Пока пусто.</p><?php else: ?>
    <table><tr><th>Название</th><th>Формат</th><th>Разделов</th><th>Фрагм.</th><th>Доверие</th><th>Добавлен</th><th></th></tr>
    <?php foreach ($docs as $d): ?>
      <tr class="<?= $d['active'] ? '' : 'off' ?>">
        <td><?= h($d['title']) ?></td><td><?= h(strtoupper($d['format'])) ?></td><td><?= $d['pages'] ?></td><td><?= $d['chunks'] ?></td>
        <td><?= $d['trust'] === 'aux' ? 'вспомогательный' : 'проверенный' ?></td><td class="nw"><?= h($d['created_at']) ?></td>
        <td class="act">
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="sec"><?= $d['active'] ? 'Выключить' : 'Включить' ?></button></form>
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="trust"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="sec"><?= $d['trust'] === 'aux' ? 'Сделать проверенным' : 'Сделать вспомогат.' ?></button></form>
          <form method="post" onsubmit="return confirm('Удалить материал из базы?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="danger">Удалить</button></form>
        </td>
      </tr>
    <?php endforeach; ?></table><?php endif; ?>
  </div>

<?php elseif ($tab === 'test'): ?>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="test">
    <h2>Проверка</h2>
    <p class="hint">Покажет, какие фрагменты материалов бот найдёт под вопрос. С галочкой — ещё и ответ бота (тестовый диалог помнит контекст).</p>
    <textarea name="q" rows="3" required placeholder="Какие документы нужны на туристическую визу в Испанию?"><?= h($test['q'] ?? '') ?></textarea>
    <label class="row"><input type="checkbox" name="with_answer" value="1" checked> Получить ответ бота</label>
    <button>Проверить</button>
  </form>
  <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="reset_test"><button class="sec">Очистить тестовый диалог</button></form>
  <?php if ($test): ?>
    <?php if (!empty($test['error'])): ?><p class="err"><?= h($test['error']) ?></p><?php endif; ?>
    <?php if (isset($test['answer'])): ?><div class="card"><h2>Ответ бота</h2><div class="answer"><?= nl2br(h($test['answer'])) ?></div></div><?php endif; ?>
    <?php if (isset($test['client'])): $tc = $test['client']; ?>
      <div class="card"><h2>Что бот узнал о тестовом клиенте</h2>
        <p class="hint">Имя: <?= h($tc['first_name'] ?: '—') ?> · Фамилия: <?= h($tc['last_name'] ?: '—') ?> · Телефон: <?= h($tc['phone'] ?: '—') ?> · Мессенджер: <?= h($tc['messenger'] ? (MESSENGERS[$tc['messenger']] ?? $tc['messenger']) . ' ' . $tc['messenger_contact'] : '—') ?></p>
        <?php if ($tr = (client_requests((int)$tc['id'])[0] ?? null)): ?>
          <p class="hint"><b>Лид</b> (<?= h(REQUEST_STATUSES[$tr['status']]) ?><?= $tr['ready'] && $tr['status'] === 'draft' ? ', клиент готов — ждём контакт' : '' ?>):
          <?php foreach (REQUEST_FIELDS as $f => $l): ?><?= $tr[$f] !== '' ? h($l) . ': ' . h($tr[$f]) . ' · ' : '' ?><?php endforeach; ?></p>
          <p class="hint">В «Проверке» лид менеджерам и в CRM не отправляется.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="card"><h2>Найденные фрагменты (<?= count($test['chunks'] ?? []) ?>)</h2>
      <?php foreach ($test['chunks'] ?? [] as $i => $c): ?>
        <details><summary>[<?= $i + 1 ?>] <?= h(chunk_label($c)) ?><?= $c['trust'] === 'aux' ? ' · вспомогательный' : '' ?></summary><pre><?= h($c['text']) ?></pre></details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'bitrix'): ?>
  <?php
    $b24err = '';
    $lines = [];
    try { $lines = b24_lines_status(); } catch (Throwable $e) { $b24err = $e->getMessage(); }
    $botOk = cfg('bitrix_bot_id') && cfg('bitrix_router_bot_id');
  ?>
  <div class="card">
    <h2>Подключение</h2>
    <p class="hint">Портал: <?= h(b24_portal()) ?> ·
      <?= $b24err ? '<b class="bad">вебхук не отвечает: ' . h($b24err) . '</b>' : '<span class="good">вебхук работает</span>' ?> ·
      боты: <?= $botOk ? '<span class="good">Консультант #' . (int)cfg('bitrix_bot_id') . ', Приёмщик #' . (int)cfg('bitrix_router_bot_id') . ' (' . h(b24_api()) . ')</span>' : '<b class="bad">не зарегистрированы — php bin/bitrix-register.php</b>' ?> ·
      лиды: <?= cfg('bitrix_crm_mode', 'comments') === 'fields' ? 'поля «Виза: …»' : 'текстом в комментарий' ?> ·
      события: <?php if (cfg('bitrix_event_mode', 'fetch') === 'webhook'): ?>Битрикс присылает на наш адрес<?php else:
        $hb = @filemtime(cfg('data_dir', BOT_ROOT . '/data') . '/b24-poll.heartbeat');
        echo $hb && time() - $hb < 30 ? '<span class="good">сборщик работает</span>' : '<b class="bad">сборщик не запущен' . ($hb ? ' (последний раз ' . date('d.m H:i', $hb) . ')' : '') . ' — sudo systemctl start visabot-b24</b>';
      endif; ?></p>
    <form method="post" class="row-form">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="b24_pause">
      <label>Бот молчит после реплики оператора, минут <input type="number" name="pause" min="0" max="1440" value="<?= b24_pause_min() ?>"></label>
      <button>Сохранить</button>
    </form>
  </div>
  <div class="card">
    <h2>Открытые линии</h2>
    <p class="hint">Включение прописывает «Приёмщика» чат-ботом линии: он добавляет Консультанта в каждый новый диалог и сразу отдаёт диалог в обычную очередь операторов. Подключения каналов не меняются.</p>
    <?php if ($lines): ?>
    <table>
      <tr><th>#</th><th>Линия</th><th>Чат-бот в настройках линии</th><th>Бот работает</th><th></th></tr>
      <?php foreach ($lines as $l): ?>
        <tr class="<?= $l['active'] ? '' : 'off' ?>">
          <td><?= $l['id'] ?></td>
          <td><?= h($l['name']) ?><?= $l['active'] ? '' : ' <small>(линия выключена)</small>' ?></td>
          <td><?= ['ours' => '<span class="good">наш Приёмщик</span>', 'other' => '<b class="bad">другой бот #' . $l['b24_bot_id'] . '</b>', 'none' => '—'][$l['b24_bot']] ?></td>
          <td><?= $l['enabled'] && $l['b24_bot'] === 'ours' ? '<span class="st st-done">да</span>' : ($l['enabled'] ? '<span class="st st-new">включён у нас, но не в линии</span>' : '<span class="st">нет</span>') ?></td>
          <td class="act">
            <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="b24_line"><input type="hidden" name="line" value="<?= $l['id'] ?>">
              <?php if ($l['enabled']): ?><input type="hidden" name="on" value="0"><button class="sec">Выключить</button>
              <?php else: ?><input type="hidden" name="on" value="1"><button <?= $botOk ? '' : 'disabled' ?> <?= $l['b24_bot'] === 'other' ? "onclick=\"return confirm('В этой линии уже назначен другой чат-бот. Заменить его нашим?')\"" : '' ?>>Включить</button><?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Журнал (последние события)</h2>
    <?php $logf = cfg('data_dir', BOT_ROOT . '/data') . '/bitrix.log'; $log = is_file($logf) ? array_slice(file($logf), -60) : []; ?>
    <?php if (!$log): ?><p class="hint">Пока пусто. Напишите в линию как клиент.</p><?php else: ?><pre class="log"><?= h(implode('', array_reverse($log))) ?></pre><?php endif; ?>
  </div>

<?php elseif ($tab === 'requests' && isset($_GET['id']) && ($r = get_request((int)$_GET['id']))): $c = get_client((int)$r['client_id']); ?>
  <p><a href="?tab=requests">← Все лиды</a></p>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="save_request"><input type="hidden" name="id" value="<?= $r['id'] ?>">
    <h2>Лид #<?= $r['id'] ?><?= $r['country'] ? ' — ' . h($r['country']) : '' ?></h2>
    <p class="hint">Создан <?= h($r['created_at']) ?><?= $r['submitted_at'] ? ' · передан менеджеру ' . h($r['submitted_at']) : '' ?> · обновлён <?= h($r['updated_at']) ?> · канал: <?= h($channelName[$r['channel']] ?? $r['channel']) ?><?= $r['status'] === 'draft' && $r['ready'] ? ' · <b>клиент готов, но не оставил контакт</b>' : '' ?></p>
    <div class="contact">
      <b><a href="?tab=clients&id=<?= $c['id'] ?>"><?= h(client_display_name($c)) ?></a></b>
      <?php if ($c['phone']): ?> · <a href="tel:<?= h($c['phone']) ?>"><?= h($c['phone']) ?></a><?php endif; ?>
      <?php if ($c['messenger']): ?> · <?= h((MESSENGERS[$c['messenger']] ?? $c['messenger']) . ' ' . $c['messenger_contact']) ?><?php endif; ?>
      <?php if ($c['tg_username']): ?> · <a href="https://t.me/<?= h($c['tg_username']) ?>" target="_blank">Telegram @<?= h($c['tg_username']) ?></a><?php endif; ?>
      · <a href="?tab=clients&id=<?= $c['id'] ?>">переписка →</a>
      <?php if ($r['crm_id']): ?> · <a href="<?= h(b24_crm_url($r['crm_type'], (int)$r['crm_id'])) ?>" target="_blank"><?= $r['crm_type'] === 'DEAL' ? 'сделка' : 'лид' ?> в Битрикс24 #<?= (int)$r['crm_id'] ?></a><?php endif; ?>
    </div>
    <label>Статус<select name="status"><?php foreach (REQUEST_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
    <div class="grid">
      <?php foreach (REQUEST_FIELDS as $f => $l): if ($f === 'circumstances') continue; ?>
        <label><?= h($l) ?><input name="<?= $f ?>" value="<?= h($r[$f]) ?>"></label>
      <?php endforeach; ?>
    </div>
    <label><?= h(REQUEST_FIELDS['circumstances']) ?><textarea name="circumstances" rows="3"><?= h($r['circumstances']) ?></textarea></label>
    <label>Заметка менеджера<textarea name="manager_note" rows="3"><?= h($r['manager_note']) ?></textarea></label>
    <button>Сохранить</button>
  </form>

<?php elseif ($tab === 'requests'): ?>
  <?php
    $filter = $_GET['status'] ?? 'open';
    $filters = ['open' => 'Новые и в работе', 'draft' => 'Собираются', 'done' => 'Оформлены', 'cancelled' => 'Отменены', '' => 'Все'];
    $reqs = list_requests($filter);
  ?>
  <nav class="chips"><?php foreach ($filters as $k => $v): ?><a href="?tab=requests&status=<?= $k ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $v ?></a><?php endforeach; ?></nav>
  <div class="card">
    <h2>Лиды (<?= count($reqs) ?>)</h2>
    <?php if (!cfg('manager_chat_ids')): ?><p class="hint">Telegram-уведомления менеджерам выключены: впишите id чатов в <code>manager_chat_ids</code> в config.php (id можно узнать командой /id у бота).</p><?php endif; ?>
    <?php if (!$reqs): ?><p class="hint">Пока пусто. Лид появляется, когда клиент в диалоге рассказывает о поездке; менеджеру он передаётся, когда клиент готов оформлять и оставил контакт.</p><?php else: ?>
    <table class="clients">
      <tr><th>#</th><th>Клиент</th><th>Направление</th><th>Даты</th><th>Заявит.</th><th>Город</th><th>Статус</th><th>Обновлён</th></tr>
      <?php foreach ($reqs as $r): ?>
        <tr onclick="location='?tab=requests&id=<?= $r['id'] ?>'">
          <td><a href="?tab=requests&id=<?= $r['id'] ?>"><?= $r['id'] ?></a><?php if ($r['crm_id']): ?><br><small>B24 #<?= (int)$r['crm_id'] ?></small><?php endif; ?></td>
          <td><?= h(client_display_name(array_merge($r, ['id' => $r['client_id']]))) ?><br><small><?= h($r['phone'] ?: ($r['messenger_contact'] ?: '')) ?></small></td>
          <td><?= h(trim($r['country'] . ($r['purpose'] ? ', ' . $r['purpose'] : ''))) ?: '—' ?></td>
          <td><?= h($r['dates'] ?: '—') ?></td><td><?= h($r['applicants'] ?: '—') ?></td><td><?= h($r['city'] ?: '—') ?></td>
          <td><span class="st st-<?= $r['status'] ?>"><?= h(REQUEST_STATUSES[$r['status']]) ?></span></td>
          <td class="nw"><?= h($r['updated_at']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table><?php endif; ?>
  </div>

<?php elseif ($tab === 'clients' && isset($_GET['id']) && ($c = get_client((int)$_GET['id']))): ?>
  <p><a href="?tab=clients">← Все клиенты</a></p>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="save_client"><input type="hidden" name="id" value="<?= $c['id'] ?>">
    <h2><?= h(client_display_name($c)) ?></h2>
    <p class="hint">Создан <?= h($c['created_at']) ?> · последний визит <?= h($c['last_seen_at']) ?><?= $c['tg_id'] ? ' · Telegram id ' . h((string)$c['tg_id']) . ($c['tg_username'] ? ' (<a href="https://t.me/' . h($c['tg_username']) . '" target="_blank">@' . h($c['tg_username']) . '</a>)' : '') : '' ?></p>
    <div class="grid">
      <label>Имя<input name="first_name" value="<?= h($c['first_name']) ?>"></label>
      <label>Фамилия<input name="last_name" value="<?= h($c['last_name']) ?>"></label>
      <label>Телефон<input name="phone" value="<?= h($c['phone']) ?>"><?php if ($c['phone']): ?> <a href="tel:<?= h($c['phone']) ?>">позвонить</a><?php endif; ?></label>
      <label>Мессенджер<select name="messenger"><option value="">—</option><?php foreach (MESSENGERS as $k => $v): ?><option value="<?= $k ?>" <?= $c['messenger'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
      <label>Контакт в мессенджере<input name="messenger_contact" value="<?= h($c['messenger_contact']) ?>" placeholder="@username или номер"></label>
    </div>
    <label>Заметки менеджера<textarea name="notes" rows="3"><?= h($c['notes']) ?></textarea></label>
    <button>Сохранить</button>
  </form>
  <form method="post" class="inline" onsubmit="return confirm('Удалить клиента и все его диалоги? Это нельзя отменить.')">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="delete_client"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="danger">Удалить клиента и переписку</button>
  </form>
  <?php $creqs = client_requests((int)$c['id']); if ($creqs): ?>
    <div class="card"><h2>Лиды клиента</h2><table>
      <?php foreach ($creqs as $r): ?>
        <tr><td><a href="?tab=requests&id=<?= $r['id'] ?>">#<?= $r['id'] ?> <?= h($r['country'] ?: 'без страны') ?></a></td><td><?= h($r['dates']) ?></td><td><span class="st st-<?= $r['status'] ?>"><?= h(REQUEST_STATUSES[$r['status']]) ?></span></td><td class="nw"><?= h($r['updated_at']) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  <?php endif; ?>
  <?php $dialogs = client_dialogs((int)$c['id']); ?>
  <?php if (!$dialogs): ?><p class="hint">Диалогов нет.</p><?php endif; ?>
  <?php foreach ($dialogs as $i => $d): ?>
    <details class="card dialog" <?= $i === 0 ? 'open' : '' ?>>
      <summary>Диалог #<?= $d['id'] ?> · <?= h($channelName[$d['channel']] ?? $d['channel']) ?> · <?= h($d['started_at']) ?> — <?= h($d['last_at']) ?> · <?= (int)$d['messages'] ?> сообщ.</summary>
      <div class="chatlog"><?php foreach (dialog_messages((int)$d['id']) as $m): ?>
        <div class="msg <?= $m['role'] ?>"><small><?= ['user' => 'Клиент', 'operator' => 'Менеджер', 'assistant' => 'Бот'][$m['role']] ?? $m['role'] ?> · <?= h($m['created_at']) ?></small><?= nl2br(h($m['content'])) ?></div>
      <?php endforeach; ?></div>
    </details>
  <?php endforeach; ?>

<?php elseif ($tab === 'clients'): ?>
  <?php $q = trim((string)($_GET['q'] ?? '')); $clients = list_clients($q); ?>
  <form method="get" class="card search">
    <input type="hidden" name="tab" value="clients">
    <input name="q" value="<?= h($q) ?>" placeholder="Поиск: имя, телефон, @ник, заметки">
    <button>Найти</button>
  </form>
  <div class="card">
    <h2>Клиенты<?= $q !== '' ? ' — поиск' : '' ?> (<?= count($clients) ?>)</h2>
    <?php if (!$clients): ?><p class="hint">Пока никого. Клиент появляется после первого сообщения на сайте или в Telegram.</p><?php else: ?>
    <table class="clients">
      <tr><th>Клиент</th><th>Телефон</th><th>Мессенджер</th><th>Диалогов</th><th>Последний визит</th></tr>
      <?php foreach ($clients as $c): ?>
        <tr onclick="location='?tab=clients&id=<?= $c['id'] ?>'">
          <td><a href="?tab=clients&id=<?= $c['id'] ?>"><?= h(client_display_name($c)) ?></a></td>
          <td class="nw"><?= h($c['phone'] ?: '—') ?></td>
          <td><?= h($c['messenger'] ? (MESSENGERS[$c['messenger']] ?? $c['messenger']) . ' ' . $c['messenger_contact'] : '—') ?></td>
          <td><?= (int)$c['dialogs_count'] ?> / <?= (int)$c['messages_count'] ?> сообщ.</td>
          <td class="nw"><?= h($c['last_seen_at']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table><?php endif; ?>
  </div>
<?php endif; ?>
</main>
</body></html>
