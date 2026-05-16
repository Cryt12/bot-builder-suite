import { createFileRoute } from "@tanstack/react-router";

const cors = { "Access-Control-Allow-Origin": "*" };

function buildWidget(origin: string): string {
  return `(function(){
  var s = document.currentScript;
  var publicKey = s && (s.getAttribute('data-public-key') || s.getAttribute('data-api-key'));
  if (!publicKey) { console.error('[Helix] data-public-key required'); return; }
  var explicitOrigin = s && (s.getAttribute('data-origin') || s.getAttribute('data-helix-origin'));
  var explicitCacheMinutes = s && (s.getAttribute('data-cache-minutes') || s.getAttribute('data-session-cache-minutes') || s.getAttribute('data-conversation-cache-minutes'));
  var ORIGIN = explicitOrigin || ${JSON.stringify(origin)};
  while (ORIGIN.length > 1 && ORIGIN.charAt(ORIGIN.length - 1) === '/') ORIGIN = ORIGIN.slice(0, -1);
  var STORE_KEY = 'helix_visitor_' + publicKey;
  var SESSION_STORE_KEY = STORE_KEY + '_session';
  var visitorId = localStorage.getItem(STORE_KEY);
  if (!visitorId) { visitorId = 'v_' + Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem(STORE_KEY, visitorId); }
  var DEFAULT_CACHE_MINUTES = 10;
  var EMBED_CACHE_MINUTES = parseCacheMinutes(explicitCacheMinutes);

  var closeTimer = null;
  var state = { open: false, closing: false, conversationId: null, messages: [], sending: false, bot: null, pageContext: null, lastPageSignature: '', pageReadLabel: 'Scanning page…', draftMessage: '', draftEmail: '', activeField: null };
  restoreSession();

  // Fetch bot config
  fetch(ORIGIN + '/api/public/bot/' + publicKey).then(function(r){return r.json();}).then(function(b){
    if (b && b.logo_url && !/^https?:\/\//i.test(b.logo_url)) {
      b.logo_url = ORIGIN + (b.logo_url.charAt(0) === '/' ? b.logo_url : '/' + b.logo_url);
    }
    if (b && b.name) {
      state.bot = b;
      if (resolveCacheMinutes() === 0) clearSession();
      else persistSession();
      render();
    }
  }).catch(function(){});

  // Build root
  var root = document.createElement('div');
  root.id = 'helix-widget-root';
  document.body.appendChild(root);

  var style = document.createElement('style');
  style.textContent = [
    '#helix-widget-root *{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}',
    '.helix-bubble{position:fixed;bottom:20px;z-index:2147483646;width:56px;height:56px;border-radius:50%;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,0.18);transition:transform .24s ease,box-shadow .24s ease;overflow:hidden;padding:0}',
    '.helix-bubble:hover{transform:scale(1.06)}',
    '.helix-bubble.is-active{transform:scale(.96) rotate(-8deg);box-shadow:0 14px 34px rgba(0,0,0,0.22)}',
    '.helix-bubble img{width:100%;height:100%;object-fit:contain;display:block;border-radius:inherit;background:transparent}',
    '.helix-bubble svg{width:26px;height:26px;transition:transform .2s ease,opacity .2s ease}',
    '.helix-panel{position:fixed;bottom:90px;z-index:2147483647;width:380px;max-width:calc(100vw - 24px);height:560px;max-height:calc(100vh - 120px);background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.25);display:flex;flex-direction:column;overflow:hidden;font-size:14px;color:#0f172a}',
    '.helix-panel.is-opening{animation:helixPanelIn .32s cubic-bezier(.22,1,.36,1) forwards;opacity:0;transform:translateY(20px) scale(.92)}',
    '.helix-panel.is-open{opacity:1;transform:translateY(0) scale(1)}',
    '.helix-panel[data-side="right"]{transform-origin:bottom right}',
    '.helix-panel[data-side="left"]{transform-origin:bottom left}',
    '.helix-panel.is-closing{animation:helixPanelOut .24s cubic-bezier(.4,0,1,1) forwards;pointer-events:none}',
    '@keyframes helixPanelIn{0%{opacity:0;transform:translateY(20px) scale(.92)}100%{opacity:1;transform:translateY(0) scale(1)}}',
    '@keyframes helixPanelOut{0%{opacity:1;transform:translateY(0) scale(1)}100%{opacity:0;transform:translateY(16px) scale(.94)}}',
    '.helix-header{padding:14px 16px;color:#fff;display:flex;align-items:center;justify-content:space-between;font-weight:600}',
    '.helix-close{background:transparent;border:none;color:#fff;cursor:pointer;font-size:20px;line-height:1;padding:4px 8px;border-radius:6px}',
    '.helix-close:hover{background:rgba(255,255,255,.15)}',
    '.helix-page-status{padding:8px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc;font-size:12px;color:#475569}',
    '.helix-body{flex:1;overflow-y:auto;padding:16px;background:#f8fafc;display:flex;flex-direction:column;gap:8px}',
    '.helix-msg{max-width:85%;padding:10px 14px;border-radius:14px;line-height:1.6;word-wrap:break-word;font-size:14px}',
    '.helix-msg.bot{background:#fff;border:1px solid #e2e8f0;align-self:flex-start;border-top-left-radius:4px;color:#1e293b}',
    '.helix-msg.user{color:#fff;align-self:flex-end;border-top-right-radius:4px}',
    '.helix-msg p{margin:0 0 0.6em}',
    '.helix-msg p:last-child{margin-bottom:0}',
    '.helix-msg h1,.helix-msg h2,.helix-msg h3{font-weight:600;line-height:1.3;margin:1em 0 0.4em;color:#0f172a}',
    '.helix-msg h1{font-size:1.15em}.helix-msg h2{font-size:1.05em}.helix-msg h3{font-size:0.95em}',
    '.helix-msg h1:first-child,.helix-msg h2:first-child,.helix-msg h3:first-child{margin-top:0}',
    '.helix-msg ul,.helix-msg ol{margin:0.3em 0 0.6em;padding-left:1.5em}',
    '.helix-msg li{margin:0.15em 0}',
    '.helix-msg strong{font-weight:600;color:#0f172a}',
    '.helix-msg em{font-style:italic}',
    '.helix-msg code{background:#f1f5f9;padding:0.15em 0.35em;border-radius:4px;font-size:0.9em;font-family:"SF Mono","Fira Code","Fira Mono",Menlo,monospace;color:#e11d48}',
    '.helix-msg pre{background:#1e293b;color:#e2e8f0;padding:12px;border-radius:8px;overflow-x:auto;font-size:0.82em;line-height:1.5;margin:0.5em 0}',
    '.helix-msg pre code{background:transparent;padding:0;font-size:inherit;color:inherit}',
    '.helix-msg blockquote{border-left:3px solid #6366f1;padding:0.3em 0 0.3em 0.8em;margin:0.5em 0;color:#475569;font-style:italic}',
    '.helix-msg a{color:#6366f1;text-decoration:underline}',
    '.helix-msg hr{border:none;border-top:1px solid #e2e8f0;margin:0.75em 0}',
    '.helix-msg table{width:100%;border-collapse:collapse;margin:0.5em 0;font-size:0.9em}',
    '.helix-msg th,.helix-msg td{border:1px solid #e2e8f0;padding:6px 8px;text-align:left}',
    '.helix-msg th{background:#f1f5f9;font-weight:600}',
    '.helix-typing{display:flex;gap:4px;padding:14px}',
    '.helix-typing-wrapper{transition:opacity 0.25s ease,transform 0.25s ease}',
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
    '@media (max-width:640px){.helix-panel{bottom:88px;width:min(380px,calc(100vw - 20px));max-width:calc(100vw - 20px);height:min(560px,calc(100vh - 108px))}}',
  ].join('');
  root.appendChild(style);

  function openWidget(){
    if (closeTimer) {
      clearTimeout(closeTimer);
      closeTimer = null;
    }
    if (state.open && !state.closing) return;
    state.closing = false;
    state.open = true;
    if (state.messages.length === 0) state.messages.push({ role: 'assistant', content: state.bot.welcome_message });
    render();
  }

  function closeWidget(){
    if ((!state.open && !state.closing) || closeTimer) return;
    state.closing = true;
    // Apply closing animation to panel
    var panel = root.querySelector('.helix-panel');
    if (panel) {
      panel.className = 'helix-panel is-closing';
      panel.setAttribute('data-side', state.bot.bubble_position === 'left' ? 'left' : 'right');
    }
    render();
    closeTimer = setTimeout(function(){
      state.open = false;
      state.closing = false;
      closeTimer = null;
      // Remove panel from DOM after animation completes
      var p = root.querySelector('.helix-panel');
      if (p) root.removeChild(p);
      render();
    }, 260);
  }

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
    if (state.open) {
      var statusEl = root.querySelector('.helix-page-status');
      if (statusEl) statusEl.textContent = state.pageReadLabel || 'Reading current page';
      else render();
    }
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
      var observer = new MutationObserver(function(mutations){
        for (var i = 0; i < mutations.length; i++) {
          var target = mutations[i].target;
          if (target && target.nodeType === 1 && target.closest && target.closest('#helix-widget-root')) continue;
          if (target && target.nodeType === 3 && target.parentElement && target.parentElement.closest && target.parentElement.closest('#helix-widget-root')) continue;
          schedulePageContextRefresh(700);
          return;
        }
      });
      observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    }
  }

  function parseCacheMinutes(value){
    var num = parseInt(String(value == null ? '' : value), 10);
    if (!isFinite(num) || isNaN(num)) return null;
    return Math.max(0, Math.min(10080, num));
  }

  function resolveCacheMinutes(){
    var botMinutes = state.bot && typeof state.bot.widget_cache_minutes !== 'undefined'
      ? parseCacheMinutes(state.bot.widget_cache_minutes)
      : null;
    if (EMBED_CACHE_MINUTES !== null) return EMBED_CACHE_MINUTES;
    if (botMinutes !== null) return botMinutes;
    return DEFAULT_CACHE_MINUTES;
  }

  function clearSession(){
    localStorage.removeItem(SESSION_STORE_KEY);
    state.conversationId = null;
    state.messages = [];
    state.draftMessage = '';
  }

  function persistSession(){
    var cacheMinutes = resolveCacheMinutes();
    if (!cacheMinutes) {
      localStorage.removeItem(SESSION_STORE_KEY);
      return;
    }
    var expiresAt = Date.now() + (cacheMinutes * 60 * 1000);
    localStorage.setItem(SESSION_STORE_KEY, JSON.stringify({
      conversationId: state.conversationId,
      messages: state.messages,
      draftMessage: state.draftMessage,
      draftEmail: state.draftEmail,
      expiresAt: expiresAt
    }));
  }

  function restoreSession(){
    var raw = localStorage.getItem(SESSION_STORE_KEY);
    if (!raw) return;
    try {
      var saved = JSON.parse(raw);
      if (!saved || !saved.expiresAt || saved.expiresAt <= Date.now()) {
        localStorage.removeItem(SESSION_STORE_KEY);
        return;
      }
      state.conversationId = saved.conversationId || null;
      state.messages = Array.isArray(saved.messages) ? saved.messages : [];
      state.draftMessage = typeof saved.draftMessage === 'string' ? saved.draftMessage : '';
      state.draftEmail = typeof saved.draftEmail === 'string' ? saved.draftEmail : '';
    } catch (e) {
      localStorage.removeItem(SESSION_STORE_KEY);
    }
  }

  function getStoredEmail(){
    return localStorage.getItem(STORE_KEY + '_email') || '';
  }

  function getDraftOrStoredEmail(){
    return (state.draftEmail || getStoredEmail() || '').trim();
  }

  function isValidEmail(email){
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
  }

  function canSendMessage(message, email){
    return !state.sending && !!String(message || '').trim() && isValidEmail(email);
  }

  function persistVisitorEmail(email){
    var normalizedEmail = String(email || '').trim();
    localStorage.setItem(STORE_KEY + '_email', normalizedEmail);
    state.draftEmail = normalizedEmail;
    persistSession();
  }

  function syncComposerState(panel){
    if (!panel) return;
    var emailInput = panel.querySelector('#helix-email');
    var messageInput = panel.querySelector('#helix-input');
    var sendBtn = panel.querySelector('#helix-send');
    var email = emailInput ? emailInput.value.trim() : getDraftOrStoredEmail();
    var message = messageInput ? messageInput.value : state.draftMessage;
    if (messageInput) messageInput.disabled = !!state.sending;
    if (sendBtn) sendBtn.disabled = !canSendMessage(message, email);
  }

  function send(msg){
    var visitorEmail = getDraftOrStoredEmail();
    if (!canSendMessage(msg, visitorEmail)) return;
    state.sending = true;
    state.messages.push({ role: 'user', content: msg });
    state.draftMessage = '';
    persistSession();
    render();
    var history = state.messages.slice(-10).map(function(m){return {role:m.role,content:m.content};});
    if (!state.pageContext) refreshPageContext(true);
    var pageCtx = state.pageContext || getPageContext();
    fetch(ORIGIN + '/api/public/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      body: JSON.stringify({ publicKey: publicKey, message: msg, conversationId: state.conversationId, visitorId: visitorId, visitorEmail: visitorEmail, history: history.slice(0, -1), pageContext: pageCtx })
    }).then(function(r){return r.json().then(function(j){return {ok:r.ok,j:j};});}).then(function(res){
      state.sending = false;
      if (!res.ok) { state.messages.push({ role:'assistant', content: res.j.error || 'Sorry, something went wrong.' }); }
      else {
        state.conversationId = res.j.conversationId;
        state.messages.push({ role:'assistant', content: res.j.reply });
      }
      persistSession();
      render();
    }).catch(function(){
      state.sending = false;
      state.messages.push({ role:'assistant', content:'Network error. Please try again.' });
      persistSession();
      render();
    });
  }

  function render(){
    if (!state.bot) return;
    var color = state.bot.primary_color || '#00b0f0';
    var pos = state.bot.bubble_position === 'left' ? 'left:20px' : 'right:20px';
    
    // Bubble - update or create
    var bubble = root.querySelector('.helix-bubble');
    if (!bubble) {
      bubble = document.createElement('button');
      bubble.className = 'helix-bubble';
      bubble.setAttribute('aria-label', 'Open chat');
      bubble.onclick = function(){ if (state.open && !state.closing) closeWidget(); else openWidget(); };
      root.insertBefore(bubble, root.firstChild);
    }
    bubble.className = 'helix-bubble' + ((state.open || state.closing) ? ' is-active' : '');
    bubble.setAttribute('style', pos + ';background:' + (state.bot.logo_url ? 'transparent' : color));
    bubble.innerHTML = (state.open || state.closing)
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 6L18 18M6 18L18 6"/></svg>'
      : (state.bot.logo_url
          ? '<img src="' + escapeHtml(state.bot.logo_url) + '" alt="' + escapeHtml((state.bot.name || 'Bot') + ' logo') + '"/>'
          : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>');

    if (!state.open && !state.closing) return;

    // Panel - update or create
    var panel = root.querySelector('.helix-panel');
    var isIncrementalUpdate = !!panel;
    
    if (!panel) {
      panel = document.createElement('div');
      panel.className = 'helix-panel is-opening';
      panel.setAttribute('style', pos);
      panel.setAttribute('data-side', state.bot.bubble_position === 'left' ? 'left' : 'right');
      root.appendChild(panel);
      // After animation completes, switch to is-open class
      panel.addEventListener('animationend', function(){
        panel.className = 'helix-panel is-open';
      }, { once: true });
      
      panel.innerHTML =
        '<div class="helix-header" style="background:' + color + '"><span>' + escapeHtml(state.bot.name) + '</span><button class="helix-close" aria-label="Close">×</button></div>' +
        '<div class="helix-page-status">' + escapeHtml(state.pageReadLabel || 'Reading current page') + '</div>' +
        '<div class="helix-body" id="helix-body"></div>' +
        '<div class="helix-email"><input id="helix-email" type="email" placeholder="Your email" autocomplete="email" inputmode="email" required/></div>' +
        '<form class="helix-form" id="helix-form"><input class="helix-input" id="helix-input" placeholder="Ask anything…" autocomplete="off"/><button class="helix-send" id="helix-send" type="button" style="background:' + color + '">Send</button></form>' +
        '<div class="helix-foot">Powered by <a href="' + ORIGIN + '" target="_blank" rel="noopener">Helix</a></div>';
      
      // Setup event handlers only on initial creation
      var closeBtn = panel.querySelector('.helix-close');
      closeBtn.onclick = function(){ closeWidget(); };
      
      var form = panel.querySelector('#helix-form');
      var messageInput = panel.querySelector('#helix-input');
      var emailInput = panel.querySelector('#helix-email');
      
      // Use 'type="button"' on send to prevent form submission, handle manually
      var sendBtn = panel.querySelector('#helix-send');
      sendBtn.onclick = function(){ form.dispatchEvent(new Event('submit', {cancelable: true})); };
      
      form.onsubmit = function(e){
        e.preventDefault();
        e.stopPropagation();
        var v = messageInput.value.trim();
        var email = emailInput ? emailInput.value.trim() : getDraftOrStoredEmail();
        if (!isValidEmail(email)) {
          if (emailInput) emailInput.focus();
          syncComposerState(panel);
          return false;
        }
        persistVisitorEmail(email);
        messageInput.value = '';
        syncComposerState(panel);
        send(v);
        return false;
      };
      
      messageInput.oninput = function(){ state.draftMessage = messageInput.value; persistSession(); syncComposerState(panel); };
      messageInput.onfocus = function(){ state.activeField = 'message'; };
      messageInput.onblur = function(){ if (state.activeField === 'message') state.activeField = null; };
      messageInput.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          form.dispatchEvent(new Event('submit', {cancelable: true}));
        }
      });
      
      if (emailInput) {
        emailInput.value = getDraftOrStoredEmail();
        emailInput.oninput = function(){ state.draftEmail = emailInput.value; persistSession(); syncComposerState(panel); };
        emailInput.onfocus = function(){ state.activeField = 'email'; };
        emailInput.onblur = function(){ if (state.activeField === 'email') state.activeField = null; };
      }
      syncComposerState(panel);
    } else {
      // Incremental update - only update dynamic content
      var headerSpan = panel.querySelector('.helix-header span');
      if (headerSpan) headerSpan.textContent = state.bot.name || 'Bot';
      
      var statusEl = panel.querySelector('.helix-page-status');
      if (statusEl) statusEl.textContent = state.pageReadLabel || 'Reading current page';
      
      var emailDiv = panel.querySelector('.helix-email');
      if (!emailDiv) {
        var newEmailDiv = document.createElement('div');
        newEmailDiv.className = 'helix-email';
        newEmailDiv.innerHTML = '<input id="helix-email" type="email" placeholder="Your email" autocomplete="email" inputmode="email" required/>';
        panel.insertBefore(newEmailDiv, panel.querySelector('.helix-form'));
        var injectedEmailInput = newEmailDiv.querySelector('#helix-email');
        injectedEmailInput.value = getDraftOrStoredEmail();
        injectedEmailInput.oninput = function(){ state.draftEmail = injectedEmailInput.value; persistSession(); syncComposerState(panel); };
        injectedEmailInput.onfocus = function(){ state.activeField = 'email'; };
        injectedEmailInput.onblur = function(){ if (state.activeField === 'email') state.activeField = null; };
      } else {
        var existingEmailInput = emailDiv.querySelector('#helix-email');
        if (existingEmailInput && state.activeField !== 'email') existingEmailInput.value = getDraftOrStoredEmail();
      }
    }
    
    // Skip content updates during closing animation
    if (state.closing) return;

    // Update message body incrementally
    var body = panel.querySelector('#helix-body');
    var existingMsgs = body.querySelectorAll('.helix-msg:not(.helix-typing-wrapper)');
    var existingCount = existingMsgs.length;
    var msgCount = state.messages.length;
    
    // Remove typing indicator first (if present) so it doesn't interfere
    var oldTyping = body.querySelector('.helix-typing-wrapper');
    if (oldTyping) {
      oldTyping.style.opacity = '0';
      oldTyping.style.transform = 'translateY(6px)';
      (function(el){ setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 180); })(oldTyping);
    }
    
    // Remove excess message nodes (only actual messages, not typing)
    var currentMsgNodes = body.querySelectorAll('.helix-msg:not(.helix-typing-wrapper)');
    for (var r = currentMsgNodes.length - 1; r >= msgCount; r--) {
      body.removeChild(currentMsgNodes[r]);
    }
    
    // Add new messages with smooth fade-in
    var isFirstOpen = !isIncrementalUpdate;
    for (var i = existingCount; i < msgCount; i++) {
      var m = state.messages[i];
      var div = document.createElement('div');
      div.className = 'helix-msg ' + (m.role === 'user' ? 'user' : 'bot');
      div.style.opacity = '0';
      div.style.transform = 'translateY(12px)';
      div.style.transition = 'opacity 0.35s ease, transform 0.35s cubic-bezier(.22,1,.36,1)';
      if (m.role === 'user') {
        div.style.background = color;
        div.textContent = m.content;
      } else {
        div.innerHTML = renderMessageHtml(m.content);
      }
      body.appendChild(div);
      // Trigger fade-in with optional stagger delay for first open
      (function(el, idx){
        var delay = isFirstOpen ? 180 + idx * 60 : 20;
        setTimeout(function(){
          requestAnimationFrame(function(){
            el.style.opacity = '1';
            el.style.transform = 'translateY(0)';
          });
        }, delay);
      })(div, i - existingCount);
    }
    
    // Add typing indicator with smooth entrance
    if (state.sending) {
      var t = document.createElement('div');
      t.className = 'helix-msg bot helix-typing-wrapper';
      t.innerHTML = '<div class="helix-typing"><span></span><span></span><span></span></div>';
      t.style.padding = '0';
      t.style.opacity = '0';
      t.style.transform = 'translateY(10px)';
      t.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
      body.appendChild(t);
      requestAnimationFrame(function(){
        requestAnimationFrame(function(){
          t.style.opacity = '1';
          t.style.transform = 'translateY(0)';
        });
      });
    }
    
    // Smooth scroll to bottom
    body.scrollTo({ top: body.scrollHeight, behavior: 'smooth' });
    
    // Restore input value only if user is not actively typing
    var messageInput = panel.querySelector('#helix-input');
    if (messageInput && state.activeField !== 'message') {
      messageInput.value = state.draftMessage || '';
    } else if (messageInput && state.draftMessage === '') {
      messageInput.value = '';
    }
    persistSession();
    syncComposerState(panel);
  }

  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]); }); }
  function normalizeMessageText(content){
    return String(content || '')
      .replace(/\r\n?/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }
  function formatInline(text){
    var urlPlaceholders = [];
    var urlIdx = 0;
    text = text.replace(/https?:\/\/[^\s<>"')\"]]+/g, function(url){
      var clean = url.replace(/[.,;:!?)\"]]+$/, '');
      urlPlaceholders.push(clean);
      return '\x00URL' + (urlIdx++) + '\x00';
    });
    text = escapeHtml(text);
    var result = '';
    var i = 0;
    while (i < text.length) {
      if (text[i] === '\`') {
        var end = text.indexOf('\`', i + 1);
        if (end !== -1) {
          result += '<code>' + text.slice(i + 1, end) + '</code>';
          i = end + 1;
          continue;
        }
      }
      if (text[i] === '*' && text[i + 1] === '*') {
        var end = text.indexOf('**', i + 2);
        if (end !== -1) {
          result += '<strong>' + text.slice(i + 2, end) + '</strong>';
          i = end + 2;
          continue;
        }
      }
      if (text[i] === '*' && text[i + 1] !== '*') {
        var end = text.indexOf('*', i + 1);
        if (end !== -1) {
          result += '<em>' + text.slice(i + 1, end) + '</em>';
          i = end + 1;
          continue;
        }
      }
      result += text[i];
      i++;
    }
    result = result.replace(/\x00URL(\d+)\x00/g, function(_, idx){
      var url = urlPlaceholders[parseInt(idx, 10)];
      return '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>';
    });
    return result;
  }
  function renderMessageHtml(content){
    var lines = normalizeMessageText(content).split('\n');
    var out = '';
    var i = 0;
    while (i < lines.length) {
      var raw = lines[i];
      var t = raw.trim();
      if (!t) { i++; continue; }
      if (t.indexOf('\`\`\`') === 0) {
        var lang = t.slice(3).trim();
        var codeLines = [];
        i++;
        while (i < lines.length && lines[i].trim().indexOf('\`\`\`') !== 0) {
          codeLines.push(lines[i]);
          i++;
        }
        i++;
        var langAttr = lang ? ' data-lang="' + escapeHtml(lang) + '"' : '';
        out += '<pre' + langAttr + '><code>' + escapeHtml(codeLines.join('\n')) + '</code></pre>';
        continue;
      }
      var hMatch = t.match(/^(#{1,3})\s+(.+)/);
      if (hMatch) {
        var level = hMatch[1].length;
        out += '<h' + level + '>' + formatInline(hMatch[2]) + '</h' + level + '>';
        i++; continue;
      }
      if (t[0] === '>' && (t[1] === ' ' || t.length === 1)) {
        var qLines = [];
        while (i < lines.length && lines[i].trim()[0] === '>') {
          qLines.push(lines[i].trim().replace(/^>\s?/, ''));
          i++;
        }
        out += '<blockquote><p>' + formatInline(qLines.join('\n')) + '</p></blockquote>';
        continue;
      }
      if (t.match(/^(-{3,}|\*{3,}|_{3,})$/)) {
        out += '<hr>';
        i++; continue;
      }
      if (t.match(/^\d+\.\s/)) {
        out += '<ol>';
        while (i < lines.length && lines[i].trim().match(/^\d+\.\s/)) {
          var item = lines[i].trim().replace(/^\d+\.\s*/, '');
          out += '<li>' + formatInline(item) + '</li>';
          i++;
        }
        out += '</ol>';
        continue;
      }
      if (t.match(/^[-*]\s/)) {
        out += '<ul>';
        while (i < lines.length && lines[i].trim().match(/^[-*]\s/)) {
          var item = lines[i].trim().replace(/^[-*]\s*/, '');
          out += '<li>' + formatInline(item) + '</li>';
          i++;
        }
        out += '</ul>';
        continue;
      }
      out += '<p>' + formatInline(t) + '</p>';
      i++;
    }
    return out || '<p></p>';
  }
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
            "Cache-Control": "no-store, no-cache, must-revalidate, max-age=0",
            "Pragma": "no-cache",
            "Expires": "0",
            ...cors,
          },
        });
      },
    },
  },
});
