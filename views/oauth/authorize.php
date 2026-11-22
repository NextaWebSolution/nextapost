<?php if (!empty($error)): ?>
  <h1 class="text-xl font-semibold"><?= e(__('Can’t connect this app')) ?></h1>
  <p class="mt-2 text-sm text-muted"><?= e($error) ?></p>
  <a class="btn mt-6 w-full" href="<?= e(url('/')) ?>"><?= e(__('Go to NextaPost')) ?></a>
<?php else: ?>
  <div class="flex items-center gap-3">
    <?php if (!empty($client['logo_uri']) && safe_url((string) $client['logo_uri'])): ?>
      <img src="<?= e(safe_url((string) $client['logo_uri'])) ?>" alt="" class="size-10 rounded-lg bg-canvas object-contain" referrerpolicy="no-referrer">
    <?php else: ?>
      <span class="grid size-10 place-items-center rounded-lg bg-canvas"><?= icon('sparkles', 'size-5') ?></span>
    <?php endif; ?>
    <div class="min-w-0">
      <h1 class="text-xl font-semibold"><?= e(__('Connect {app}?', ['app' => $client['name']])) ?></h1>
      <p class="text-xs text-muted"><?= e($verified ? __('Identified by {host}', ['host' => (string) parse_url((string) $client['client_id'], PHP_URL_HOST)]) : __('Self-registered app. Only continue if you started this from an app you trust.')) ?></p>
    </div>
  </div>
  <p class="mt-4 text-sm"><?= e(__('It will be able to do what you can do in the workspace you choose: read and write posts, media, labels and notes. Approvals still apply.')) ?></p>
  <ul class="mt-3 space-y-1 text-sm text-muted">
    <li><?= e(__('Signed in as {name} ({email})', ['name' => $user['name'], 'email' => $user['email']])) ?></li>
    <li><?= e(__('You’ll be sent back to {host}', ['host' => $redirectHost])) ?></li>
  </ul>
  <form method="post" action="<?= e(url('/oauth/authorize')) ?>" class="mt-6 space-y-4" x-data="{busy:false}" @submit="busy=true">
    <?= csrf_field() ?>
    <input type="hidden" name="decision" value="" x-ref="decision">
    <?php foreach ($params as $k => $v): ?>
      <input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>">
    <?php endforeach; ?>
    <div>
      <label class="label" for="workspace"><?= e(__('Workspace')) ?></label>
      <select id="workspace" name="workspace" class="input" required>
        <?php foreach ($workspaces as $w): ?>
          <option value="<?= e($w['uuid']) ?>"<?= $w['uuid'] === $current ? ' selected' : '' ?>><?= e($w['name']) ?> · <?= e(\App\Core\Gate::roleName($w['role'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex gap-2">
      <button class="btn flex-1" @click="$refs.decision.value = 'deny'" :disabled="busy"><?= e(__('Cancel')) ?></button>
      <button class="btn btn-primary flex-1" @click="$refs.decision.value = 'allow'" :disabled="busy"><?= e(__('Allow')) ?></button>
    </div>
  </form>
  <p class="mt-4 text-xs text-subtle"><?= e(__('You can disconnect it any time under Developers & AI.')) ?></p>
<?php endif; ?>
