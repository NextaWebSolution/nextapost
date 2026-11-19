<h1 class="text-xl font-semibold">Reset your password</h1>
<p class="mt-1 text-sm text-muted">Enter your account email and we’ll send you a link to choose a new password.</p>
<form method="post" action="<?= e(url('/forgot')) ?>" class="mt-6 space-y-4">
  <?= csrf_field() ?>
  <div>
    <label class="label" for="email">Email</label>
    <input id="email" name="email" type="email" class="input" autocomplete="email" required autofocus>
  </div>
  <button class="btn btn-primary w-full">Send reset link</button>
</form>
<p class="mt-6 text-center text-sm"><a class="font-semibold text-brand-strong hover:underline dark:text-violet-300" href="<?= e(url('/login')) ?>">Back to sign in</a></p>
