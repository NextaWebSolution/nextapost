<div class="mx-auto max-w-2xl p-3 sm:p-5">
  <form method="post" action="<?= e(url('/account')) ?>" class="card space-y-4 p-5">
    <?= csrf_field() ?>
    <h2 class="text-base font-semibold">Profile</h2>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="label" for="name">Name</label><input id="name" name="name" class="input" value="<?= e($u['name']) ?>" required maxlength="120" autocomplete="name"></div>
      <div><label class="label" for="email">Email</label><input id="email" name="email" type="email" class="input" value="<?= e($u['email']) ?>" required autocomplete="email"></div>
      <div class="sm:col-span-2"><label class="label" for="timezone">Your time zone</label>
        <select id="timezone" name="timezone" class="input"><?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>"<?= selected($tz === $u['timezone']) ?>><?= e($tz) ?></option><?php endforeach; ?></select>
        <p class="help">Used as the default for new workspaces. Each workspace has its own publishing time zone.</p>
      </div>
    </div>
    <h2 class="border-t border-line pt-4 text-base font-semibold">Password</h2>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="label" for="current_password">Current password</label><input id="current_password" name="current_password" type="password" class="input" autocomplete="current-password"><p class="help">Needed to change your email or password.</p></div>
      <div><label class="label" for="new_password">New password</label><input id="new_password" name="new_password" type="password" class="input" autocomplete="new-password" minlength="8"><p class="help">Leave empty to keep your current password.</p></div>
    </div>
    <div class="flex justify-end"><button class="btn btn-primary">Save changes</button></div>
  </form>
</div>
