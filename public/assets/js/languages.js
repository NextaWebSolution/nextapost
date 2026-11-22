/* NextaPost System → Languages: fill missing interface text with a workspace's AI key, one batch at a time. */
document.addEventListener('alpine:init', () => {
  Alpine.data('languageTool', (cfg) => ({
    cfg,
    langs: cfg.languages || {},
    workspace: (cfg.workspaces[0] || {}).uuid || '',
    running: null,
    stopped: false,
    message: '',

    stop() { this.stopped = true; },

    async fill(code) {
      if (this.running !== null || !this.workspace) return;
      this.running = code;
      this.stopped = false;
      this.message = '';
      let total = 0;
      try {
        // Each request translates one batch; keep going until nothing is left, the person stops, or a batch fails.
        while (!this.stopped) {
          const res = await np.api(this.cfg.base + '/' + encodeURIComponent(code) + '/fill', { method: 'POST', body: { workspace: this.workspace } });
          total += res.filled || 0;
          if (res.status) this.langs[code] = res.status;
          if (!res.remaining || !res.filled) break;
        }
        this.message = np.t('{n} strings translated.', { n: total });
        if (total) np.toast(this.message);
      } catch (e) {
        this.message = e.message;
        np.toast(e.message, 'error');
      } finally {
        this.running = null;
      }
    },
  }));
});
