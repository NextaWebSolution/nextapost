/* NextaPost repurpose rules page. */
document.addEventListener('alpine:init', () => {
  Alpine.data('repurposePage', (cfg) => ({
    cfg,
    rules: cfg.rules,
    form: { source: '', format: 'reel', mode: 'draft', destinations: [] },
    errors: {},
    busy: false,
    sources() { return cfg.channels.filter((c) => c.source); },
    when(s) { const d = new Date(s); return isNaN(d) ? '' : d.toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' }); },
    async call(url, body) {
      this.busy = true; this.errors = {};
      try { return await np.api(cfg.base + url, { method: 'POST', body: body || {} }); } catch (e) {
        this.errors = e.errors || {};
        if (!e.errors || !Object.keys(e.errors).length) np.toast(e.message, 'error');
        return null;
      } finally { this.busy = false; }
    },
    async create() {
      const d = await this.call('', this.form);
      if (!d) return;
      this.rules.unshift(d.rule);
      this.form = { source: '', format: 'reel', mode: 'draft', destinations: [] };
      np.toast(np.t('Rule created'), 'success');
    },
    async run(r) {
      const d = await this.call('/' + r.id + '/run');
      if (!d) return;
      Object.assign(r, d.rule);
      np.toast(np.t('{n} new post(s) made').replace('{n}', d.made), 'success');
    },
    async toggle(r) {
      const status = r.status === 'active' ? 'paused' : 'active';
      if (await this.call('/' + r.id + '/status', { status })) { r.status = status; if (status === 'active') r.error = null; }
    },
    async remove(r) {
      if (!window.confirm(np.t('Delete this rule? Posts it already made are kept.'))) return;
      if (await this.call('/' + r.id + '/delete')) this.rules = this.rules.filter((x) => x.id !== r.id);
    },
  }));
});
