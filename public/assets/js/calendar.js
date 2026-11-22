/* NextaPost calendar: week / month / list views with drag-and-drop rescheduling.
   All dates are wall-clock dates in the workspace time zone, handled as UTC Date objects
   so the browser's own time zone never shifts them. */
document.addEventListener('alpine:init', () => {
  const pad = (n) => String(n).padStart(2, '0');
  const toDate = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d)); };
  const ymd = (dt) => dt.getUTCFullYear() + '-' + pad(dt.getUTCMonth() + 1) + '-' + pad(dt.getUTCDate());
  const addDays = (s, n) => { const d = toDate(s); d.setUTCDate(d.getUTCDate() + n); return ymd(d); };
  const weekStart = (s) => { const d = toDate(s); const dow = (d.getUTCDay() + 6) % 7; return addDays(s, -dow); };
  // Month and weekday names in the interface language (falls back to English).
  const LOC = document.documentElement.lang || 'en';
  const names = (opts, n, mk) => { try { const f = new Intl.DateTimeFormat(LOC, Object.assign({ timeZone: 'UTC' }, opts)); return Array.from({ length: n }, (_, i) => f.format(mk(i))); } catch (e) { return null; } };
  const MONTHS = names({ month: 'long' }, 12, (i) => new Date(Date.UTC(2024, i, 1))) || ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const MON = names({ month: 'short' }, 12, (i) => new Date(Date.UTC(2024, i, 1))) || MONTHS.map((m) => m.slice(0, 3));
  const DOW = names({ weekday: 'short' }, 7, (i) => new Date(Date.UTC(2024, 0, 7 + i))) || ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const COLORS = { draft: 'gray', cancelled: 'gray', in_review: 'amber', scheduled: 'violet', publishing: 'sky', published: 'green', partially_published: 'teal', failed: 'red' };

  Alpine.data('calendarPage', (cfg) => ({
    cfg,
    canEdit: cfg.canEdit,
    view: 'week',
    anchor: cfg.now.slice(0, 10),
    events: [],
    drafts: cfg.drafts || [],
    byDay: {},
    byHour: {},
    loading: false,
    panel: false,
    over: null,
    drag: null,
    dragging: null,
    expanded: {},
    nowStr: cfg.now,
    offset: 0,
    hours: Array.from({ length: 24 }, (_, i) => i),
    labelFilter: [],

    init() {
      // Keep "now" ticking relative to the server's clock in the workspace time zone.
      const [d, t] = cfg.now.split(' ');
      const serverNow = Date.UTC(...d.split('-').map((v, i) => (i === 1 ? +v - 1 : +v)), ...t.split(':').map(Number));
      this.offset = serverNow - Date.now();
      setInterval(() => this.tick(), 30000);
      let saved = null;
      try { saved = localStorage.getItem('np.calview'); } catch (e) {}
      this.view = window.innerWidth < 768 ? 'list' : (['week', 'month', 'list'].includes(saved) ? saved : 'week');
      try { this.labelFilter = JSON.parse(localStorage.getItem('np.calLabels.' + cfg.ws) || '[]').filter((id) => (cfg.labels || []).some((l) => l.id === id)); } catch (e) { this.labelFilter = []; }
      this.$watch('labelFilter', (v) => { try { localStorage.setItem('np.calLabels.' + cfg.ws, JSON.stringify(v)); } catch (e) {} this.index(); });
      this.load().then(() => this.scrollToMorning());
    },
    tick() {
      const n = new Date(Date.now() + this.offset);
      this.nowStr = ymd(n) + ' ' + pad(n.getUTCHours()) + ':' + pad(n.getUTCMinutes());
    },
    get nowDate() { return this.nowStr.slice(0, 10); },
    get nowHour() { return +this.nowStr.slice(11, 13); },
    get nowPct() { return (+this.nowStr.slice(14, 16) / 60) * 100; },
    pad,

    range() {
      if (this.view === 'week') { const s = weekStart(this.anchor); return [s, addDays(s, 7)]; }
      const first = this.anchor.slice(0, 8) + '01';
      if (this.view === 'list') { const d = toDate(first); d.setUTCMonth(d.getUTCMonth() + 1); return [first, ymd(d)]; }
      const s = weekStart(first); return [s, addDays(s, 42)];
    },
    get days() {
      const [s, e] = this.range();
      const out = [];
      const month = this.anchor.slice(0, 7);
      for (let d = s; d < e; d = addDays(d, 1)) {
        const dt = toDate(d);
        out.push({ date: d, dow: DOW[dt.getUTCDay()], label: this.view === 'week' ? dt.getUTCDate() + ' ' + MON[dt.getUTCMonth()] : String(dt.getUTCDate()), isToday: d === this.nowDate, inMonth: d.slice(0, 7) === month });
      }
      return out;
    },
    get listDays() {
      return Object.keys(this.byDay).sort().map((date) => {
        const dt = toDate(date);
        return { date, isToday: date === this.nowDate, long: DOW[dt.getUTCDay()] + ', ' + dt.getUTCDate() + ' ' + MONTHS[dt.getUTCMonth()] + (date === this.nowDate ? ' · ' + np.t('Today') : '') };
      });
    },
    get title() {
      const [s, e] = this.range();
      if (this.view === 'week') {
        const a = toDate(s), b = toDate(addDays(e, -1));
        const same = a.getUTCMonth() === b.getUTCMonth();
        return a.getUTCDate() + (same ? '' : ' ' + MON[a.getUTCMonth()]) + ' – ' + b.getUTCDate() + ' ' + MON[b.getUTCMonth()] + ' ' + b.getUTCFullYear();
      }
      const a = toDate(this.anchor);
      return MONTHS[a.getUTCMonth()] + ' ' + a.getUTCFullYear();
    },

    async load() {
      const [start, end] = this.range();
      this.loading = true;
      try {
        const data = await np.api(cfg.eventsUrl + '?start=' + start + '&end=' + end);
        this.events = data.events || [];
        this.drafts = data.drafts || this.drafts;
        if (data.now) { this.nowStr = data.now; }
        this.index();
      } catch (e) {
        np.toast(e.message || np.t('Could not load the calendar.'), 'error');
      } finally {
        this.loading = false;
      }
    },
    index() {
      const byDay = {}, byHour = {};
      const want = this.labelFilter;
      for (const ev of this.events) {
        if (!ev.date) continue;
        if (want.length && !(ev.labels || []).some((l) => want.includes(l.uuid))) continue;
        (byDay[ev.date] = byDay[ev.date] || []).push(ev);
        const k = ev.date + '|' + (+ev.time.slice(0, 2));
        (byHour[k] = byHour[k] || []).push(ev);
      }
      for (const k in byDay) byDay[k].sort((a, b) => a.time.localeCompare(b.time));
      this.byDay = byDay; this.byHour = byHour;
    },
    onDay(date) { return this.byDay[date] || []; },
    toggleLabelFilter(id) { this.labelFilter = this.labelFilter.includes(id) ? this.labelFilter.filter((x) => x !== id) : [...this.labelFilter, id]; },
    labelDots(ev) { return (ev.labels || []).slice(0, 3).map((l) => '<span class="ev-dot" style="background:' + np.esc(l.color) + '" title="' + np.esc(l.name) + '"></span>').join(''); },
    at(date, h) { return this.byHour[date + '|' + h] || []; },

    setView(v) { this.view = v; this.expanded = {}; try { localStorage.setItem('np.calview', v); } catch (e) {} this.load().then(() => this.scrollToMorning()); },
    shift(dir) {
      if (this.view === 'week') this.anchor = addDays(this.anchor, 7 * dir);
      else { const d = toDate(this.anchor.slice(0, 8) + '01'); d.setUTCMonth(d.getUTCMonth() + dir); this.anchor = ymd(d); }
      this.expanded = {};
      this.load();
    },
    today() { this.anchor = this.nowDate; this.load().then(() => this.scrollToMorning()); },
    keys(e) {
      const tag = (e.target.tagName || '').toLowerCase();
      if (['input', 'textarea', 'select'].includes(tag) || e.metaKey || e.ctrlKey || e.altKey) return;
      if (e.key === 'ArrowLeft') this.shift(-1);
      else if (e.key === 'ArrowRight') this.shift(1);
      else if (e.key === 't') this.today();
    },
    scrollToMorning() {
      if (this.view !== 'week') return;
      this.$nextTick(() => {
        const h = Math.max(0, Math.min(this.nowHour - 1, 8));
        const row = this.$refs.scroller.querySelector('[data-slot$=" ' + pad(h) + '"]');
        if (row) this.$refs.scroller.scrollTop = row.offsetTop - 48;
      });
    },

    hourLabel(h) { try { return new Intl.DateTimeFormat(LOC, { hour: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, 1, h))); } catch (e) { return (h % 12 || 12) + (h < 12 ? ' am' : ' pm'); } },
    isPastSlot(date, h) { return date < this.nowDate || (date === this.nowDate && h < this.nowHour); },
    stVar(status) { return '--st: var(--c-' + (COLORS[status] || 'gray') + ')'; },
    avatars(ev, size) { return (ev.channels || []).slice(0, 4).map((c) => np.avatar(c, size || 18)).join('') + (ev.channels.length > 4 ? '<span class="ml-1 text-[10px] text-muted">+' + (ev.channels.length - 4) + '</span>' : ''); },
    open(ev) { window.location.href = cfg.editUrl.replace('__ID__', ev.id); },
    create(date, h) {
      if (!this.canEdit) return;
      let time = h === null ? '10:00' : pad(h) + ':00';
      if (date + ' ' + time < this.nowStr) {
        if (date < this.nowDate) { np.toast(np.t('That day is in the past. Pick a future slot.'), 'error'); return; }
        const n = new Date(Date.now() + this.offset + 3600000);
        time = pad(n.getUTCHours()) + ':00';
      }
      window.location.href = cfg.newUrl + '?at=' + encodeURIComponent(date + 'T' + time);
    },

    dragStart(e, ev, fromDraft = false) {
      if (!this.canEdit) { e.preventDefault(); return; }
      this.drag = { ev, fromDraft };
      this.dragging = ev.id;
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', ev.id); } catch (err) {}
    },
    dragEnd() { this.dragging = null; this.over = null; },
    dragOver(date, h) { if (this.drag) this.over = date + '|' + h; },
    async drop(date, h) {
      const d = this.drag;
      this.over = null; this.drag = null; this.dragging = null;
      if (!d) return;
      const ev = d.ev;
      const minutes = ev.time ? ev.time.slice(3, 5) : '00';
      const time = h === null ? (ev.time || '10:00') : pad(h) + ':' + minutes;
      const at = date + 'T' + time;
      if (ev.date === date && ev.time === time) return;
      if (ev.status !== 'draft' && date + ' ' + time < this.nowStr) { np.toast(np.t('You can’t move a post into the past.'), 'error'); return; }
      const before = { date: ev.date, time: ev.time };
      ev.date = date; ev.time = time;
      if (d.fromDraft) { this.drafts = this.drafts.filter((x) => x.id !== ev.id); this.events.push(ev); }
      this.index();
      try {
        const res = await np.api(cfg.moveUrl.replace('__ID__', ev.id), { method: 'PATCH', body: { at, version: ev.version } });
        ev.version = res.version;
        np.toast(res.message || np.t('Moved.'));
      } catch (e) {
        ev.date = before.date; ev.time = before.time;
        np.toast(e.message || np.t('Could not move the post.'), 'error');
        this.load();
        return;
      }
      if (d.fromDraft) this.load();
    },
  }));
});
