<div class="mx-auto max-w-5xl p-3 sm:p-5" x-data="developersPage(<?= json_attr($cfg) ?>)">
  <div class="flex flex-wrap gap-1 rounded-lg bg-canvas p-0.5" role="tablist" aria-label="<?= e(__('Developers & AI')) ?>">
    <button type="button" class="tab" role="tab" :aria-selected="tab==='agents'" @click="tab='agents'"><?= icon('sparkles', 'size-4') ?> <?= e(__('AI agents')) ?></button>
    <button type="button" class="tab" role="tab" :aria-selected="tab==='keys'" @click="tab='keys'"><?= icon('key', 'size-4') ?> <?= e(__('API keys')) ?></button>
    <button type="button" class="tab" role="tab" :aria-selected="tab==='webhooks'" @click="tab='webhooks'" x-show="cfg.canWebhooks"><?= icon('link', 'size-4') ?> <?= e(__('Webhooks')) ?></button>
  </div>

  <!-- AI agents -->
  <section x-show="tab==='agents'" class="mt-4 space-y-4" aria-label="<?= e(__('AI agents')) ?>">
    <p class="text-sm text-muted"><?= e(__('Let an AI assistant or automation tool draft, schedule and manage posts for you. It gets the same access as you (or less), and approvals still apply.')) ?></p>

    <div class="card p-5">
      <h2 class="font-semibold"><?= e(__('MCP server address')) ?></h2>
      <p class="mt-1 text-sm text-muted"><?= e(__('Add this as a custom connector (MCP server) in your AI app.')) ?></p>
      <div class="mt-3 flex gap-2" x-data="copyText(cfg.mcpUrl)">
        <input class="input font-mono text-sm" :value="cfg.mcpUrl" readonly aria-label="<?= e(__('MCP server address')) ?>" @focus="$el.select()">
        <button type="button" class="btn" @click="copy()"><?= icon('copy', 'size-4') ?> <span x-text="copied ? <?= js(__('Copied')) ?> : <?= js(__('Copy')) ?>"></span></button>
      </div>
      <div class="mt-4 grid gap-4 md:grid-cols-2">
        <div class="min-w-0 rounded-lg border border-[var(--np-line)] p-4">
          <h3 class="text-sm font-semibold"><?= e(__('Apps with sign-in')) ?></h3>
          <ol class="mt-2 list-decimal space-y-1 ps-5 text-sm text-muted">
            <li><?= e(__('Add a custom connector and paste the address above.')) ?></li>
            <li><?= e(__('Sign in to NextaPost when the app asks, and choose this workspace.')) ?></li>
            <li><?= e(__('Ask it things like “Schedule three posts for next week about our new service”.')) ?></li>
          </ol>
        </div>
        <div class="min-w-0 rounded-lg border border-[var(--np-line)] p-4">
          <h3 class="text-sm font-semibold"><?= e(__('Apps and editors that use a key')) ?></h3>
          <p class="mt-1 text-sm text-muted"><?= e(__('Create an API key, then add this to the app’s MCP settings:')) ?></p>
          <div class="relative mt-2 min-w-0" x-data="copyText(mcpConfig())">
            <pre class="max-w-full overflow-x-auto rounded-md bg-canvas p-3 pe-10 text-xs" x-text="mcpConfig()"></pre>
            <button type="button" class="btn btn-ghost btn-icon absolute end-1 top-1" @click="copy()" aria-label="<?= e(__('Copy')) ?>"><?= icon('copy', 'size-4') ?></button>
          </div>
        </div>
      </div>
    </div>

    <div class="card p-5">
      <h2 class="font-semibold"><?= e(__('Connected apps')) ?></h2>
      <p class="mt-1 text-sm text-muted"><?= e(__('Apps you signed in to with your account for this workspace.')) ?></p>
      <ul class="mt-3 divide-y divide-[var(--np-line)]" x-show="apps.length">
        <template x-for="a in apps" :key="a.id">
          <li class="flex items-center gap-3 py-3">
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium" x-text="a.name"></span>
              <span class="block text-xs text-muted" x-text="a.last_used_at ? <?= js(__('Last used')) ?> + ' ' + when(a.last_used_at) : <?= js(__('Not used yet')) ?>"></span>
            </span>
            <button type="button" class="btn btn-ghost text-danger" @click="disconnect(a)"><?= e(__('Disconnect')) ?></button>
          </li>
        </template>
      </ul>
      <p class="mt-3 text-sm text-subtle" x-show="!apps.length"><?= e(__('No apps connected yet.')) ?></p>
    </div>

    <div class="card p-5">
      <h2 class="font-semibold"><?= e(__('REST API')) ?></h2>
      <p class="mt-1 text-sm text-muted"><?= e(__('For your own scripts and tools such as Zapier, Make or n8n. Send an API key as a Bearer token.')) ?></p>
      <div class="relative mt-3 min-w-0" x-data="copyText(curlExample())">
        <pre class="max-w-full overflow-x-auto rounded-md bg-canvas p-3 pe-10 text-xs" x-text="curlExample()"></pre>
        <button type="button" class="btn btn-ghost btn-icon absolute end-1 top-1" @click="copy()" aria-label="<?= e(__('Copy')) ?>"><?= icon('copy', 'size-4') ?></button>
      </div>
      <p class="mt-2 text-sm"><a class="font-semibold text-brand-strong hover:underline dark:text-violet-300" :href="cfg.apiUrl" target="_blank" rel="noopener"><?= e(__('See every endpoint')) ?></a></p>
    </div>
  </section>

  <!-- API keys -->
  <section x-show="tab==='keys'" x-cloak class="mt-4 space-y-4" aria-label="<?= e(__('API keys')) ?>">
    <p class="text-sm text-muted"><?= e(__('A key acts as you in this workspace. It can never do more than your own role allows.')) ?></p>

    <div class="alert alert-success" x-show="newToken" role="status">
      <div class="min-w-0 flex-1">
        <p class="font-semibold"><?= e(__('Copy your new key now. You won’t see it again.')) ?></p>
        <div class="mt-2 flex gap-2" x-data="{ copied: false }">
          <input class="input font-mono text-sm" :value="newToken" readonly aria-label="<?= e(__('New API key')) ?>" @focus="$el.select()">
          <button type="button" class="btn" @click="navigator.clipboard.writeText(newToken).catch(() => {}); copied = true; setTimeout(() => copied = false, 1600)"><?= icon('copy', 'size-4') ?> <span x-text="copied ? <?= js(__('Copied')) ?> : <?= js(__('Copy')) ?>"></span></button>
        </div>
      </div>
      <button type="button" class="btn btn-ghost btn-icon" @click="newToken = ''" aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-4') ?></button>
    </div>

    <form class="card flex flex-wrap items-end gap-3 p-4" @submit.prevent="createKey()">
      <div class="min-w-[12rem] flex-1">
        <label class="label" for="key-name"><?= e(__('Name')) ?></label>
        <input id="key-name" class="input" maxlength="100" x-model="keyForm.name" placeholder="<?= e(__('e.g. Zapier')) ?>" required :aria-invalid="!!errors.name">
        <p class="help text-danger" x-show="errors.name" x-text="errors.name"></p>
      </div>
      <div>
        <label class="label" for="key-role"><?= e(__('Access')) ?></label>
        <select id="key-role" class="input" x-model="keyForm.role">
          <template x-for="r in cfg.roles" :key="r.id"><option :value="r.id" x-text="r.label"></option></template>
        </select>
        <p class="help text-danger" x-show="errors.role" x-text="errors.role"></p>
      </div>
      <div>
        <label class="label" for="key-exp"><?= e(__('Expires')) ?></label>
        <select id="key-exp" class="input" x-model="keyForm.expires_days">
          <option value="0"><?= e(__('Never')) ?></option>
          <option value="30"><?= e(__('In 30 days')) ?></option>
          <option value="90"><?= e(__('In 90 days')) ?></option>
          <option value="365"><?= e(__('In a year')) ?></option>
        </select>
      </div>
      <button class="btn btn-primary" :disabled="busy"><?= icon('plus', 'size-4') ?> <?= e(__('Create key')) ?></button>
    </form>

    <div class="card relative overflow-x-auto" x-show="keys.length">
      <table class="table w-full text-sm">
        <thead><tr>
          <th scope="col"><?= e(__('Name')) ?></th><th scope="col"><?= e(__('Key')) ?></th><th scope="col"><?= e(__('Access')) ?></th>
          <th scope="col"><?= e(__('Last used')) ?></th><th scope="col"><?= e(__('Expires')) ?></th><th scope="col"><span class="sr-only"><?= e(__('Actions')) ?></span></th>
        </tr></thead>
        <tbody>
          <template x-for="k in keys" :key="k.id">
            <tr>
              <td><span class="font-medium" x-text="k.name"></span><span class="block text-xs text-muted" x-show="!k.mine" x-text="k.owner"></span></td>
              <td class="font-mono text-xs" x-text="k.prefix + '…'"></td>
              <td x-text="roleLabel(k.role)"></td>
              <td x-text="k.last_used_at ? when(k.last_used_at) : <?= js(__('Never')) ?>"></td>
              <td x-text="k.expires_at ? when(k.expires_at) : <?= js(__('Never')) ?>"></td>
              <td class="text-end"><button type="button" class="btn btn-ghost text-danger" @click="revoke(k)" :aria-label="<?= js(__('Revoke')) ?> + ' ' + k.name"><?= e(__('Revoke')) ?></button></td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
    <div class="card p-8 text-center" x-show="!keys.length">
      <p class="font-semibold"><?= e(__('No API keys yet')) ?></p>
      <p class="mt-1 text-sm text-muted"><?= e(__('Create one to connect an automation tool or an AI app that doesn’t support sign-in.')) ?></p>
    </div>
  </section>

  <!-- Webhooks -->
  <section x-show="tab==='webhooks' && cfg.canWebhooks" x-cloak class="mt-4 space-y-4" aria-label="<?= e(__('Webhooks')) ?>">
    <p class="text-sm text-muted"><?= e(__('Get a signed HTTPS request whenever something happens to a post, for example to post in Slack when something publishes or fails.')) ?></p>

    <div class="alert alert-success" x-show="newSecret" role="status">
      <div class="min-w-0 flex-1">
        <p class="font-semibold"><?= e(__('Copy the signing secret now. You won’t see it again.')) ?></p>
        <div class="mt-2 flex gap-2" x-data="{ copied: false }">
          <input class="input font-mono text-sm" :value="newSecret" readonly aria-label="<?= e(__('Signing secret')) ?>" @focus="$el.select()">
          <button type="button" class="btn" @click="navigator.clipboard.writeText(newSecret).catch(() => {}); copied = true; setTimeout(() => copied = false, 1600)"><?= icon('copy', 'size-4') ?> <span x-text="copied ? <?= js(__('Copied')) ?> : <?= js(__('Copy')) ?>"></span></button>
        </div>
      </div>
      <button type="button" class="btn btn-ghost btn-icon" @click="newSecret = ''" aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-4') ?></button>
    </div>

    <form class="card grid gap-3 p-4" @submit.prevent="createHook()">
      <div>
        <label class="label" for="hook-url"><?= e(__('Endpoint URL')) ?></label>
        <input id="hook-url" type="url" class="input" maxlength="700" x-model="hookForm.url" placeholder="https://" required :aria-invalid="!!errors.url">
        <p class="help text-danger" x-show="errors.url" x-text="errors.url"></p>
      </div>
      <fieldset>
        <legend class="label"><?= e(__('Events')) ?></legend>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" x-model="hookForm.all"> <?= e(__('All events, including ones added later')) ?></label>
        <div class="mt-2 grid gap-1.5 sm:grid-cols-2" x-show="!hookForm.all">
          <template x-for="ev in cfg.events" :key="ev.id">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" :value="ev.id" x-model="hookForm.events"> <span x-text="ev.label"></span></label>
          </template>
        </div>
        <p class="help text-danger" x-show="errors.events" x-text="errors.events"></p>
      </fieldset>
      <div><button class="btn btn-primary" :disabled="busy"><?= icon('plus', 'size-4') ?> <?= e(__('Add webhook')) ?></button></div>
    </form>

    <template x-for="h in hooks" :key="h.id">
      <article class="card p-4">
        <div class="flex flex-wrap items-center gap-2">
          <span class="min-w-0 flex-1 truncate font-mono text-sm" x-text="h.url"></span>
          <span class="badge" :class="h.status === 'enabled' ? 'st-published' : (h.status === 'paused' ? 'st-failed' : 'st-draft')" x-text="h.status === 'paused' ? <?= js(__('Paused after failures')) ?> : (h.status === 'enabled' ? <?= js(__('Enabled')) ?> : <?= js(__('Disabled')) ?>)"></span>
        </div>
        <p class="mt-1 text-xs text-muted" x-text="(h.events.includes('*') ? <?= js(__('All events')) ?> : h.events.join(', ')) + (h.last_sent_at ? ' · ' + <?= js(__('Last sent')) ?> + ' ' + when(h.last_sent_at) : '')"></p>
        <div class="mt-3 flex flex-wrap gap-1.5">
          <button type="button" class="btn btn-sm" @click="testHook(h)" :disabled="busy"><?= icon('send', 'size-4') ?> <?= e(__('Send test')) ?></button>
          <button type="button" class="btn btn-sm" @click="toggleLog(h)" :aria-expanded="openLog === h.id"><?= icon('list', 'size-4') ?> <?= e(__('Deliveries')) ?></button>
          <button type="button" class="btn btn-sm" @click="setStatus(h, h.status === 'enabled' ? 'disabled' : 'enabled')" x-text="h.status === 'enabled' ? <?= js(__('Disable')) ?> : <?= js(__('Enable')) ?>"></button>
          <button type="button" class="btn btn-sm" @click="rotate(h)"><?= icon('refresh', 'size-4') ?> <?= e(__('New secret')) ?></button>
          <button type="button" class="btn btn-sm btn-ghost text-danger" @click="deleteHook(h)"><?= icon('trash', 'size-4') ?> <?= e(__('Delete')) ?></button>
        </div>
        <div class="relative mt-3 overflow-x-auto" x-show="openLog === h.id" x-cloak>
          <p class="text-sm text-subtle" x-show="!log.length"><?= e(__('No deliveries yet.')) ?></p>
          <table class="table w-full text-sm" x-show="log.length">
            <thead><tr><th scope="col"><?= e(__('Event')) ?></th><th scope="col"><?= e(__('Result')) ?></th><th scope="col"><?= e(__('Attempts')) ?></th><th scope="col"><?= e(__('Time')) ?></th><th scope="col"><span class="sr-only"><?= e(__('Actions')) ?></span></th></tr></thead>
            <tbody>
              <template x-for="d in log" :key="d.id">
                <tr>
                  <td class="font-mono text-xs" x-text="d.event"></td>
                  <td><span class="badge" :class="d.status === 'delivered' ? 'st-published' : (d.status === 'failed' ? 'st-failed' : 'st-scheduled')" x-text="d.response_code ? d.status + ' · ' + d.response_code : d.status"></span></td>
                  <td x-text="d.attempts"></td>
                  <td x-text="when(d.created_at)"></td>
                  <td class="text-end"><button type="button" class="btn btn-ghost btn-sm" @click="replay(h, d)"><?= e(__('Resend')) ?></button></td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </article>
    </template>
    <div class="card p-8 text-center" x-show="!hooks.length">
      <p class="font-semibold"><?= e(__('No webhooks yet')) ?></p>
      <p class="mt-1 text-sm text-muted"><?= e(__('Add an endpoint above to start receiving events.')) ?></p>
    </div>

    <details class="card p-4 text-sm">
      <summary class="cursor-pointer font-semibold"><?= e(__('How to check a webhook is genuine')) ?></summary>
      <p class="mt-2 text-muted"><?= e(__('Each request has a header X-NextaPost-Signature: t=<timestamp>,v1=<signature>. Compute HMAC-SHA256 of “<timestamp>.<raw body>” with your signing secret and compare it with v1. Reject requests older than 5 minutes.')) ?></p>
      <p class="mt-2 text-muted"><?= e(__('Failed deliveries are retried after 1 minute, 5 minutes and 30 minutes. After 20 failures in a row the webhook is paused.')) ?></p>
    </details>
  </section>
</div>
<?php $head = '<script src="' . asset('js/developers.js') . '"></script>'; ?>
