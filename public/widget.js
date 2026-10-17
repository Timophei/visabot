/*
 * Чат консультанта визового центра.
 *
 * 1) Плавающая кнопка на любом сайте (сайт должен быть в allowed_origins в config.php):
 *    <script src="https://visabot.b-integration.ru/widget.js" data-title="Визовый консультант" data-color="#1f4e8c" defer></script>
 *
 * 2) Встроенный чат во всю область (как на главной странице бота):
 *    <div id="chat"></div>
 *    <script src="widget.js" data-embed="#chat" data-tg-slot="#tg-login" defer></script>
 */
(function () {
  'use strict';
  var script = document.currentScript;
  var base = script.src.replace(/widget\.js(\?.*)?$/, '');
  var api = base + 'api/chat.php';
  var title = script.getAttribute('data-title') || 'Визовый консультант';
  var color = script.getAttribute('data-color') || '#1f4e8c';
  var embedSel = script.getAttribute('data-embed');
  var tgSlotSel = script.getAttribute('data-tg-slot');
  var embed = !!embedSel;
  var suggestions = ['Нужна виза в Шенген', 'Какие документы нужны?', 'Сколько стоит оформление?', 'Хочу записаться на подачу'];

  // --- идентификатор посетителя (дублирует cookie на случай, если браузер режет сторонние cookie) ---
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };
  var uid = store.get('visabot_uid');

  function call(body) {
    if (uid) body.uid = uid;
    return fetch(api, {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    }).then(function (r) { return r.json(); })
      .then(function (d) { if (d.uid && d.uid !== uid) { uid = d.uid; store.set('visabot_uid', uid); } return d; })
      .catch(function () { return { error: 'Нет связи с сервером. Проверьте интернет и попробуйте ещё раз.' }; });
  }

  function esc(s) {
    return s.replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  // Мини-разметка: **жирный**, списки «- », ссылки, переносы строк
  function render(text) {
    var s = esc(text);
    s = s.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
    s = s.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
    s = s.replace(/^\s*[-•*]\s+(.*)$/gm, '<li>$1</li>');
    s = s.replace(/(?:<li>.*<\/li>\n?)+/g, function (m) { return '<ul>' + m.replace(/\n/g, '') + '</ul>'; });
    return s.replace(/\n/g, '<br>');
  }

  // --- разметка ---
  var host;
  if (embed) {
    host = document.querySelector(embedSel);
    if (!host) return;
  } else {
    host = document.createElement('div');
    host.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:2147483000';
    document.body.appendChild(host);
  }
  var root = host.attachShadow({ mode: 'open' });
  root.innerHTML =
    '<style>' +
    ':host{all:initial;display:block;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d2330;--c:' + color + '}' +
    (embed ? ':host{height:100%}' : '') +
    '*{box-sizing:border-box}' +
    '.btn{width:60px;height:60px;border-radius:50%;border:0;background:var(--c);color:#fff;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);display:grid;place-items:center}' +
    '.btn svg{width:28px;height:28px}' +
    '.panel{display:flex;flex-direction:column;overflow:hidden;background:#fff}' +
    (embed
      ? '.panel{height:100%}'
      : '.panel{display:none;position:absolute;right:0;bottom:76px;width:min(390px,calc(100vw - 32px));height:min(600px,calc(100vh - 120px));border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25)}.panel.open{display:flex}') +
    '.head{background:var(--c);color:#fff;padding:12px 16px;display:flex;align-items:center;gap:10px}' +
    '.head .t{flex:1;font-weight:600}' +
    '.head button{background:rgba(255,255,255,.16);border:0;color:#fff;cursor:pointer;font:inherit;font-size:13px;padding:5px 10px;border-radius:8px}' +
    '.head button:hover{background:rgba(255,255,255,.28)}' +
    '.log{flex:1;overflow-y:auto;padding:18px 14px;background:#f4f6fa}' +
    '.inner{max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:10px}' +
    '.m{max-width:85%;padding:10px 14px;border-radius:14px;word-wrap:break-word;overflow-wrap:anywhere}' +
    '.m ul{margin:4px 0;padding-left:20px}.m li{margin:2px 0}.m a{color:inherit}' +
    '.assistant{background:#fff;border:1px solid #e2e7ef;align-self:flex-start;border-bottom-left-radius:4px}' +
    '.user{background:var(--c);color:#fff;align-self:flex-end;border-bottom-right-radius:4px;white-space:pre-wrap}' +
    '.err{background:#fdecec;color:#a32020;align-self:center;font-size:14px}' +
    '.typing{display:flex;gap:4px;padding:14px}.typing i{width:7px;height:7px;border-radius:50%;background:#9aa4b5;animation:b 1.2s infinite}' +
    '.typing i:nth-child(2){animation-delay:.15s}.typing i:nth-child(3){animation-delay:.3s}' +
    '@keyframes b{0%,60%,100%{opacity:.3;transform:none}30%{opacity:1;transform:translateY(-3px)}}' +
    '.chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}' +
    '.chips button{border:1px solid var(--c);color:var(--c);background:#fff;border-radius:18px;padding:6px 12px;font:inherit;font-size:14px;cursor:pointer}' +
    '.chips button:hover{background:var(--c);color:#fff}' +
    '.bottom{border-top:1px solid #e2e7ef;background:#fff;padding:10px 14px}' +
    'form{max-width:760px;margin:0 auto;display:flex;gap:8px;align-items:flex-end}' +
    'textarea{flex:1;resize:none;border:1px solid #cdd5e1;border-radius:12px;padding:10px 12px;font:inherit;line-height:1.4;max-height:140px;min-height:44px}' +
    'textarea:focus{outline:2px solid var(--c);outline-offset:-1px;border-color:transparent}' +
    'form button{border:0;border-radius:12px;background:var(--c);color:#fff;width:46px;height:44px;cursor:pointer;display:grid;place-items:center;flex:none}' +
    'form button:disabled{opacity:.45;cursor:default}form button svg{width:20px;height:20px}' +
    '.note{max-width:760px;margin:6px auto 0;font-size:12px;color:#7a8496;text-align:center}.note a{color:inherit}' +
    '</style>' +
    '<div class="panel" role="' + (embed ? 'region' : 'dialog') + '" aria-label="' + esc(title) + '">' +
    '  <div class="head"><span class="t">' + esc(title) + '</span><button class="new" type="button">Новый диалог</button>' +
    (embed ? '' : '<button class="close" type="button" aria-label="Закрыть">✕</button>') + '</div>' +
    '  <div class="log" aria-live="polite"><div class="inner"></div></div>' +
    '  <div class="bottom"><form><textarea rows="1" placeholder="Напишите вопрос…" aria-label="Ваш вопрос"></textarea>' +
    '    <button type="submit" aria-label="Отправить"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 20.5 21 12 3 3.5 3 10l12 2-12 2z"/></svg></button></form>' +
    '    <div class="note">Консультант — ИИ и может ошибаться. Не отправляйте в чат паспортные и платёжные данные.<span class="pp"></span></div></div>' +
    '</div>' +
    (embed ? '' : '<button class="btn" type="button" aria-label="Открыть чат с консультантом"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"/></svg></button>');

  var panel = root.querySelector('.panel'), log = root.querySelector('.log'), inner = root.querySelector('.inner');
  var form = root.querySelector('form'), input = root.querySelector('textarea'), send = form.querySelector('button');
  var started = false, busy = false;

  function scroll() { log.scrollTop = log.scrollHeight; }
  function add(text, role) {
    var d = document.createElement('div');
    d.className = 'm ' + role;
    if (role === 'user') d.textContent = text; else d.innerHTML = render(text);
    inner.appendChild(d); scroll(); return d;
  }
  function chips() {
    var c = document.createElement('div'); c.className = 'chips';
    suggestions.forEach(function (s) {
      var b = document.createElement('button'); b.type = 'button'; b.textContent = s;
      b.onclick = function () { input.value = s; form.requestSubmit(); };
      c.appendChild(b);
    });
    inner.appendChild(c); scroll();
  }
  function showMessages(list) {
    inner.innerHTML = '';
    list.forEach(function (m) { add(m.text, m.role); });
    if (list.length === 1 && list[0].role === 'assistant') chips();
  }
  function grow() { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 140) + 'px'; }

  // --- вход через Telegram (только во встроенном режиме, на домене бота) ---
  function renderTg(info) {
    var slot = tgSlotSel && document.querySelector(tgSlotSel);
    if (!slot) return;
    slot.innerHTML = '';
    if (info.client && info.client.telegram) {
      slot.textContent = 'Вы вошли через Telegram: ' + info.client.telegram;
      return;
    }
    if (!info.tg_bot) return;
    window.visabotTgAuth = function (user) {
      call({ action: 'tg_login', data: user }).then(function (r) {
        if (r.error) { add(r.error, 'err'); return; }
        renderTg({ client: r.client });
        if (r.client && r.client.name) add('Отлично, ' + r.client.name + '! Теперь я вас запомнил — можно продолжать и здесь, и в Telegram.', 'assistant');
      });
    };
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://telegram.org/js/telegram-widget.js?22';
    s.setAttribute('data-telegram-login', info.tg_bot);
    s.setAttribute('data-size', 'medium');
    s.setAttribute('data-radius', '8');
    s.setAttribute('data-request-access', 'write');
    s.setAttribute('data-onauth', 'visabotTgAuth(user)');
    slot.appendChild(s);
  }

  function start() {
    if (started) return; started = true;
    call({ action: 'init' }).then(function (r) {
      if (r.error) { add(r.error, 'err'); started = false; return; }
      showMessages(r.messages || []);
      if (r.privacy) root.querySelector('.pp').innerHTML = ' <a href="' + esc(r.privacy) + '" target="_blank" rel="noopener">Политика обработки данных</a>';
      if (embed) renderTg(r);
    });
  }

  if (embed) { start(); input.focus(); }
  else {
    root.querySelector('.btn').onclick = function () {
      panel.classList.toggle('open');
      if (panel.classList.contains('open')) { start(); input.focus(); }
    };
    root.querySelector('.close').onclick = function () { panel.classList.remove('open'); };
  }

  root.querySelector('.new').onclick = function () {
    if (busy) return;
    call({ action: 'new' }).then(function (r) { if (r.error) add(r.error, 'err'); else showMessages(r.messages || []); input.focus(); });
  };
  input.addEventListener('input', grow);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
  });
  form.onsubmit = function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || busy) return;
    var ch = inner.querySelector('.chips'); if (ch) ch.remove();
    add(text, 'user'); input.value = ''; grow();
    busy = true; send.disabled = true;
    var t = document.createElement('div'); t.className = 'm assistant typing'; t.innerHTML = '<i></i><i></i><i></i>';
    inner.appendChild(t); scroll();
    call({ action: 'message', message: text }).then(function (r) {
      t.remove();
      if (r.error) add(r.error, 'err'); else add(r.reply, 'assistant');
      busy = false; send.disabled = false; input.focus();
    });
  };
})();
