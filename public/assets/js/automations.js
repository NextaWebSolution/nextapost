/* NextaPost Comment-to-DM flows page. */
document.addEventListener('alpine:init', () => {
  Alpine.data('dmFlows', (cfg) => ({
    cfg,
    flows: cfg.flows,
    form: null,
    errors: {},
    busy: false,
    posts: null,
    activity: null,
    channel() { return this.form ? cfg.channels.find((c) => c.id === this.form.channel) : null; },
    fill(t) { return String(t || '').split('{name}').join('Riya').split('{username}').join('Riya'); },
    when(s) { const d = new Date(String(s).replace(' ', 'T') + 'Z'); return isNaN(d) ? '' : d.toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' }); },
    statusLabel(ev) {
      return {
        queued: np.t('Waiting to send'), sending: np.t('Sending'), waiting: np.t('DM sent, waiting for tap'),
        follow_asked: np.t('Asked to follow'), done: ev.clicked ? np.t('Link sent, clicked') : np.t('Link sent'),
        skipped: np.t('Skipped'), failed: np.t('Failed'),
      }[ev.status] || ev.status;
    },
    open(f) {
      this.errors = {};
      this.posts = null;
      if (f) {
        this.form = { ...f, keywords: f.keywords.join(', '), exclude_words: f.exclude_words.join(', '), public_replies: f.public_replies.join('\n'), media_ids: [...f.media_ids] };
        if (f.post_scope === 'posts') this.loadPosts();
      } else {
        const first = cfg.channels.find((c) => c.connected) || cfg.channels[0];
        this.form = { ...cfg.defaults, channel: first ? first.id : '', media_ids: [] };
      }
      this.$nextTick(() => { const el = document.getElementById('dm-name'); if (el) el.focus(); });
    },
    async loadPosts() {
      if (!this.form || !this.form.channel) return;
      this.posts = null;
      try { const d = await np.api(cfg.base + '/channels/' + this.form.channel + '/posts'); this.posts = d.posts; } catch (e) { this.posts = []; np.toast(e.message, 'error'); }
    },
    async call(url, body) {
      this.busy = true;
      try { return await np.api(cfg.base + url, { method: 'POST', body: body || {} }); } catch (e) {
        this.errors = e.errors || {};
        np.toast(e.message, 'error');
        return null;
      } finally { this.busy = false; }
    },
    async save() {
      this.errors = {};
      const f = this.form;
      const body = { ...f, keywords: f.keywords, exclude_words: f.exclude_words, public_replies: String(f.public_replies || '').split('\n'), ask_first: f.ask_first ? 1 : 0, require_follow: f.require_follow ? 1 : 0, once_per_user: f.once_per_user ? 1 : 0 };
      const d = await this.call(f.id ? '/' + f.id : '', body);
      if (!d) return;
      const i = this.flows.findIndex((x) => x.id === d.flow.id);
      if (i >= 0) this.flows.splice(i, 1, d.flow); else this.flows.unshift(d.flow);
      this.form = null;
      np.toast(np.t('Flow saved'), 'success');
    },
    async toggle(f) {
      const status = f.status === 'active' ? 'paused' : 'active';
      if (await this.call('/' + f.id + '/status', { status })) f.status = status;
    },
    async remove(f) {
      if (!window.confirm(np.t('Delete this flow and its activity log?'))) return;
      if (await this.call('/' + f.id + '/delete')) this.flows = this.flows.filter((x) => x.id !== f.id);
    },
    async showActivity(f) {
      try { const d = await np.api(cfg.base + '/' + f.id + '/activity'); this.activity = { flow: f, events: d.events }; } catch (e) { np.toast(e.message, 'error'); }
    },
    async connect(c) {
      if (await this.call('/channels/' + c.id + '/connect')) { c.connected = true; np.toast(np.t('DMs turned on for {name}', { name: c.name }), 'success'); }
    },
  }));
});
