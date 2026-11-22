/* NextaPost media library page. */
document.addEventListener('alpine:init', () => {
  Alpine.data('mediaLibrary', (cfg) => ({
    cfg,
    items: cfg.items || [],
    canEdit: cfg.canEdit,
    uploading: 0,
    dropping: false,
    async upload(files) {
      for (const f of Array.from(files || [])) {
        if (f.size > cfg.maxUpload) { np.toast(f.name + ' is larger than the ' + Math.round(cfg.maxUpload / 1048576) + ' MB limit.', 'error'); continue; }
        this.uploading++;
        const fd = new FormData();
        fd.append('file', f);
        fd.append('_token', np.csrf());
        try {
          const d = await np.api(cfg.uploadUrl, { method: 'POST', body: fd });
          this.items.unshift(d.media);
        } catch (e) {
          np.toast(f.name + ': ' + e.message, 'error');
        } finally {
          this.uploading--;
        }
      }
    },
    async remove(m) {
      if (!window.confirm(np.t('Delete {name}?', { name: m.name }))) return;
      try {
        await np.api(cfg.base + '/' + m.id + '/delete', { method: 'POST', body: {} });
        this.items = this.items.filter((x) => x.id !== m.id);
        np.toast(np.t('File deleted.'));
      } catch (e) { np.toast(e.message, 'error'); }
    },
    aiBusy: null,

    // ---- Add from a source (link, Unsplash, Canva, Google Photos, Google Drive)
    src: null, srcBusy: false, waiting: false, url: '', q: '', photos: [], page: 1, pages: 0, designs: [], continuation: null, format: 'png',
    openSource(s) {
      this.src = s; this.q = ''; this.photos = []; this.designs = []; this.continuation = null;
      if (s === 'canva' && cfg.connected.canva) this.loadDesigns(true);
      // Focus once the dialog is on screen (x-show applies after this tick).
      setTimeout(() => { const el = document.getElementById(s === 'url' ? 'src-url' : (s === 'unsplash' ? 'src-q' : 'src-cq')); if (el && el.offsetParent) el.focus(); }, 30);
    },
    srcTitle() { return { url: np.t('Add from a link'), unsplash: 'Unsplash', canva: 'Canva', photos: 'Google Photos', drive: 'Google Drive' }[this.src] || ''; },
    addItems(list) {
      for (const m of list.slice().reverse()) this.items.unshift(m);
      np.toast(list.length === 1 ? np.t('Added to the library') : np.t('{n} files added to the library').replace('{n}', list.length), 'success');
    },
    async srcCall(path, body, method) {
      this.srcBusy = true;
      try {
        return await np.api(cfg.srcBase + path, body === undefined ? {} : { method: method || 'POST', body });
      } catch (e) {
        np.toast(e.message, 'error');
        if (e.reconnect) location.reload();
        return null;
      } finally { this.srcBusy = false; }
    },
    async importUrl() {
      const d = await this.srcCall('/url/import', { url: this.url });
      if (d) { this.addItems(d.media); this.url = ''; this.src = null; }
    },
    async searchUnsplash(page) {
      const d = await this.srcCall('/unsplash/search?q=' + encodeURIComponent(this.q) + '&page=' + page);
      if (!d) return;
      this.photos = d.results; this.page = page; this.pages = d.total_pages;
      if (!d.results.length) np.toast(np.t('No photos found.'), 'error');
    },
    async importUnsplash(ph) {
      const d = await this.srcCall('/unsplash/import', { id: ph.id });
      if (d) this.addItems(d.media);
    },
    async loadDesigns(reset) {
      const d = await this.srcCall('/canva/designs?q=' + encodeURIComponent(this.q) + (!reset && this.continuation ? '&continuation=' + encodeURIComponent(this.continuation) : ''));
      if (!d) return;
      this.designs = reset ? d.designs : this.designs.concat(d.designs);
      this.continuation = d.continuation;
    },
    async importCanva(dz) {
      np.toast(np.t('Exporting from Canva…'));
      const d = await this.srcCall('/canva/import', { design: dz.id, format: this.format });
      if (d) this.addItems(d.media);
    },
    async pickPhotos() {
      const tab = window.open('', '_blank');
      const s = await this.srcCall('/google-photos/session', {});
      if (!s) { if (tab) tab.close(); return; }
      if (tab) tab.location = s.picker_uri + '/autoclose'; else window.open(s.picker_uri + '/autoclose', '_blank');
      this.srcBusy = true; this.waiting = true;
      const until = Date.now() + 10 * 60 * 1000;
      while (Date.now() < until && this.src === 'photos') {
        await new Promise((r) => setTimeout(r, 3000));
        try {
          const p = await np.api(cfg.srcBase + '/google-photos/session/' + encodeURIComponent(s.id));
          if (p.done) {
            this.waiting = false;
            const d = await np.api(cfg.srcBase + '/google-photos/import', { method: 'POST', body: { session: s.id } });
            this.addItems(d.media);
            break;
          }
        } catch (e) { np.toast(e.message, 'error'); break; }
      }
      this.srcBusy = false; this.waiting = false;
    },
    loadScript(src) {
      return new Promise((resolve, reject) => {
        if (document.querySelector('script[src="' + src + '"]')) { resolve(); return; }
        const el = document.createElement('script'); el.src = src; el.onload = resolve; el.onerror = reject; document.head.appendChild(el);
      });
    },
    async pickDrive() {
      const c = await this.srcCall('/google-drive/picker');
      if (!c) return;
      try {
        await this.loadScript('https://apis.google.com/js/api.js');
        await new Promise((r) => window.gapi.load('picker', r));
      } catch (e) { np.toast(np.t('Google Drive couldn’t be opened.'), 'error'); return; }
      const P = window.google.picker;
      const view = new P.DocsView(P.ViewId.DOCS).setMimeTypes('image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,application/pdf').setIncludeFolders(true);
      const picker = new P.PickerBuilder().addView(view).enableFeature(P.Feature.MULTISELECT_ENABLED)
        .setOAuthToken(c.token).setDeveloperKey(c.api_key).setAppId(c.app_id).setOrigin(location.origin)
        .setCallback(async (data) => {
          if (data[P.Response.ACTION] !== P.Action.PICKED) return;
          const files = data[P.Response.DOCUMENTS].map((doc) => doc[P.Document.ID]);
          const d = await this.srcCall('/google-drive/import', { files });
          if (d) { this.addItems(d.media); this.src = null; }
        }).build();
      picker.setVisible(true);
    },
    async disconnect(s) {
      if (!window.confirm(np.t('Disconnect this account?'))) return;
      if (await this.srcCall('/' + s + '/disconnect', {})) { this.cfg.connected[s] = null; this.src = null; }
    },
    /** Ask the AI assistant for alt text, then let the person check and edit it before saving. */
    async aiAlt(m) {
      this.aiBusy = m.id;
      try {
        const d = await np.api(cfg.base + '/' + m.id + '/alt-text', { method: 'POST', body: {} });
        await this.editAlt(m, d.text);
      } catch (e) { np.toast(e.message, 'error'); } finally { this.aiBusy = null; }
    },
    async editAlt(m, suggestion) {
      const alt = m.type === 'document'
        ? window.prompt(np.t('Document title shown on LinkedIn (up to 400 characters). Leave empty to use the file name:'), m.alt || '')
        : window.prompt(np.t('Describe this image for people using screen readers (alt text):'), suggestion ?? (m.alt || ''));
      if (alt === null) return;
      try {
        await np.api(cfg.base + '/' + m.id, { method: 'POST', body: { alt } });
        m.alt = alt;
        if (m.type === 'document') m.title = alt || m.name;
        np.toast(m.type === 'document' ? np.t('Title saved.') : np.t('Alt text saved.'));
      } catch (e) { np.toast(e.message, 'error'); }
    },
  }));
});
