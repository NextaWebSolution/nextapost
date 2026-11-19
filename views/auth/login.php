<h1 class="text-xl font-semibold">Sign in</h1>
<p class="mt-1 text-sm text-muted">Welcome back. Plan, approve and publish from one calendar.</p>
<form method="post" action="<?= e(url('/login')) ?>" class="mt-6 space-y-4" x-data="{show:false, busy:false}" @submit="busy=true">
  <?= csrf_field() ?>
  <div>
    <label class="label" for="email">Email</label>
    <input id="email" name="email" type="email" class="input" autocomplete="email" required autofocus value="<?= e(old('email')) ?>">
  </div>
  <div>
    <div class="flex items-center justify-between">
      <label class="label" for="password">Password</label>
      <a href="<?= e(url('/forgot')) ?>" class="mb-1.5 text-xs font-medium text-brand-strong hover:underline dark:text-violet-300">Forgot password?</a>
    </div>
    <div class="relative">
      <input id="password" name="password" :type="show ? 'text' : 'password'" type="password" class="input pr-16" autocomplete="current-password" required>
      <button type="button" class="absolute inset-y-0 right-0 px-3 text-xs font-medium text-muted hover:text-ink" @click="show=!show" x-text="show ? 'Hide' : 'Show'">Show</button>
    </div>
  </div>
  <button class="btn btn-primary w-full" :disabled="busy"><span x-show="!busy">Sign in</span><span x-cloak x-show="busy">Signing in…</span></button>
</form>
<?php if (!empty($registration)): ?>
  <p class="mt-6 text-center text-sm text-muted">New here? <a class="font-semibold text-brand-strong hover:underline dark:text-violet-300" href="<?= e(url('/register')) ?>">Create an account</a></p>
<?php endif; ?>
