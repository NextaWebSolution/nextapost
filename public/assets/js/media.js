/* NextaPost media library page. */
document.addEventListener('alpine:init', () => {
  Alpine.data('mediaLibrary', (cfg) => ({
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
      if (!window.confirm('Delete ' + m.name + '?')) return;
      try {
        await np.api(cfg.base + '/' + m.id + '/delete', { method: 'POST', body: {} });
        this.items = this.items.filter((x) => x.id !== m.id);
        np.toast('File deleted.');
      } catch (e) { np.toast(e.message, 'error'); }
    },
    async editAlt(m) {
      const alt = m.type === 'document'
        ? window.prompt('Document title shown on LinkedIn (up to 400 characters). Leave empty to use the file name:', m.alt || '')
        : window.prompt('Describe this image for people using screen readers (alt text):', m.alt || '');
      if (alt === null) return;
      try {
        await np.api(cfg.base + '/' + m.id, { method: 'POST', body: { alt } });
        m.alt = alt;
        if (m.type === 'document') m.title = alt || m.name;
        np.toast(m.type === 'document' ? 'Title saved.' : 'Alt text saved.');
      } catch (e) { np.toast(e.message, 'error'); }
    },
  }));
});
