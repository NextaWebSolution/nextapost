<?php
$cfg = [
    'eventsUrl' => url('/w/' . $ws['uuid'] . '/calendar/events'),
    'moveUrl' => url('/w/' . $ws['uuid'] . '/posts/__ID__/move'),
    'editUrl' => url('/w/' . $ws['uuid'] . '/posts/__ID__/edit'),
    'newUrl' => url('/w/' . $ws['uuid'] . '/posts/new'),
    'now' => to_local(now_utc(), $ws['timezone'], 'Y-m-d H:i'),
    'canEdit' => $canEdit,
    'tz' => $ws['timezone'],
    'drafts' => $drafts,
    'ws' => $ws['uuid'],
    'labels' => $labels,
];
$head = '<script src="' . asset('js/calendar.js') . '"></script>';
?>
<div class="flex h-[calc(100dvh-3.5rem)] min-h-[520px]" x-data="calendarPage(<?= json_attr($cfg) ?>)" @keydown.window="keys($event)">
  <!-- Side panel: channels + unscheduled drafts -->
  <div x-cloak x-show="panel" x-transition.opacity class="fixed inset-0 z-30 bg-black/40 xl:hidden" @click="panel=false"></div>
  <section class="fixed inset-y-0 right-0 z-40 flex w-72 translate-x-full flex-col border-l border-line bg-panel transition-transform xl:static xl:z-auto xl:translate-x-0 xl:border-l-0 xl:border-r"
           :class="panel ? 'translate-x-0' : ''" aria-label="<?= e(__('Channels and drafts')) ?>">
    <div class="flex items-center justify-between border-b border-line px-4 py-3 xl:hidden">
      <h2 class="font-semibold"><?= e(__('Channels & drafts')) ?></h2>
      <button class="btn btn-ghost btn-icon" @click="panel=false" aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-4') ?></button>
    </div>
    <div class="flex-1 overflow-y-auto p-4 scroll-thin">
      <div class="flex items-center justify-between">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-subtle"><?= e(__('Channels')) ?></h2>
        <?php if ($canChannels): ?><a href="<?= e(url('/w/' . $ws['uuid'] . '/channels')) ?>" class="text-xs font-semibold text-brand-strong hover:underline dark:text-violet-300"><?= e(__('Manage')) ?></a><?php endif; ?>
      </div>
      <?php if (!$channels): ?>
        <div class="mt-3 rounded-xl border border-dashed border-line p-4 text-center">
          <p class="text-sm text-muted"><?= e(__('No channels yet.')) ?></p>
          <?php if ($canChannels): ?><a href="<?= e(url('/w/' . $ws['uuid'] . '/channels')) ?>" class="btn btn-primary btn-sm mt-3"><?= icon('plug', 'size-4') ?> <?= e(__('Connect a channel')) ?></a><?php endif; ?>
        </div>
      <?php else: ?>
        <ul class="mt-2 space-y-0.5">
          <?php foreach ($channels as $ch): ?>
            <li>
              <a href="<?= e(url('/w/' . $ws['uuid'] . '/posts/new', ['channels' => $ch['uuid']])) ?>" class="flex items-center gap-2.5 rounded-lg px-2 py-1.5 hover:bg-raised" title="Create a post for <?= e($ch['name']) ?>">
                <?= channel_avatar($ch, 'size-7') ?>
                <span class="min-w-0 flex-1"><span class="block truncate text-sm"><?= e($ch['name']) ?></span><span class="block truncate text-xs text-muted"><?= e(network_meta($ch['network'])['name']) ?></span></span>
                <?php if ($ch['status'] !== 'active'): ?><span class="badge st-failed" title="<?= e($ch['last_error'] ?? '') ?>"><?= e(__('Reconnect')) ?></span><?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($labels): ?>
        <div class="mt-6 flex items-center justify-between">
          <h2 class="text-xs font-semibold uppercase tracking-wide text-subtle"><?= e(__('Filter by label')) ?></h2>
          <button type="button" class="text-xs font-semibold text-brand-strong hover:underline dark:text-violet-300" x-show="labelFilter.length" @click="labelFilter=[]"><?= e(__('Show all')) ?></button>
        </div>
        <div class="mt-2 flex flex-wrap gap-1.5">
          <template x-for="l in cfg.labels" :key="l.id">
            <button type="button" class="label-chip" :class="labelFilter.includes(l.id) ? 'is-on' : ''" :style="'--lc:' + l.color" @click="toggleLabelFilter(l.id)" :aria-pressed="labelFilter.includes(l.id)">
              <span class="size-2 rounded-full" :style="'background:' + l.color"></span><span x-text="l.name"></span>
            </button>
          </template>
        </div>
      <?php endif; ?>

      <div class="mt-6 flex items-center justify-between">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-subtle"><?= e(__('Unscheduled drafts')) ?></h2>
        <span class="text-xs text-muted" x-text="drafts.length"></span>
      </div>
      <p class="mt-1 text-xs text-muted" x-show="canEdit && drafts.length"><?= e(__('Drag a draft onto the calendar to give it a date.')) ?></p>
      <div class="mt-2 space-y-1.5">
        <template x-for="d in drafts" :key="d.id">
          <button type="button" class="ev" :style="stVar(d.status)" :draggable="canEdit" @dragstart="dragStart($event, d, true)" @dragend="dragEnd()" @click="open(d)">
            <span class="ev-body"><span class="ev-avs" x-html="avatars(d)"></span><span class="ev-text" x-text="d.title"></span></span>
          </button>
        </template>
        <p class="rounded-xl border border-dashed border-line p-3 text-center text-xs text-muted" x-show="!drafts.length"><?= e(__('Drafts without a date show up here.')) ?></p>
      </div>
    </div>
  </section>

  <!-- Calendar -->
  <div class="flex min-w-0 flex-1 flex-col">
    <div class="flex flex-wrap items-center gap-2 border-b border-line bg-panel px-3 py-2 sm:px-4">
      <div class="flex items-center gap-1">
        <button class="btn btn-secondary btn-sm" @click="today()"><?= e(__('Today')) ?></button>
        <button class="btn btn-ghost btn-icon" @click="shift(-1)" aria-label="<?= e(__('Previous')) ?>"><?= icon('chevron-left', 'size-4') ?></button>
        <button class="btn btn-ghost btn-icon" @click="shift(1)" aria-label="<?= e(__('Next')) ?>"><?= icon('chevron-right', 'size-4') ?></button>
      </div>
      <h2 class="min-w-0 truncate text-sm font-semibold sm:text-base" x-text="title"></h2>
      <span class="hidden text-xs text-muted md:inline"><?= e($ws['timezone']) ?></span>
      <div class="ml-auto flex items-center gap-1">
        <div class="flex rounded-lg bg-canvas p-0.5" role="tablist" aria-label="<?= e(__('Calendar view')) ?>">
          <button class="tab" role="tab" :aria-selected="view==='week'" @click="setView('week')"><?= e(__('Week')) ?></button>
          <button class="tab" role="tab" :aria-selected="view==='month'" @click="setView('month')"><?= e(__('Month')) ?></button>
          <button class="tab" role="tab" :aria-selected="view==='list'" @click="setView('list')"><?= e(__('List')) ?></button>
        </div>
        <button class="btn btn-secondary btn-sm xl:hidden" @click="panel=true"><?= icon('layers', 'size-4') ?><span class="hidden sm:inline"><?= e(__('Drafts')) ?></span> <span class="badge" x-text="drafts.length"></span></button>
      </div>
    </div>

    <div class="relative flex-1 overflow-auto bg-panel scroll-thin" x-ref="scroller">
      <div x-show="loading" class="absolute right-3 top-3 z-10 text-xs text-muted"><?= e(__('Loading…')) ?></div>

      <!-- Week view -->
      <template x-if="view==='week'">
        <div class="min-w-[760px]">
          <div class="cal-grid cal-head">
            <div class="border-r border-line"></div>
            <template x-for="d in days" :key="d.date">
              <div class="border-r border-line px-2 py-2 text-center" :class="d.isToday ? 'text-brand-strong dark:text-violet-300' : ''">
                <div class="text-[11px] font-medium uppercase text-muted" x-text="d.dow"></div>
                <div class="text-sm font-semibold" x-text="d.label"></div>
              </div>
            </template>
          </div>
          <template x-for="h in hours" :key="h">
            <div class="cal-grid">
              <div class="cal-hour" x-text="hourLabel(h)"></div>
              <template x-for="d in days" :key="d.date + h">
                <div class="cal-cell" :class="{ 'is-past': isPastSlot(d.date, h), 'is-today': d.isToday && !isPastSlot(d.date, h), 'is-over': over === d.date + '|' + h }"
                     @dragover.prevent="dragOver(d.date, h)" @dragleave="over=null" @drop.prevent="drop(d.date, h)"
                     @click.self="create(d.date, h)" :data-slot="d.date + ' ' + pad(h)">
                  <template x-if="d.isToday && nowHour === h"><div class="cal-now" :style="'top:' + nowPct + '%'"></div></template>
                  <template x-for="ev in at(d.date, h)" :key="ev.id">
                    <button type="button" class="ev" :style="stVar(ev.status)" :draggable="canEdit && ev.movable" @dragstart="dragStart($event, ev)" @dragend="dragEnd()" @click="open(ev)" :class="dragging === ev.id ? 'is-dragging' : ''">
                      <span class="ev-tag"><span x-text="ev.statusLabel"></span><span x-text="ev.time"></span></span>
                      <span class="ev-body"><span class="ev-avs" x-html="avatars(ev)"></span><span class="ev-text" x-text="ev.title"></span><span class="ev-dots" x-show="ev.labels && ev.labels.length" x-html="labelDots(ev)"></span></span>
                    </button>
                  </template>
                </div>
              </template>
            </div>
          </template>
        </div>
      </template>

      <!-- Month view -->
      <template x-if="view==='month'">
        <div class="min-w-[680px]">
          <div class="cal-month cal-head">
            <template x-for="n in ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']" :key="n"><div class="border-r border-line py-2 text-center text-[11px] font-medium uppercase text-muted" x-text="n"></div></template>
          </div>
          <div class="cal-month">
            <template x-for="d in days" :key="d.date">
              <div class="cal-cell cal-day" :class="{ 'is-other': !d.inMonth, 'is-today': d.isToday, 'is-past': d.date < nowDate, 'is-over': over === d.date + '|m' }"
                   @dragover.prevent="dragOver(d.date, 'm')" @dragleave="over=null" @drop.prevent="drop(d.date, null)" @click.self="create(d.date, null)">
                <div class="pointer-events-none flex items-center justify-between px-1 text-xs font-semibold" :class="d.isToday ? 'text-brand-strong dark:text-violet-300' : 'text-muted'"><span x-text="d.label"></span></div>
                <template x-for="ev in (expanded[d.date] ? onDay(d.date) : onDay(d.date).slice(0, 3))" :key="ev.id">
                  <button type="button" class="ev" :style="stVar(ev.status)" :draggable="canEdit && ev.movable" @dragstart="dragStart($event, ev)" @dragend="dragEnd()" @click="open(ev)">
                    <span class="ev-body"><span class="ev-avs" x-html="avatars(ev)"></span><span class="shrink-0 font-semibold" x-text="ev.time"></span><span class="ev-text" x-text="ev.title"></span><span class="ev-dots" x-show="ev.labels && ev.labels.length" x-html="labelDots(ev)"></span></span>
                  </button>
                </template>
                <button type="button" class="text-left text-xs font-semibold text-brand-strong hover:underline dark:text-violet-300" x-show="onDay(d.date).length > 3 && !expanded[d.date]" @click="expanded[d.date]=true" x-text="np.t('+{n} more', {n: onDay(d.date).length - 3})"></button>
              </div>
            </template>
          </div>
        </div>
      </template>

      <!-- List (agenda) view — default on phones -->
      <template x-if="view==='list'">
        <div class="mx-auto max-w-3xl p-3 sm:p-5">
          <template x-for="d in listDays" :key="d.date">
            <section class="mb-5">
              <h3 class="sticky top-0 z-[1] -mx-1 bg-panel/95 px-1 py-1.5 text-sm font-semibold backdrop-blur" :class="d.isToday ? 'text-brand-strong dark:text-violet-300' : ''" x-text="d.long"></h3>
              <div class="mt-1 space-y-2">
                <template x-for="ev in onDay(d.date)" :key="ev.id">
                  <button type="button" class="card flex w-full items-center gap-3 p-3 text-left hover:border-[var(--np-brand)]" @click="open(ev)">
                    <img x-show="ev.thumb" :src="ev.thumb" alt="" class="size-14 shrink-0 rounded-lg object-cover" loading="lazy">
                    <span class="min-w-0 flex-1">
                      <span class="flex items-center gap-2 text-xs text-muted"><span class="font-semibold text-ink" x-text="ev.time"></span><span class="badge" :class="'st-' + ev.status" x-text="ev.statusLabel"></span></span>
                      <span class="mt-1 block truncate text-sm font-medium" x-text="ev.title"></span>
                      <span class="mt-1.5 flex" x-html="avatars(ev, 20)"></span>
                    </span>
                    <?= icon('chevron-right', 'size-4 shrink-0 text-subtle') ?>
                  </button>
                </template>
              </div>
            </section>
          </template>
          <div x-show="!loading && !listDays.length" class="py-16 text-center">
            <p class="font-semibold"><?= e(__('Nothing planned this month')) ?></p>
            <p class="mt-1 text-sm text-muted"><?= e(__('Posts you schedule will appear here.')) ?></p>
            <a x-show="canEdit" :href="cfg.newUrl" class="btn btn-primary mt-4"><?= icon('plus', 'size-4') ?> <?= e(__('Create post')) ?></a>
          </div>
        </div>
      </template>
    </div>
  </div>
</div>
