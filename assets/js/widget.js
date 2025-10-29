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
      <span>Chatbot</span>
      <button class="acurcb-close" aria-label="Close">×</button>
    </div>
    <div class="acurcb-body" id="acurcb-body"></div>
    <form id="acurcb-form">
      <input id="acurcb-input" type="text" placeholder="Type your question..." autocomplete="off" required />
      <button type="submit" aria-label="Send message">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
          <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
        </svg>
      </button>
    </form>
  `;
  document.body.append(btn, panel);

  const body = panel.querySelector('#acurcb-body');
  const input = panel.querySelector('#acurcb-input');
  const form = panel.querySelector('#acurcb-form');

  // Conversation state
  let conversationStarted = false;
  let sessionHistory = [];

  function addMsg(role, html) {
    const el = document.createElement('div');
    el.className = 'acurcb-msg ' + role;
    el.innerHTML = html;
    body.appendChild(el);
    body.scrollTop = body.scrollHeight;
  }

  function showWelcomeMessage() {
    if (!conversationStarted) {
      conversationStarted = true;
      const welcomeMessages = [
        "Hi there! 👋 I'm here to help answer your questions. What can I assist you with today?",
        "Hello! Welcome to ACUR support. How can I help you today?",
        "Hi! I'm your virtual assistant. Feel free to ask me anything!"
      ];
      const welcomeMsg = welcomeMessages[Math.floor(Math.random() * welcomeMessages.length)];
      addMsg('bot', welcomeMsg);
    }
  }

  function detectIntent(question) {
    const q = question.toLowerCase().trim();

    // Greetings
    if (/^(hi|hello|hey|good morning|good afternoon|good evening)/.test(q)) {
      return { type: 'greeting', confidence: 0.9 };
    }

    // Thanks
    if (/^(thank|thanks|thx|ty|thank you)/.test(q)) {
      return { type: 'thanks', confidence: 0.9 };
    }

    // Simple responses
    if (/^(bye|goodbye|see you|exit|quit)$/.test(q)) {
      return { type: 'goodbye', confidence: 0.9 };
    }

    return { type: 'question', confidence: 0.7 };
  }

  function getConversationalResponse(intent, answer = null, score = 0) {
    const responses = {
      greeting: [
        "Hello! Great to meet you. What can I help you with?",
        "Hi there! I'm here to assist you. What's your question?",
        "Hey! How can I help you today?"
      ],
      thanks: [
        "You're very welcome! Is there anything else I can help you with?",
        "Happy to help! Do you have any other questions?",
        "Glad I could assist! Feel free to ask if you need anything else."
      ],
      goodbye: [
        "Goodbye! Feel free to come back anytime if you have more questions.",
        "See you later! I'm here whenever you need help.",
        "Take care! Don't hesitate to ask if you need assistance again."
      ]
    };

    if (responses[intent.type]) {
      return responses[intent.type][Math.floor(Math.random() * responses[intent.type].length)];
    }

    // For FAQ answers, add conversational context
    if (answer && score > 0.7) {
      const intros = [
        "Great question! ",
        "I can help with that. ",
        "Here's what I found: ",
        "Let me explain: "
      ];
      return intros[Math.floor(Math.random() * intros.length)] + answer;
    } else if (answer && score > 0.4) {
      const intros = [
        "I think this might help: ",
        "This could be what you're looking for: ",
        "Here's something related: "
      ];
      return intros[Math.floor(Math.random() * intros.length)] + answer;
    }

    return answer;
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
    // Store conversation history
    sessionHistory.push({ role: 'user', content: q, timestamp: Date.now() });

    addMsg('user', q);
    input.value = ''; input.focus();

    // Detect intent first
    const intent = detectIntent(q);

    // Handle simple conversational responses
    if (intent.type !== 'question') {
      setTimeout(() => {
        const response = getConversationalResponse(intent);
        addMsg('bot', response);
        sessionHistory.push({ role: 'bot', content: response, timestamp: Date.now() });
      }, 500 + Math.random() * 1000); // More natural delay
      return;
    }

    // Show thinking with more realistic delay
    addMsg('bot', '<em>Let me think about that...</em>');

    try {
      // Add realistic delay for thinking
      await new Promise(resolve => setTimeout(resolve, 800 + Math.random() * 1200));

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

      // Generate conversational response
      const conversationalAnswer = getConversationalResponse(intent, data.answer, data.score);
      body.lastChild.innerHTML = conversationalAnswer;

      // Store in history
      sessionHistory.push({ role: 'bot', content: conversationalAnswer, timestamp: Date.now() });

      // Show follow-up suggestions for good matches
      if (data.score > 0.6) {
        setTimeout(() => {
          const suggestions = [
            "Is there anything else you'd like to know about this topic?",
            "Would you like me to explain anything in more detail?",
            "Do you have any follow-up questions?"
          ];
          const suggestion = suggestions[Math.floor(Math.random() * suggestions.length)];
          addMsg('bot', `<em>${suggestion}</em>`);
        }, 2000);
      }

      showHelpful(data.id || null);

      if (Array.isArray(data.alternates) && data.alternates.length) {
        const alts = document.createElement('div');
        alts.className = 'acurcb-alts';
        alts.innerHTML = '<div class="title">You might also be interested in:</div>' +
          data.alternates.slice(0,3).map(a => `<button type="button" class="alt">${a.question}</button>`).join('');
        alts.querySelectorAll('button.alt').forEach(b=>{
          b.onclick = ()=> ask(b.innerText);
        });
        body.appendChild(alts);
      }
    } catch (e) {
      body.lastChild.innerHTML = 'Sorry—I\'m having trouble connecting right now. Could you try again?';
    }
  }

  // events
  btn.onclick = ()=> {
    panel.classList.toggle('open');
    input.focus();
    showWelcomeMessage();
  };
  panel.querySelector('.acurcb-close').onclick = ()=> panel.classList.remove('open');
  form.onsubmit = (e)=> { e.preventDefault(); ask(input.value.trim()); };
})();
