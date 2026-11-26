/* NextaPost — shared front-end helpers (loaded before Alpine). */
(function () {
  'use strict';

  const meta = (name) => document.querySelector('meta[name="' + name + '"]')?.content || '';
  window.np = window.np || {};
  np.csrf = () => meta('csrf');

  /** Interface text in the user's language (English text is the key; {name} placeholders). */
  np.t = (text, vars) => {
    let out = (window.NP_I18N && window.NP_I18N[text]) || text;
    for (const k in (vars || {})) out = out.split('{' + k + '}').join(String(vars[k]));
    return out;
  };

  /** fetch() wrapper: JSON in/out, CSRF header, readable errors (throws the parsed body). */
  np.api = async (url, opts = {}) => {
    const isForm = opts.body instanceof FormData;
    const res = await fetch(url, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: Object.assign({ Accept: 'application/json', 'X-CSRF-Token': np.csrf() }, isForm || !opts.body ? {} : { 'Content-Type': 'application/json' }, opts.headers || {}),
      body: opts.body ? (isForm ? opts.body : JSON.stringify(opts.body)) : undefined,
    });
    let data = {};
    try { data = await res.json(); } catch (e) { data = {}; }
    if (!res.ok) {
      const err = data && typeof data === 'object' ? data : {};
      err.status = res.status;
      err.message = (err.error && err.error.message) || (res.status === 413 ? np.t('That file is too large for the server.') : np.t('Something went wrong ({status}). Please try again.', { status: res.status }));
      throw err;
    }
    return data;
  };

  np.toast = (msg, type) => { if (window.Alpine) Alpine.store('toast').push(msg, type); };

  // The browser silently refuses to submit a form when a field it can't show (hidden, or in a closed panel) is invalid,
  // e.g. after a password manager autofills it. Say which field it is instead of leaving a button that does nothing.
  let invalidShown = 0;
  document.addEventListener('invalid', (e) => {
    const el = e.target;
    if (!(el instanceof HTMLElement) || el.getClientRects().length || Date.now() - invalidShown < 1000) return;
    invalidShown = Date.now();
    const lab = (el.id && document.querySelector('label[for="' + CSS.escape(el.id) + '"]')) || el.closest('label');
    const name = ((lab && lab.textContent) || el.getAttribute('aria-label') || el.name || '').trim();
    np.toast(np.t('Can’t save: “{field}” has an invalid value ({message}). It may have been filled in by your browser’s autofill.', { field: name, message: el.validationMessage }), 'error');
  }, true);
  np.esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  np.count = (s) => {
    s = String(s || '');
    try { return [...new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(s)].length; } catch (e) { return [...s].length; }
  };

  /** Avatar HTML for a channel object {name, avatar, color, letter}. */
  np.avatar = (c, size = 22) => {
    const img = c.avatar
      ? '<img src="' + np.esc(c.avatar) + '" alt="" referrerpolicy="no-referrer" style="width:100%;height:100%;border-radius:999px;object-fit:cover">'
      : '<span class="net-dot" style="width:100%;height:100%;font-size:' + Math.round(size * 0.42) + 'px;background:' + np.esc(c.color) + '">' + np.esc((c.name || '?').trim().charAt(0).toUpperCase()) + '</span>';
    return '<span title="' + np.esc(c.name) + '" style="position:relative;display:inline-flex;width:' + size + 'px;height:' + size + 'px;border-radius:999px;flex-shrink:0">' + img +
      '<span class="net-dot" style="position:absolute;right:-3px;bottom:-3px;width:' + Math.round(size * 0.55) + 'px;height:' + Math.round(size * 0.55) + 'px;font-size:' + Math.round(size * 0.3) + 'px;background:' + np.esc(c.color) + ';box-shadow:0 0 0 2px var(--np-panel)">' + np.esc(c.letter) + '</span></span>';
  };

  document.addEventListener('alpine:init', () => {
    Alpine.store('toast', {
      items: [],
      push(msg, type = 'success') {
        const id = Date.now() + Math.random();
        this.items.push({ id, msg, type });
        setTimeout(() => this.remove(id), type === 'error' ? 8000 : 4500);
      },
      remove(id) { this.items = this.items.filter((t) => t.id !== id); },
    });

    Alpine.data('appShell', () => ({
      nav: false,
      newWs: false,
      theme: document.documentElement.dataset.theme || 'system',
      init() {
        try {
          const f = JSON.parse(this.$root.dataset.flash || '{}');
          if (f.success) this.$store.toast.push(f.success, 'success');
          if (f.error) this.$store.toast.push(f.error, 'error');
        } catch (e) {}
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => { if (this.theme === 'system') this.applyTheme(); });
      },
      applyTheme() {
        let t = this.theme;
        if (t === 'system') t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.classList.toggle('dark', t === 'dark');
        document.documentElement.dataset.theme = this.theme;
      },
      async setTheme(t) {
        this.theme = t;
        this.applyTheme();
        try { await np.api(document.querySelector('meta[name="base"]').content.replace(/\/$/, '') + '/account/theme', { method: 'POST', body: { theme: t } }); } catch (e) {}
      },
      openNewWs() { this.newWs = true; this.$nextTick(() => document.getElementById('ws-name')?.focus()); },
      hotkeys(e) {
        if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey) return;
        const tag = (e.target.tagName || '').toLowerCase();
        if (['input', 'textarea', 'select'].includes(tag) || e.target.isContentEditable) return;
        if (e.key === 'n') { const a = document.querySelector('[data-hotkey="n"]'); if (a) { e.preventDefault(); a.click(); } }
      },
    }));

    /** Inline confirmation for destructive forms: <form x-data="confirmForm('Delete this?')" @submit="check"> */
    Alpine.data('confirmForm', (message) => ({
      check(e) { if (!window.confirm(message)) e.preventDefault(); },
    }));

    /** Workspace settings → AI writing assistant (provider-dependent fields + connection test). */
    Alpine.data('aiSettings', (o) => ({
      providers: o.providers,
      cfg: o.cfg,
      provider: o.cfg.provider || '',
      testing: false,
      testMsg: '',
      testOk: false,
      async test() {
        this.testing = true; this.testMsg = '';
        try {
          const d = await np.api(o.testUrl, { method: 'POST', body: {} });
          this.testOk = true; this.testMsg = d.message;
        } catch (e) {
          this.testOk = false; this.testMsg = e.message;
        } finally { this.testing = false; }
      },
    }));

    Alpine.data('copyText', (text) => ({
      copied: false,
      async copy() {
        try { await navigator.clipboard.writeText(text); } catch (e) {
          const t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove();
        }
        this.copied = true; setTimeout(() => (this.copied = false), 1600);
      },
    }));
  });
})();
