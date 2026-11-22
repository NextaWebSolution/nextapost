/* NextaPost Developers & AI page: API keys, connected apps, webhooks. */
document.addEventListener('alpine:init', () => {
  Alpine.data('developersPage', (cfg) => ({
    cfg,
    tab: (location.hash || '#agents').slice(1),
    keys: cfg.keys || [],
    apps: cfg.apps || [],
    hooks: cfg.webhooks || [],
    keyForm: { name: '', role: (cfg.roles[1] || cfg.roles[0] || {}).id || 'viewer', expires_days: '0' },
    hookForm: { url: '', all: true, events: [] },
    newToken: '',
    newSecret: '',
    openLog: null,
    log: [],
    errors: {},
    busy: false,

    init() {
      const tabs = ['agents', 'keys'].concat(cfg.canWebhooks ? ['webhooks'] : []);
      if (!tabs.includes(this.tab)) this.tab = 'agents';
      this.$watch('tab', (t) => { history.replaceState(null, '', '#' + t); this.errors = {}; });
    },

    when(s) {
      if (!s) return '';
      const d = new Date(/Z$|[+-]\d\d:?\d\d$/.test(s) ? s : s.replace(' ', 'T') + 'Z');
      return isNaN(d) ? s : d.toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' });
    },
    roleLabel(id) { return (cfg.roles.find((r) => r.id === id) || { label: id }).label; },
    mcpConfig() {
      return JSON.stringify({ mcpServers: { nextapost: { url: cfg.mcpUrl, headers: { Authorization: 'Bearer YOUR_API_KEY' } } } }, null, 2);
    },
    curlExample() {
      return 'curl ' + cfg.apiUrl + '/posts \\\n  -H "Authorization: Bearer YOUR_API_KEY" \\\n  -H "Content-Type: application/json" \\\n  -d \'{"text":"Hello from the API","channels":["CHANNEL_ID"],"publish_at":"2026-12-01T09:30:00+05:30"}\'';
    },

    async send(url, body, method = 'POST') {
      this.busy = true;
      this.errors = {};
      try {
        return await np.api(url, { method, body });
      } catch (e) {
        this.errors = e.errors || {};
        if (!e.errors || !Object.keys(e.errors).length) np.toast(e.message, 'error');
        return null;
      } finally {
        this.busy = false;
      }
    },

    // API keys
    async createKey() {
      const d = await this.send(cfg.base + '/keys', { name: this.keyForm.name, role: this.keyForm.role, expires_days: Number(this.keyForm.expires_days) });
      if (!d) return;
      this.keys.unshift(d.key);
      this.newToken = d.token;
      this.keyForm.name = '';
    },
    async revoke(k) {
      if (!window.confirm(np.t('Revoke this key? Apps using it will stop working.'))) return;
      if (await this.send(cfg.base + '/keys/' + k.id + '/revoke', {})) {
        this.keys = this.keys.filter((x) => x.id !== k.id);
        np.toast(np.t('Key revoked'), 'success');
      }
    },
    async disconnect(a) {
      if (!window.confirm(np.t('Disconnect this app? It will need to sign in again.'))) return;
      if (await this.send(cfg.base + '/apps/' + a.id + '/disconnect', {})) {
        this.apps = this.apps.filter((x) => x.id !== a.id);
        np.toast(np.t('Disconnected'), 'success');
      }
    },

    // Webhooks
    replaceHook(h) {
      const i = this.hooks.findIndex((x) => x.id === h.id);
      if (i >= 0) this.hooks.splice(i, 1, h); else this.hooks.push(h);
    },
    async createHook() {
      const events = this.hookForm.all ? ['*'] : this.hookForm.events;
      const d = await this.send(cfg.base + '/webhooks', { url: this.hookForm.url, events });
      if (!d) return;
      this.newSecret = d.data.secret;
      delete d.data.secret;
      this.replaceHook(d.data);
      this.hookForm = { url: '', all: true, events: [] };
    },
    async setStatus(h, status) {
      const d = await this.send(cfg.base + '/webhooks/' + h.id, { status });
      if (d) this.replaceHook(d.data);
    },
    async rotate(h) {
      if (!window.confirm(np.t('Make a new signing secret? The old one stops working right away.'))) return;
      const d = await this.send(cfg.base + '/webhooks/' + h.id + '/rotate', {});
      if (d) this.newSecret = d.data.secret;
    },
    async deleteHook(h) {
      if (!window.confirm(np.t('Delete this webhook?'))) return;
      if (await this.send(cfg.base + '/webhooks/' + h.id + '/delete', {})) {
        this.hooks = this.hooks.filter((x) => x.id !== h.id);
        np.toast(np.t('Deleted'), 'success');
      }
    },
    async loadLog(h) {
      try {
        const d = await np.api(cfg.base + '/webhooks/' + h.id + '/deliveries');
        this.log = d.data.deliveries;
      } catch (e) { np.toast(e.message, 'error'); }
    },
    async toggleLog(h) {
      if (this.openLog === h.id) { this.openLog = null; return; }
      this.openLog = h.id;
      this.log = [];
      await this.loadLog(h);
    },
    async testHook(h) {
      const d = await this.send(cfg.base + '/webhooks/' + h.id + '/test', {});
      if (!d) return;
      const ok = d.data.status === 'delivered';
      np.toast(ok ? np.t('Test delivered') : np.t('Test failed') + (d.data.response_code ? ' (HTTP ' + d.data.response_code + ')' : ''), ok ? 'success' : 'error');
      if (this.openLog === h.id) await this.loadLog(h);
    },
    async replay(h, dl) {
      const d = await this.send(cfg.base + '/webhooks/' + h.id + '/deliveries/' + dl.id + '/replay', {});
      if (d) { np.toast(np.t('Sent again'), 'success'); await this.loadLog(h); }
    },
  }));
});
