<?php
$tabs = ['general' => 'General', 'health' => 'Health & cron', 'email' => 'Email', 'integrations' => 'Integrations', 'updates' => 'Updates', 'backups' => 'Backups', 'users' => 'Users'];
$tab = isset($tabs[$tab]) ? $tab : 'general';
?>
<div class="mx-auto max-w-4xl p-3 sm:p-5">
  <nav class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-2 scroll-thin" aria-label="System sections">
    <?php foreach ($tabs as $k => $label): ?><a class="tab" href="<?= e(url('/system', ['tab' => $k])) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
  </nav>

<?php if ($tab === 'general'): ?>
  <form method="post" action="<?= e(url('/system/general')) ?>" class="card mt-3 space-y-4 p-5">
    <?= csrf_field() ?>
    <div><label class="label" for="app_name">Product name</label><input id="app_name" name="app_name" class="input" maxlength="60" value="<?= e($general['app_name']) ?>"><p class="help">Shown in the sidebar, emails and browser tab — white-label it for your agency.</p></div>
    <label class="flex items-start gap-3"><input type="checkbox" class="checkbox mt-0.5" name="allow_registration" value="1" <?= $general['allow_registration'] ? 'checked' : '' ?>><span><span class="text-sm font-medium">Allow public sign-ups</span><span class="block text-xs text-muted">Anyone can create an account and their own workspace. Leave off to add people by invite only.</span></span></label>
    <div class="flex justify-end"><button class="btn btn-primary">Save</button></div>
  </form>

<?php elseif ($tab === 'health'): ?>
  <section class="card mt-3 p-5">
    <h2 class="text-base font-semibold">System checks</h2>
    <ul class="mt-3 space-y-2">
      <?php foreach ($checks as [$label, $ok]): ?>
        <li class="flex items-center gap-2 text-sm"><span class="flex size-5 items-center justify-center rounded-full <?= $ok ? 'bg-[color-mix(in_srgb,var(--c-green)_18%,transparent)] text-success' : 'bg-[color-mix(in_srgb,var(--c-red)_18%,transparent)] text-danger' ?>"><?= $ok ? icon('check', 'size-3.5') : icon('x', 'size-3.5') ?></span><?= e($label) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="card mt-4 p-5" x-data="copyText(<?= json_attr('* * * * * ' . $cronCmd) ?>)">
    <h2 class="text-base font-semibold">Scheduler cron job</h2>
    <p class="mt-1 text-sm text-muted">Posts publish when this runs. In cPanel open <strong>Cron Jobs</strong>, choose “Once Per Minute (* * * * *)” and paste this command:</p>
    <div class="mt-3 flex items-center gap-2 rounded-xl bg-canvas p-3"><code class="min-w-0 flex-1 break-all text-xs"><?= e($cronCmd) ?></code><button type="button" class="btn btn-secondary btn-sm" @click="copy()" x-text="copied ? 'Copied' : 'Copy'"></button></div>
    <p class="help">If your host uses another PHP path, run <code>which php</code> in cPanel Terminal or use <code>/usr/local/bin/ea-php83</code>.</p>
    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
      <div class="rounded-xl bg-canvas p-3"><dt class="text-xs text-muted">Last run</dt><dd class="font-semibold"><?= e(time_ago($hb['at'] ?? null)) ?></dd></div>
      <div class="rounded-xl bg-canvas p-3"><dt class="text-xs text-muted">Queued jobs</dt><dd class="font-semibold"><?= (int) $stats['queued'] ?></dd></div>
      <div class="rounded-xl bg-canvas p-3"><dt class="text-xs text-muted">Completed</dt><dd class="font-semibold"><?= (int) $stats['done'] ?></dd></div>
      <div class="rounded-xl bg-canvas p-3"><dt class="text-xs text-muted">Failed</dt><dd class="font-semibold"><?= (int) $stats['failed'] ?></dd></div>
    </dl>
    <form method="post" action="<?= e(url('/system/cron')) ?>" class="mt-4"><?= csrf_field() ?><button class="btn btn-secondary btn-sm"><?= icon('refresh', 'size-4') ?> Run scheduler now</button></form>
    <?php if ($failedJobs): ?>
      <h3 class="mt-5 text-sm font-semibold">Recent failures</h3>
      <ul class="mt-2 space-y-1 text-xs"><?php foreach ($failedJobs as $j): ?><li class="text-muted"><span class="text-ink"><?= e($j['finished_at']) ?> UTC</span> — <?= e(str_excerpt($j['last_error'], 200)) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'email'): ?>
  <form method="post" action="<?= e(url('/system/mail')) ?>" class="card mt-3 space-y-4 p-5" x-data="{driver: <?= json_attr($mail['driver']) ?>}">
    <?= csrf_field() ?>
    <p class="text-sm text-muted">Used for password resets and team invitations. cPanel email accounts work well with SMTP (port 465 SSL or 587 TLS).</p>
    <div><label class="label" for="driver">Send email with</label>
      <select id="driver" name="driver" class="input" x-model="driver"><option value="smtp">SMTP (recommended)</option><option value="mail">PHP mail()</option><option value="log">Write to log file (testing)</option></select></div>
    <div class="grid gap-4 sm:grid-cols-3" x-show="driver==='smtp'">
      <div class="sm:col-span-2"><label class="label" for="host">SMTP host</label><input id="host" name="host" class="input" value="<?= e($mail['host']) ?>" placeholder="mail.yourdomain.com"></div>
      <div><label class="label" for="port">Port</label><input id="port" name="port" type="number" class="input" value="<?= e((string) $mail['port']) ?>"></div>
      <div><label class="label" for="encryption">Security</label><select id="encryption" name="encryption" class="input"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($mail['encryption'] === $k) ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div><label class="label" for="username">Username</label><input id="username" name="username" class="input" value="<?= e($mail['username']) ?>" autocomplete="off"></div>
      <div><label class="label" for="password">Password</label><input id="password" name="password" type="password" class="input" placeholder="<?= $mail['password_enc'] ? '•••••••• (saved)' : '' ?>" autocomplete="new-password"></div>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="label" for="from_email">From email</label><input id="from_email" name="from_email" type="email" class="input" value="<?= e($mail['from_email']) ?>" placeholder="no-reply@yourdomain.com"></div>
      <div><label class="label" for="from_name">From name</label><input id="from_name" name="from_name" class="input" value="<?= e($mail['from_name']) ?>"></div>
    </div>
    <div class="flex flex-wrap justify-end gap-2">
      <button class="btn btn-secondary" name="send_test" value="1">Save &amp; send test email</button>
      <button class="btn btn-primary">Save</button>
    </div>
  </form>

<?php elseif ($tab === 'integrations'): ?>
  <form method="post" action="<?= e(url('/system/integrations')) ?>" class="mt-3 space-y-4">
    <?= csrf_field() ?>
    <p class="text-sm text-muted">Create a developer app on each network, paste its keys here, and add the <strong>redirect URL</strong> shown to the app’s settings. Secrets are encrypted.</p>
    <?php foreach ($apps as $key => $a): ?>
      <section class="card p-5" x-data="copyText(<?= json_attr($a['redirect']) ?>)">
        <div class="flex flex-wrap items-center gap-2">
          <h2 class="text-base font-semibold"><?= e($a['name']) ?></h2>
          <?= $a['client_id'] && $a['has_secret'] ? '<span class="badge st-published">Configured</span>' : '<span class="badge">Not set up</span>' ?>
          <a class="ml-auto inline-flex items-center gap-1 text-sm font-medium text-brand-strong hover:underline dark:text-violet-300" href="<?= e($a['docs']) ?>" target="_blank" rel="noopener">Developer console <?= icon('external', 'size-3.5') ?></a>
        </div>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <div><label class="label" for="<?= $key ?>-id"><?= e($a['id_label']) ?></label><input id="<?= $key ?>-id" name="<?= $key ?>[client_id]" class="input" value="<?= e($a['client_id']) ?>" autocomplete="off" spellcheck="false"></div>
          <div><label class="label" for="<?= $key ?>-secret"><?= e($a['secret_label']) ?></label><input id="<?= $key ?>-secret" name="<?= $key ?>[client_secret]" type="password" class="input" placeholder="<?= $a['has_secret'] ? '•••••••• (saved)' : '' ?>" autocomplete="new-password"></div>
        </div>
        <label class="label mt-3">Redirect URL</label>
        <div class="flex items-center gap-2 rounded-xl bg-canvas p-2.5"><code class="min-w-0 flex-1 break-all text-xs"><?= e($a['redirect']) ?></code><button type="button" class="btn btn-secondary btn-sm" @click="copy()" x-text="copied ? 'Copied' : 'Copy'"></button></div>
        <?php if ($key === 'meta'): ?><p class="help">Add “Facebook Login for Business” and the Instagram Graph API. Permissions: pages_show_list, pages_manage_posts, pages_read_engagement, business_management, instagram_basic, instagram_content_publish (needs Meta App Review before other people can use it).</p>
        <?php elseif ($key === 'linkedin'): ?><p class="help">Add the products “Share on LinkedIn” and “Sign In with LinkedIn using OpenID Connect”. Self-serve, for personal profiles.</p>
        <?php elseif ($key === 'linkedin_page'): ?><p class="help">Create a <strong>separate</strong> LinkedIn app associated with your company Page, request the <strong>Community Management API</strong> product (keep it the only product on this app), and complete LinkedIn’s review (Development tier, then Standard tier with a screencast). Scopes used: w_organization_social, rw_organization_admin.</p>
        <?php else: ?><p class="help">User authentication settings → OAuth 2.0, type “Web App”, permissions Read and write. X bills API usage to your developer account.</p><?php endif; ?>
      </section>
    <?php endforeach; ?>
    <div class="flex justify-end"><button class="btn btn-primary">Save integrations</button></div>
  </form>

<?php elseif ($tab === 'updates'): ?>
  <section class="card mt-3 p-5">
    <div class="flex flex-wrap items-center gap-3">
      <div><p class="text-xs text-muted">Installed version</p><p class="text-2xl font-bold">v<?= e(APP_VERSION) ?></p></div>
      <form method="post" action="<?= e(url('/system/updates/check')) ?>" class="ml-auto"><?= csrf_field() ?><button class="btn btn-secondary"><?= icon('refresh', 'size-4') ?> Check for updates</button></form>
    </div>
    <p class="mt-1 text-xs text-muted">Last checked: <?= e($updateChecked ? time_ago($updateChecked) : 'never') ?><?= $updateLast ? ' · Last update ' . e($updateLast['from'] . ' → ' . $updateLast['to'] . ', ' . time_ago($updateLast['at'])) : '' ?></p>
    <?php if (!empty($updateError['message'])): ?><div class="alert alert-error mt-3"><?= icon('alert', 'size-5 shrink-0') ?><span>Last automatic update failed (<?= e(time_ago($updateError['at'])) ?>): <?= e($updateError['message']) ?></span></div><?php endif; ?>
    <?php if (!empty($update['version'])): ?>
      <div class="mt-4 rounded-xl border border-line bg-canvas p-4">
        <p class="font-semibold">Version <?= e($update['version']) ?> is available<?= $update['released_at'] ? ' · released ' . e($update['released_at']) : '' ?></p>
        <?php if (trim((string) $updates['public_key']) === ''): ?><p class="mt-2 text-sm text-warn">No signing key is set, so this package is verified by checksum only. Add your update signing public key below for full protection.</p><?php endif; ?>
        <?php if ($update['notes']): ?><div class="preview-text mt-2 max-h-60 overflow-auto text-sm text-muted"><?= e($update['notes']) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('/system/updates/install')) ?>" class="mt-3" x-data="{busy:false}" @submit="if(!confirm(<?= json_attr('Install version ' . $update['version'] . ' now? A full backup is taken first and restored automatically if anything fails.') ?>)){$event.preventDefault()}else{busy=true}">
          <?= csrf_field() ?><button class="btn btn-primary" :disabled="busy"><?= icon('download', 'size-4') ?> <span x-text="busy ? 'Updating — don’t close this page…' : 'Back up and update now'">Back up and update now</span></button>
        </form>
      </div>
    <?php endif; ?>
  </section>
  <form method="post" action="<?= e(url('/system/updates')) ?>" class="card mt-4 space-y-4 p-5">
    <?= csrf_field() ?>
    <h2 class="text-base font-semibold">Update settings</h2>
    <div class="grid gap-4 sm:grid-cols-2">
      <div class="sm:col-span-2"><label class="label" for="server">Update server</label><input id="server" name="server" class="input" value="<?= e($updates['server']) ?>"></div>
      <div><label class="label" for="channel">Release channel</label><select id="channel" name="channel" class="input"><option value="stable"<?= selected($updates['channel'] === 'stable') ?>>Stable</option><option value="beta"<?= selected($updates['channel'] === 'beta') ?>>Beta</option></select></div>
      <div><label class="label" for="license_key">Licence key <span class="font-normal text-muted">(optional)</span></label><input id="license_key" name="license_key" class="input" value="<?= e($updates['license_key']) ?>" autocomplete="off"></div>
      <div class="sm:col-span-2"><label class="label" for="public_key">Signing public key <span class="font-normal text-muted">(recommended)</span></label><input id="public_key" name="public_key" class="input font-mono text-xs" value="<?= e($updates['public_key']) ?>" spellcheck="false"><p class="help">Base64 Ed25519 key from your update server. When set, unsigned or tampered packages are refused.</p></div>
    </div>
    <label class="flex items-start gap-3"><input type="checkbox" class="checkbox mt-0.5" name="auto_install" value="1" <?= !empty($updates['auto_install']) ? 'checked' : '' ?>><span><span class="text-sm font-medium">Install updates automatically</span><span class="block text-xs text-muted">The cron job checks daily and installs new versions with a backup first. Off by default, and only works when a signing public key is set.</span></span></label>
    <div class="flex justify-end"><button class="btn btn-primary">Save</button></div>
  </form>
  <form method="post" action="<?= e(url('/system/updates/upload')) ?>" enctype="multipart/form-data" class="card mt-4 space-y-3 p-5">
    <?= csrf_field() ?>
    <h2 class="text-base font-semibold">Upload an update package</h2>
    <p class="text-sm text-muted">Got a NextaPost .zip from Nexta? Upload it here to install it with the same backup and rollback protection.</p>
    <input type="file" name="package" accept=".zip,application/zip" class="input" required>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="reinstall" value="1"> Allow reinstalling the same version</label>
    <div class="flex justify-end"><button class="btn btn-secondary"><?= icon('upload', 'size-4') ?> Upload and install</button></div>
  </form>

<?php elseif ($tab === 'backups'): ?>
  <section class="card mt-3 p-5">
    <div class="flex flex-wrap items-center gap-3">
      <p class="text-sm text-muted">Backups contain the application files, your .env and a full database dump. Uploaded media isn’t included — back up <code>public/media</code> with cPanel Backup.</p>
      <form method="post" action="<?= e(url('/system/backups')) ?>" class="ml-auto"><?= csrf_field() ?><button class="btn btn-primary"><?= icon('download', 'size-4') ?> Create backup</button></form>
    </div>
    <?php if (!$backups): ?><p class="mt-4 text-sm text-muted">No backups yet.</p><?php else: ?>
      <ul class="mt-4 divide-y divide-[var(--np-line)]">
        <?php foreach ($backups as $b): ?>
          <li class="flex flex-wrap items-center gap-3 py-2.5 text-sm">
            <span class="min-w-0 flex-1 break-all font-mono text-xs"><?= e($b['name']) ?></span>
            <span class="text-xs text-muted"><?= e(human_bytes($b['size'])) ?> · <?= e(time_ago(gmdate('Y-m-d H:i:s', $b['created']))) ?></span>
            <a class="btn btn-secondary btn-sm" href="<?= e(url('/system/backups/' . $b['name'])) ?>">Download</a>
            <form method="post" action="<?= e(url('/system/backups/' . $b['name'] . '/delete')) ?>" x-data="confirmForm('Delete this backup?')" @submit="check"><?= csrf_field() ?><button class="btn btn-ghost btn-sm text-danger">Delete</button></form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'users'): ?>
  <section class="card mt-3 overflow-x-auto">
    <table class="table min-w-[600px]">
      <thead><tr><th class="pl-5">User</th><th>Role</th><th>Last sign-in</th><th class="pr-5"></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td class="pl-5"><p class="font-medium"><?= e($u['name']) ?></p><p class="text-xs text-muted"><?= e($u['email']) ?></p></td>
            <td><?= (int) $u['is_super_admin'] ? '<span class="badge st-scheduled">System admin</span>' : 'User' ?> <?= (int) $u['is_active'] ? '' : '<span class="badge st-failed">Disabled</span>' ?></td>
            <td class="text-muted"><?= e(time_ago($u['last_login_at'])) ?></td>
            <td class="pr-5 text-right"><?php if ((int) $u['id'] !== (int) $user['id']): ?>
              <div class="flex justify-end gap-1">
              <form method="post" action="<?= e(url('/system/users/' . $u['uuid'] . '/toggle')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm"><?= (int) $u['is_active'] ? 'Disable' : 'Enable' ?></button></form>
              <form method="post" action="<?= e(url('/system/users/' . $u['uuid'] . '/delete')) ?>" x-data="confirmForm(<?= json_attr('Permanently delete ' . $u['name'] . '’s account and personal data? Their posts stay in the workspaces.') ?>)" @submit="check"><?= csrf_field() ?><button class="btn btn-ghost btn-sm text-danger">Delete</button></form>
              </div>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>
</div>
