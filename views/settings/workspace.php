<?php $base = '/w/' . $ws['uuid']; $invite = flash('invite_link'); ?>
<div class="mx-auto max-w-4xl space-y-5 p-3 sm:p-5">
  <section class="card p-5">
    <h2 class="text-base font-semibold">Workspace</h2>
    <form method="post" action="<?= e(url($base . '/settings')) ?>" class="mt-4 grid gap-4 sm:grid-cols-2">
      <?= csrf_field() ?>
      <div>
        <label class="label" for="name">Name</label>
        <input id="name" name="name" class="input" value="<?= e($ws['name']) ?>" required maxlength="150" <?= $canSettings ? '' : 'disabled' ?>>
      </div>
      <div>
        <label class="label" for="timezone">Time zone</label>
        <select id="timezone" name="timezone" class="input" <?= $canSettings ? '' : 'disabled' ?>>
          <?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>"<?= selected($tz === $ws['timezone']) ?>><?= e($tz) ?></option><?php endforeach; ?>
        </select>
        <p class="help">The calendar and all publish times use this zone.</p>
      </div>
      <div>
        <label class="label" for="approval_mode">Who can publish without approval?</label>
        <select id="approval_mode" name="approval_mode" class="input" <?= $canSettings ? '' : 'disabled' ?>>
          <option value="editors"<?= selected($ws['approval_mode'] === 'editors') ?>>Admins and editors (contributors need approval)</option>
          <option value="required"<?= selected($ws['approval_mode'] === 'required') ?>>Admins only (everyone else needs approval)</option>
          <option value="none"<?= selected($ws['approval_mode'] === 'none') ?>>Everyone who can edit (no approvals)</option>
        </select>
      </div>
      <div>
        <label class="label" for="color">Colour</label>
        <input id="color" name="color" type="color" class="input h-10 w-20 p-1" value="<?= e($ws['color']) ?>" <?= $canSettings ? '' : 'disabled' ?>>
      </div>
      <?php if ($canSettings): ?><div class="sm:col-span-2 flex justify-end"><button class="btn btn-primary">Save changes</button></div><?php endif; ?>
    </form>
  </section>

  <section class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="text-base font-semibold">Team</h2>
      <span class="text-sm text-muted"><?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?></span>
    </div>
    <?php if ($invite): ?>
      <div class="alert alert-info mt-4" x-data="copyText(<?= json_attr($invite) ?>)">
        <?= icon('mail', 'size-5 shrink-0') ?>
        <div class="min-w-0 flex-1"><p class="font-semibold">Set-password link for the new member (valid 7 days)</p><p class="mt-1 break-all font-mono text-xs"><?= e($invite) ?></p></div>
        <button type="button" class="btn btn-secondary btn-sm" @click="copy()" x-text="copied ? 'Copied' : 'Copy'"></button>
      </div>
    <?php endif; ?>
    <div class="-mx-5 mt-3 overflow-x-auto">
      <table class="table min-w-[560px]">
        <thead><tr><th class="pl-5">Member</th><th>Role</th><th>Last sign-in</th><th class="pr-5"></th></tr></thead>
        <tbody>
          <?php foreach ($members as $m): ?>
            <tr>
              <td class="pl-5"><p class="font-medium"><?= e($m['name']) ?><?= (int) $m['id'] === (int) $user['id'] ? ' <span class="text-muted">(you)</span>' : '' ?></p><p class="text-xs text-muted"><?= e($m['email']) ?></p></td>
              <td>
                <?php if ($canSettings): ?>
                  <form method="post" action="<?= e(url($base . '/members/' . $m['uuid'] . '/role')) ?>">
                    <?= csrf_field() ?>
                    <label class="sr-only" for="role-<?= e($m['uuid']) ?>">Role</label>
                    <select id="role-<?= e($m['uuid']) ?>" name="role" class="input min-h-9 py-1" x-data @change="$el.form.submit()">
                      <?php foreach ($roles as $r => $desc): ?><option value="<?= e($r) ?>"<?= selected($r === $m['role']) ?>><?= e(ucfirst($r)) ?></option><?php endforeach; ?>
                    </select>
                  </form>
                <?php else: ?><?= e(ucfirst($m['role'])) ?><?php endif; ?>
              </td>
              <td class="text-muted"><?= e(time_ago($m['last_login_at'])) ?></td>
              <td class="pr-5 text-right">
                <?php if ($canSettings): ?>
                  <form method="post" action="<?= e(url($base . '/members/' . $m['uuid'] . '/remove')) ?>" x-data="confirmForm(<?= json_attr('Remove ' . $m['name'] . ' from this workspace?') ?>)" @submit="check">
                    <?= csrf_field() ?><button class="btn btn-ghost btn-sm text-danger">Remove</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($canSettings): ?>
      <form method="post" action="<?= e(url($base . '/members')) ?>" class="mt-5 grid gap-3 border-t border-line pt-5 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end">
        <?= csrf_field() ?>
        <div><label class="label" for="inv-email">Email</label><input id="inv-email" name="email" type="email" class="input" required placeholder="colleague@company.com"></div>
        <div><label class="label" for="inv-name">Name <span class="font-normal text-muted">(new users)</span></label><input id="inv-name" name="name" class="input" maxlength="120"></div>
        <div><label class="label" for="inv-role">Role</label>
          <select id="inv-role" name="role" class="input"><?php foreach ($roles as $r => $desc): ?><option value="<?= e($r) ?>"<?= selected($r === 'editor') ?> title="<?= e($desc) ?>"><?= e(ucfirst($r)) ?></option><?php endforeach; ?></select>
        </div>
        <button class="btn btn-primary"><?= icon('plus', 'size-4') ?> Add member</button>
      </form>
      <ul class="mt-3 grid gap-1 text-xs text-muted sm:grid-cols-2"><?php foreach ($roles as $desc): ?><li>• <?= e($desc) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>

  <section class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="text-base font-semibold">Storage &amp; automatic clean-up</h2>
      <span class="text-sm text-muted"><?= (int) $usage['files'] ?> files · <?= e(human_bytes((int) $usage['bytes'])) ?> of <?= e(human_bytes($quota)) ?></span>
    </div>
    <div class="mt-3 h-2 overflow-hidden rounded-full bg-raised" role="progressbar" aria-label="Storage used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) min(100, round((int) $usage['bytes'] / $quota * 100)) ?>">
      <div class="h-full rounded-full bg-brand" style="width: <?= e((string) max(1, min(100, round((int) $usage['bytes'] / $quota * 100, 1)))) ?>%"></div>
    </div>
    <p class="mt-3 text-sm text-muted">Like hosted schedulers, NextaPost can remove its local copies of old content to save disk space. Posts stay live on Facebook, Instagram and the other networks. Drafts, posts in review, scheduled posts and any file they use are never deleted.</p>
    <form method="post" action="<?= e(url($base . '/retention')) ?>" class="mt-4 grid gap-4 sm:grid-cols-3">
      <?= csrf_field() ?>
      <div>
        <label class="label" for="media_retention_days">Images &amp; videos of published posts</label>
        <select id="media_retention_days" name="media_retention_days" class="input" <?= $canSettings ? '' : 'disabled' ?>>
          <?php foreach (\App\Services\RetentionService::MEDIA_OPTIONS as $d => $label): ?><option value="<?= $d ?>"<?= selected((int) ($ws['media_retention_days'] ?? 0) === $d) ?>><?= e($d ? 'Delete ' . lcfirst($label) : 'Keep forever') ?></option><?php endforeach; ?>
        </select>
        <p class="help">Recommended: 90 days. The post text and links are kept.</p>
      </div>
      <div>
        <label class="label" for="post_retention_days">Published &amp; failed post history</label>
        <select id="post_retention_days" name="post_retention_days" class="input" <?= $canSettings ? '' : 'disabled' ?>>
          <?php foreach (\App\Services\RetentionService::POST_OPTIONS as $d => $label): ?><option value="<?= $d ?>"<?= selected((int) ($ws['post_retention_days'] ?? 0) === $d) ?>><?= e($d ? 'Delete ' . lcfirst($label) : 'Keep forever') ?></option><?php endforeach; ?>
        </select>
        <p class="help">Removes the post and files no other post uses.</p>
      </div>
      <div>
        <label class="label" for="library_retention_days">Unused library uploads</label>
        <select id="library_retention_days" name="library_retention_days" class="input" <?= $canSettings ? '' : 'disabled' ?>>
          <?php foreach (\App\Services\RetentionService::LIBRARY_OPTIONS as $d => $label): ?><option value="<?= $d ?>"<?= selected((int) ($ws['library_retention_days'] ?? 0) === $d) ?>><?= e($d ? 'Delete ' . lcfirst($label) : 'Keep forever') ?></option><?php endforeach; ?>
        </select>
        <p class="help">Files never attached to any post.</p>
      </div>
      <?php if ($canSettings): ?>
        <div class="flex flex-wrap items-center justify-between gap-3 sm:col-span-3">
          <p class="text-sm text-muted">With the saved rules, a clean-up now would remove <strong class="text-ink"><?= (int) $cleanupPreview['media_files'] ?> file(s) (<?= e(human_bytes($cleanupPreview['media_bytes'])) ?>)</strong> and <strong class="text-ink"><?= (int) $cleanupPreview['posts'] ?> post(s)</strong>. Rules run automatically once a day.</p>
          <button class="btn btn-primary">Save clean-up rules</button>
        </div>
      <?php endif; ?>
    </form>
    <?php if ($canSettings && ($cleanupPreview['media_files'] || $cleanupPreview['posts'])): ?>
      <form method="post" action="<?= e(url($base . '/retention/run')) ?>" class="mt-3" x-data="confirmForm(<?= json_attr('Permanently delete ' . $cleanupPreview['media_files'] . ' file(s) and ' . $cleanupPreview['posts'] . ' post(s) now? This can’t be undone.') ?>)" @submit="check">
        <?= csrf_field() ?><button class="btn btn-danger btn-sm"><?= icon('trash', 'size-4') ?> Run clean-up now</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="card p-5">
    <h2 class="text-base font-semibold">Recent activity</h2>
    <?php if (!$activity): ?><p class="mt-2 text-sm text-muted">Nothing yet.</p><?php else: ?>
      <ul class="mt-3 space-y-2 text-sm">
        <?php foreach ($activity as $a): ?>
          <li class="flex gap-3"><span class="w-24 shrink-0 text-xs text-muted"><?= e(time_ago($a['created_at'])) ?></span><span class="min-w-0"><span class="font-medium"><?= e($a['user_name'] ?? 'System') ?></span> <span class="text-muted">—</span> <?= e($a['message']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($canSettings): ?>
  <section class="card border-[color-mix(in_srgb,var(--c-red)_35%,transparent)] p-5">
    <h2 class="text-base font-semibold text-danger">Delete workspace</h2>
    <p class="mt-1 text-sm text-muted">Cancels all scheduled posts and removes this workspace for everyone. Type <strong class="text-ink"><?= e($ws['name']) ?></strong> to confirm.</p>
    <form method="post" action="<?= e(url($base . '/delete')) ?>" class="mt-3 flex flex-col gap-2 sm:flex-row">
      <?= csrf_field() ?>
      <label for="confirm_name" class="sr-only">Workspace name</label>
      <input id="confirm_name" name="confirm_name" class="input sm:max-w-xs" autocomplete="off" required>
      <button class="btn btn-danger">Delete “<?= e(str_excerpt($ws['name'], 30)) ?>”</button>
    </form>
  </section>
  <?php endif; ?>
</div>
