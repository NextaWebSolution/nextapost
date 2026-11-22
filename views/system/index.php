<?php
$tabs = ['general' => __('General'), 'health' => __('Health & cron'), 'email' => __('Email'), 'integrations' => __('Integrations'), 'languages' => __('Languages'), 'updates' => __('Updates'), 'backups' => __('Backups'), 'users' => __('Users')];
$tab = isset($tabs[$tab]) ? $tab : 'general';
?>
<div class="mx-auto max-w-4xl p-3 sm:p-5">
  <nav class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-2 scroll-thin" aria-label="<?= e(__('System sections')) ?>">
    <?php foreach ($tabs as $k => $label): ?><a class="tab" href="<?= e(url('/system', ['tab' => $k])) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
  </nav>

<?php if ($tab === 'general'): ?>
  <form method="post" action="<?= e(url('/system/general')) ?>" class="card mt-3 space-y-4 p-5">
    <?= csrf_field() ?>
    <div><label class="label" for="app_name"><?= e(__('Product name')) ?></label><input id="app_name" name="app_name" class="input" maxlength="60" value="<?= e($general['app_name']) ?>"><p class="help"><?= e(__('Shown in the sidebar, emails and browser tab — white-label it for your agency.')) ?></p></div>
    <label class="flex items-start gap-3"><input type="checkbox" class="checkbox mt-0.5" name="allow_registration" value="1" <?= $general['allow_registration'] ? 'checked' : '' ?>><span><span class="text-sm font-medium"><?= e(__('Allow public sign-ups')) ?></span><span class="block text-xs text-muted"><?= e(__('Anyone can create an account and their own workspace. Leave off to add people by invite only.')) ?></span></span></label>
    <div class="flex justify-end"><button class="btn btn-primary"><?= e(__('Save')) ?></button></div>
  </form>

<?php elseif ($tab === 'health'): ?>
  <section class="card mt-3 p-5">
    <h2 class="text-base font-semibold"><?= e(__('System checks')) ?></h2>
    <ul class="mt-3 space-y-2">
      <?php foreach ($checks as [$label, $ok]): ?>
        <li class="flex items-center gap-2 text-sm"><span class="flex size-5 items-center justify-center rounded-full <?= $ok ? 'bg-[color-mix(in_srgb,var(--c-green)_18%,transparent)] text-success' : 'bg-[color-mix(in_srgb,var(--c-red)_18%,transparent)] text-danger' ?>"><?= $ok ? icon('check', 'size-3.5') : icon('x', 'size-3.5') ?></span><?= e($label) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="card mt-4 p-5" x-data="copyText(<?= json_attr('* * * * * ' . $cronCmd) ?>)">
    <h2 class="text-base font-semibold"><?= e(__('Scheduler cron job')) ?></h2>
    <p class="mt-1 text-sm text-muted"><?= __html('Posts publish when this runs. In cPanel open {cron}, choose “Once Per Minute (* * * * *)” and paste this command:', ['cron' => '<strong>' . e(__('Cron Jobs')) . '</strong>']) ?></p>
    <div class="mt-3 flex items-center gap-2 rounded-xl bg-canvas p-3"><code class="min-w-0 flex-1 break-all text-xs"><?= e($cronCmd) ?></code><button type="button" class="btn btn-secondary btn-sm" @click="copy()" x-text="copied ? np.t('Copied') : np.t('Copy')"></button></div>
    <p class="help"><?= __html('If your host uses another PHP path, run {cmd} in cPanel Terminal or use {path}.', ['cmd' => '<code>which php</code>', 'path' => '<code>/usr/local/bin/ea-php83</code>']) ?></p>
    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
      <div class="rounded-xl bg-canvas p-3"><dt class="text-xs text-muted"><?= e(__('Last run')) ?></dt><dd class="font-semibold"><?= e(time_ago($hb['at'] ?? null)) ?></dd></div>
      <div class="rounded-xl bg-canvas p-3"><dt class="text-xs text-muted"><?= e(__('Queued jobs')) ?></dt><dd class="font-semibold"><?= (int) $stats['queued'] ?></dd></div>
      <div class="rounded-xl bg-canvas p-3"><dt class="text-xs text-muted"><?= e(__('Completed')) ?></dt><dd class="font-semibold"><?= (int) $stats['done'] ?></dd></div>
      <div class="rounded-xl bg-canvas p-3"><dt class="text-xs text-muted"><?= e(__('Failed')) ?></dt><dd class="font-semibold"><?= (int) $stats['failed'] ?></dd></div>
    </dl>
    <form method="post" action="<?= e(url('/system/cron')) ?>" class="mt-4"><?= csrf_field() ?><button class="btn btn-secondary btn-sm"><?= icon('refresh', 'size-4') ?> <?= e(__('Run scheduler now')) ?></button></form>
    <?php if ($failedJobs): ?>
      <h3 class="mt-5 text-sm font-semibold"><?= e(__('Recent failures')) ?></h3>
      <ul class="mt-2 space-y-1 text-xs"><?php foreach ($failedJobs as $j): ?><li class="text-muted"><span class="text-ink"><?= e($j['finished_at']) ?> UTC</span> — <?= e(str_excerpt($j['last_error'], 200)) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'email'): ?>
  <form method="post" action="<?= e(url('/system/mail')) ?>" class="card mt-3 space-y-4 p-5" x-data="{driver: <?= json_attr($mail['driver']) ?>}">
    <?= csrf_field() ?>
    <p class="text-sm text-muted"><?= e(__('Used for password resets and team invitations. cPanel email accounts work well with SMTP (port 465 SSL or 587 TLS).')) ?></p>
    <div><label class="label" for="driver"><?= e(__('Send email with')) ?></label>
      <select id="driver" name="driver" class="input" x-model="driver"><option value="smtp"><?= e(__('SMTP (recommended)')) ?></option><option value="mail">PHP mail()</option><option value="log"><?= e(__('Write to log file (testing)')) ?></option></select></div>
    <div class="grid gap-4 sm:grid-cols-3" x-show="driver==='smtp'">
      <div class="sm:col-span-2"><label class="label" for="host"><?= e(__('SMTP host')) ?></label><input id="host" name="host" class="input" value="<?= e($mail['host']) ?>" placeholder="mail.yourdomain.com"></div>
      <div><label class="label" for="port"><?= e(__('Port')) ?></label><input id="port" name="port" type="number" class="input" value="<?= e((string) $mail['port']) ?>"></div>
      <div><label class="label" for="encryption"><?= e(__('Security')) ?></label><select id="encryption" name="encryption" class="input"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($mail['encryption'] === $k) ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div><label class="label" for="username"><?= e(__('Username')) ?></label><input id="username" name="username" class="input" value="<?= e($mail['username']) ?>" autocomplete="off"></div>
      <div><label class="label" for="password"><?= e(__('Password')) ?></label><input id="password" name="password" type="password" class="input" placeholder="<?= $mail['password_enc'] ? '•••••••• (saved)' : '' ?>" autocomplete="new-password"></div>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="label" for="from_email"><?= e(__('From email')) ?></label><input id="from_email" name="from_email" type="email" class="input" value="<?= e($mail['from_email']) ?>" placeholder="no-reply@yourdomain.com"></div>
      <div><label class="label" for="from_name"><?= e(__('From name')) ?></label><input id="from_name" name="from_name" class="input" value="<?= e($mail['from_name']) ?>"></div>
    </div>
    <div class="flex flex-wrap justify-end gap-2">
      <button class="btn btn-secondary" name="send_test" value="1"><?= e(__('Save & send test email')) ?></button>
      <button class="btn btn-primary"><?= e(__('Save')) ?></button>
    </div>
  </form>

<?php elseif ($tab === 'integrations'): ?>
  <form method="post" action="<?= e(url('/system/integrations')) ?>" class="mt-3 space-y-4">
    <?= csrf_field() ?>
    <p class="text-sm text-muted"><?= __html('Create a developer app on each network, paste its keys here, and add the {redirect} shown to the app’s settings. Secrets are encrypted.', ['redirect' => '<strong>' . e(__('redirect URL')) . '</strong>']) ?></p>
    <?php foreach ($apps as $key => $a): ?>
      <section class="card p-5" x-data="copyText(<?= json_attr($a['redirect']) ?>)">
        <div class="flex flex-wrap items-center gap-2">
          <h2 class="text-base font-semibold"><?= e($a['name']) ?></h2>
          <?= $a['client_id'] && $a['has_secret'] ? '<span class="badge st-published">Configured</span>' : '<span class="badge">Not set up</span>' ?>
          <a class="ml-auto inline-flex items-center gap-1 text-sm font-medium text-brand-strong hover:underline dark:text-violet-300" href="<?= e($a['docs']) ?>" target="_blank" rel="noopener"><?= e(__('Developer console')) ?> <?= icon('external', 'size-3.5') ?></a>
        </div>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <div><label class="label" for="<?= $key ?>-id"><?= e($a['id_label']) ?></label><input id="<?= $key ?>-id" name="<?= $key ?>[client_id]" class="input" value="<?= e($a['client_id']) ?>" autocomplete="off" spellcheck="false"></div>
          <div><label class="label" for="<?= $key ?>-secret"><?= e($a['secret_label']) ?></label><input id="<?= $key ?>-secret" name="<?= $key ?>[client_secret]" type="password" class="input" placeholder="<?= $a['has_secret'] ? '•••••••• (saved)' : '' ?>" autocomplete="new-password"></div>
        </div>
        <label class="label mt-3"><?= e(__('Redirect URL')) ?></label>
        <div class="flex items-center gap-2 rounded-xl bg-canvas p-2.5"><code class="min-w-0 flex-1 break-all text-xs"><?= e($a['redirect']) ?></code><button type="button" class="btn btn-secondary btn-sm" @click="copy()" x-text="copied ? np.t('Copied') : np.t('Copy')"></button></div>
        <?php if (!empty($a['help'])): ?><p class="help"><?= e($a['help']) ?></p><?php endif; ?>
      </section>
    <?php endforeach; ?>
    <h2 class="pt-4 text-base font-semibold"><?= e(__('Media sources')) ?></h2>
    <p class="text-sm text-muted"><?= e(__('Let people add photos and designs to the media library from Unsplash, Canva, Google Photos and Google Drive. Each person connects their own Canva and Google account.')) ?></p>
    <?php foreach ($mediaApps as $key => $a): ?>
      <section class="card p-5" x-data="copyText(<?= json_attr((string) $a['redirect']) ?>)">
        <div class="flex flex-wrap items-center gap-2">
          <h3 class="text-base font-semibold"><?= e($a['name']) ?></h3>
          <?= $a['client_id'] ? '<span class="badge st-published">Configured</span>' : '<span class="badge">Not set up</span>' ?>
          <a class="ml-auto inline-flex items-center gap-1 text-sm font-medium text-brand-strong hover:underline dark:text-violet-300" href="<?= e($a['docs']) ?>" target="_blank" rel="noopener"><?= e(__('Developer console')) ?> <?= icon('external', 'size-3.5') ?></a>
        </div>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <div><label class="label" for="<?= $key ?>-id"><?= e($a['id_label']) ?></label><input id="<?= $key ?>-id" name="<?= $key ?>[client_id]" class="input" value="<?= e($a['client_id']) ?>" autocomplete="off" spellcheck="false"></div>
          <?php if (($a['secret'] ?? true) !== false): ?>
            <div><label class="label" for="<?= $key ?>-secret"><?= e($a['secret_label']) ?></label>
              <?php if (!empty($a['secret_visible'])): ?>
                <input id="<?= $key ?>-secret" name="<?= $key ?>[client_secret]" class="input" value="<?= e($a['secret_value']) ?>" autocomplete="off">
              <?php else: ?>
                <input id="<?= $key ?>-secret" name="<?= $key ?>[client_secret]" type="password" class="input" placeholder="<?= $a['has_secret'] ? '•••••••• (saved)' : '' ?>" autocomplete="new-password">
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($a['redirect']): ?>
          <label class="label mt-3"><?= e(__('Redirect URL')) ?></label>
          <div class="flex items-center gap-2 rounded-xl bg-canvas p-2.5"><code class="min-w-0 flex-1 break-all text-xs"><?= e($a['redirect']) ?></code><button type="button" class="btn btn-secondary btn-sm" @click="copy()" x-text="copied ? np.t('Copied') : np.t('Copy')"></button></div>
        <?php endif; ?>
        <p class="help"><?= e($a['help']) ?></p>
      </section>
    <?php endforeach; ?>
    <div class="flex justify-end"><button class="btn btn-primary"><?= e(__('Save integrations')) ?></button></div>
  </form>

<?php elseif ($tab === 'updates'): ?>
  <section class="card mt-3 p-5">
    <div class="flex flex-wrap items-center gap-3">
      <div><p class="text-xs text-muted"><?= e(__('Installed version')) ?></p><p class="text-2xl font-bold">v<?= e(APP_VERSION) ?></p></div>
      <form method="post" action="<?= e(url('/system/updates/check')) ?>" class="ml-auto"><?= csrf_field() ?><button class="btn btn-secondary"><?= icon('refresh', 'size-4') ?> <?= e(__('Check for updates')) ?></button></form>
    </div>
    <p class="mt-1 text-xs text-muted"><?= e(__('Last checked: {when}', ['when' => $updateChecked ? time_ago($updateChecked) : __('never')])) ?><?= $updateLast ? ' · ' . e(__('Last update {from} → {to}, {when}', ['from' => $updateLast['from'], 'to' => $updateLast['to'], 'when' => time_ago($updateLast['at'])])) : '' ?></p>
    <?php if (!empty($updateError['message'])): ?><div class="alert alert-error mt-3"><?= icon('alert', 'size-5 shrink-0') ?><span><?= e(__('Last automatic update failed ({when}): {error}', ['when' => time_ago($updateError['at']), 'error' => $updateError['message']])) ?></span></div><?php endif; ?>
    <?php if (!empty($update['version'])): ?>
      <div class="mt-4 rounded-xl border border-line bg-canvas p-4">
        <p class="font-semibold"><?= e(__('Version {v} is available', ['v' => $update['version']])) ?><?= $update['released_at'] ? ' · ' . e(__('released {date}', ['date' => $update['released_at']])) : '' ?></p>
        <?php if (trim((string) $updates['public_key']) === ''): ?><p class="mt-2 text-sm text-warn"><?= e(__('No signing key is set, so this package is verified by checksum only. Add your update signing public key below for full protection.')) ?></p><?php endif; ?>
        <?php if ($update['notes']): ?><div class="preview-text mt-2 max-h-60 overflow-auto text-sm text-muted"><?= e($update['notes']) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('/system/updates/install')) ?>" class="mt-3" x-data="{busy:false}" @submit="if(!confirm(<?= json_attr('Install version ' . $update['version'] . ' now? A full backup is taken first and restored automatically if anything fails.') ?>)){$event.preventDefault()}else{busy=true}">
          <?= csrf_field() ?><button class="btn btn-primary" :disabled="busy"><?= icon('download', 'size-4') ?> <span x-text="busy ? np.t('Updating — don’t close this page…') : np.t('Back up and update now')"><?= e(__('Back up and update now')) ?></span></button>
        </form>
      </div>
    <?php endif; ?>
  </section>
  <form method="post" action="<?= e(url('/system/updates')) ?>" class="card mt-4 space-y-4 p-5">
    <?= csrf_field() ?>
    <h2 class="text-base font-semibold"><?= e(__('Update settings')) ?></h2>
    <div class="grid gap-4 sm:grid-cols-2">
      <div class="sm:col-span-2"><label class="label" for="server"><?= e(__('Update server')) ?></label><input id="server" name="server" class="input" value="<?= e($updates['server']) ?>"></div>
      <div><label class="label" for="channel"><?= e(__('Release channel')) ?></label><select id="channel" name="channel" class="input"><option value="stable"<?= selected($updates['channel'] === 'stable') ?>><?= e(__('Stable')) ?></option><option value="beta"<?= selected($updates['channel'] === 'beta') ?>><?= e(__('Beta')) ?></option></select></div>
      <div><label class="label" for="license_key"><?= e(__('Licence key')) ?> <span class="font-normal text-muted"><?= e(__('(optional)')) ?></span></label><input id="license_key" name="license_key" class="input" value="<?= e($updates['license_key']) ?>" autocomplete="off"></div>
      <div class="sm:col-span-2"><label class="label" for="public_key"><?= e(__('Signing public key')) ?> <span class="font-normal text-muted"><?= e(__('(recommended)')) ?></span></label><input id="public_key" name="public_key" class="input font-mono text-xs" value="<?= e($updates['public_key']) ?>" spellcheck="false"><p class="help"><?= e(__('Base64 Ed25519 key from your update server. When set, unsigned or tampered packages are refused.')) ?></p></div>
    </div>
    <label class="flex items-start gap-3"><input type="checkbox" class="checkbox mt-0.5" name="auto_install" value="1" <?= !empty($updates['auto_install']) ? 'checked' : '' ?>><span><span class="text-sm font-medium"><?= e(__('Install updates automatically')) ?></span><span class="block text-xs text-muted"><?= e(__('The cron job checks daily and installs new versions with a backup first. Off by default, and only works when a signing public key is set.')) ?></span></span></label>
    <div class="flex justify-end"><button class="btn btn-primary"><?= e(__('Save')) ?></button></div>
  </form>
  <form method="post" action="<?= e(url('/system/updates/upload')) ?>" enctype="multipart/form-data" class="card mt-4 space-y-3 p-5">
    <?= csrf_field() ?>
    <h2 class="text-base font-semibold"><?= e(__('Upload an update package')) ?></h2>
    <p class="text-sm text-muted"><?= e(__('Got a NextaPost .zip from Nexta? Upload it here to install it with the same backup and rollback protection.')) ?></p>
    <input type="file" name="package" accept=".zip,application/zip" class="input" required>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="reinstall" value="1"> <?= e(__('Allow reinstalling the same version')) ?></label>
    <div class="flex justify-end"><button class="btn btn-secondary"><?= icon('upload', 'size-4') ?> <?= e(__('Upload and install')) ?></button></div>
  </form>

<?php elseif ($tab === 'backups'): ?>
  <section class="card mt-3 p-5">
    <div class="flex flex-wrap items-center gap-3">
      <p class="text-sm text-muted"><?= __html('Backups contain the application files, your .env and a full database dump. Uploaded media isn’t included — back up {dir} with cPanel Backup.', ['dir' => '<code>public/media</code>']) ?></p>
      <form method="post" action="<?= e(url('/system/backups')) ?>" class="ml-auto"><?= csrf_field() ?><button class="btn btn-primary"><?= icon('download', 'size-4') ?> <?= e(__('Create backup')) ?></button></form>
    </div>
    <?php if (!$backups): ?><p class="mt-4 text-sm text-muted"><?= e(__('No backups yet.')) ?></p><?php else: ?>
      <ul class="mt-4 divide-y divide-[var(--np-line)]">
        <?php foreach ($backups as $b): ?>
          <li class="flex flex-wrap items-center gap-3 py-2.5 text-sm">
            <span class="min-w-0 flex-1 break-all font-mono text-xs"><?= e($b['name']) ?></span>
            <span class="text-xs text-muted"><?= e(human_bytes($b['size'])) ?> · <?= e(time_ago(gmdate('Y-m-d H:i:s', $b['created']))) ?></span>
            <a class="btn btn-secondary btn-sm" href="<?= e(url('/system/backups/' . $b['name'])) ?>"><?= e(__('Download')) ?></a>
            <form method="post" action="<?= e(url('/system/backups/' . $b['name'] . '/delete')) ?>" x-data="confirmForm(<?= json_attr(__('Delete this backup?')) ?>)" @submit="check"><?= csrf_field() ?><button class="btn btn-ghost btn-sm text-danger"><?= e(__('Delete')) ?></button></form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'users'): ?>
  <section class="card relative mt-3 overflow-x-auto">
    <table class="table min-w-[600px]">
      <thead><tr><th class="pl-5"><?= e(__('User')) ?></th><th><?= e(__('Role')) ?></th><th><?= e(__('Last sign-in')) ?></th><th class="pr-5"></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td class="pl-5"><p class="font-medium"><?= e($u['name']) ?></p><p class="text-xs text-muted"><?= e($u['email']) ?></p></td>
            <td><?= (int) $u['is_super_admin'] ? '<span class="badge st-scheduled">System admin</span>' : 'User' ?> <?= (int) $u['is_active'] ? '' : '<span class="badge st-failed">Disabled</span>' ?></td>
            <td class="text-muted"><?= e(time_ago($u['last_login_at'])) ?></td>
            <td class="pr-5 text-right"><?php if ((int) $u['id'] !== (int) $user['id']): ?>
              <div class="flex justify-end gap-1">
              <form method="post" action="<?= e(url('/system/users/' . $u['uuid'] . '/toggle')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm"><?= (int) $u['is_active'] ? 'Disable' : 'Enable' ?></button></form>
              <form method="post" action="<?= e(url('/system/users/' . $u['uuid'] . '/delete')) ?>" x-data="confirmForm(<?= json_attr(__('Permanently delete {name}’s account and personal data? Their posts stay in the workspaces.', ['name' => $u['name']])) ?>)" @submit="check"><?= csrf_field() ?><button class="btn btn-ghost btn-sm text-danger"><?= e(__('Delete')) ?></button></form>
              </div>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php elseif ($tab === 'languages'): ?>
  <?php $langCfg = ['base' => url('/system/languages'), 'workspaces' => $aiWorkspaces, 'languages' => $languages, 'resetUrl' => url('/system/languages/__CODE__/reset')]; ?>
  <section class="card mt-3 p-5" x-data="languageTool(<?= json_attr($langCfg) ?>)">
    <h2 class="text-base font-semibold"><?= e(__('Interface languages')) ?></h2>
    <p class="mt-1 text-sm text-muted"><?= e(__('Each person picks their language under Your account. Text that isn’t translated yet shows in English. You can fill the gaps for a language with the AI assistant of one of your workspaces; those translations are saved on this site and kept across updates.')) ?></p>
    <?php if (!$aiWorkspaces): ?>
      <div class="alert alert-info mt-3"><?= icon('info', 'size-5 shrink-0') ?><span><?= e(__('To fill missing text with AI, set up the AI writing assistant in the settings of a workspace where you are an admin.')) ?></span></div>
    <?php else: ?>
      <div class="mt-3 max-w-sm">
        <label class="label" for="lang-ws"><?= e(__('Use the AI key of')) ?></label>
        <select id="lang-ws" class="input" x-model="workspace">
          <?php foreach ($aiWorkspaces as $w): ?><option value="<?= e($w['uuid']) ?>"><?= e($w['name']) ?></option><?php endforeach; ?>
        </select>
        <p class="help"><?= e(__('Requests are billed to that workspace’s AI provider. A full language is roughly 25–45 requests.')) ?></p>
      </div>
    <?php endif; ?>
    <div class="relative mt-4 overflow-x-auto">
      <table class="table min-w-[640px]">
        <thead><tr><th><?= e(__('Language')) ?></th><th><?= e(__('Translated')) ?></th><th><?= e(__('Filled on this site')) ?></th><th><?= e(__('Missing')) ?></th><th><span class="sr-only"><?= e(__('Actions')) ?></span></th></tr></thead>
        <tbody>
          <template x-for="(l, code) in langs" :key="code">
            <tr>
              <td><span class="font-medium" x-text="l.native"></span> <span class="text-xs text-muted" x-text="'· ' + l.name"></span></td>
              <td>
                <div class="flex items-center gap-2">
                  <div class="h-1.5 w-24 overflow-hidden rounded-full bg-canvas" role="progressbar" :aria-valuenow="l.percent" aria-valuemin="0" aria-valuemax="100" :aria-label="l.name"><div class="h-full rounded-full bg-brand" :style="'width:' + l.percent + '%'"></div></div>
                  <span class="text-sm tabular-nums" x-text="l.percent + '%'"></span>
                </div>
              </td>
              <td class="tabular-nums" x-text="l.site"></td>
              <td class="tabular-nums" x-text="l.missing"></td>
              <td class="text-right">
                <div class="flex items-center justify-end gap-2">
                  <?php if ($aiWorkspaces): ?>
                  <button type="button" class="btn btn-secondary btn-sm" x-show="l.missing > 0" :disabled="running !== null" @click="fill(code)">
                    <?= icon('sparkles', 'size-4') ?> <span x-text="running === code ? np.t('Filling… {n} left', {n: l.missing}) : np.t('Fill missing with AI')"></span>
                  </button>
                  <?php endif; ?>
                  <button type="button" class="btn btn-ghost btn-sm" x-show="running === code" @click="stop()"><?= e(__('Stop')) ?></button>
                  <form method="post" :action="cfg.resetUrl.replace('__CODE__', code)" x-show="l.site > 0 && running === null" x-data="confirmForm(<?= json_attr(__('Remove this site’s own translations for this language? The shipped translations stay.')) ?>)" @submit="check">
                    <?= csrf_field() ?><button class="btn btn-ghost btn-sm"><?= e(__('Reset')) ?></button>
                  </form>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
    <p class="help mt-3" x-show="message" x-text="message" role="status"></p>
  </section>
<?php endif; ?>
</div>
<?php $head = '<script src="' . asset('js/languages.js') . '"></script>'; ?>
