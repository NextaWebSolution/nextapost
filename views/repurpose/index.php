<div class="mx-auto max-w-5xl p-3 sm:p-5" x-data="repurposePage(<?= json_attr($cfg) ?>)">
  <p class="text-sm text-muted"><?= e(__('Post once, publish everywhere: when a new Reel or post appears on your Instagram or Threads account, NextaPost copies it (with its caption) to the channels you choose. Accounts are checked about every hour, and only posts made after a rule is switched on are used.')) ?></p>

  <form class="card mt-4 grid gap-4 p-5" x-show="cfg.canManage" @submit.prevent="create()">
    <h2 class="text-base font-semibold"><?= e(__('New rule')) ?></h2>
    <template x-if="!sources().length">
      <div class="alert alert-info"><span><?= e(__('Connect an Instagram or Threads account first.')) ?> <a class="font-semibold underline" :href="cfg.channelsUrl"><?= e(__('Go to Channels')) ?></a></span></div>
    </template>
    <div class="grid gap-4 sm:grid-cols-3" x-show="sources().length">
      <div>
        <label class="label" for="rp-source"><?= e(__('Watch')) ?></label>
        <select id="rp-source" class="input" x-model="form.source" required :aria-invalid="!!errors.source">
          <option value=""><?= e(__('Choose an account…')) ?></option>
          <template x-for="c in sources()" :key="c.id"><option :value="c.id" x-text="c.name + ' (' + c.networkName + ')'"></option></template>
        </select>
        <p class="help text-danger" x-show="errors.source" x-text="errors.source"></p>
      </div>
      <div>
        <label class="label" for="rp-format"><?= e(__('Use')) ?></label>
        <select id="rp-format" class="input" x-model="form.format">
          <template x-for="(l, k) in cfg.formats" :key="k"><option :value="k" x-text="l"></option></template>
        </select>
      </div>
      <div>
        <label class="label" for="rp-mode"><?= e(__('Then')) ?></label>
        <select id="rp-mode" class="input" x-model="form.mode">
          <template x-for="(l, k) in cfg.modes" :key="k"><option :value="k" x-text="l"></option></template>
        </select>
      </div>
    </div>
    <fieldset x-show="sources().length">
      <legend class="label"><?= e(__('Post to')) ?></legend>
      <div class="flex flex-wrap gap-2">
        <template x-for="c in cfg.channels.filter((x) => x.id !== form.source)" :key="c.id">
          <label class="flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm"><input type="checkbox" class="checkbox" :value="c.id" x-model="form.destinations"> <span x-text="c.name"></span> <span class="text-xs text-muted" x-text="c.networkName"></span></label>
        </template>
      </div>
      <p class="help text-danger" x-show="errors.destinations" x-text="errors.destinations"></p>
      <p class="help" x-show="form.mode !== 'draft'"><?= e(__('Queued posts take each channel’s next free posting time. Channels without posting times get a draft instead.')) ?></p>
    </fieldset>
    <div x-show="sources().length"><button class="btn btn-primary" :disabled="busy"><?= e(__('Create rule')) ?></button></div>
  </form>

  <ul class="mt-4 space-y-3" x-show="rules.length">
    <template x-for="r in rules" :key="r.id">
      <li class="card p-4">
        <div class="flex flex-wrap items-center gap-2">
          <p class="min-w-0 flex-1 font-semibold">
            <span x-text="r.source ? r.source.name : <?= js(__('Removed channel')) ?>"></span>
            <span class="font-normal text-muted">→</span>
            <span x-text="r.destinations.map((d) => d.name).join(', ')"></span>
          </p>
          <span class="badge" :class="r.status === 'active' ? 'st-published' : 'st-failed'" x-text="r.status === 'active' ? <?= js(__('On')) ?> : <?= js(__('Paused')) ?>"></span>
        </div>
        <p class="mt-1 text-sm text-muted" x-text="cfg.formats[r.format] + ' · ' + cfg.modes[r.mode] + ' · ' + r.posts_made + ' ' + <?= js(__('posts made')) ?> + (r.last_checked_at ? ' · ' + <?= js(__('checked')) ?> + ' ' + when(r.last_checked_at) : '')"></p>
        <p class="mt-1 text-sm text-danger" x-show="r.error" x-text="r.error"></p>
        <div class="mt-3 flex flex-wrap gap-1.5" x-show="cfg.canManage">
          <button type="button" class="btn btn-sm" x-show="r.status === 'active'" @click="run(r)" :disabled="busy"><?= icon('refresh', 'size-4') ?> <?= e(__('Check now')) ?></button>
          <button type="button" class="btn btn-sm" @click="toggle(r)" x-text="r.status === 'active' ? <?= js(__('Pause')) ?> : <?= js(__('Turn on')) ?>"></button>
          <button type="button" class="btn btn-sm btn-ghost text-danger" @click="remove(r)"><?= icon('trash', 'size-4') ?> <?= e(__('Delete')) ?></button>
        </div>
      </li>
    </template>
  </ul>
  <div class="card mt-4 p-8 text-center" x-show="!rules.length">
    <p class="font-semibold"><?= e(__('No repurpose rules yet')) ?></p>
    <p class="mt-1 text-sm text-muted"><?= e(__('For example: turn every new Instagram Reel into drafts for TikTok, YouTube Shorts and Facebook.')) ?></p>
  </div>
</div>
<?php $head = '<script src="' . asset('js/repurpose.js') . '"></script>'; ?>
