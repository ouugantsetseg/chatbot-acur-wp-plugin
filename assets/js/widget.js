(() => {
  const cfg = window.ACURCB_CFG || {};
  const REST = cfg.restBase;

  // session id
  const sidKey = 'acurcb_sid';
  let sid = localStorage.getItem(sidKey);
  if (!sid) { sid = 'wp-' + Math.random().toString(36).slice(2); localStorage.setItem(sidKey, sid); }

  // UI
  const btn = document.createElement('button');
  btn.id = 'acurcb-btn'; btn.innerText = cfg.widgetTitle || 'Ask';
  const panel = document.createElement('div'); panel.id = 'acurcb-panel';
  panel.innerHTML = `
    <div class="acurcb-header">
      <span>ACUR Chatbot</span>
      <button class="acurcb-close" aria-label="Close">×</button>
    </div>
    <div class="acurcb-body" id="acurcb-body"></div>
    <form id="acurcb-form">
      <input id="acurcb-input" type="text" placeholder="Type your question..." autocomplete="off" required />
      <button>Send</button>
    </form>
  `;
  document.body.append(btn, panel);

  const body = panel.querySelector('#acurcb-body');
  const input = panel.querySelector('#acurcb-input');
  const form = panel.querySelector('#acurcb-form');

  function addMsg(role, html) {
    const el = document.createElement('div');
    el.className = 'acurcb-msg ' + role;
    el.innerHTML = html;
    body.appendChild(el);
    body.scrollTop = body.scrollHeight;
  }

  function showHelpful(faqId) {
    const c = document.createElement('div');
    c.className = 'acurcb-help';
    c.innerHTML = `
      <span>Was this helpful?</span>
      <button data-h="1">Yes</button>
      <button data-h="0">No</button>
    `;
    c.querySelectorAll('button').forEach(b=>{
      b.onclick = async () => {
        addMsg('system', b.dataset.h==='1' ? 'Thanks for the feedback!' : 'Sorry about that.');
        if (REST) {
          fetch(`${REST}feedback`, {
            method:'POST',
            headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.siteNonce},
            body: JSON.stringify({ session_id: sid, faq_id: faqId || null, helpful: b.dataset.h==='1' })
          });
        }
        c.remove();
        if (b.dataset.h==='0') showEscalate();
      };
    });
    body.appendChild(c);
  }

  function showEscalate() {
    const c = document.createElement('form');
    c.className = 'acurcb-escalate';
    c.innerHTML = `
      <div>Leave your email and we’ll follow up:</div>
      <input type="email" name="email" placeholder="you@example.com" required />
      <button>Send</button>
    `;
    c.onsubmit = async (e)=>{
      e.preventDefault();
      const email = c.querySelector('input[name=email]').value.trim();
      const lastQ = [...body.querySelectorAll('.acurcb-msg.user')].pop()?.innerText || '';
      addMsg('system', 'Thanks — our team will get back to you.');
      if (REST) {
        await fetch(`${REST}escalate`, {
          method:'POST',
          headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.siteNonce},
          body: JSON.stringify({ session_id: sid, user_query: lastQ, contact_email: email })
        });
      }
      c.remove();
    };
    body.appendChild(c);
  }

  async function ask(q) {
    addMsg('user', q);
    input.value = ''; input.focus();
    addMsg('bot', '<em>Thinking…</em>');

   try {
  const url = new URL(`${REST}match`);
  url.searchParams.set('q', q);
  url.searchParams.set('session_id', sid);

  const r = await fetch(url);
  let data = null;
  try { data = await r.json(); } catch (_) {}

  if (!r.ok || !data || typeof data.answer === 'undefined') {
    const msg = (data && (data.detail || data.message || data.error)) ||
                `Request failed (${r.status})`;
    body.lastChild.innerHTML = 'Sorry—something went wrong. ' + msg;
    return;
  }

  body.lastChild.innerHTML = data.answer;
  showHelpful(data.id || null);

  if (Array.isArray(data.alternates) && data.alternates.length) {
    const alts = document.createElement('div');
    alts.className = 'acurcb-alts';
    alts.innerHTML = '<div class="title">Related:</div>' +
      data.alternates.slice(0,3).map(a => `<button type="button" class="alt">${a.question}</button>`).join('');
    alts.querySelectorAll('button.alt').forEach(b=>{
      b.onclick = ()=> ask(b.innerText);
    });
    body.appendChild(alts);
  }
} catch (e) {
  body.lastChild.innerHTML = 'Sorry—network error.';
}

  }

  // events
  btn.onclick = ()=> { panel.classList.toggle('open'); input.focus(); };
  panel.querySelector('.acurcb-close').onclick = ()=> panel.classList.remove('open');
  form.onsubmit = (e)=> { e.preventDefault(); ask(input.value.trim()); };
})();
