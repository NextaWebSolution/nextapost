<h1 class="text-xl font-semibold"><?= e(__('Choose a new password')) ?></h1>
<p class="mt-1 text-sm text-muted"><?= e(__('For {email}', ['email' => $email])) ?></p>
<form method="post" action="<?= e(url('/reset/' . $token)) ?>" class="mt-6 space-y-4">
  <?= csrf_field() ?>
  <div>
    <label class="label" for="password"><?= e(__('New password')) ?></label>
    <input id="password" name="password" type="password" class="input" autocomplete="new-password" required minlength="8" autofocus>
    <p class="help"><?= e(__('At least 8 characters.')) ?></p>
  </div>
  <button class="btn btn-primary w-full"><?= e(__('Save password and sign in')) ?></button>
</form>
