<?php
$flashes = array_filter(['success' => flash('success'), 'error' => flash('error')]);
$theme = $user['theme'] ?? 'system';
?><!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<meta name="color-scheme" content="light dark">
<title><?= e($title ?? app_name()) ?> · <?= e(app_name()) ?></title>
<link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
<script nonce="<?= e(csp_nonce()) ?>">(function(){var t=document.documentElement.dataset.theme;if(t!=='light'&&t!=='dark'){t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.classList.toggle('dark',t==='dark')})();</script>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<script src="<?= asset('js/app.js') ?>"></script>
<script defer src="<?= asset('vendor/alpine.min.js') ?>"></script>
</head>
<body class="hero-gradient min-h-full" x-data="appShell" data-flash="<?= json_attr([]) ?>">
<main class="flex min-h-screen items-center justify-center px-4 py-10">
  <div class="w-full max-w-md">
    <a href="<?= e(url('/')) ?>" class="mb-6 flex items-center justify-center gap-2.5 text-white">
      <img src="<?= asset('img/logo.svg') ?>" alt="" class="size-9">
      <span class="text-xl font-bold tracking-tight"><?= e(app_name()) ?></span>
    </a>
    <div class="card p-6 sm:p-8">
      <?php foreach ($flashes as $type => $msg): ?>
        <div class="alert alert-<?= $type === 'error' ? 'error' : 'success' ?> mb-5" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= icon($type === 'error' ? 'alert' : 'check-circle', 'size-5 shrink-0') ?><span><?= e($msg) ?></span></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
    <p class="mt-6 text-center text-xs text-white/70">Self-hosted social media scheduler · v<?= e(APP_VERSION) ?></p>
  </div>
</main>
</body>
</html>
