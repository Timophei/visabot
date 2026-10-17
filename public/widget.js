/*
 * Виджет консультанта визового центра.
 * Подключение на сайте:
 *   <script src="https://visa.gorodchaya.ru/widget.js" data-title="Визовый консультант" data-color="#1f4e8c" defer></script>
 */
(function () {
  'use strict';
  var script = document.currentScript;
  var base = script.src.replace(/widget\.js(\?.*)?$/, '');
  var api = base + 'api/chat.php';
  var title = script.getAttribute('data-title') || 'Визовый консультант';
  var color = script.getAttribute('data-color') || '#1f4e8c';

  function sessionId() {
    var k = 'visabot_session', id = null;
    try { id = localStorage.getItem(k); } catch (e) {}
    if (!id) {
      var a = new Uint8Array(16); crypto.getRandomValues(a);
      id = Array.from(a, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
      try { localStorage.setItem(k, id); } catch (e) {}
    }
    return id;
  }
  var session = sessionId();

  // Мини-разметка: экранируем HTML, затем **жирный**, списки, ссылки, переносы
  function render(text) {
    var s = text.replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    s = s.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
    s = s.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
    s = s.replace(/^\s*[-•*]\s+(.*)$/gm, '<li>$1</li>').replace(/^\s*\d+[.)]\s+(.*)$/gm, '<li>$1</li>');
    s = s.replace(/(<li>.*<\/li>\n?)+/g, function (m) { return '<ul>' + m.replace(/\n/g, '') + '</ul>'; });
    return s.replace(/\n/g, '<br>');
  }

  var host = document.createElement('div');
  host.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:2147483000';
  document.body.appendChild(host);
  var root = host.attachShadow({ mode: 'open' });
  root.innerHTML =
    '<style>' +
    ':host{all:initial;font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1d2330}' +
    '.btn{width:60px;height:60px;border-radius:50%;border:0;background:' + color + ';color:#fff;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);display:grid;place-items:center}' +
    '.btn svg{width:28px;height:28px}' +
    '.panel{display:none;position:absolute;right:0;bottom:76px;width:min(380px,calc(100vw - 32px));height:min(560px,calc(100vh - 120px));background:#fff;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25);flex-direction:column;overflow:hidden}' +
    '.panel.open{display:flex}' +
    '.head{background:' + color + ';color:#fff;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;font-weight:600}' +
    '.head button{background:none;border:0;color:#fff;cursor:pointer;font-size:13px;opacity:.85}' +
    '.log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#f5f7fa}' +
    '.m{max-width:85%;padding:9px 12px;border-radius:12px;white-space:normal;word-wrap:break-word}' +
    '.m ul{margin:4px 0;padding-left:18px}.m a{color:inherit}' +
    '.bot{background:#fff;border:1px solid #e3e7ee;align-self:flex-start}' +
    '.me{background:' + color + ';color:#fff;align-self:flex-end}' +
    '.typing{color:#7a8496;font-style:italic}' +
    'form{display:flex;gap:8px;padding:10px;border-top:1px solid #e3e7ee;background:#fff}' +
    'textarea{flex:1;resize:none;border:1px solid #cfd6e0;border-radius:10px;padding:9px 10px;font:inherit;max-height:110px}' +
    'textarea:focus{outline:2px solid ' + color + ';outline-offset:-1px}' +
    'form button{border:0;border-radius:10px;background:' + color + ';color:#fff;padding:0 14px;cursor:pointer;font:inherit}' +
    'form button:disabled{opacity:.5;cursor:default}' +
    '.note{font-size:11px;color:#7a8496;text-align:center;padding:0 10px 8px;background:#fff}' +
    '</style>' +
    '<div class="panel" role="dialog" aria-label="' + title + '">' +
    '  <div class="head"><span>' + title + '</span><span><button class="reset" type="button">Новый диалог</button> <button class="close" type="button" aria-label="Закрыть">✕</button></span></div>' +
    '  <div class="log" aria-live="polite"></div>' +
    '  <form><textarea rows="1" placeholder="Ваш вопрос…" aria-label="Ваш вопрос"></textarea><button type="submit">➤</button></form>' +
    '  <div class="note">Консультант — ИИ. Не отправляйте в чат паспортные данные.</div>' +
    '</div>' +
    '<button class="btn" type="button" aria-label="Открыть чат"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"/></svg></button>';

  var panel = root.querySelector('.panel'), log = root.querySelector('.log');
  var form = root.querySelector('form'), input = root.querySelector('textarea'), send = form.querySelector('button');
  var started = false;

  function add(text, who) {
    var d = document.createElement('div');
    d.className = 'm ' + who;
    d.innerHTML = who === 'me' ? render(text).replace(/<li>|<\/li>|<ul>|<\/ul>/g, '') : render(text);
    log.appendChild(d); log.scrollTop = log.scrollHeight; return d;
  }
  function call(body) {
    body.session = session;
    return fetch(api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .catch(function () { return { error: 'Нет связи с сервером. Попробуйте ещё раз.' }; });
  }

  root.querySelector('.btn').onclick = function () {
    panel.classList.toggle('open');
    if (panel.classList.contains('open')) {
      input.focus();
      if (!started) { started = true; call({ action: 'greeting' }).then(function (r) { add(r.reply || r.error, 'bot'); }); }
    }
  };
  root.querySelector('.close').onclick = function () { panel.classList.remove('open'); };
  root.querySelector('.reset').onclick = function () {
    log.innerHTML = '';
    call({ action: 'reset' }).then(function (r) { add(r.reply || r.error, 'bot'); });
  };
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });
  form.onsubmit = function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || send.disabled) return;
    add(text, 'me'); input.value = ''; send.disabled = true;
    var t = add('Печатает…', 'bot typing');
    call({ message: text }).then(function (r) {
      t.remove(); add(r.reply || r.error || 'Ошибка', 'bot'); send.disabled = false; input.focus();
    });
  };
})();
