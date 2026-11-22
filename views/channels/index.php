<?php $base = '/w/' . $ws['uuid']; ?>
<div class="mx-auto max-w-5xl p-3 sm:p-5" x-data="channelSchedules(<?= json_attr($scheduleCfg) ?>)">
  <h2 class="text-sm font-semibold"><?= e(__('Connected channels')) ?></h2>
  <?php if (!$channels): ?>
    <div class="card mt-2 p-6 text-center">
      <p class="font-semibold"><?= e(__('No channels connected yet')) ?></p>
      <p class="mt-1 text-sm text-muted"><?= $canManage ? 'Pick a network below. Not sure where to start? Add a Sandbox channel to try the workflow safely.' : 'Ask a workspace admin to connect channels.' ?></p>
    </div>
  <?php else: ?>
    <ul class="mt-2 grid gap-2 sm:grid-cols-2">
      <?php foreach ($channels as $ch): $m = network_meta($ch['network']); ?>
        <li class="card flex items-center gap-3 p-3">
          <?= channel_avatar($ch, 'size-10') ?>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold"><?= e($ch['name']) ?></p>
            <p class="truncate text-xs text-muted"><?= e($m['name']) ?><?= $ch['username'] ? ' · ' . e($ch['username']) : '' ?></p>
            <?php if ($ch['status'] !== 'active'): ?><p class="mt-1 text-xs text-danger"><?= e($ch['last_error'] ?: __('{name} needs to be reconnected.', ['name' => $ch['name']])) ?></p><?php endif; ?>
          </div>
          <?php if ($canSchedule): ?><button type="button" class="btn btn-ghost btn-sm" @click="open(<?= json_attr($ch['uuid']) ?>)" title="<?= e(__('Posting times')) ?>"><?= icon('clock', 'size-4') ?><span class="hidden sm:inline"><?= e(__('Posting times')) ?></span></button><?php endif; ?>
          <?php if ($ch['status'] === 'active'): ?><span class="badge st-published hidden sm:inline-flex"><?= e(__('Active')) ?></span><?php else: ?><span class="badge st-failed"><?= e(__('Reconnect')) ?></span><?php endif; ?>
          <?php if ($canManage): ?>
            <?php if ($ch['status'] !== 'active'): ?><a class="btn btn-secondary btn-sm" href="<?= e(url($base . '/channels/connect/' . $ch['network'])) ?>"><?= e(__('Reconnect')) ?></a><?php endif; ?>
            <form method="post" action="<?= e(url($base . '/channels/' . $ch['uuid'] . '/delete')) ?>" x-data="confirmForm(<?= json_attr(__('Remove {name}? Scheduled posts will skip this channel.', ['name' => $ch['name']])) ?>)" @submit="check">
              <?= csrf_field() ?><input type="hidden" name="confirm" value="yes">
              <button class="btn btn-ghost btn-icon text-danger" title="<?= e(__('Remove')) ?>" aria-label="<?= e(__('Remove') . ' ' . $ch['name']) ?>"><?= icon('trash', 'size-4') ?></button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($canManage): ?>
    <h2 class="mt-8 text-sm font-semibold"><?= e(__('Connect a channel')) ?></h2>
    <?php if (!$httpsOk): ?>
      <div class="alert alert-warn mt-2"><?= icon('alert', 'size-5 shrink-0') ?><span><?= e(__('Your site address isn’t HTTPS. Facebook, Instagram, LinkedIn and X require HTTPS for sign-in and media. Enable AutoSSL in cPanel and set APP_URL to https:// in .env.')) ?></span></div>
    <?php endif; ?>
    <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
      <?php foreach ($networks as $key => $n): ?>
        <div class="card flex flex-col p-4">
          <div class="flex items-center gap-3">
            <span class="net-dot size-9 text-sm" style="background:<?= e($n['color']) ?>"><?= e($n['letter']) ?></span>
            <div class="min-w-0">
              <p class="font-semibold"><?= e($n['name']) ?></p>
              <p class="text-xs text-muted"><?= $n['kind'] === 'oauth' ? 'Sign in with ' . e(explode(' ', $n['name'])[0]) : 'Token or webhook' ?></p>
            </div>
          </div>
          <p class="mt-2 flex-1 text-sm text-muted"><?= e($n['description']) ?></p>
          <?php if ($n['configured']): ?>
            <a href="<?= e(url($base . '/channels/connect/' . $key)) ?>" class="btn btn-secondary btn-sm mt-3"><?= icon('plus', 'size-4') ?> <?= e(__('Connect')) ?></a>
          <?php else: ?>
            <p class="mt-3 text-xs text-muted"><?php if (!empty($user['is_super_admin'])): ?><?= __html('Add the app keys in {link} first.', ['link' => '<a class="font-semibold text-brand-strong underline dark:text-violet-300" href="' . e(url('/system?tab=integrations')) . '">' . e(__('System → Integrations')) . '</a>']) ?><?php else: ?><?= e(__('Your administrator needs to enable this network first.')) ?><?php endif; ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Posting schedule dialog -->
  <div x-cloak x-show="ch" class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 sm:items-center sm:p-4" @keydown.escape.window="ch=null">
    <div class="card flex max-h-[90vh] w-full max-w-2xl flex-col rounded-b-none sm:rounded-b-[.875rem]" @click.outside="ch=null" role="dialog" aria-modal="true" :aria-label="<?= js(__('Posting times')) ?>">
      <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <h2 class="font-semibold"><?= e(__('Posting times')) ?> · <span x-text="ch && ch.name"></span></h2>
        <button class="btn btn-ghost btn-icon" @click="ch=null" aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-4') ?></button>
      </div>
      <div class="flex-1 overflow-y-auto p-4 scroll-thin">
        <p class="text-sm text-muted"><?= e(__('“Add to queue” in the composer uses these times. Each time slot holds one post.')) ?></p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="sch-tz"><?= e(__('Time zone')) ?></label>
            <select id="sch-tz" class="input" x-model="tz"><option value=""><?= e(__('Same as workspace')) ?></option><template x-for="z in cfg.timezones" :key="z"><option :value="z" x-text="z"></option></template></select>
          </div>
          <div>
            <label class="label" for="sch-goal"><?= e(__('Posts per week')) ?></label>
            <div class="flex gap-2"><input id="sch-goal" type="number" min="1" max="42" class="input w-24" x-model="goal"><button type="button" class="btn btn-secondary" @click="generate()" :disabled="busy"><?= e(__('Suggest times')) ?></button></div>
          </div>
        </div>
        <div class="mt-4 divide-y divide-[var(--np-line)] rounded-xl border border-line">
          <template x-for="d in days" :key="d.key">
            <div class="flex flex-wrap items-center gap-2 px-3 py-2">
              <span class="w-12 text-sm font-semibold" x-text="d.label"></span>
              <template x-for="(t, i) in schedule[d.key]" :key="d.key + t">
                <span class="badge gap-1"><span x-text="t"></span><button type="button" class="hover:text-danger" @click="schedule[d.key].splice(i, 1)" :aria-label="<?= js(__('Remove')) ?> + ' ' + d.label + ' ' + t">×</button></span>
              </template>
              <span class="ml-auto flex items-center gap-1">
                <input type="time" class="input h-8 w-28 py-0 text-sm" x-model="newTime[d.key]" :aria-label="<?= js(__('Add a time on')) ?> + ' ' + d.label">
                <button type="button" class="btn btn-ghost btn-sm" @click="addTime(d.key)"><?= icon('plus', 'size-4') ?></button>
              </span>
            </div>
          </template>
        </div>
        <p class="mt-2 text-sm text-danger" x-show="error" x-text="error"></p>
        <div class="mt-4" x-show="slots.length">
          <p class="text-sm font-semibold"><?= e(__('Next free times')) ?></p>
          <p class="mt-1 text-sm text-muted" x-text="slots.join(' · ')"></p>
        </div>
      </div>
      <div class="flex justify-end gap-2 border-t border-line px-4 py-3">
        <button type="button" class="btn btn-ghost" @click="clearAll()"><?= e(__('Clear all')) ?></button>
        <button type="button" class="btn btn-primary" @click="save()" :disabled="busy"><?= e(__('Save times')) ?></button>
      </div>
    </div>
  </div>
</div>
<?php $head = '<script src="' . asset('js/channels.js') . '"></script>'; ?>
