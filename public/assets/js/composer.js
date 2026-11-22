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
    labels: [...(cfg.post.labels || [])],
    scheduleMode: cfg.post.scheduleMode === 'queue' ? 'queue' : 'custom',
    repeat: (() => {
      const r = cfg.post.recurrence || {};
      return { freq: r.freq || '', interval: r.interval || 1, ends: r.count ? 'count' : (r.until ? 'until' : 'never'), count: r.count || 5, until: r.until || '' };
    })(),
    targetOptions: Object.assign({}, cfg.post.targetOptions || {}),
    notes: [...(cfg.notes || [])],
    noteBody: '',
    noteMentions: [],
    noteBusy: false,
    ai: { busy: false, result: null, last: '', idea: '', ideaOpen: false, langOpen: false, lang: 'English', forTab: 'global' },

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
      this.$watch('labels', () => (this.dirty = true));
      this.$watch('repeat', () => (this.dirty = true), { deep: true });
      this.$watch('scheduleMode', () => (this.dirty = true));
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
      if (!this.publishAt) return np.t('Not scheduled');
      const d = new Date(this.publishAt + ':00Z');
      if (isNaN(d)) return this.publishAt;
      return d.toLocaleString(undefined, { timeZone: 'UTC', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    },

    async upload(files, target = null) {
      const key = target || '_';
      for (const f of Array.from(files || [])) {
        if (f.size > cfg.maxUpload) { np.toast(np.t('{file} is larger than the {mb} MB limit.', { file: f.name, mb: Math.round(cfg.maxUpload / 1048576) }), 'error'); continue; }
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

    // ---- labels, snippets
    hasLabel(id) { return this.labels.includes(id); },
    toggleLabel(id) { if (!this.editable) return; this.labels = this.hasLabel(id) ? this.labels.filter((x) => x !== id) : [...this.labels, id]; },
    /** Inserts text at the cursor of the text box being edited (shared text or a channel's own text). */
    insertText(text) {
      if (!this.editable) return;
      const own = this.tab !== 'global' && this.custom[this.tab];
      const el = this.$root.querySelector(own ? 'textarea[data-own]' : '#body');
      const cur = own ? (this.overrides[this.tab] || '') : this.body;
      const at = el && document.activeElement === el ? el.selectionStart : cur.length;
      const before = cur.slice(0, at);
      const sep = before && !/\n\s*$/.test(before) ? '\n\n' : '';
      const next = before + sep + text + cur.slice(at);
      if (own) this.overrides[this.tab] = next; else this.body = next;
      this.dirty = true;
      this.$nextTick(() => { if (el) { el.focus(); el.selectionStart = el.selectionEnd = (before + sep + text).length; } });
    },
    applyTemplate(t) {
      if (!this.editable) return;
      if (this.body.trim() && !window.confirm(np.t('Replace the current text with this template?'))) { this.insertText(t.body); return; }
      this.body = t.body;
      this.tab = 'global';
    },

    // ---- AI writing assistant
    aiActions() {
      const list = [['rephrase', np.t('Rephrase')], ['shorten', np.t('Make shorter')], ['expand', np.t('Make longer')], ['casual', np.t('More casual')],
        ['formal', np.t('More professional')], ['fix', np.t('Fix spelling and grammar')], ['hashtags', np.t('Add hashtags')], ['emoji', np.t('Add emoji')]];
      if (this.tab !== 'global') list.push(['fit', np.t('Fit this channel’s limit')]);
      return list;
    },
    aiCurrent() { return this.tab !== 'global' && this.custom[this.tab] ? (this.overrides[this.tab] || '') : this.body; },
    async aiRun(action, again = false) {
      if (!this.editable || this.ai.busy) return;
      const text = action === 'write' ? this.ai.idea : (again && this.ai.source !== undefined ? this.ai.source : this.aiCurrent());
      if (!text.trim()) { np.toast(action === 'write' ? np.t('Describe what the post should be about.') : np.t('Write some text first.'), 'error'); return; }
      this.ai.busy = true;
      this.ai.last = action;
      this.ai.source = text;
      this.ai.forTab = this.tab;
      try {
        const d = await np.api(cfg.ai.url, { method: 'POST', body: { action, text, channel: this.tab !== 'global' ? this.tab : '', language: this.ai.lang } });
        this.ai.result = d.text;
        this.ai.ideaOpen = false;
        this.ai.langOpen = false;
      } catch (e) {
        np.toast(e.message, 'error');
      } finally {
        this.ai.busy = false;
      }
    },
    aiApply(mode) {
      if (this.ai.result === null || !this.editable) return;
      if (this.tab !== this.ai.forTab) this.tab = this.ai.forTab;
      const own = this.tab !== 'global' && this.custom[this.tab];
      const cur = this.aiCurrent();
      const next = mode === 'append' && cur.trim() ? cur.replace(/\s+$/, '') + '\n\n' + this.ai.result : this.ai.result;
      if (own) this.overrides[this.tab] = next; else this.body = next;
      this.dirty = true;
      this.ai.result = null;
      this.ai.idea = '';
    },

    // ---- per-network post type
    typesFor(id) { return Object.entries(this.ch(id).contentTypes || {}); },
    optFor(id) { return (this.targetOptions[id] = this.targetOptions[id] || {}); },
    /** Option fields of a channel that apply to its chosen post type (defaults filled once). */
    fieldsFor(id) {
      if (id === 'global') return [];
      const c = this.ch(id);
      const types = Object.keys(c.contentTypes || {});
      const type = this.optFor(id).content_type || types[0] || null;
      const o = this.optFor(id);
      return (c.options || []).filter((f) => {
        if (f.types && f.types.length && !f.types.includes(type)) return false;
        if (o[f.key] === undefined && f.default !== undefined) o[f.key] = f.default;
        return true;
      });
    },
    listFor(id, f) {
      const o = this.optFor(id);
      if (!Array.isArray(o[f.key])) o[f.key] = [];
      const n = Math.max(f.min || 2, Math.min(f.max || 4, o[f.key].filter((x) => x !== '').length + 1));
      while (o[f.key].length < n) o[f.key].push('');
      return o[f.key];
    },

    // ---- scheduling
    queueMissing() { return this.selected.filter((id) => !this.ch(id).hasSchedule).map((id) => this.ch(id).name); },
    recurrencePayload() {
      const r = this.repeat;
      if (!r.freq) return null;
      return { freq: r.freq, interval: Number(r.interval) || 1, count: r.ends === 'count' ? Number(r.count) || null : null, until: r.ends === 'until' ? r.until || null : null };
    },

    // ---- notes
    mention(m) {
      if (!this.noteMentions.includes(m.id)) this.noteMentions.push(m.id);
      this.noteBody = (this.noteBody ? this.noteBody.replace(/\s*$/, ' ') : '') + '@' + m.name + ' ';
      this.$nextTick(() => this.$refs.note && this.$refs.note.focus());
    },
    async addNote() {
      if (!this.noteBody.trim() || this.noteBusy) return;
      this.noteBusy = true;
      try {
        const mentions = this.noteMentions.filter((id) => { const m = (cfg.members || []).find((x) => x.id === id); return m && this.noteBody.includes('@' + m.name); });
        const d = await np.api(cfg.notesUrl, { method: 'POST', body: { body: this.noteBody, mentions } });
        this.notes.push(d.note);
        this.noteBody = '';
        this.noteMentions = [];
      } catch (e) { np.toast(e.message, 'error'); }
      this.noteBusy = false;
    },
    async deleteNote(n) {
      if (!window.confirm(np.t('Delete this note?'))) return;
      try {
        await np.api(cfg.notesUrl + '/' + n.id + '/delete', { method: 'POST', body: {} });
        this.notes = this.notes.filter((x) => x.id !== n.id);
      } catch (e) { np.toast(e.message, 'error'); }
    },

    primary() {
      if (!this.editable || this.saving) return;
      this.save(cfg.canPublish ? 'schedule' : 'review');
    },
    async save(action) {
      if (this.uploading) { np.toast(np.t('Wait for uploads to finish.'), 'error'); return; }
      if (action === 'now' && !window.confirm(np.t('Publish to {n} channel(s) right now?', { n: this.selected.length }))) return;
      this.saving = true;
      this.errors = {};
      const overrides = {};
      for (const id of this.selected) if (this.custom[id]) overrides[id] = this.overrides[id] || '';
      const targetOptions = {};
      for (const id of this.selected) {
        const src = this.targetOptions[id];
        const o = {};
        for (const [k, v] of Object.entries(src || {})) o[k] = Array.isArray(v) ? v.filter((x) => String(x).trim() !== '') : v;
        if (Object.keys(o).some((k) => o[k] !== '' && o[k] !== null && o[k] !== undefined && !(Array.isArray(o[k]) && !o[k].length))) targetOptions[id] = o;
      }
      const mediaOverrides = {};
      for (const id of this.selected) if (this.customMedia[id]) mediaOverrides[id] = (this.mediaFor[id] || []).map((m) => m.id);
      try {
        const d = await np.api(cfg.saveUrl, {
          method: 'POST',
          body: {
            action, body: this.body, channels: this.selected, overrides, media: this.media.map((m) => m.id), media_overrides: mediaOverrides,
            publish_at: this.scheduleMode === 'queue' ? '' : this.publishAt, schedule_mode: this.scheduleMode, labels: this.labels,
            recurrence: this.recurrencePayload(), target_options: targetOptions, version: cfg.post.version || undefined, idea: cfg.post.idea || undefined,
          },
        });
        this.dirty = false;
        window.location.href = d.redirect;
      } catch (e) {
        this.errors = e.errors || {};
        const firstChannel = this.selected.find((id) => this.errors[id]);
        if (firstChannel) { this.tab = firstChannel; this.previewId = firstChannel; }
        np.toast(e.message || np.t('Could not save.'), 'error');
        this.saving = false;
      }
    },
  }));
});
