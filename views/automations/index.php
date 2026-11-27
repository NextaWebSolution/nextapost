<?php
$defaults = [
    'name' => '', 'channel' => '', 'post_scope' => 'all', 'media_ids' => [], 'match_mode' => 'keywords', 'keywords' => 'LINK', 'exclude_words' => '',
    'public_replies' => implode("\n", [__('Sent you a DM 📩'), __('Check your DMs 🙌'), __('Just sent it, check your inbox ✨')]),
    'ask_first' => true, 'opening_text' => __('Hey {name}! Thanks for your comment 🙌 Tap below and I’ll send you the link.'), 'opening_button' => __('Send me the link'),
    'require_follow' => false, 'follow_text' => __('Almost there! Follow us first, then tap “I’m following” and the link is yours.'),
    'follow_profile_button' => __('Visit profile'), 'follow_confirm_button' => __('I’m following'),
    'link_text' => __('Here you go, {name}! 👇'), 'link_button' => __('Open link'), 'link_url' => '', 'once_per_user' => true,
];
$cfg['defaults'] = $defaults;
?>
<div class="mx-auto max-w-5xl p-3 sm:p-5" x-data="dmFlows(<?= json_attr($cfg) ?>)">
  <div class="flex flex-wrap items-start gap-3">
    <p class="min-w-0 flex-1 text-sm text-muted"><?= e(__('When someone comments a keyword on your post, NextaPost can reply under the comment, send them a DM, check that they follow you, and then send the link. It uses Meta’s official API, so it works within Instagram’s and Facebook’s rules.')) ?></p>
    <button type="button" class="btn btn-primary" @click="open()" :disabled="!cfg.channels.length"><?= icon('plus', 'size-4') ?> <?= e(__('New flow')) ?></button>
  </div>

  <ol class="mt-4 grid gap-2 text-sm sm:grid-cols-5" aria-label="<?= e(__('How a flow works')) ?>">
    <?php foreach ([__('They comment your keyword'), __('Public reply under the comment'), __('DM with a button'), __('Follow check (Instagram)'), __('DM with your link')] as $i => $step): ?>
      <li class="flex items-center gap-2 rounded-xl border border-line bg-panel px-3 py-2"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand text-xs font-semibold text-white"><?= $i + 1 ?></span><span><?= e($step) ?></span></li>
    <?php endforeach; ?>
  </ol>

  <template x-if="!cfg.channels.length">
    <div class="alert alert-info mt-4"><span><?= e(__('Connect an Instagram account or a Facebook Page first.')) ?> <a class="font-semibold underline" :href="cfg.channelsUrl"><?= e(__('Go to Channels')) ?></a></span></div>
  </template>

  <!-- One-time setup (admins) -->
  <details class="card mt-4 p-5" x-show="cfg.isAdmin && cfg.channels.length" :open="!cfg.channels.some((c) => c.connected)">
    <summary class="cursor-pointer text-base font-semibold"><?= e(__('Setup')) ?> <span class="ml-1 text-xs font-normal text-muted" x-text="cfg.channels.filter((c) => c.connected).length + ' / ' + cfg.channels.length + ' ' + <?= js(__('accounts ready')) ?>"></span></summary>
    <ol class="mt-3 list-decimal space-y-3 pl-5 text-sm">
      <li><?= __html('In your Meta app, open {webhooks} and add this callback URL and verify token. Subscribe to {ig} for Instagram and {fb} for Facebook Pages.', [
            'webhooks' => '<strong>' . e(__('Webhooks')) . '</strong>',
            'ig' => '<code>comments</code>, <code>messages</code>, <code>messaging_postbacks</code>',
            'fb' => '<code>feed</code>, <code>messages</code>, <code>messaging_postbacks</code>',
          ]) ?>
        <div class="mt-2 grid gap-2">
          <div class="flex items-center gap-2 rounded-xl bg-canvas p-2.5" x-data="copyText(cfg.webhookUrl)"><span class="w-28 shrink-0 text-xs text-muted"><?= e(__('Callback URL')) ?></span><code class="min-w-0 flex-1 break-all text-xs" x-text="cfg.webhookUrl"></code><button type="button" class="btn btn-secondary btn-sm" @click="copy()" x-text="copied ? np.t('Copied') : np.t('Copy')"></button></div>
          <div class="flex items-center gap-2 rounded-xl bg-canvas p-2.5" x-data="copyText(cfg.verifyToken)"><span class="w-28 shrink-0 text-xs text-muted"><?= e(__('Verify token')) ?></span><code class="min-w-0 flex-1 break-all text-xs" x-text="cfg.verifyToken"></code><button type="button" class="btn btn-secondary btn-sm" @click="copy()" x-text="copied ? np.t('Copied') : np.t('Copy')"></button></div>
        </div>
      </li>
      <li><?= e(__('Reconnect each account once in Channels, so it grants the messaging permission. Your Meta app needs messaging access (no App Review is needed for accounts you own or manage).')) ?></li>
      <li><?= e(__('Turn on DMs for each account below.')) ?>
        <ul class="mt-2 grid gap-2 sm:grid-cols-2">
          <template x-for="c in cfg.channels" :key="c.id">
            <li class="flex items-center gap-2 rounded-xl border border-line p-2.5">
              <span class="min-w-0 flex-1"><span class="block truncate font-medium" x-text="c.name"></span><span class="block text-xs text-muted" x-text="c.networkName"></span></span>
              <span class="badge st-published" x-show="c.connected"><?= e(__('Ready')) ?></span>
              <button type="button" class="btn btn-secondary btn-sm" x-show="!c.connected" @click="connect(c)" :disabled="busy || !c.active" x-text="c.active ? <?= js(__('Turn on DMs')) ?> : <?= js(__('Reconnect first')) ?>"></button>
            </li>
          </template>
        </ul>
      </li>
    </ol>
  </details>

  <!-- Flows -->
  <ul class="mt-4 space-y-3" x-show="flows.length">
    <template x-for="f in flows" :key="f.id">
      <li class="card p-4">
        <div class="flex flex-wrap items-center gap-2">
          <p class="min-w-0 flex-1"><span class="font-semibold" x-text="f.name"></span> <span class="text-sm text-muted" x-text="'· ' + f.channelName + ' (' + f.networkName + ')'"></span></p>
          <span class="badge" :class="f.status === 'active' ? 'st-published' : 'st-failed'" x-text="f.status === 'active' ? <?= js(__('On')) ?> : <?= js(__('Paused')) ?>"></span>
        </div>
        <p class="mt-1 text-sm text-muted">
          <span x-text="f.match_mode === 'any' ? <?= js(__('Any comment')) ?> : <?= js(__('Keywords:')) ?> + ' ' + f.keywords.join(', ')"></span>
          <span x-text="' · ' + (f.post_scope === 'all' ? <?= js(__('All posts')) ?> : f.media_ids.length + ' ' + <?= js(__('selected posts')) ?>)"></span>
          <span x-show="f.require_follow" x-text="' · ' + <?= js(__('Followers only')) ?>"></span>
        </p>
        <p class="mt-1 text-sm text-danger" x-show="!f.channelActive"><?= e(__('This account needs to be reconnected in Channels.')) ?></p>
        <dl class="mt-3 grid grid-cols-3 gap-2 text-center text-sm sm:grid-cols-6">
          <div class="rounded-xl bg-canvas p-2"><dt class="text-xs text-muted"><?= e(__('Comments')) ?></dt><dd class="font-semibold" x-text="f.stats.comments"></dd></div>
          <div class="rounded-xl bg-canvas p-2"><dt class="text-xs text-muted"><?= e(__('DMs sent')) ?></dt><dd class="font-semibold" x-text="f.stats.dms"></dd></div>
          <div class="rounded-xl bg-canvas p-2"><dt class="text-xs text-muted"><?= e(__('Asked to follow')) ?></dt><dd class="font-semibold" x-text="f.stats.asked"></dd></div>
          <div class="rounded-xl bg-canvas p-2"><dt class="text-xs text-muted"><?= e(__('Links sent')) ?></dt><dd class="font-semibold" x-text="f.stats.links"></dd></div>
          <div class="rounded-xl bg-canvas p-2"><dt class="text-xs text-muted"><?= e(__('Clicks')) ?></dt><dd class="font-semibold" x-text="f.stats.clicks"></dd></div>
          <div class="rounded-xl bg-canvas p-2"><dt class="text-xs text-muted"><?= e(__('Waiting')) ?></dt><dd class="font-semibold" x-text="f.stats.queued"></dd></div>
        </dl>
        <div class="mt-3 flex flex-wrap gap-1.5">
          <button type="button" class="btn btn-sm" @click="open(f)"><?= icon('pencil', 'size-4') ?> <?= e(__('Edit')) ?></button>
          <button type="button" class="btn btn-sm" @click="showActivity(f)"><?= icon('list', 'size-4') ?> <?= e(__('Activity')) ?></button>
          <button type="button" class="btn btn-sm" @click="toggle(f)" x-text="f.status === 'active' ? <?= js(__('Pause')) ?> : <?= js(__('Turn on')) ?>"></button>
          <button type="button" class="btn btn-sm btn-ghost text-danger" @click="remove(f)"><?= icon('trash', 'size-4') ?> <?= e(__('Delete')) ?></button>
        </div>
      </li>
    </template>
  </ul>
  <div class="card mt-4 p-8 text-center" x-show="!flows.length && cfg.channels.length">
    <p class="font-semibold"><?= e(__('No flows yet')) ?></p>
    <p class="mt-1 text-sm text-muted"><?= e(__('For example: “Comment GUIDE and I’ll DM you the free guide”. Create a flow, then add the call to action to your caption.')) ?></p>
  </div>

  <!-- Flow editor -->
  <div x-cloak x-show="form" class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 sm:items-center sm:p-4" @keydown.escape.window="form = null">
    <form class="card flex max-h-[92vh] w-full max-w-4xl flex-col rounded-b-none sm:rounded-b-[.875rem]" role="dialog" aria-modal="true" aria-labelledby="dm-editor-title" @submit.prevent="save()" novalidate>
      <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <h2 id="dm-editor-title" class="font-semibold" x-text="form && form.id ? <?= js(__('Edit flow')) ?> : <?= js(__('New flow')) ?>"></h2>
        <button type="button" class="btn btn-ghost btn-icon" @click="form = null" aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-4') ?></button>
      </div>
      <template x-if="form">
        <div class="grid min-h-0 flex-1 gap-5 overflow-y-auto p-4 md:grid-cols-[1fr_17rem]">
          <div class="space-y-5">
            <section class="grid gap-3 sm:grid-cols-2">
              <div>
                <label class="label" for="dm-name"><?= e(__('Flow name')) ?></label>
                <input id="dm-name" class="input" maxlength="120" x-model="form.name" placeholder="<?= e(__('e.g. Free guide reel')) ?>" :aria-invalid="!!errors.name">
                <p class="help text-danger" x-show="errors.name" x-text="errors.name"></p>
              </div>
              <div>
                <label class="label" for="dm-channel"><?= e(__('Account')) ?></label>
                <select id="dm-channel" class="input" x-model="form.channel" @change="posts = null; if (channel() && !channel().followCheck) form.require_follow = false" :aria-invalid="!!errors.channel">
                  <template x-for="c in cfg.channels" :key="c.id"><option :value="c.id" x-text="c.name + ' (' + c.networkName + ')'" :selected="c.id === form.channel"></option></template>
                </select>
                <p class="help text-danger" x-show="errors.channel" x-text="errors.channel"></p>
              </div>
            </section>

            <fieldset class="space-y-3">
              <legend class="text-sm font-semibold"><?= e(__('1. When someone comments')) ?></legend>
              <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="<?= e(__('Which comments')) ?>">
                <label class="flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm"><input type="radio" class="radio" value="keywords" x-model="form.match_mode"> <?= e(__('Comments with a keyword')) ?></label>
                <label class="flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm"><input type="radio" class="radio" value="any" x-model="form.match_mode"> <?= e(__('Any comment')) ?></label>
              </div>
              <div x-show="form.match_mode === 'keywords'">
                <label class="label" for="dm-keywords"><?= e(__('Keywords')) ?></label>
                <input id="dm-keywords" class="input" x-model="form.keywords" placeholder="LINK, GUIDE, PRICE" :aria-invalid="!!errors.keywords">
                <p class="help"><?= e(__('Separate with commas. Matching ignores capital letters and punctuation, and allows one typo in longer words.')) ?></p>
                <p class="help text-danger" x-show="errors.keywords" x-text="errors.keywords"></p>
              </div>
              <div>
                <label class="label" for="dm-exclude"><?= e(__('Ignore comments containing (optional)')) ?></label>
                <input id="dm-exclude" class="input" x-model="form.exclude_words" placeholder="<?= e(__('e.g. scam, spam')) ?>">
              </div>
              <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="<?= e(__('Which posts')) ?>">
                <label class="flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm"><input type="radio" class="radio" value="all" x-model="form.post_scope"> <?= e(__('All posts')) ?></label>
                <label class="flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm"><input type="radio" class="radio" value="posts" x-model="form.post_scope" @change="loadPosts()"> <?= e(__('Specific posts')) ?></label>
              </div>
              <div x-show="form.post_scope === 'posts'">
                <p class="text-sm text-muted" x-show="posts === null"><?= e(__('Loading recent posts…')) ?></p>
                <p class="text-sm text-muted" x-show="posts && !posts.length"><?= e(__('No recent posts found for this account.')) ?></p>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-4" x-show="posts && posts.length">
                  <template x-for="p in posts || []" :key="p.id">
                    <label class="relative block cursor-pointer overflow-hidden rounded-lg border-2" :class="form.media_ids.includes(p.id) ? 'border-brand' : 'border-line'">
                      <input type="checkbox" class="sr-only" :value="p.id" x-model="form.media_ids">
                      <img x-show="p.thumb && !p.isVideo" :src="p.thumb" alt="" class="aspect-square w-full object-cover" loading="lazy">
                      <span x-show="!p.thumb || p.isVideo" class="flex aspect-square items-center justify-center bg-canvas text-xs text-muted" x-text="p.kind"></span>
                      <span class="block truncate px-1.5 py-1 text-xs" x-text="p.caption || <?= js(__('(no caption)')) ?>"></span>
                    </label>
                  </template>
                </div>
                <p class="help text-danger" x-show="errors.media_ids" x-text="errors.media_ids"></p>
              </div>
            </fieldset>

            <fieldset class="space-y-2">
              <legend class="text-sm font-semibold"><?= e(__('2. Reply under the comment (optional)')) ?></legend>
              <label class="label" for="dm-public"><?= e(__('Public replies, one per line')) ?></label>
              <textarea id="dm-public" class="input" rows="3" x-model="form.public_replies"></textarea>
              <p class="help"><?= e(__('One is picked at random each time, so replies don’t look copied. Leave empty to only send the DM.')) ?></p>
            </fieldset>

            <fieldset class="space-y-3">
              <legend class="text-sm font-semibold"><?= e(__('3. First DM')) ?></legend>
              <label class="flex items-start gap-3"><input type="checkbox" class="checkbox mt-0.5" x-model="form.ask_first" :disabled="form.require_follow"><span class="text-sm"><?= e(__('Ask them to tap a button first (recommended)')) ?><span class="block text-xs text-muted"><?= e(__('The tap opens the conversation, which helps the link arrive in their inbox and is needed for the follow check.')) ?></span></span></label>
              <div x-show="form.ask_first" class="grid gap-3 sm:grid-cols-[1fr_11rem]">
                <div><label class="label" for="dm-opening"><?= e(__('Message')) ?></label><textarea id="dm-opening" class="input" rows="3" :maxlength="cfg.limits.text" x-model="form.opening_text" :aria-invalid="!!errors.opening_text"></textarea><p class="help text-danger" x-show="errors.opening_text" x-text="errors.opening_text"></p></div>
                <div><label class="label" for="dm-opening-btn"><?= e(__('Button')) ?></label><input id="dm-opening-btn" class="input" :maxlength="cfg.limits.button" x-model="form.opening_button"></div>
              </div>
            </fieldset>

            <fieldset class="space-y-3" x-show="channel() && channel().followCheck">
              <legend class="text-sm font-semibold"><?= e(__('4. Follow check')) ?></legend>
              <label class="flex items-start gap-3"><input type="checkbox" class="checkbox mt-0.5" x-model="form.require_follow" @change="if (form.require_follow) form.ask_first = true"><span class="text-sm"><?= e(__('Only send the link to people who follow you')) ?><span class="block text-xs text-muted"><?= e(__('Instagram checks the follow when they tap the button. If Instagram doesn’t answer, the link is sent anyway.')) ?></span></span></label>
              <div x-show="form.require_follow" class="grid gap-3">
                <div><label class="label" for="dm-follow"><?= e(__('Message for people who don’t follow yet')) ?></label><textarea id="dm-follow" class="input" rows="2" :maxlength="cfg.limits.text" x-model="form.follow_text" :aria-invalid="!!errors.follow_text"></textarea><p class="help text-danger" x-show="errors.follow_text" x-text="errors.follow_text"></p></div>
                <div class="grid gap-3 sm:grid-cols-2">
                  <div><label class="label" for="dm-follow-profile"><?= e(__('Profile button')) ?></label><input id="dm-follow-profile" class="input" :maxlength="cfg.limits.button" x-model="form.follow_profile_button"></div>
                  <div><label class="label" for="dm-follow-confirm"><?= e(__('Confirm button')) ?></label><input id="dm-follow-confirm" class="input" :maxlength="cfg.limits.button" x-model="form.follow_confirm_button"></div>
                </div>
              </div>
            </fieldset>

            <fieldset class="space-y-3">
              <legend class="text-sm font-semibold" x-text="channel() && channel().followCheck ? <?= js(__('5. DM with the link')) ?> : <?= js(__('4. DM with the link')) ?>"></legend>
              <div><label class="label" for="dm-link-text"><?= e(__('Message')) ?></label><textarea id="dm-link-text" class="input" rows="2" :maxlength="cfg.limits.text" x-model="form.link_text" :aria-invalid="!!errors.link_text"></textarea><p class="help text-danger" x-show="errors.link_text" x-text="errors.link_text"></p></div>
              <div class="grid gap-3 sm:grid-cols-[1fr_11rem]">
                <div><label class="label" for="dm-link-url"><?= e(__('Link')) ?></label><input id="dm-link-url" class="input" type="text" inputmode="url" maxlength="700" x-model="form.link_url" placeholder="https://" :aria-invalid="!!errors.link_url"><p class="help text-danger" x-show="errors.link_url" x-text="errors.link_url"></p></div>
                <div><label class="label" for="dm-link-btn"><?= e(__('Button')) ?></label><input id="dm-link-btn" class="input" :maxlength="cfg.limits.button" x-model="form.link_button"></div>
              </div>
              <p class="help"><?= e(__('Use {name} for their name. Clicks are counted through a short NextaPost link.')) ?></p>
              <label class="flex items-start gap-3"><input type="checkbox" class="checkbox mt-0.5" x-model="form.once_per_user"><span class="text-sm"><?= e(__('Send once per person (recommended)')) ?></span></label>
            </fieldset>
          </div>

          <aside class="md:sticky md:top-0 md:self-start" aria-label="<?= e(__('Preview')) ?>">
            <p class="label"><?= e(__('Preview')) ?></p>
            <div class="space-y-2 rounded-2xl bg-canvas p-3 text-sm">
              <div class="max-w-[85%] rounded-2xl rounded-bl-sm bg-panel p-2.5 shadow-sm" x-show="form.ask_first"><p class="whitespace-pre-line" x-text="fill(form.opening_text)"></p><span class="mt-2 block rounded-lg border border-line py-1.5 text-center font-medium text-brand-strong" x-text="form.opening_button"></span></div>
              <div class="ml-auto w-fit rounded-2xl rounded-br-sm bg-brand px-3 py-1.5 text-white" x-show="form.ask_first" x-text="form.opening_button"></div>
              <div class="max-w-[85%] rounded-2xl rounded-bl-sm bg-panel p-2.5 shadow-sm" x-show="form.require_follow"><p class="whitespace-pre-line" x-text="fill(form.follow_text)"></p><span class="mt-2 block rounded-lg border border-line py-1.5 text-center font-medium text-brand-strong" x-text="form.follow_profile_button"></span><span class="mt-1 block rounded-lg border border-line py-1.5 text-center font-medium text-brand-strong" x-text="form.follow_confirm_button"></span></div>
              <div class="max-w-[85%] rounded-2xl rounded-bl-sm bg-panel p-2.5 shadow-sm"><p class="whitespace-pre-line" x-text="fill(form.link_text)"></p><span class="mt-2 block rounded-lg border border-line py-1.5 text-center font-medium text-brand-strong" x-show="form.link_url" x-text="form.link_button"></span></div>
            </div>
          </aside>
        </div>
      </template>
      <div class="flex justify-end gap-2 border-t border-line px-4 py-3">
        <button type="button" class="btn" @click="form = null"><?= e(__('Cancel')) ?></button>
        <button class="btn btn-primary" :disabled="busy"><?= e(__('Save flow')) ?></button>
      </div>
    </form>
  </div>

  <!-- Activity -->
  <div x-cloak x-show="activity" class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 sm:items-center sm:p-4" @keydown.escape.window="activity = null">
    <div class="card flex max-h-[90vh] w-full max-w-3xl flex-col rounded-b-none sm:rounded-b-[.875rem]" role="dialog" aria-modal="true" aria-labelledby="dm-activity-title">
      <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <h2 id="dm-activity-title" class="font-semibold"><?= e(__('Activity')) ?> · <span x-text="activity && activity.flow.name"></span></h2>
        <button type="button" class="btn btn-ghost btn-icon" @click="activity = null" aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-4') ?></button>
      </div>
      <div class="min-h-0 flex-1 overflow-y-auto p-4">
        <p class="text-sm text-muted" x-show="activity && !activity.events.length"><?= e(__('No comments yet. New matching comments show up here.')) ?></p>
        <div class="relative overflow-x-auto" x-show="activity && activity.events.length">
          <table class="w-full text-left text-sm">
            <thead class="text-xs text-muted"><tr><th class="py-2 pr-3"><?= e(__('Person')) ?></th><th class="py-2 pr-3"><?= e(__('Comment')) ?></th><th class="py-2 pr-3"><?= e(__('Status')) ?></th><th class="py-2"><?= e(__('When')) ?></th></tr></thead>
            <tbody>
              <template x-for="(ev, i) in activity ? activity.events : []" :key="i">
                <tr class="border-t border-line align-top">
                  <td class="py-2 pr-3 font-medium" x-text="ev.name"></td>
                  <td class="max-w-[16rem] py-2 pr-3"><span class="line-clamp-2" x-text="ev.comment"></span></td>
                  <td class="py-2 pr-3"><span x-text="statusLabel(ev)"></span><span class="block text-xs text-danger" x-show="ev.error && ev.status !== 'done'" x-text="ev.error"></span></td>
                  <td class="whitespace-nowrap py-2 text-xs text-muted" x-text="when(ev.at)"></td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php $head = '<script data-cfasync="false" src="' . asset('js/automations.js') . '"></script>'; ?>
