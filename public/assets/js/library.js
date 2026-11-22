/* NextaPost labels, signatures and templates page. */
document.addEventListener('alpine:init', () => {
  Alpine.data('libraryPage', (cfg) => ({
    cfg,
    tab: (location.hash || '#labels').slice(1),
    labels: cfg.labels || [],
    signatures: cfg.signatures || [],
    templates: cfg.templates || [],
    labelForm: { id: null, name: '', color: cfg.colors[0] },
    sigForm: { id: null, name: '', content: '', is_default: false },
    tplForm: { id: null, title: '', body: '', visibility: 'workspace' },
    errors: {},
    busy: false,

    init() {
      if (!['labels', 'signatures', 'templates'].includes(this.tab)) this.tab = 'labels';
      this.$watch('tab', (t) => { history.replaceState(null, '', '#' + t); this.errors = {}; });
    },

    async send(url, body) {
      this.busy = true;
      this.errors = {};
      try {
        return await np.api(url, { method: 'POST', body });
      } catch (e) {
        this.errors = e.errors || {};
        if (!e.errors) np.toast(e.message, 'error');
        return null;
      } finally {
        this.busy = false;
      }
    },
    upsert(list, row) {
      const i = list.findIndex((x) => x.uuid === row.uuid);
      if (i >= 0) list.splice(i, 1, row); else list.push(row);
    },

    resetLabel() { this.labelForm = { id: null, name: '', color: cfg.colors[0] }; this.errors = {}; },
    editLabel(l) { this.labelForm = { id: l.uuid, name: l.name, color: l.color }; this.$nextTick(() => document.getElementById('lbl-name').focus()); },
    async saveLabel() {
      const f = this.labelForm;
      const d = await this.send(cfg.base + '/labels' + (f.id ? '/' + f.id : ''), { name: f.name, color: f.color });
      if (!d) return;
      this.upsert(this.labels, d.label);
      this.labels.sort((a, b) => a.name.localeCompare(b.name));
      this.resetLabel();
      np.toast(np.t('Saved'), 'success');
    },

    resetSig() { this.sigForm = { id: null, name: '', content: '', is_default: false }; this.errors = {}; },
    editSig(s) { this.sigForm = { id: s.uuid, name: s.name, content: s.content, is_default: Number(s.is_default) === 1 }; this.$nextTick(() => document.getElementById('sig-name').focus()); },
    async saveSignature() {
      const f = this.sigForm;
      const d = await this.send(cfg.base + '/signatures' + (f.id ? '/' + f.id : ''), { name: f.name, content: f.content, is_default: f.is_default ? 1 : 0 });
      if (!d) return;
      if (Number(d.signature.is_default)) this.signatures.forEach((s) => (s.is_default = 0));
      this.upsert(this.signatures, d.signature);
      this.resetSig();
      np.toast(np.t('Saved'), 'success');
    },

    resetTpl() { this.tplForm = { id: null, title: '', body: '', visibility: 'workspace' }; this.errors = {}; },
    editTpl(t) { this.tplForm = { id: t.uuid, title: t.title, body: t.body, visibility: t.visibility }; this.$nextTick(() => document.getElementById('tpl-title').focus()); },
    async saveTemplate() {
      const f = this.tplForm;
      const d = await this.send(cfg.base + '/templates' + (f.id ? '/' + f.id : ''), { title: f.title, body: f.body, visibility: f.visibility });
      if (!d) return;
      this.upsert(this.templates, d.template);
      this.resetTpl();
      np.toast(np.t('Saved'), 'success');
    },

    async remove(kind, row) {
      if (!window.confirm(np.t('Delete this?'))) return;
      try {
        await np.api(cfg.base + '/' + kind + '/' + row.uuid + '/delete', { method: 'POST', body: {} });
        this[kind] = this[kind].filter((x) => x.uuid !== row.uuid);
        np.toast(np.t('Deleted'), 'success');
      } catch (e) { np.toast(e.message, 'error'); }
    },
  }));
});
