<?php $base = '/w/' . $ws['uuid']; ?>
<div class="mx-auto max-w-5xl p-3 sm:p-5">
  <h2 class="text-sm font-semibold">Connected channels</h2>
  <?php if (!$channels): ?>
    <div class="card mt-2 p-6 text-center">
      <p class="font-semibold">No channels connected yet</p>
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
            <?php if ($ch['status'] !== 'active'): ?><p class="mt-1 text-xs text-danger"><?= e($ch['last_error'] ?: 'Needs to be reconnected.') ?></p><?php endif; ?>
          </div>
          <?php if ($ch['status'] === 'active'): ?><span class="badge st-published">Active</span><?php else: ?><span class="badge st-failed">Reconnect</span><?php endif; ?>
          <?php if ($canManage): ?>
            <?php if ($ch['status'] !== 'active'): ?><a class="btn btn-secondary btn-sm" href="<?= e(url($base . '/channels/connect/' . $ch['network'])) ?>">Reconnect</a><?php endif; ?>
            <form method="post" action="<?= e(url($base . '/channels/' . $ch['uuid'] . '/delete')) ?>" x-data="confirmForm(<?= json_attr('Remove ' . $ch['name'] . '? Scheduled posts will skip this channel.') ?>)" @submit="check">
              <?= csrf_field() ?><input type="hidden" name="confirm" value="yes">
              <button class="btn btn-ghost btn-icon text-danger" title="Remove" aria-label="Remove <?= e($ch['name']) ?>"><?= icon('trash', 'size-4') ?></button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($canManage): ?>
    <h2 class="mt-8 text-sm font-semibold">Connect a channel</h2>
    <?php if (!$httpsOk): ?>
      <div class="alert alert-warn mt-2"><?= icon('alert', 'size-5 shrink-0') ?><span>Your site address isn’t HTTPS. Facebook, Instagram, LinkedIn and X require HTTPS for sign-in and media. Enable AutoSSL in cPanel and set APP_URL to https:// in .env.</span></div>
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
            <a href="<?= e(url($base . '/channels/connect/' . $key)) ?>" class="btn btn-secondary btn-sm mt-3"><?= icon('plus', 'size-4') ?> Connect</a>
          <?php else: ?>
            <p class="mt-3 text-xs text-muted"><?php if (!empty($user['is_super_admin'])): ?>Add the app keys in <a class="font-semibold text-brand-strong underline dark:text-violet-300" href="<?= e(url('/system?tab=integrations')) ?>">System → Integrations</a> first.<?php else: ?>Your administrator needs to enable this network first.<?php endif; ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
