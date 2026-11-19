<?php
/** @var string $content */
$theme = in_array($user['theme'] ?? 'system', ['light', 'dark', 'system'], true) ? ($user['theme'] ?? 'system') : 'system';
$flashes = array_filter(['success' => flash('success'), 'error' => flash('error')]);
$path = app()->request->path ?? '/';
?><!doctype html>
<html lang="en" class="<?= $theme === 'dark' ? 'dark' : '' ?>" data-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<meta name="base" content="<?= e(url('/')) ?>">
<meta name="color-scheme" content="light dark">
<title><?= e($title ?? app_name()) ?> · <?= e(app_name()) ?></title>
<link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
<script nonce="<?= e(csp_nonce()) ?>">(function(){var t=document.documentElement.dataset.theme;if(t==='system'){t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.classList.toggle('dark',t==='dark')})();</script>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<script src="<?= asset('js/app.js') ?>"></script>
<?= $head ?? '' ?>
<script defer src="<?= asset('vendor/alpine-collapse.min.js') ?>"></script>
<script defer src="<?= asset('vendor/alpine.min.js') ?>"></script>
</head>
<body class="bg-canvas text-ink" x-data="appShell" data-flash="<?= json_attr($flashes) ?>" @keydown.window="hotkeys($event)" @new-workspace.window="openNewWs()">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 btn btn-primary">Skip to content</a>
<div class="flex min-h-full">
  <!-- Mobile drawer backdrop -->
  <div x-cloak x-show="nav" x-transition.opacity class="fixed inset-0 z-40 bg-black/50 lg:hidden" @click="nav=false"></div>
  <aside class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-r border-line bg-panel transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
         :class="nav ? 'translate-x-0' : ''" aria-label="Main navigation">
    <?= \App\Core\View::partial('partials/sidebar', get_defined_vars()) ?>
  </aside>

  <div class="flex min-w-0 flex-1 flex-col">
    <header class="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-line bg-panel/90 px-3 backdrop-blur sm:px-5">
      <button type="button" class="btn btn-ghost btn-icon lg:hidden" @click="nav=true" aria-label="Open menu"><?= icon('menu') ?></button>
      <h1 class="min-w-0 truncate text-base font-semibold"><?= e($title ?? '') ?></h1>
      <div class="ml-auto flex items-center gap-2">
        <?= $actions ?? '' ?>
        <?php if (!empty($ws) && \App\Core\Gate::can($role ?? 'viewer', 'edit', $ws)): ?>
          <a href="<?= e(url('/w/' . $ws['uuid'] . '/posts/new')) ?>" class="btn btn-primary btn-sm sm:btn" data-hotkey="n"><?= icon('plus', 'size-4') ?><span class="hidden sm:inline">Create post</span><span class="sm:hidden">New</span></a>
        <?php endif; ?>
      </div>
    </header>

    <?php if (!empty($updateAvailable['version'])): ?>
      <div class="border-b border-line bg-brand-soft px-4 py-2 text-sm">
        <?= icon('download', 'mr-1 inline size-4 align-[-3px]') ?> Version <?= e($updateAvailable['version']) ?> is available.
        <a class="font-semibold underline underline-offset-2" href="<?= e(url('/system?tab=updates')) ?>">Review and update</a>
      </div>
    <?php endif; ?>
    <?php if (!empty($user['is_super_admin']) && (empty($cronAt) || $cronAt < now_utc(-600))): ?>
      <div class="border-b border-line px-4 py-2 text-sm alert-warn" style="border-radius:0;border-width:0 0 1px 0">
        <?= icon('alert', 'mr-1 inline size-4 align-[-3px]') ?> The scheduler cron job isn’t running, so scheduled posts won’t publish.
        <a class="font-semibold underline underline-offset-2" href="<?= e(url('/system?tab=health')) ?>">Set it up</a>
      </div>
    <?php endif; ?>

    <main id="main" class="min-w-0 flex-1"><?= $content ?></main>
  </div>
</div>

<!-- Toasts -->
<div class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end" aria-live="polite" role="status">
  <template x-for="t in $store.toast.items" :key="t.id">
    <div class="toast" x-transition.opacity>
      <span class="mt-0.5 size-2 shrink-0 rounded-full" :class="t.type==='error' ? 'bg-danger' : 'bg-success'"></span>
      <p class="min-w-0 flex-1" x-text="t.msg"></p>
      <button type="button" class="text-muted hover:text-ink" @click="$store.toast.remove(t.id)" aria-label="Dismiss"><?= icon('x', 'size-4') ?></button>
    </div>
  </template>
</div>

<!-- New workspace dialog -->
<div x-cloak x-show="newWs" class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" @keydown.escape.window="newWs=false">
  <form method="post" action="<?= e(url('/workspaces')) ?>" class="card w-full max-w-md rounded-b-none p-5 sm:rounded-b-[.875rem]" @click.outside="newWs=false">
    <?= csrf_field() ?>
    <h2 class="text-lg font-semibold">New workspace</h2>
    <p class="mt-1 text-sm text-muted">One workspace per brand or client keeps channels, posts and teams separate.</p>
    <label class="label mt-4" for="ws-name">Workspace name</label>
    <input id="ws-name" name="name" class="input" required maxlength="150" placeholder="e.g. Acme Foods" x-ref="wsname">
    <input type="hidden" name="timezone" value="<?= e($ws['timezone'] ?? ($user['timezone'] ?? 'Asia/Kolkata')) ?>">
    <div class="mt-5 flex justify-end gap-2">
      <button type="button" class="btn btn-secondary" @click="newWs=false">Cancel</button>
      <button class="btn btn-primary">Create workspace</button>
    </div>
  </form>
</div>
<?= $scripts ?? '' ?>
</body>
</html>
