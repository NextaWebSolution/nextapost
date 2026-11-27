<?php
$path = app()->request->path ?? '/';
$cur = static fn (string $p): string => (str_starts_with($path, $p) ? ' aria-current="page"' : '');
$theme = $user['theme'] ?? 'system';
?>
<div class="flex h-14 items-center gap-1.5 border-b border-line px-4">
  <img src="<?= asset('img/logo.svg') ?>" alt="" class="h-6 w-auto dark:hidden"><img src="<?= asset('img/logo-dark.svg') ?>" alt="" class="hidden h-6 w-auto dark:block">
  <span class="truncate text-[15px] font-bold tracking-tight"><?= e(app_name()) ?></span>
  <button type="button" class="btn btn-ghost btn-icon ml-auto lg:hidden" @click="nav=false" aria-label="<?= e(__('Close menu')) ?>"><?= icon('x', 'size-4') ?></button>
</div>

<?php if (!empty($ws)): ?>
<div class="relative px-3 pt-3" x-data="{open:false}" @keydown.escape="open=false">
  <button type="button" class="flex w-full items-center gap-2.5 rounded-xl border border-line bg-canvas px-2.5 py-2 text-left hover:bg-raised" @click="open=!open" :aria-expanded="open" aria-haspopup="menu">
    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg text-xs font-bold text-white" style="background:<?= e($ws['color']) ?>"><?= e(initials($ws['name'])) ?></span>
    <span class="min-w-0 flex-1">
      <span class="block truncate text-sm font-semibold"><?= e($ws['name']) ?></span>
      <span class="block truncate text-xs text-muted"><?= e(\App\Core\Gate::roleName($role)) ?> · <?= e($ws['timezone']) ?></span>
    </span>
    <?= icon('chevron-down', 'size-4 text-muted') ?>
  </button>
  <div x-cloak x-show="open" x-transition.origin.top @click.outside="open=false" class="menu left-3 right-3 mt-1 max-h-80 overflow-auto" role="menu">
    <p class="px-2.5 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-subtle"><?= e(__('Workspaces')) ?></p>
    <?php foreach ($workspaces as $w): ?>
      <a role="menuitem" href="<?= e(url('/w/' . $w['uuid'] . '/calendar')) ?>" class="menu-item">
        <span class="size-2.5 rounded-full" style="background:<?= e($w['color']) ?>"></span>
        <span class="min-w-0 flex-1 truncate"><?= e($w['name']) ?></span>
        <?php if ($w['uuid'] === $ws['uuid']): ?><?= icon('check', 'size-4 text-brand-strong') ?><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <div class="my-1 border-t border-line"></div>
    <button type="button" role="menuitem" class="menu-item" @click="open=false; $dispatch('new-workspace')"><?= icon('plus', 'size-4') ?> <?= e(__('New workspace')) ?></button>
  </div>
</div>

<nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-3 scroll-thin">
  <?php $base = '/w/' . $ws['uuid']; ?>
  <a class="nav-link" href="<?= e(url($base . '/calendar')) ?>"<?= $cur($base . '/calendar') ?: ($path === $base ? ' aria-current="page"' : '') ?>><?= icon('calendar') ?> <?= e(__('Calendar')) ?></a>
  <a class="nav-link" href="<?= e(url($base . '/posts')) ?>"<?= (str_starts_with($path, $base . '/posts') && !isset($_GET['status'])) || (isset($_GET['status']) && $_GET['status'] !== 'in_review' && str_starts_with($path, $base . '/posts')) ? ' aria-current="page"' : '' ?>>
    <?= icon('list') ?> <?= e(__('Posts')) ?>
    <?php if (!empty($failedCount)): ?><span class="ml-auto badge st-failed" title="<?= e(__('Failed in the last 7 days')) ?>"><?= (int) $failedCount ?></span><?php endif; ?>
  </a>
  <a class="nav-link" href="<?= e(url($base . '/posts?status=in_review')) ?>"<?= ($_GET['status'] ?? '') === 'in_review' ? ' aria-current="page"' : '' ?>>
    <?= icon('check-circle') ?> <?= e(__('Approvals')) ?>
    <?php if (!empty($reviewCount)): ?><span class="ml-auto badge st-in_review"><?= (int) $reviewCount ?></span><?php endif; ?>
  </a>
  <a class="nav-link" href="<?= e(url($base . '/media')) ?>"<?= $cur($base . '/media') ?>><?= icon('image') ?> <?= e(__('Media')) ?></a>
  <a class="nav-link" href="<?= e(url($base . '/ideas')) ?>"<?= $cur($base . '/ideas') ?>><?= icon('bulb') ?> <?= e(__('Ideas')) ?></a>
  <a class="nav-link" href="<?= e(url($base . '/library')) ?>"<?= $cur($base . '/library') ?>><?= icon('tag') ?> <?= e(__('Labels & templates')) ?></a>
  <a class="nav-link" href="<?= e(url($base . '/channels')) ?>"<?= $cur($base . '/channels') ?>><?= icon('plug') ?> <?= e(__('Channels')) ?></a>
  <a class="nav-link" href="<?= e(url($base . '/repurpose')) ?>"<?= $cur($base . '/repurpose') ?>><?= icon('repeat') ?> <?= e(__('Repurpose')) ?></a>
  <?php if (\App\Services\CommentDmService::enabled() && \App\Core\Gate::can((string) $role, 'automations', $ws)): ?><a class="nav-link" href="<?= e(url($base . '/automations')) ?>"<?= $cur($base . '/automations') ?>><?= icon('message') ?> <?= e(__('Comment-to-DM')) ?></a><?php endif; ?>
  <a class="nav-link" href="<?= e(url($base . '/settings')) ?>"<?= $cur($base . '/settings') ?>><?= icon('users') ?> <?= e(__('Team & settings')) ?></a>
  <a class="nav-link" href="<?= e(url($base . '/developers')) ?>"<?= $cur($base . '/developers') ?>><?= icon('key') ?> <?= e(__('Developers & AI')) ?></a>
  <?php if (!empty($user['is_super_admin'])): ?>
    <div class="pt-3"><p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-subtle"><?= e(__('Administration')) ?></p></div>
    <a class="nav-link" href="<?= e(url('/system')) ?>"<?= $cur('/system') ?>><?= icon('server') ?> <?= e(__('System')) ?>
      <?php if (!empty($updateAvailable['version'])): ?><span class="ml-auto badge st-scheduled"><?= e(__('Update')) ?></span><?php endif; ?>
    </a>
  <?php endif; ?>
</nav>
<?php else: ?>
<nav class="flex-1 space-y-0.5 px-3 py-3">
  <a class="nav-link" href="<?= e(url('/')) ?>"><?= icon('calendar') ?> <?= e(__('Back to calendar')) ?></a>
  <?php if (!empty($user['is_super_admin'])): ?><a class="nav-link" href="<?= e(url('/system')) ?>"<?= $cur('/system') ?>><?= icon('server') ?> <?= e(__('System')) ?></a><?php endif; ?>
</nav>
<?php endif; ?>

<div class="border-t border-line p-3">
  <div class="mb-2 grid grid-cols-3 gap-1 rounded-xl bg-canvas p-1" role="radiogroup" aria-label="<?= e(__('Theme')) ?>">
    <?php foreach (['light' => ['sun', __('Light')], 'dark' => ['moon', __('Dark')], 'system' => ['monitor', __('Auto')]] as $k => [$ic, $lbl]): ?>
      <button type="button" role="radio" class="flex items-center justify-center gap-1 rounded-lg py-1.5 text-xs font-medium text-muted hover:text-ink"
              :class="theme==='<?= $k ?>' ? 'bg-panel text-ink shadow-sm' : ''" :aria-checked="theme==='<?= $k ?>'"
              @click="setTheme('<?= $k ?>')"><?= icon($ic, 'size-3.5') ?><?= e($lbl) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="relative" x-data="{open:false}" @keydown.escape="open=false">
    <button type="button" class="flex w-full items-center gap-2.5 rounded-xl px-2 py-1.5 text-left hover:bg-raised" @click="open=!open" :aria-expanded="open">
      <span class="flex size-8 items-center justify-center rounded-full bg-brand-soft text-xs font-bold"><?= e(initials($user['name'] ?? '?')) ?></span>
      <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium"><?= e($user['name'] ?? '') ?></span><span class="block truncate text-xs text-muted"><?= e($user['email'] ?? '') ?></span></span>
      <?= icon('more', 'size-4 text-muted') ?>
    </button>
    <div x-cloak x-show="open" x-transition @click.outside="open=false" class="menu bottom-full left-0 right-0 mb-1" role="menu">
      <a class="menu-item" role="menuitem" href="<?= e(url('/account')) ?>"><?= icon('settings', 'size-4') ?> <?= e(__('Your account')) ?></a>
      <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="menu-item" role="menuitem"><?= icon('logout', 'size-4') ?> <?= e(__('Sign out')) ?></button></form>
    </div>
  </div>
  <p class="mt-2 px-2 text-[11px] text-subtle">v<?= e(APP_VERSION) ?></p>
</div>
