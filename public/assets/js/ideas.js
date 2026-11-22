/* NextaPost ideas board and feed inbox. */
document.addEventListener('alpine:init', () => {
  Alpine.data('ideasPage', (cfg) => ({
    cfg,
    tab: (location.hash || '#board').slice(1) === 'feeds' ? 'feeds' : 'board',
    stages: cfg.stages,
    ideas: cfg.ideas,
    feeds: cfg.feeds,
    items: cfg.items,
    q: '',
    draft: {},
    newStage: '',
    edit: null,
    errors: {},
    busy: false,
    dragging: null,
    dropAt: null,
    feedForm: { url: '', auto: 'inbox' },

    init() {
      this.$watch('tab', (t) => { history.replaceState(null, '', '#' + t); this.errors = {}; });
    },
    host(u) { try { return new URL(u).host.replace(/^www\./, ''); } catch (e) { return u || ''; } },
    excerpt(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; },
    when(s) { const d = new Date(s); return isNaN(d) ? '' : d.toLocaleDateString(document.documentElement.lang || undefined, { day: 'numeric', month: 'short' }); },
    inStage(st) {
      const q = this.q.trim().toLowerCase();
      return this.ideas.filter((i) => i.stage && i.stage.id === st.id && (!q || (i.title || '').toLowerCase().includes(q) || (i.body || '').toLowerCase().includes(q)));
    },

    async call(url, body) {
      this.busy = true;
      this.errors = {};
      try {
        return await np.api(cfg.base + url, { method: 'POST', body: body || {} });
      } catch (e) {
        this.errors = e.errors || {};
        if (!e.errors || !Object.keys(e.errors).length) np.toast(e.message, 'error');
        return null;
      } finally { this.busy = false; }
    },
    upsert(idea) {
      const i = this.ideas.findIndex((x) => x.id === idea.id);
      if (i >= 0) this.ideas.splice(i, 1, idea); else this.ideas.push(idea);
    },

    // ---- ideas
    async quickAdd(st) {
      const text = (this.draft[st.id] || '').trim();
      if (!text) return;
      const d = await this.call('/ideas', { title: text, stage: st.id });
      if (!d) return;
      this.upsert(d.idea);
      this.draft[st.id] = '';
    },
    openIdea(i) {
      this.errors = {};
      this.edit = { id: i.id, title: i.title || '', body: i.body || '', link: i.link || '', stage: i.stage ? i.stage.id : this.stages[0].id, labels: i.labels.map((l) => l.id) };
      this.$nextTick(() => { const el = document.getElementById('idea-title'); if (el) el.focus(); });
    },
    toggleLabel(id) { const l = this.edit.labels; const k = l.indexOf(id); if (k >= 0) l.splice(k, 1); else l.push(id); },
    async saveIdea() {
      const e = this.edit;
      const d = await this.call('/ideas/' + e.id, { title: e.title, body: e.body, link: e.link, stage: e.stage, labels: e.labels });
      if (!d) return;
      this.upsert(d.idea);
      this.edit = null;
      np.toast(np.t('Saved'), 'success');
    },
    async deleteIdea() {
      if (!window.confirm(np.t('Delete this idea?'))) return;
      if (await this.call('/ideas/' + this.edit.id + '/delete')) {
        this.ideas = this.ideas.filter((x) => x.id !== this.edit.id);
        this.edit = null;
      }
    },

    // ---- drag and drop between columns
    dragOver(ev, st) {
      if (!this.dragging) return;
      const cards = [...ev.currentTarget.querySelectorAll('article[data-idea]')].filter((c) => c.dataset.idea !== this.dragging.id);
      let index = cards.length;
      for (let k = 0; k < cards.length; k++) {
        const r = cards[k].getBoundingClientRect();
        if (ev.clientY < r.top + r.height / 2) { index = k; break; }
      }
      this.dropAt = { stage: st.id, index };
    },
    async drop(st) {
      const idea = this.dragging;
      const at = this.dropAt;
      this.dragging = null;
      this.dropAt = null;
      if (!idea || !at) return;
      const list = this.inStage(st).filter((x) => x.id !== idea.id);
      list.splice(at.index, 0, idea);
      idea.stage = { id: st.id, name: st.name };
      // Reorder locally so the card lands where it was dropped.
      const others = this.ideas.filter((x) => !list.includes(x));
      this.ideas = others.concat(list);
      if (!(await this.call('/ideas/' + idea.id + '/move', { stage: st.id, position: at.index }))) location.reload();
    },

    // ---- columns
    async addStage() {
      const d = await this.call('/idea-stages', { name: this.newStage });
      if (d) { this.stages.push(d.stage); this.newStage = ''; }
    },
    async renameStage(st) {
      const name = window.prompt(np.t('Column name'), st.name);
      if (name === null || !name.trim()) return;
      const d = await this.call('/idea-stages/' + st.id, { name });
      if (d) st.name = d.stage.name;
    },
    async deleteStage(st) {
      if (!window.confirm(np.t('Delete this column? Its ideas move to the first column.'))) return;
      if (await this.call('/idea-stages/' + st.id + '/delete')) location.reload();
    },

    // ---- feeds
    async addFeed() {
      const d = await this.call('/feeds', this.feedForm);
      if (!d) return;
      this.feeds.push(d.feed);
      this.items = d.items.concat(this.items.filter((x) => !d.items.some((y) => y.id === x.id)));
      this.feedForm.url = '';
      np.toast(np.t('Following'), 'success');
    },
    async setAuto(f) { await this.call('/feeds/' + f.id, { auto: f.auto }); },
    async refreshFeed(f) {
      const d = await this.call('/feeds/' + f.id + '/refresh');
      if (!d) return;
      f.error = d.error;
      this.items = d.items.concat(this.items.filter((x) => !d.items.some((y) => y.id === x.id)));
      np.toast(d.error ? d.error : np.t('{n} new').replace('{n}', d.new), d.error ? 'error' : 'success');
    },
    async deleteFeed(f) {
      if (!window.confirm(np.t('Stop following this feed?'))) return;
      if (await this.call('/feeds/' + f.id + '/delete')) {
        this.feeds = this.feeds.filter((x) => x.id !== f.id);
        this.items = this.items.filter((x) => !x.feed || x.feed.id !== f.id);
      }
    },
    async itemToIdea(it) {
      const d = await this.call('/feeds/items/' + it.id + '/idea');
      if (!d) return;
      this.upsert(d.idea);
      this.items = this.items.filter((x) => x.id !== it.id);
      np.toast(np.t('Saved as an idea'), 'success');
    },
    async itemToPost(it) {
      const d = await this.call('/feeds/items/' + it.id + '/idea');
      if (d) location.href = cfg.composeUrl + '?idea=' + d.idea.id;
    },
    async dismiss(it) {
      if (await this.call('/feeds/items/' + it.id + '/dismiss')) this.items = this.items.filter((x) => x.id !== it.id);
    },
  }));
});
