<div class="mx-auto max-w-xl p-3 sm:p-5">
  <a href="<?= e(url('/w/' . $ws['uuid'] . '/channels')) ?>" class="btn btn-ghost btn-sm mb-3"><?= icon('arrow-left', 'size-4') ?> <?= e(__('Channels')) ?></a>
  <form method="post" action="<?= e(url('/w/' . $ws['uuid'] . '/channels/connect/' . $network)) ?>" class="card p-5" x-data="{busy:false}" @submit="busy=true">
    <?= csrf_field() ?>
    <div class="flex items-center gap-3">
      <span class="net-dot size-10 text-base" style="background:<?= e($meta['color']) ?>"><?= e($meta['letter']) ?></span>
      <div><h2 class="text-lg font-semibold"><?= e(__('Connect {network}', ['network' => $meta['name']])) ?></h2><p class="text-sm text-muted"><?= e(__($meta['description'])) ?></p></div>
    </div>
    <?php if ($err = flash('error')): ?><div class="alert alert-error mt-4" role="alert"><?= icon('alert', 'size-5 shrink-0') ?><span><?= e($err) ?></span></div><?php endif; ?>
    <div class="mt-5 space-y-4">
      <?php foreach ($fields as $f): ?>
        <div>
          <label class="label" for="f-<?= e($f['name']) ?>"><?= e($f['label']) ?></label>
          <input id="f-<?= e($f['name']) ?>" name="<?= e($f['name']) ?>" type="<?= e($f['type']) ?>" class="input" placeholder="<?= e($f['placeholder'] ?? '') ?>"
                 value="<?= $f['type'] === 'password' ? '' : e(old($f['name'])) ?>" <?= str_contains($f['label'], 'optional') ? '' : 'required' ?> autocomplete="off" spellcheck="false">
          <?php if (!empty($f['help'])): ?><p class="help"><?= e($f['help']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="mt-4 flex items-center gap-1.5 text-xs text-muted"><?= icon('shield', 'size-4') ?> <?= e(__('Credentials are encrypted on your server and never shown again.')) ?></p>
    <div class="mt-5 flex justify-end gap-2">
      <a href="<?= e(url('/w/' . $ws['uuid'] . '/channels')) ?>" class="btn btn-secondary"><?= e(__('Cancel')) ?></a>
      <button class="btn btn-primary" :disabled="busy"><span x-text="busy ? np.t('Checking…') : np.t('Connect channel')"><?= e(__('Connect channel')) ?></span></button>
    </div>
  </form>
</div>
