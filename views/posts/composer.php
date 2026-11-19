<?php
$head = '<script src="' . asset('js/composer.js') . '"></script>';
$p = $cfg['post'];
/** Media strip for the shared media (target "null") or one channel's own media (target "tab"). */
$strip = static function (string $t): string {
    ob_start(); ?>
<div class="flex flex-wrap gap-2">
  <template x-for="(m, i) in listOf(<?= $t ?>)" :key="m.id">
    <div class="group relative size-20 overflow-hidden rounded-xl border border-line bg-raised sm:size-24" :title="m.type==='document' ? m.name + (m.pages ? ' · ' + m.pages + ' pages' : '') : (m.name || '')">
      <img x-show="m.type!=='video'" :src="m.thumb" :alt="m.alt || ''" class="size-full object-cover">
      <video x-show="m.type==='video'" :src="m.url" class="size-full object-cover" muted preload="metadata"></video>
      <span x-show="m.type==='video'" class="absolute left-1 top-1 badge st-publishing">Video</span>
      <span x-show="m.type==='document'" class="absolute inset-x-1 top-1 truncate rounded bg-black/60 px-1 text-[10px] text-white" x-text="(m.pages ? m.pages + ' p · ' : '') + m.name"></span>
      <div x-show="editable" class="absolute inset-x-0 bottom-0 flex justify-between bg-black/55 p-1 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
        <button type="button" class="rounded p-1 text-white hover:bg-white/20" @click="moveIn(<?= $t ?>, i, -1)" aria-label="Move left"><?= icon('chevron-left', 'size-3.5') ?></button>
        <button type="button" class="rounded p-1 text-white hover:bg-white/20" @click="removeAt(<?= $t ?>, i)" aria-label="Remove"><?= icon('trash', 'size-3.5') ?></button>
        <button type="button" class="rounded p-1 text-white hover:bg-white/20" @click="moveIn(<?= $t ?>, i, 1)" aria-label="Move right"><?= icon('chevron-right', 'size-3.5') ?></button>
      </div>
    </div>
  </template>
  <template x-for="n in (uploadingFor[<?= $t ?> ?? '_'] || 0)" :key="'u'+n"><div class="skeleton size-20 sm:size-24"></div></template>
  <template x-if="editable">
    <div class="flex gap-2">
      <label class="flex size-20 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-line text-xs font-medium text-muted hover:border-[var(--np-brand)] hover:text-ink sm:size-24"
             @dragover.prevent @drop.prevent="upload($event.dataTransfer.files, <?= $t ?>)">
        <?= icon('upload', 'size-5') ?> Upload
        <input type="file" class="sr-only" multiple accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,application/pdf,.pdf,.pptx,.docx,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.openxmlformats-officedocument.wordprocessingml.document" @change="upload($event.target.files, <?= $t ?>); $event.target.value=''">
      </label>
      <button type="button" class="flex size-20 flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-line text-xs font-medium text-muted hover:border-[var(--np-brand)] hover:text-ink sm:size-24" @click="openLibrary(<?= $t ?>)">
        <?= icon('image', 'size-5') ?> Library
      </button>
    </div>
  </template>
</div>
<?php return (string) ob_get_clean();
};
?>
<div class="mx-auto max-w-6xl p-3 sm:p-5" x-data="composer(<?= json_attr($cfg) ?>)" @keydown.ctrl.enter.window="primary()" @keydown.meta.enter.window="primary()">
  <div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="<?= e($cfg['backUrl']) ?>" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 'size-4') ?> Calendar</a>
    <span class="badge st-<?= e($p['status'] === 'new' ? 'draft' : $p['status']) ?>"><?= e($p['statusLabel']) ?></span>
    <?php if ($post): ?><span class="text-xs text-muted">Last change <?= e(time_ago($post['updated_at'] ?? $post['created_at'])) ?></span><?php endif; ?>
    <div class="ml-auto flex rounded-lg bg-canvas p-0.5 lg:hidden" role="tablist">
      <button class="tab" role="tab" :aria-selected="pane==='write'" @click="pane='write'">Write</button>
      <button class="tab" role="tab" :aria-selected="pane==='preview'" @click="pane='preview'">Preview</button>
    </div>
  </div>

  <?php if (!empty($p['review_note']) && in_array($p['status'], ['draft'], true)): ?>
    <div class="alert alert-warn mb-4" role="note"><?= icon('alert', 'size-5 shrink-0') ?><div><p class="font-semibold">Changes requested</p><p class="mt-0.5 text-ink"><?= e($p['review_note']) ?></p></div></div>
  <?php endif; ?>
  <?php if (!empty($post['media_removed_at'])): ?>
    <div class="alert alert-info mb-4" role="note"><?= icon('image', 'size-5 shrink-0') ?><span>The images/videos of this post were removed by the workspace’s storage clean-up on <?= e(to_local($post['media_removed_at'], $ws['timezone'], 'd M Y')) ?>. The post is still live on the social networks.</span></div>
  <?php endif; ?>
  <div class="alert alert-error mb-4" role="alert" x-cloak x-show="errors.general"><?= icon('alert', 'size-5 shrink-0') ?><div><template x-for="m in (errors.general || [])"><p x-text="m"></p></template></div></div>

  <?php if ($p['targets'] && !in_array($p['status'], ['draft', 'in_review', 'scheduled'], true)): ?>
    <div class="card mb-4 divide-y divide-[var(--np-line)]">
      <?php foreach ($p['targets'] as $t): $ch = array_values(array_filter($cfg['channels'], static fn ($c) => $c['id'] === $t['channel']))[0] ?? null; ?>
        <div class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
          <span class="font-medium"><?= e($ch['name'] ?? 'Removed channel') ?></span>
          <span class="badge st-<?= e($t['status']) ?>"><?= e($t['statusLabel']) ?></span>
          <?php if ($t['url']): ?><a class="inline-flex items-center gap-1 text-brand-strong hover:underline dark:text-violet-300" href="<?= e($t['url']) ?>" target="_blank" rel="noopener">View post <?= icon('external', 'size-3.5') ?></a><?php endif; ?>
          <?php if ($t['error']): ?><p class="w-full text-danger"><?= e($t['error']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
    <!-- Editor -->
    <section class="card p-4 sm:p-5" :class="pane==='write' ? '' : 'hidden lg:block'" aria-label="Post editor">
      <template x-if="!cfg.channels.length">
        <div class="rounded-xl border border-dashed border-line p-6 text-center">
          <p class="font-semibold">Connect a channel first</p>
          <p class="mt-1 text-sm text-muted">Posts are published to the channels you connect.</p>
          <a :href="cfg.channelsUrl" class="btn btn-primary mt-4"><?= icon('plug', 'size-4') ?> Connect a channel</a>
        </div>
      </template>

      <div x-show="cfg.channels.length">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold">Post to</h2>
          <button type="button" class="text-xs font-semibold text-brand-strong hover:underline dark:text-violet-300" x-show="editable" @click="toggleAll()" x-text="selected.length === activeChannels.length ? 'Clear' : 'Select all'"></button>
        </div>
        <div class="mt-2 flex flex-wrap gap-2" role="group" aria-label="Channels">
          <template x-for="c in cfg.channels" :key="c.id">
            <button type="button" class="relative rounded-full p-0.5 transition" :disabled="!editable || !c.active"
                    :class="isSel(c.id) ? 'ring-2 ring-[var(--np-brand)]' : 'opacity-45 grayscale hover:opacity-80 hover:grayscale-0'"
                    @click="toggle(c.id)" :aria-pressed="isSel(c.id)" :aria-label="c.name + ' (' + c.networkName + ')'" :title="c.name + ' · ' + c.networkName + (c.active ? '' : ' (reconnect needed)')">
              <span x-html="np.avatar(c, 36)"></span>
              <span x-show="errors[c.id]" class="absolute -left-0.5 -top-0.5 size-3 rounded-full bg-danger ring-2 ring-[var(--np-panel)]"></span>
            </button>
          </template>
        </div>
        <p class="mt-2 text-sm text-danger" x-show="errors.channels" x-text="(errors.channels || []).join(' ')"></p>

        <!-- Text tabs -->
        <div class="mt-5 flex gap-1 overflow-x-auto pb-1 scroll-thin" role="tablist" aria-label="Customise per channel">
          <button type="button" class="tab" role="tab" :aria-selected="tab==='global'" @click="tab='global'">All channels</button>
          <template x-for="id in selected" :key="id">
            <button type="button" class="tab" role="tab" :aria-selected="tab===id" @click="tab=id">
              <span x-html="np.avatar(ch(id), 16)"></span><span class="max-w-[8rem] truncate" x-text="ch(id).name"></span>
              <span x-show="custom[id]" class="size-1.5 rounded-full bg-brand" title="Customised"></span>
              <span x-show="over(id) || errors[id]" class="size-1.5 rounded-full bg-danger" title="Needs attention"></span>
            </button>
          </template>
        </div>

        <div class="mt-2">
          <template x-if="tab==='global'">
            <div>
              <label for="body" class="sr-only">Post text</label>
              <textarea id="body" x-model="body" x-ref="body" class="input min-h-[180px]" rows="8" :readonly="!editable"
                        placeholder="What do you want to share? Write once — customise per channel with the tabs above."></textarea>
            </div>
          </template>
          <template x-if="tab!=='global'">
            <div>
              <label class="mb-2 flex items-center gap-2 text-sm">
                <input type="checkbox" class="checkbox" :checked="custom[tab]" @change="customize(tab, $event.target.checked)" :disabled="!editable">
                <span>Customise text for <strong x-text="ch(tab).name"></strong></span>
              </label>
              <textarea x-show="custom[tab]" x-model="overrides[tab]" class="input min-h-[180px]" rows="8" :readonly="!editable" :aria-label="'Text for ' + ch(tab).name"></textarea>
              <div x-show="!custom[tab]" class="rounded-xl border border-dashed border-line p-3 text-sm text-muted">Uses the text from “All channels”.</div>

              <label class="mb-2 mt-4 flex items-center gap-2 text-sm">
                <input type="checkbox" class="checkbox" :checked="customMedia[tab]" @change="customizeMedia(tab, $event.target.checked)" :disabled="!editable">
                <span>Use different media for <strong x-text="ch(tab).name"></strong></span>
              </label>
              <p class="help -mt-1 mb-2" x-text="'Supports: ' + typesLabel(tab)"></p>
              <div x-show="customMedia[tab]"><?= $strip('tab') ?></div>
              <div x-show="!customMedia[tab]" class="rounded-xl border border-dashed border-line p-3 text-sm text-muted">Uses the media from “All channels”.</div>
              <ul class="mt-2 space-y-1 text-sm text-danger" x-show="errors[tab]"><template x-for="m in (errors[tab] || [])"><li x-text="m"></li></template></ul>
            </div>
          </template>
        </div>

        <!-- Counters -->
        <div class="mt-2 flex flex-wrap gap-1.5" aria-live="polite">
          <template x-for="id in selected" :key="id">
            <span class="badge" :class="over(id) ? 'st-failed' : ''" :title="ch(id).name">
              <span x-text="ch(id).networkName"></span> <span x-text="countFor(id) + '/' + limitFor(id)"></span>
            </span>
          </template>
        </div>

        <!-- Shared media -->
        <div class="mt-5">
          <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold">Media</h2>
            <span class="text-xs text-muted" x-text="media.length ? media.length + ' attached' : 'Images, video or a document (PDF, PPTX, DOCX)'"></span>
          </div>
          <div class="mt-2"><?= $strip('null') ?></div>
          <div class="alert alert-warn mt-3" x-show="docConflicts().length" role="note">
            <?= icon('alert', 'size-5 shrink-0') ?>
            <div>
              <p><span x-text="docConflicts().map(id => ch(id).name).join(', ')"></span> can’t post documents. Only LinkedIn, Telegram and Discord can.</p>
              <p class="mt-1 text-ink">Give those channels their own media (for example the slides exported as images):
                <template x-for="id in docConflicts()" :key="id"><button type="button" class="ml-1 font-semibold underline" @click="tab=id; customizeMedia(id, true)" x-text="ch(id).name"></button></template>
              </p>
            </div>
          </div>
        </div>

        <!-- Schedule -->
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="publish_at">Publish date &amp; time</label>
            <input id="publish_at" type="datetime-local" class="input" x-model="publishAt" :readonly="!editable" :aria-invalid="!!errors.publish_at">
            <p class="help" x-show="!errors.publish_at">Time zone: <span x-text="cfg.tz"></span></p>
            <p class="help text-danger" x-show="errors.publish_at" x-text="(errors.publish_at || []).join(' ')"></p>
          </div>
          <div class="hidden sm:block"></div>
        </div>
      </div>

      <!-- Actions -->
      <div class="sticky bottom-0 -mx-4 -mb-4 mt-6 flex flex-wrap items-center gap-2 border-t border-line bg-panel/95 px-4 py-3 backdrop-blur sm:-mx-5 sm:-mb-5 sm:px-5" x-show="cfg.channels.length">
        <?php if ($post && $cfg['editable']): ?>
          <form method="post" action="<?= e(url('/w/' . $ws['uuid'] . '/posts/' . $post['uuid'] . '/delete')) ?>" x-data="confirmForm('Delete this post? This can’t be undone.')" @submit="check">
            <?= csrf_field() ?><button class="btn btn-danger btn-sm"><?= icon('trash', 'size-4') ?><span class="hidden sm:inline">Delete</span></button>
          </form>
        <?php endif; ?>
        <?php if ($post): ?>
          <form method="post" action="<?= e(url('/w/' . $ws['uuid'] . '/posts/' . $post['uuid'] . '/duplicate')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" title="Duplicate"><?= icon('copy', 'size-4') ?><span class="hidden sm:inline">Duplicate</span></button></form>
        <?php endif; ?>
        <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
          <?php if ($cfg['canApprove']): ?>
            <form method="post" action="<?= e(url('/w/' . $ws['uuid'] . '/posts/' . $post['uuid'] . '/reject')) ?>" x-data="{open:false}" class="relative">
              <?= csrf_field() ?>
              <button type="button" class="btn btn-secondary btn-sm" @click="open=!open">Request changes</button>
              <div x-cloak x-show="open" @click.outside="open=false" class="menu bottom-full right-0 mb-2 w-72 p-3">
                <label class="label" for="note">What should change?</label>
                <textarea id="note" name="note" class="input" rows="3" required></textarea>
                <button class="btn btn-primary btn-sm mt-2 w-full">Send back to author</button>
              </div>
            </form>
            <form method="post" action="<?= e(url('/w/' . $ws['uuid'] . '/posts/' . $post['uuid'] . '/approve')) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-sm"><?= icon('check', 'size-4') ?> Approve &amp; schedule</button></form>
          <?php endif; ?>
          <template x-if="editable">
            <div class="flex flex-wrap items-center justify-end gap-2">
              <button type="button" class="btn btn-secondary btn-sm sm:btn" :disabled="saving" @click="save('draft')">Save draft</button>
              <template x-if="cfg.canPublish">
                <div class="flex gap-2">
                  <button type="button" class="btn btn-secondary btn-sm sm:btn" :disabled="saving" @click="save('now')"><?= icon('send', 'size-4') ?> Post now</button>
                  <button type="button" class="btn btn-primary btn-sm sm:btn" :disabled="saving" @click="save('schedule')"><?= icon('calendar', 'size-4') ?> <span x-text="saving ? 'Saving…' : 'Add to calendar'"></span></button>
                </div>
              </template>
              <template x-if="!cfg.canPublish">
                <button type="button" class="btn btn-primary btn-sm sm:btn" :disabled="saving" @click="save('review')"><span x-text="saving ? 'Sending…' : 'Submit for review'"></span></button>
              </template>
            </div>
          </template>
        </div>
      </div>
    </section>

    <!-- Preview -->
    <aside class="lg:sticky lg:top-[4.5rem] lg:self-start" :class="pane==='preview' ? '' : 'hidden lg:block'" aria-label="Preview">
      <div class="card p-4">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold">Preview</h2>
          <div class="flex gap-1">
            <template x-for="id in selected" :key="id">
              <button type="button" class="rounded-full p-0.5" :class="previewId===id ? 'ring-2 ring-[var(--np-brand)]' : 'opacity-60 hover:opacity-100'" @click="previewId=id" :aria-label="'Preview ' + ch(id).name"><span x-html="np.avatar(ch(id), 22)"></span></button>
            </template>
          </div>
        </div>
        <template x-if="previewId && ch(previewId)">
          <div class="mt-3 rounded-xl border border-line bg-canvas p-3">
            <div class="flex items-center gap-2.5">
              <span x-html="np.avatar(ch(previewId), 38)"></span>
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold" x-text="ch(previewId).name"></p>
                <p class="truncate text-xs text-muted"><span x-text="ch(previewId).username || ch(previewId).networkName"></span> · <span x-text="previewWhen()"></span></p>
              </div>
            </div>
            <p class="preview-text mt-3" x-text="textFor(previewId) || 'Your text will appear here.'" :class="textFor(previewId) ? '' : 'text-subtle'"></p>
            <template x-if="previewDoc()">
              <div class="mt-3 overflow-hidden rounded-lg border border-line bg-panel">
                <div class="flex aspect-[4/3] items-center justify-center bg-raised"><img :src="previewDoc().thumb" alt="" class="h-full"></div>
                <div class="flex items-center justify-between gap-2 px-3 py-2 text-xs">
                  <span class="min-w-0 truncate font-semibold" x-text="previewDoc().title || previewDoc().name"></span>
                  <span class="shrink-0 text-muted" x-text="previewDoc().kind + (previewDoc().pages ? ' · ' + previewDoc().pages + ' pages' : '')"></span>
                </div>
              </div>
            </template>
            <div class="mt-3 grid gap-1 overflow-hidden rounded-lg" :class="visualMedia().length > 1 ? 'grid-cols-2' : 'grid-cols-1'" x-show="!previewDoc() && visualMedia().length">
              <template x-for="(m, i) in visualMedia().slice(0, 4)" :key="m.id">
                <div class="relative bg-raised" :class="visualMedia().length === 3 && i === 0 ? 'row-span-2' : ''">
                  <img x-show="m.type==='image'" :src="m.thumb" alt="" class="size-full max-h-80 object-cover" :class="visualMedia().length > 1 ? 'aspect-square' : ''">
                  <video x-show="m.type==='video'" :src="m.url" class="size-full max-h-80 object-cover" controls preload="metadata"></video>
                  <span x-show="i===3 && visualMedia().length>4" class="absolute inset-0 flex items-center justify-center bg-black/50 text-lg font-semibold text-white" x-text="'+' + (visualMedia().length-4)"></span>
                </div>
              </template>
            </div>
            <p class="mt-2 text-xs text-danger" x-show="previewDoc() && !supportsDocs(previewId)" x-text="ch(previewId).networkName + ' can’t show documents — give it its own media.'"></p>
            <div class="mt-3 flex justify-between border-t border-line pt-2 text-xs text-subtle"><span>♡ Like</span><span>💬 Comment</span><span>↗ Share</span></div>
          </div>
        </template>
        <p x-show="!selected.length" class="mt-3 rounded-xl border border-dashed border-line p-4 text-center text-sm text-muted">Select a channel to see a preview.</p>
      </div>
      <p class="mt-3 hidden text-center text-xs text-subtle lg:block"><span class="kbd">Ctrl</span> + <span class="kbd">Enter</span> to save</p>
    </aside>
  </div>

  <!-- Media library dialog -->
  <div x-cloak x-show="library.open" class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 sm:items-center sm:p-4" @keydown.escape.window="library.open=false">
    <div class="card flex max-h-[85vh] w-full max-w-3xl flex-col rounded-b-none sm:rounded-b-[.875rem]" @click.outside="library.open=false" role="dialog" aria-modal="true" aria-label="Media library">
      <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <h2 class="font-semibold" x-text="library.target ? 'Media for ' + ch(library.target).name : 'Media library'">Media library</h2>
        <button class="btn btn-ghost btn-icon" @click="library.open=false" aria-label="Close"><?= icon('x', 'size-4') ?></button>
      </div>
      <div class="grid flex-1 grid-cols-3 gap-2 overflow-y-auto p-4 sm:grid-cols-5 scroll-thin">
        <template x-for="m in library.items" :key="m.id">
          <button type="button" class="relative aspect-square overflow-hidden rounded-lg border-2" :class="hasMedia(m.id) ? 'border-[var(--np-brand)]' : 'border-transparent'" @click="pick(m)" :aria-pressed="hasMedia(m.id)" :title="m.name">
            <img x-show="m.type!=='video'" :src="m.thumb" alt="" class="size-full object-cover" loading="lazy">
            <span x-show="m.type==='document'" class="absolute inset-x-1 bottom-1 truncate rounded bg-black/60 px-1 text-[10px] text-white" x-text="m.name"></span>
            <video x-show="m.type==='video'" :src="m.url" class="size-full object-cover" muted preload="metadata"></video>
            <span x-show="hasMedia(m.id)" class="absolute right-1 top-1 rounded-full bg-brand p-0.5 text-white"><?= icon('check', 'size-3.5') ?></span>
          </button>
        </template>
        <p x-show="library.loaded && !library.items.length" class="col-span-full py-10 text-center text-sm text-muted">Your library is empty. Upload files from the composer.</p>
        <template x-if="!library.loaded"><template x-for="n in 10"><div class="skeleton aspect-square"></div></template></template>
      </div>
      <div class="flex justify-end border-t border-line px-4 py-3"><button class="btn btn-primary" @click="library.open=false">Done</button></div>
    </div>
  </div>
</div>
