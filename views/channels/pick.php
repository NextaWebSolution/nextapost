<?php $base = '/w/' . $ws['uuid']; ?>
<div class="mx-auto max-w-xl p-3 sm:p-5">
  <form method="post" action="<?= e(url($base . '/channels/pick')) ?>" class="card p-5">
    <?= csrf_field() ?>
    <input type="hidden" name="key" value="<?= e($key) ?>">
    <h2 class="text-lg font-semibold"><?= count($accounts) === 1 ? 'Connect this ' . e($meta['name']) . ' account?' : 'Choose ' . e($meta['name']) . ' accounts' ?></h2>
    <p class="mt-1 text-sm text-muted"><?= count($accounts) === 1 ? 'This is the account ' . e(explode(' ', $meta['name'])[0]) . ' signed you in with.' : 'Select the accounts this workspace should post to.' ?></p>
    <ul class="mt-4 divide-y divide-[var(--np-line)] rounded-xl border border-line">
      <?php foreach ($accounts as $i => $a): $isExisting = in_array((string) $a['external_id'], $existing ?? [], true); ?>
        <li>
          <label class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-raised">
            <input type="checkbox" class="checkbox" name="accounts[]" value="<?= e($a['external_id']) ?>" <?= $i === 0 || count($accounts) === 1 ? 'checked' : '' ?>>
            <?= channel_avatar(['name' => $a['name'], 'network' => $network, 'avatar_url' => $a['avatar_url']], 'size-8') ?>
            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium"><?= e($a['name']) ?></span><?php if ($a['username']): ?><span class="block truncate text-xs text-muted"><?= e($a['username']) ?></span><?php endif; ?></span>
            <?php if ($isExisting): ?><span class="badge"><?= e(__('Already connected')) ?></span><?php endif; ?>
          </label>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($signOut): ?>
      <div class="mt-4 rounded-xl bg-canvas p-3 text-sm">
        <p class="font-medium"><?= e(__('Not the right account?')) ?></p>
        <p class="mt-1 text-muted"><?= __html('{signout} (or open a private window), sign in with the other account, then {retry}.', [
          'signout' => '<a class="font-semibold text-brand-strong underline dark:text-violet-300" href="' . e($signOut[0]) . '" target="_blank" rel="noopener noreferrer">' . e(__($signOut[1])) . '</a>',
          'retry' => '<a class="font-semibold text-brand-strong underline dark:text-violet-300" href="' . e(url($base . '/channels/connect/' . $network)) . '">' . e(__('try again')) . '</a>',
        ]) ?></p>
      </div>
    <?php endif; ?>

    <div class="mt-5 flex justify-end gap-2">
      <a href="<?= e(url($base . '/channels')) ?>" class="btn btn-secondary"><?= e(__('Cancel')) ?></a>
      <button class="btn btn-primary"><?= count($accounts) === 1 ? 'Connect account' : 'Connect selected' ?></button>
    </div>
  </form>
</div>
