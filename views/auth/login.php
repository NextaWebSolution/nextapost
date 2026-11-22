<h1 class="text-xl font-semibold"><?= e(__('Sign in')) ?></h1>
<p class="mt-1 text-sm text-muted"><?= e(__('Welcome back. Plan, approve and publish from one calendar.')) ?></p>
<form method="post" action="<?= e(url('/login')) ?>" class="mt-6 space-y-4" x-data="{show:false, busy:false}" @submit="busy=true">
  <?= csrf_field() ?>
  <div>
    <label class="label" for="email"><?= e(__('Email')) ?></label>
    <input id="email" name="email" type="email" class="input" autocomplete="email" required autofocus value="<?= e(old('email')) ?>">
  </div>
  <div>
    <div class="flex items-center justify-between">
      <label class="label" for="password"><?= e(__('Password')) ?></label>
      <a href="<?= e(url('/forgot')) ?>" class="mb-1.5 text-xs font-medium text-brand-strong hover:underline dark:text-violet-300"><?= e(__('Forgot password?')) ?></a>
    </div>
    <div class="relative">
      <input id="password" name="password" :type="show ? 'text' : 'password'" type="password" class="input pr-16" autocomplete="current-password" required>
      <button type="button" class="absolute inset-y-0 right-0 px-3 text-xs font-medium text-muted hover:text-ink" @click="show=!show" x-text="show ? np.t('Hide') : np.t('Show')"><?= e(__('Show')) ?></button>
    </div>
  </div>
  <button class="btn btn-primary w-full" :disabled="busy"><span x-show="!busy"><?= e(__('Sign in')) ?></span><span x-cloak x-show="busy"><?= e(__('Signing in…')) ?></span></button>
</form>
<?php if (!empty($registration)): ?>
  <p class="mt-6 text-center text-sm text-muted"><?= e(__('New here?')) ?> <a class="font-semibold text-brand-strong hover:underline dark:text-violet-300" href="<?= e(url('/register')) ?>"><?= e(__('Create an account')) ?></a></p>
<?php endif; ?>
