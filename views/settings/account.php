<div class="mx-auto max-w-2xl p-3 sm:p-5">
  <form method="post" action="<?= e(url('/account')) ?>" class="card space-y-4 p-5">
    <?= csrf_field() ?>
    <h2 class="text-base font-semibold"><?= e(__('Profile')) ?></h2>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="label" for="name"><?= e(__('Name')) ?></label><input id="name" name="name" class="input" value="<?= e($u['name']) ?>" required maxlength="120" autocomplete="name"></div>
      <div><label class="label" for="email"><?= e(__('Email')) ?></label><input id="email" name="email" type="email" class="input" value="<?= e($u['email']) ?>" required autocomplete="email"></div>
      <div class="sm:col-span-2"><label class="label" for="timezone"><?= e(__('Your time zone')) ?></label>
        <select id="timezone" name="timezone" class="input"><?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>"<?= selected($tz === $u['timezone']) ?>><?= e($tz) ?></option><?php endforeach; ?></select>
        <p class="help"><?= e(__('Used as the default for new workspaces. Each workspace has its own publishing time zone.')) ?></p>
      </div>
      <div class="sm:col-span-2"><label class="label" for="locale"><?= e(__('Language')) ?></label>
        <select id="locale" name="locale" class="input"><?php foreach (\App\Core\Lang::LOCALES as $code => [$native, $english]): ?><option value="<?= e($code) ?>"<?= selected($code === \App\Core\Lang::normalize($u['locale'] ?? \App\Core\Lang::current())) ?>><?= e($native) ?><?= $native !== $english ? ' · ' . e($english) : '' ?></option><?php endforeach; ?></select>
        <p class="help"><?= e(__('The language of menus, buttons and the emails you receive.')) ?></p>
      </div>
    </div>
    <h2 class="border-t border-line pt-4 text-base font-semibold"><?= e(__('Email notifications')) ?></h2>
    <input type="hidden" name="notify_form" value="1">
    <div class="grid gap-2">
      <?php $prefs = json_decode((string) ($u['notify_json'] ?? ''), true) ?: []; foreach (\App\Services\Notifier::KINDS as $kind => $label): ?>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="notify[<?= e($kind) ?>]" value="1"<?= ($prefs[$kind] ?? true) ? ' checked' : '' ?>> <?= e(__($label)) ?></label>
      <?php endforeach; ?>
    </div>
    <h2 class="border-t border-line pt-4 text-base font-semibold"><?= e(__('Password')) ?></h2>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="label" for="current_password"><?= e(__('Current password')) ?></label><input id="current_password" name="current_password" type="password" class="input" autocomplete="current-password"><p class="help"><?= e(__('Needed to change your email or password.')) ?></p></div>
      <div><label class="label" for="new_password"><?= e(__('New password')) ?></label><input id="new_password" name="new_password" type="password" class="input" autocomplete="new-password" minlength="8"><p class="help"><?= e(__('Leave empty to keep your current password.')) ?></p></div>
    </div>
    <div class="flex justify-end"><button class="btn btn-primary"><?= e(__('Save changes')) ?></button></div>
  </form>
</div>
