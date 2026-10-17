<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/core.php';

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
$tab = $_GET['tab'] ?? 'prompt';
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
                $f = $_FILES['pdf'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Файл не загрузился (код ' . ($f['error'] ?? '?') . '). Проверьте лимиты upload_max_filesize / client_max_body_size.');
                if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'pdf') throw new RuntimeException('Нужен PDF.');
                $dir = cfg('data_dir') . '/pdf';
                if (!is_dir($dir)) mkdir($dir, 0770, true);
                $dest = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
                move_uploaded_file($f['tmp_name'], $dest);
                $title = trim((string)($_POST['title'] ?? '')) ?: pathinfo($f['name'], PATHINFO_FILENAME);
                $info = '';
                index_pdf($dest, $title, function ($m) use (&$info) { $info = $m; });
                back('docs', "«{$title}» проиндексирован: {$info}.");
            case 'toggle':
                db()->prepare('UPDATE documents SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['id']]);
                back('docs');
            case 'delete':
                $st = db()->prepare('SELECT filename FROM documents WHERE id = ?'); $st->execute([(int)$_POST['id']]);
                if ($fn = $st->fetchColumn()) @unlink(cfg('data_dir') . '/pdf/' . basename($fn));
                delete_document((int)$_POST['id']);
                back('docs', 'Удалено.');
            case 'test':
                $tab = 'test';
                $q = trim((string)$_POST['q']);
                $test = ['q' => $q, 'chunks' => retrieve($q)];
                if (!empty($_POST['with_answer'])) $test['answer'] = answer('admin-test:' . session_id(), 'admin', $q);
                break;
            case 'reset_test':
                reset_session('admin-test:' . session_id());
                back('test', 'История тестового диалога очищена.');
        }
    } catch (Throwable $e) {
        if ($do === 'test') { $test['error'] = $e->getMessage(); }
        else back($_POST['tab'] ?? $tab, 'Ошибка: ' . $e->getMessage());
    }
}

$docs = db()->query('SELECT * FROM documents ORDER BY id DESC')->fetchAll();
$tabs = ['prompt' => 'Промт', 'company' => 'Данные компании', 'docs' => 'Учебники (PDF)', 'test' => 'Проверка', 'log' => 'Диалоги'];
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
    <p class="hint">Главная инструкция. Всё, что найдено в учебниках, подчиняется этому промту и данным компании.</p>
    <textarea name="system_prompt" rows="22"><?= h(setting('system_prompt')) ?></textarea>
    <h2>Приветствие</h2>
    <textarea name="greeting" rows="3"><?= h(setting('greeting')) ?></textarea>
    <button>Сохранить</button>
  </form>

<?php elseif ($tab === 'company'): ?>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="save_settings"><input type="hidden" name="tab" value="company">
    <h2>Данные компании</h2>
    <p class="hint">Цены, сроки, страны, контакты, условия. Отправляются в каждом запросе и важнее учебников: если в PDF другие цифры, бот назовёт эти. Держите текст компактным — до 2–3 страниц.</p>
    <textarea name="company_data" rows="28"><?= h(setting('company_data')) ?></textarea>
    <p class="hint">≈ <?= number_format(mb_strlen(setting('company_data'))) ?> символов</p>
    <button>Сохранить</button>
  </form>

<?php elseif ($tab === 'docs'): ?>
  <form method="post" enctype="multipart/form-data" class="card" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Индексирую… не закрывайте страницу'">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="upload">
    <h2>Загрузить учебник</h2>
    <p class="hint">PDF разбирается один раз: текст режется на фрагменты и индексируется. В запросы к нейросети попадают только фрагменты, подходящие к вопросу. Сканы без текстового слоя нужно сначала распознать.</p>
    <label>Название (как бот будет понимать источник)<input name="title" placeholder="Напр.: Шенген — требования к документам, 2026"></label>
    <label>PDF-файл<input type="file" name="pdf" accept="application/pdf" required></label>
    <button>Загрузить и проиндексировать</button>
  </form>
  <div class="card">
    <h2>Учебники в базе</h2>
    <?php if (!$docs): ?><p class="hint">Пока пусто.</p><?php else: ?>
    <table><tr><th>Название</th><th>Стр.</th><th>Фрагм.</th><th>Поиск</th><th>Добавлен</th><th></th></tr>
    <?php foreach ($docs as $d): ?>
      <tr class="<?= $d['active'] ? '' : 'off' ?>">
        <td><?= h($d['title']) ?></td><td><?= $d['pages'] ?></td><td><?= $d['chunks'] ?></td>
        <td><?= $d['has_embeddings'] ? 'смысловой + текст' : 'текстовый' ?></td><td><?= h($d['created_at']) ?></td>
        <td class="act">
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="sec"><?= $d['active'] ? 'Выключить' : 'Включить' ?></button></form>
          <form method="post" onsubmit="return confirm('Удалить учебник из базы?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="danger">Удалить</button></form>
        </td>
      </tr>
    <?php endforeach; ?></table><?php endif; ?>
  </div>

<?php elseif ($tab === 'test'): ?>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="test">
    <h2>Проверка</h2>
    <p class="hint">Покажет, какие фрагменты учебников бот найдёт под вопрос. С галочкой — ещё и ответ бота (тестовый диалог помнит контекст).</p>
    <textarea name="q" rows="3" required placeholder="Какие документы нужны на туристическую визу в Испанию?"><?= h($test['q'] ?? '') ?></textarea>
    <label class="row"><input type="checkbox" name="with_answer" value="1" checked> Получить ответ бота</label>
    <button>Проверить</button>
  </form>
  <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="reset_test"><button class="sec">Очистить тестовый диалог</button></form>
  <?php if ($test): ?>
    <?php if (!empty($test['error'])): ?><p class="err"><?= h($test['error']) ?></p><?php endif; ?>
    <?php if (isset($test['answer'])): ?><div class="card"><h2>Ответ бота</h2><div class="answer"><?= nl2br(h($test['answer'])) ?></div></div><?php endif; ?>
    <div class="card"><h2>Найденные фрагменты (<?= count($test['chunks'] ?? []) ?>)</h2>
      <?php foreach ($test['chunks'] ?? [] as $i => $c): ?>
        <details><summary>[<?= $i + 1 ?>] <?= h($c['title']) ?>, стр. <?= $c['page'] ?></summary><pre><?= h($c['text']) ?></pre></details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'log'): ?>
  <div class="card"><h2>Последние сообщения</h2>
  <?php $rows = db()->query("SELECT * FROM messages WHERE channel != 'admin' ORDER BY id DESC LIMIT 200")->fetchAll(); ?>
  <?php if (!$rows): ?><p class="hint">Пока нет диалогов.</p><?php endif; ?>
  <table><?php foreach ($rows as $r): ?>
    <tr><td class="nw"><?= h($r['created_at']) ?><br><small><?= h($r['channel']) ?> · <?= h(substr($r['session'], -6)) ?></small></td>
    <td class="<?= $r['role'] ?>"><b><?= $r['role'] === 'user' ? 'Клиент' : 'Бот' ?>:</b> <?= nl2br(h($r['content'])) ?></td></tr>
  <?php endforeach; ?></table></div>
<?php endif; ?>
</main>
</body></html>
