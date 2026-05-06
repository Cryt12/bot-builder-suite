import { createFileRoute } from "@tanstack/react-router";

const cors = { "Access-Control-Allow-Origin": "*" };

function buildWidget(origin: string): string {
  return `(function(){
  var s = document.currentScript;
  var apiKey = s && s.getAttribute('data-api-key');
  if (!apiKey) { console.error('[Helix] data-api-key required'); return; }
  var ORIGIN = ${JSON.stringify(origin)};
  var STORE_KEY = 'helix_visitor_' + apiKey;
  var visitorId = localStorage.getItem(STORE_KEY);
  if (!visitorId) { visitorId = 'v_' + Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem(STORE_KEY, visitorId); }

  var state = { open: false, conversationId: null, messages: [], sending: false, bot: null, pageContext: null, lastPageSignature: '', pageReadLabel: 'Scanning page…' };

  // Fetch bot config
  fetch(ORIGIN + '/api/public/bot/' + apiKey).then(function(r){return r.json();}).then(function(b){
    if (b && b.name) { state.bot = b; render(); }
  }).catch(function(){});

  // Build root
  var root = document.createElement('div');
  root.id = 'helix-widget-root';
  document.body.appendChild(root);

  var style = document.createElement('style');
  style.textContent = [
    '#helix-widget-root *{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}',
    '.helix-bubble{position:fixed;bottom:20px;z-index:2147483646;width:56px;height:56px;border-radius:50%;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,0.18);transition:transform .2s}',
    '.helix-bubble:hover{transform:scale(1.06)}',
    '.helix-bubble svg{width:26px;height:26px}',
    '.helix-panel{position:fixed;bottom:90px;z-index:2147483647;width:380px;max-width:calc(100vw - 24px);height:560px;max-height:calc(100vh - 120px);background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.25);display:flex;flex-direction:column;overflow:hidden;font-size:14px;color:#0f172a}',
    '.helix-header{padding:14px 16px;color:#fff;display:flex;align-items:center;justify-content:space-between;font-weight:600}',
    '.helix-close{background:transparent;border:none;color:#fff;cursor:pointer;font-size:20px;line-height:1;padding:4px 8px;border-radius:6px}',
    '.helix-close:hover{background:rgba(255,255,255,.15)}',
    '.helix-page-status{padding:8px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc;font-size:12px;color:#475569}',
    '.helix-body{flex:1;overflow-y:auto;padding:16px;background:#f8fafc;display:flex;flex-direction:column;gap:8px}',
    '.helix-msg{max-width:85%;padding:10px 14px;border-radius:14px;line-height:1.4;white-space:pre-wrap;word-wrap:break-word}',
    '.helix-msg.bot{background:#fff;border:1px solid #e2e8f0;align-self:flex-start;border-top-left-radius:4px}',
    '.helix-msg.user{color:#fff;align-self:flex-end;border-top-right-radius:4px}',
    '.helix-typing{display:flex;gap:4px;padding:14px}',
    '.helix-typing span{width:6px;height:6px;border-radius:50%;background:#94a3b8;animation:helixBounce 1.2s infinite}',
    '.helix-typing span:nth-child(2){animation-delay:.15s}.helix-typing span:nth-child(3){animation-delay:.3s}',
    '@keyframes helixBounce{0%,80%,100%{opacity:.3;transform:translateY(0)}40%{opacity:1;transform:translateY(-4px)}}',
    '.helix-form{display:flex;gap:8px;padding:12px;border-top:1px solid #e2e8f0;background:#fff}',
    '.helix-input{flex:1;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:14px;outline:none;color:#0f172a}',
    '.helix-input:focus{border-color:#94a3b8}',
    '.helix-send{border:none;color:#fff;border-radius:10px;padding:0 14px;cursor:pointer;font-weight:600}',
    '.helix-send:disabled{opacity:.5;cursor:not-allowed}',
    '.helix-foot{text-align:center;padding:6px;font-size:11px;color:#94a3b8;background:#fff;border-top:1px solid #f1f5f9}',
    '.helix-foot a{color:#64748b;text-decoration:none}',
    '.helix-email{padding:12px;background:#fff;border-top:1px solid #e2e8f0}',
    '.helix-email input{width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;outline:none}',
  ].join('');
  root.appendChild(style);

  function normalizeText(value, maxLen){
    var text = String(value || '').replace(/\\s+/g, ' ').trim();
    if (!maxLen) return text;
    return text.length > maxLen ? text.substring(0, maxLen) : text;
  }

  function dedupeTexts(items, limit){
    var seen = {};
    var out = [];
    for (var i = 0; i < items.length; i++) {
      var value = normalizeText(items[i], 240);
      var key = value.toLowerCase();
      if (!value || seen[key]) continue;
      seen[key] = true;
      out.push(value);
      if (out.length >= limit) break;
    }
    return out;
  }

  function collectTexts(selectors, limit){
    var values = [];
    for (var i = 0; i < selectors.length; i++) {
      var nodes = document.querySelectorAll(selectors[i]);
      for (var j = 0; j < nodes.length; j++) {
        var el = nodes[j];
        if (!el || (el.closest && el.closest('#helix-widget-root'))) continue;
        var text = normalizeText(el.innerText || el.textContent || '', 240);
        if (text) values.push(text);
      }
    }
    return dedupeTexts(values, limit);
  }

  function extractMainText(){
    try {
      var source = document.querySelector('main, [role="main"], .main-content, .content, #root main') || document.body;
      if (!source) return '';
      var clone = source.cloneNode(true);
      var remove = clone.querySelectorAll ? clone.querySelectorAll('script,style,noscript,iframe,svg,canvas,#helix-widget-root') : [];
      for (var i = 0; i < remove.length; i++) remove[i].remove();
      return normalizeText(clone.innerText || clone.textContent || '', 12000);
    } catch (e) {
      return '';
    }
  }

  function buildSections(){
    var sections = [];
    var h1 = normalizeText((document.querySelector('h1') || {}).innerText || '', 180);
    var subtitle = normalizeText((document.querySelector('h2, p') || {}).innerText || '', 280);
    var navItems = collectTexts(['aside a', 'nav a', '[role="navigation"] a', 'aside button'], 12);
    var headings = collectTexts(['main h1', 'main h2', 'main h3', '[role="main"] h1', '[role="main"] h2', '[role="main"] h3'], 12);
    var buttons = collectTexts(['main button', '[role="main"] button', 'main [role="button"]'], 10);
    var listItems = collectTexts(['main li', '[role="main"] li', 'table tr'], 12);
    var cards = collectTexts(['main article', 'main section', 'main [class*="card"]', '[role="main"] [class*="card"]'], 8);

    if (h1) sections.push({ name: 'Primary heading', content: h1 });
    if (subtitle && subtitle !== h1) sections.push({ name: 'Page summary', content: subtitle });
    if (navItems.length) sections.push({ name: 'Navigation', content: navItems.join(' | ') });
    if (headings.length) sections.push({ name: 'Visible sections', content: headings.join(' | ') });
    if (buttons.length) sections.push({ name: 'Actions', content: buttons.join(' | ') });
    if (listItems.length) sections.push({ name: 'Rows and items', content: listItems.join(' | ') });
    if (cards.length) sections.push({ name: 'Cards and panels', content: cards.join(' | ') });

    return sections.slice(0, 12);
  }

  function getPageContext(){
    try{
      var pageName = normalizeText((document.querySelector('h1') || {}).innerText || document.title || '', 180);
      var pageSections = buildSections();
      var bodyText = extractMainText();
      return {
        pageTitle: normalizeText(document.title || '', 300),
        pageName: pageName,
        pageUrl: window.location.href,
        pageSections: pageSections,
        pageContent: bodyText,
        scrapedAt: new Date().toISOString()
      };
    }catch(e){ return { pageTitle:'', pageName:'', pageUrl:window.location.href, pageContent:'', pageSections:[], scrapedAt:new Date().toISOString() }; }
  }

  function buildPageSignature(ctx){
    return JSON.stringify([
      ctx.pageUrl || '',
      ctx.pageTitle || '',
      ctx.pageName || '',
      (ctx.pageContent || '').substring(0, 1600)
    ]);
  }

  function refreshPageContext(force){
    var next = getPageContext();
    var signature = buildPageSignature(next);
    if (!force && signature === state.lastPageSignature) return;
    state.pageContext = next;
    state.lastPageSignature = signature;
    state.pageReadLabel = next.pageName ? 'Reading: ' + next.pageName : 'Reading current page';
    if (state.open) render();
  }

  var pageRefreshTimer = null;
  function schedulePageContextRefresh(delay){
    if (pageRefreshTimer) clearTimeout(pageRefreshTimer);
    pageRefreshTimer = setTimeout(function(){ refreshPageContext(false); }, typeof delay === 'number' ? delay : 400);
  }

  function watchPageChanges(){
    refreshPageContext(true);

    var originalPushState = history.pushState;
    var originalReplaceState = history.replaceState;

    history.pushState = function(){
      var result = originalPushState.apply(history, arguments);
      schedulePageContextRefresh(250);
      return result;
    };

    history.replaceState = function(){
      var result = originalReplaceState.apply(history, arguments);
      schedulePageContextRefresh(250);
      return result;
    };

    window.addEventListener('popstate', function(){ schedulePageContextRefresh(250); });
    window.addEventListener('hashchange', function(){ schedulePageContextRefresh(250); });
    window.addEventListener('load', function(){ schedulePageContextRefresh(250); });

    if (window.MutationObserver && document.body) {
      var observer = new MutationObserver(function(){ schedulePageContextRefresh(700); });
      observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    }
  }

  function send(msg){
    if (state.sending || !msg.trim()) return;
    state.sending = true;
    state.messages.push({ role: 'user', content: msg });
    render();
    var history = state.messages.slice(-10).map(function(m){return {role:m.role,content:m.content};});
    var visitorEmail = localStorage.getItem(STORE_KEY + '_email') || null;
    if (!state.pageContext) refreshPageContext(true);
    var pageCtx = state.pageContext || getPageContext();
    fetch(ORIGIN + '/api/public/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ apiKey: apiKey, message: msg, conversationId: state.conversationId, visitorId: visitorId, visitorEmail: visitorEmail, history: history.slice(0, -1), pageContext: pageCtx })
    }).then(function(r){return r.json().then(function(j){return {ok:r.ok,j:j};});}).then(function(res){
      state.sending = false;
      if (!res.ok) { state.messages.push({ role:'assistant', content: res.j.error || 'Sorry, something went wrong.' }); }
      else {
        state.conversationId = res.j.conversationId;
        state.messages.push({ role:'assistant', content: res.j.reply });
      }
      render();
    }).catch(function(){
      state.sending = false;
      state.messages.push({ role:'assistant', content:'Network error. Please try again.' });
      render();
    });
  }

  function render(){
    if (!state.bot) return;
    var color = state.bot.primary_color || '#7c5cff';
    var pos = state.bot.bubble_position === 'left' ? 'left:20px' : 'right:20px';
    root.innerHTML = '';
    root.appendChild(style);

    // Bubble
    var bubble = document.createElement('button');
    bubble.className = 'helix-bubble';
    bubble.setAttribute('style', pos + ';background:' + color);
    bubble.setAttribute('aria-label', 'Open chat');
    bubble.innerHTML = state.open
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 6L18 18M6 18L18 6"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>';
    bubble.onclick = function(){ state.open = !state.open; if (state.open && state.messages.length === 0) state.messages.push({role:'assistant',content:state.bot.welcome_message}); render(); };
    root.appendChild(bubble);

    if (!state.open) return;

    // Panel
    var panel = document.createElement('div');
    panel.className = 'helix-panel';
    panel.setAttribute('style', pos);
    panel.innerHTML =
      '<div class="helix-header" style="background:' + color + '"><span>' + escapeHtml(state.bot.name) + '</span><button class="helix-close" aria-label="Close">×</button></div>' +
      '<div class="helix-page-status">' + escapeHtml(state.pageReadLabel || 'Reading current page') + '</div>' +
      '<div class="helix-body" id="helix-body"></div>' +
      (state.bot.collect_email && !localStorage.getItem(STORE_KEY + '_email')
        ? '<div class="helix-email"><input id="helix-email" type="email" placeholder="Your email (optional)"/></div>'
        : '') +
      '<form class="helix-form" id="helix-form"><input class="helix-input" id="helix-input" placeholder="Ask anything…" autocomplete="off"/><button class="helix-send" id="helix-send" type="submit" style="background:' + color + '">Send</button></form>' +
      '<div class="helix-foot">Powered by <a href="' + ORIGIN + '" target="_blank" rel="noopener">Helix</a></div>';
    root.appendChild(panel);

    var body = panel.querySelector('#helix-body');
    state.messages.forEach(function(m){
      var div = document.createElement('div');
      div.className = 'helix-msg ' + (m.role === 'user' ? 'user' : 'bot');
      if (m.role === 'user') div.style.background = color;
      div.textContent = m.content;
      body.appendChild(div);
    });
    if (state.sending) {
      var t = document.createElement('div');
      t.className = 'helix-msg bot';
      t.innerHTML = '<div class="helix-typing"><span></span><span></span><span></span></div>';
      t.style.padding = '0';
      body.appendChild(t);
    }
    body.scrollTop = body.scrollHeight;

    panel.querySelector('.helix-close').onclick = function(){ state.open = false; render(); };
    var emailInput = panel.querySelector('#helix-email');
    panel.querySelector('#helix-form').onsubmit = function(e){
      e.preventDefault();
      var input = panel.querySelector('#helix-input');
      var v = input.value;
      if (emailInput && emailInput.value) localStorage.setItem(STORE_KEY + '_email', emailInput.value);
      input.value = '';
      send(v);
    };
  }

  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]); }); }
  watchPageChanges();
})();`;
}

export const Route = createFileRoute("/api/public/widget.js")({
  server: {
    handlers: {
      GET: async ({ request }) => {
        const url = new URL(request.url);
        const origin = `${url.protocol}//${url.host}`;
        return new Response(buildWidget(origin), {
          status: 200,
          headers: {
            "Content-Type": "application/javascript; charset=utf-8",
            "Cache-Control": "public, max-age=300",
            ...cors,
          },
        });
      },
    },
  },
});
