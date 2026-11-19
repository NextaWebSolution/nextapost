/* NextaPost composer: multi-channel text with per-channel overrides, media, schedule, preview. */
document.addEventListener('alpine:init', () => {
  Alpine.data('composer', (cfg) => ({
    cfg,
    editable: cfg.editable,
    body: cfg.post.body || '',
    selected: [],
    overrides: Object.assign({}, cfg.post.overrides || {}),
    custom: {},
    media: [...(cfg.post.media || [])],
    mediaFor: Object.assign({}, cfg.post.mediaOverrides || {}),
    customMedia: {},
    uploadingFor: {},
    publishAt: cfg.post.publish_at || '',
    tab: 'global',
    pane: 'write',
    previewId: null,
    saving: false,
    dirty: false,
    uploading: 0,
    errors: {},
    library: { open: false, items: [], loaded: false, target: null },

    init() {
      const known = new Set(cfg.channels.map((c) => c.id));
      this.selected = (cfg.post.channels || []).filter((id) => known.has(id));
      for (const id of Object.keys(this.overrides)) this.custom[id] = true;
      for (const id of Object.keys(this.mediaFor)) this.customMedia[id] = true;
      if (!this.selected.length && this.activeChannels.length === 1) this.selected = [this.activeChannels[0].id];
      this.previewId = this.selected[0] || null;
      this.$watch('body', () => (this.dirty = true));
      this.$watch('media', () => (this.dirty = true));
      this.$watch('mediaFor', () => (this.dirty = true), { deep: true });
      this.$watch('selected', () => (this.dirty = true));
      window.addEventListener('beforeunload', (e) => { if (this.dirty && !this.saving && this.editable) { e.preventDefault(); e.returnValue = ''; } });
      this.$nextTick(() => { if (this.editable && this.$refs.body && !this.body) this.$refs.body.focus(); });
    },

    get activeChannels() { return cfg.channels.filter((c) => c.active); },
    ch(id) { return cfg.channels.find((c) => c.id === id) || { name: '?', color: '#888', letter: '?', limit: 0 }; },
    isSel(id) { return this.selected.includes(id); },
    toggle(id) {
      if (!this.editable) return;
      if (this.isSel(id)) {
        this.selected = this.selected.filter((x) => x !== id);
        if (this.tab === id) this.tab = 'global';
        if (this.previewId === id) this.previewId = this.selected[0] || null;
      } else {
        this.selected.push(id);
        if (!this.previewId) this.previewId = id;
      }
      delete this.errors[id];
    },
    toggleAll() {
      this.selected = this.selected.length === this.activeChannels.length ? [] : this.activeChannels.map((c) => c.id);
      this.previewId = this.selected[0] || null;
      if (!this.selected.includes(this.tab)) this.tab = 'global';
    },
    customize(id, on) {
      this.custom[id] = on;
      if (on && !this.overrides[id]) this.overrides[id] = this.body;
      this.dirty = true;
    },
    textFor(id) { return this.custom[id] ? (this.overrides[id] || '') : this.body; },
    countFor(id) {
      let t = this.textFor(id);
      const c = this.ch(id);
      if (c.countUrlsAs) t = t.replace(/https?:\/\/\S+/g, 'x'.repeat(c.countUrlsAs));
      return np.count(t);
    },
    limitFor(id) {
      const c = this.ch(id);
      return this.mediaOf(id).length && c.captionLimit ? c.captionLimit : c.limit;
    },

    // ---- media lists: target null = shared media, otherwise a channel id with its own media
    listOf(target) { return target ? (this.mediaFor[target] = this.mediaFor[target] || []) : this.media; },
    mediaOf(channelId) { return this.customMedia[channelId] ? (this.mediaFor[channelId] || []) : this.media; },
    customizeMedia(id, on) {
      this.customMedia[id] = on;
      if (on && !this.mediaFor[id]) this.mediaFor[id] = this.media.filter((m) => m.type !== 'document' || this.supportsDocs(id));
      this.dirty = true;
    },
    removeAt(target, i) { this.listOf(target).splice(i, 1); },
    moveIn(target, i, dir) {
      const list = this.listOf(target);
      const j = i + dir;
      if (j < 0 || j >= list.length) return;
      [list[i], list[j]] = [list[j], list[i]];
    },
    supportsDocs(id) { return (this.ch(id).types || []).includes('document'); },
    typesLabel(id) {
      const t = this.ch(id).types || [];
      return [t.includes('image') && 'images', t.includes('video') && 'video', t.includes('document') && 'documents (PDF, PPTX, DOCX)'].filter(Boolean).join(', ') || 'text only';
    },
    /** Selected channels that would receive a document they can't post. */
    docConflicts() { return this.selected.filter((id) => !this.supportsDocs(id) && this.mediaOf(id).some((m) => m.type === 'document')); },
    previewDoc() { return this.previewId ? this.mediaOf(this.previewId).find((m) => m.type === 'document') || null : null; },
    visualMedia() { return this.previewId ? this.mediaOf(this.previewId).filter((m) => m.type !== 'document') : this.media; },
    over(id) { return this.countFor(id) > this.limitFor(id); },
    previewWhen() {
      if (!this.publishAt) return 'Not scheduled';
      const d = new Date(this.publishAt + ':00Z');
      if (isNaN(d)) return this.publishAt;
      return d.toLocaleString(undefined, { timeZone: 'UTC', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    },

    async upload(files, target = null) {
      const key = target || '_';
      for (const f of Array.from(files || [])) {
        if (f.size > cfg.maxUpload) { np.toast(f.name + ' is larger than the ' + Math.round(cfg.maxUpload / 1048576) + ' MB limit.', 'error'); continue; }
        this.uploading++;
        this.uploadingFor[key] = (this.uploadingFor[key] || 0) + 1;
        const fd = new FormData();
        fd.append('file', f);
        fd.append('_token', np.csrf());
        try {
          const d = await np.api(cfg.uploadUrl, { method: 'POST', body: fd });
          this.listOf(target).push(d.media);
          this.library.loaded = false;
        } catch (e) {
          np.toast(f.name + ': ' + e.message, 'error');
        } finally {
          this.uploading--;
          this.uploadingFor[key]--;
        }
      }
    },
    async openLibrary(target = null) {
      this.library.target = target;
      this.library.open = true;
      if (this.library.loaded) return;
      try {
        const d = await np.api(cfg.libraryUrl);
        this.library.items = d.media || [];
      } catch (e) { np.toast(e.message, 'error'); }
      this.library.loaded = true;
    },
    hasMedia(id) { return this.listOf(this.library.target).some((m) => m.id === id); },
    pick(m) {
      const list = this.listOf(this.library.target);
      const i = list.findIndex((x) => x.id === m.id);
      i >= 0 ? list.splice(i, 1) : list.push(m);
    },
    moveMedia(i, dir) {
      const j = i + dir;
      if (j < 0 || j >= this.media.length) return;
      const copy = [...this.media];
      [copy[i], copy[j]] = [copy[j], copy[i]];
      this.media = copy;
    },

    primary() {
      if (!this.editable || this.saving) return;
      this.save(cfg.canPublish ? 'schedule' : 'review');
    },
    async save(action) {
      if (this.uploading) { np.toast('Wait for uploads to finish.', 'error'); return; }
      if (action === 'now' && !window.confirm('Publish to ' + this.selected.length + ' channel(s) right now?')) return;
      this.saving = true;
      this.errors = {};
      const overrides = {};
      for (const id of this.selected) if (this.custom[id]) overrides[id] = this.overrides[id] || '';
      const mediaOverrides = {};
      for (const id of this.selected) if (this.customMedia[id]) mediaOverrides[id] = (this.mediaFor[id] || []).map((m) => m.id);
      try {
        const d = await np.api(cfg.saveUrl, {
          method: 'POST',
          body: { action, body: this.body, channels: this.selected, overrides, media: this.media.map((m) => m.id), media_overrides: mediaOverrides, publish_at: this.publishAt, version: cfg.post.version || undefined },
        });
        this.dirty = false;
        window.location.href = d.redirect;
      } catch (e) {
        this.errors = e.errors || {};
        const firstChannel = this.selected.find((id) => this.errors[id]);
        if (firstChannel) { this.tab = firstChannel; this.previewId = firstChannel; }
        np.toast(e.message || 'Could not save.', 'error');
        this.saving = false;
      }
    },
  }));
});
