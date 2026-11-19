<?php $base = '/w/' . $ws['uuid']; $go = url($base . '/channels/connect/' . $network, ['go' => 1]); ?>
<div class="mx-auto max-w-xl p-3 sm:p-5">
  <a href="<?= e(url($base . '/channels')) ?>" class="btn btn-ghost btn-sm mb-3"><?= icon('arrow-left', 'size-4') ?> Channels</a>
  <div class="card p-5">
    <div class="flex items-center gap-3">
      <span class="net-dot size-10 text-base" style="background:<?= e($meta['color']) ?>"><?= e($meta['letter']) ?></span>
      <div><h2 class="text-lg font-semibold">Connect <?= e($meta['name']) ?></h2><p class="text-sm text-muted"><?= e($meta['description']) ?></p></div>
    </div>

    <div class="alert alert-info mt-5">
      <?= icon('alert', 'size-5 shrink-0') ?>
      <div>
        <p class="font-semibold"><?= e(explode(' ', $meta['name'])[0]) ?> uses the account that’s signed in on this browser.</p>
        <p class="mt-1 text-ink">If you approved NextaPost before, it may connect straight away without asking. To connect a <strong>different</strong> account, sign out first (or use a private/incognito window), then continue.</p>
      </div>
    </div>

    <?php if ($connected): ?>
      <p class="mt-4 text-sm text-muted">Already connected here:
        <?php foreach ($connected as $i => $c): ?><?= $i ? ', ' : '' ?><strong class="text-ink"><?= e($c['name']) ?></strong><?php endforeach; ?>.
        Connecting the same account again just refreshes it.</p>
    <?php endif; ?>

    <?php if ($related): ?>
      <p class="mt-4 text-sm text-muted">
        <?php if ($network === 'linkedin'): ?>Want to post as your <strong class="text-ink">company Page</strong> instead of your profile?
        <?php elseif ($network === 'linkedin_page'): ?>Want to post as <strong class="text-ink">yourself</strong> instead of a company Page?
        <?php else: ?>Also manage <strong class="text-ink"><?= e($related['meta']['name']) ?></strong>?<?php endif; ?>
        <?php if ($related['configured']): ?>
          <a class="font-semibold text-brand-strong underline dark:text-violet-300" href="<?= e(url($base . '/channels/connect/' . $related['key'])) ?>">Connect <?= e($related['meta']['name']) ?></a>
        <?php else: ?>
          <?= e($related['meta']['name']) ?> needs its own app keys in System → Integrations first.
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
      <?php if ($signOut): ?>
        <a href="<?= e($signOut[0]) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary"><?= icon('external', 'size-4') ?> <?= e($signOut[1]) ?></a>
      <?php endif; ?>
      <a href="<?= e($go) ?>" class="btn btn-primary">Continue to <?= e(explode(' ', $meta['name'])[0]) ?> <?= icon('arrow-right', 'size-4') ?></a>
    </div>
    <p class="mt-3 text-xs text-muted">After signing out in the other tab, come back here and click Continue.</p>
  </div>
</div>
