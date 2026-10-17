<?php
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';

$title = (string)cfg('site_title', 'Визовый консультант');
$color = (string)cfg('accent_color', '#1f4e8c');
if (!preg_match('/^#[0-9a-f]{3,8}$/i', $color)) $color = '#1f4e8c';
$tgBot = cfg('telegram_token') ? (string)cfg('telegram_bot_username', '') : '';
$h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?= $h($color) ?>">
<title><?= $h($title) ?></title>
<style>
  :root { --c: <?= $h($color) ?>; }
  * { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; }
  body { display: flex; flex-direction: column; background: #f4f6fa; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1d2330; }
  .top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 10px 16px; background: #fff; border-bottom: 1px solid #e2e7ef; }
  .brand { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 200px; }
  .logo { width: 36px; height: 36px; border-radius: 10px; background: var(--c); color: #fff; display: grid; place-items: center; flex: none; }
  .logo svg { width: 20px; height: 20px; }
  .brand b { display: block; font-size: 16px; }
  .brand small { color: #6b7587; }
  #tg-login { font-size: 13px; color: #6b7587; min-height: 28px; display: flex; align-items: center; gap: 8px; }
  #tg-login a { color: var(--c); }
  #chat { flex: 1; min-height: 0; }
  .tg-link { color: var(--c); text-decoration: none; font-size: 14px; white-space: nowrap; }
  #tg-login:empty { display: none; }
  @media (max-width: 600px) {
    .top { padding: 8px 12px; gap: 8px; }
    .brand { min-width: 0; }
    .brand small { display: none; }
    .logo { width: 30px; height: 30px; }
    #tg-login { width: 100%; }
  }
</style>
</head>
<body>
  <header class="top">
    <div class="brand">
      <div class="logo" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm6.9 6h-2.9a15.7 15.7 0 0 0-1.4-3.6A8 8 0 0 1 18.9 8zM12 4c.8 1.2 1.5 2.5 1.9 4h-3.8c.4-1.5 1.1-2.8 1.9-4zM4.3 14a8.2 8.2 0 0 1 0-4h3.4a16.5 16.5 0 0 0 0 4H4.3zm.8 2h2.9c.3 1.3.8 2.5 1.4 3.6A8 8 0 0 1 5.1 16zM8 8H5.1a8 8 0 0 1 4.3-3.6C8.8 5.5 8.3 6.7 8 8zm4 12c-.8-1.2-1.5-2.5-1.9-4h3.8c-.4 1.5-1.1 2.8-1.9 4zm2.3-6H9.7a14.7 14.7 0 0 1 0-4h4.6a14.7 14.7 0 0 1 0 4zm.3 5.6c.6-1.1 1.1-2.3 1.4-3.6h2.9a8 8 0 0 1-4.3 3.6zm1.7-5.6a16.5 16.5 0 0 0 0-4h3.4a8.2 8.2 0 0 1 0 4h-3.4z"/></svg></div>
      <div><b><?= $h($title) ?></b><small>Ответим на вопросы о визах, документах, ценах и сроках</small></div>
    </div>
    <?php if ($tgBot): ?>
      <a class="tg-link" href="https://t.me/<?= $h($tgBot) ?>" target="_blank" rel="noopener">Открыть в Telegram</a>
    <?php endif; ?>
    <div id="tg-login"></div>
  </header>
  <main id="chat"></main>
  <script src="widget.js" data-embed="#chat" data-tg-slot="#tg-login" data-title="Чат с консультантом" data-color="<?= $h($color) ?>" defer></script>
</body>
</html>
