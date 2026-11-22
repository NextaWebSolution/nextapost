/* NextaPost channels page: posting times (queue schedule) editor. */
document.addEventListener('alpine:init', () => {
  Alpine.data('channelSchedules', (cfg) => ({
    cfg,
    days: cfg.days,
    ch: null,
    id: null,
    schedule: {},
    newTime: {},
    tz: '',
    goal: 5,
    slots: [],
    error: '',
    busy: false,

    open(id) {
      const c = cfg.channels[id];
      if (!c) return;
      this.id = id;
      this.ch = c;
      this.schedule = JSON.parse(JSON.stringify(c.schedule));
      this.tz = c.tz || '';
      this.goal = c.goal || 5;
      this.slots = [];
      this.error = '';
      this.newTime = {};
    },
    addTime(day) {
      const t = (this.newTime[day] || '').slice(0, 5);
      if (!/^\d{2}:\d{2}$/.test(t)) return;
      if (!this.schedule[day].includes(t)) this.schedule[day] = [...this.schedule[day], t].sort();
      this.newTime[day] = '';
    },
    clearAll() { for (const d of this.days) this.schedule[d.key] = []; },
    async generate() {
      this.busy = true;
      try {
        const r = await np.api(cfg.base + '/' + this.id + '/schedule', { method: 'POST', body: { generate: 1, goal: this.goal } });
        this.schedule = r.schedule;
      } catch (e) { this.error = e.message; }
      this.busy = false;
    },
    async save() {
      this.busy = true;
      this.error = '';
      try {
        const r = await np.api(cfg.base + '/' + this.id + '/schedule', { method: 'POST', body: { schedule: this.schedule, timezone: this.tz, goal: this.goal } });
        cfg.channels[this.id] = Object.assign({}, this.ch, { schedule: r.schedule, tz: this.tz, goal: this.goal });
        this.slots = r.slots || [];
        np.toast(np.t('Saved'), 'success');
      } catch (e) { this.error = e.message; }
      this.busy = false;
    },
  }));
});
