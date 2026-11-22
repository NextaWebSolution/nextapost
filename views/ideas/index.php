<div class="p-3 sm:p-5" x-data="ideasPage(<?= json_attr($cfg) ?>)">
  <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-2">
    <div class="flex flex-wrap gap-1 rounded-lg bg-canvas p-0.5" role="tablist" aria-label="<?= e(__('Ideas')) ?>">
      <button type="button" class="tab" role="tab" :aria-selected="tab==='board'" @click="tab='board'"><?= icon('bulb', 'size-4') ?> <?= e(__('Board')) ?></button>
      <button type="button" class="tab" role="tab" :aria-selected="tab==='feeds'" @click="tab='feeds'"><?= icon('rss', 'size-4') ?> <?= e(__('Feeds')) ?> <span class="badge" x-show="items.length" x-text="items.length"></span></button>
    </div>
    <label class="sr-only" for="idea-search"><?= e(__('Search ideas')) ?></label>
    <input id="idea-search" type="search" class="input input-sm ms-auto w-full sm:w-56" x-show="tab==='board'" x-model="q" placeholder="<?= e(__('Search ideas')) ?>">
  </div>

  <!-- Board -->
  <section x-show="tab==='board'" class="mt-4" aria-label="<?= e(__('Board')) ?>">
    <div class="relative flex gap-3 overflow-x-auto pb-3 scroll-thin">
      <template x-for="st in stages" :key="st.id">
        <div class="flex w-72 shrink-0 flex-col rounded-xl border border-line bg-panel/50 p-2" :aria-label="st.name" role="group"
             @dragover.prevent="dragOver($event, st)" @dragleave="dropAt = null" @drop.prevent="drop(st)">
          <div class="flex items-center gap-2 px-1 pb-2">
            <h2 class="min-w-0 flex-1 truncate text-sm font-semibold" x-text="st.name"></h2>
            <span class="text-xs text-muted" x-text="inStage(st).length"></span>
            <div class="relative" x-data="{open:false}" x-show="cfg.canFeeds">
              <button type="button" class="btn btn-ghost btn-icon size-7 min-h-0" @click="open=!open" :aria-label="<?= js(__('Column options')) ?> + ' ' + st.name"><?= icon('more', 'size-4') ?></button>
              <div x-cloak x-show="open" @click.outside="open=false" class="menu end-0 top-full z-20 mt-1 w-44">
                <button type="button" class="menu-item" @click="renameStage(st); open=false"><?= e(__('Rename')) ?></button>
                <button type="button" class="menu-item text-danger" @click="deleteStage(st); open=false"><?= e(__('Delete column')) ?></button>
              </div>
            </div>
          </div>
          <ul class="flex min-h-12 flex-1 flex-col gap-2">
            <template x-for="(i, n) in inStage(st)" :key="i.id">
              <li>
                <div class="h-1 rounded bg-brand" x-show="dropAt && dropAt.stage === st.id && dropAt.index === n"></div>
                <article class="card cursor-grab p-3 active:cursor-grabbing" :draggable="cfg.canEdit" @dragstart="dragging = i" @dragend="dragging = null; dropAt = null"
                         :class="dragging && dragging.id === i.id ? 'opacity-50' : ''" :data-idea="i.id">
                  <button type="button" class="block w-full text-start" @click="openIdea(i)">
                    <h3 class="text-sm font-semibold" x-text="i.title || excerpt(i.body, 60)"></h3>
                    <p class="mt-1 line-clamp-3 text-xs text-muted" x-show="i.title && i.body" x-text="i.body"></p>
                  </button>
                  <div class="mt-2 flex flex-wrap items-center gap-1" x-show="i.labels.length || i.link || i.post_id">
                    <template x-for="l in i.labels" :key="l.id"><span class="label-chip is-on text-[11px]" :style="'--lc:' + l.color" x-text="l.name"></span></template>
                    <span class="truncate text-[11px] text-subtle" x-show="i.link" x-text="host(i.link)"></span>
                    <span class="badge st-published text-[11px]" x-show="i.post_id"><?= e(__('Posted')) ?></span>
                  </div>
                  <div class="mt-2 flex items-center gap-1" x-show="cfg.canEdit">
                    <a class="btn btn-sm" :href="cfg.composeUrl + '?idea=' + i.id"><?= icon('pencil', 'size-3.5') ?> <?= e(__('Write post')) ?></a>
                  </div>
                </article>
              </li>
            </template>
            <li class="h-1 rounded bg-brand" x-show="dropAt && dropAt.stage === st.id && dropAt.index >= inStage(st).length"></li>
          </ul>
          <form class="mt-2" x-show="cfg.canEdit" @submit.prevent="quickAdd(st)">
            <label class="sr-only" :for="'add-' + st.id"><?= e(__('New idea')) ?></label>
            <textarea class="input input-sm" rows="2" maxlength="200" :id="'add-' + st.id" x-model="draft[st.id]" placeholder="<?= e(__('Add an idea…')) ?>"
                      @keydown.enter.prevent="quickAdd(st)"></textarea>
          </form>
        </div>
      </template>
      <form class="w-60 shrink-0" x-show="cfg.canFeeds" @submit.prevent="addStage()">
        <label class="sr-only" for="new-stage"><?= e(__('New column')) ?></label>
        <div class="flex gap-1.5"><input id="new-stage" class="input input-sm" maxlength="60" x-model="newStage" placeholder="<?= e(__('New column')) ?>"><button class="btn btn-sm" :disabled="!newStage.trim()"><?= icon('plus', 'size-4') ?><span class="sr-only"><?= e(__('Add column')) ?></span></button></div>
      </form>
    </div>
    <p class="mt-2 text-sm text-muted" x-show="!ideas.length"><?= e(__('Jot down post ideas here, drag them between columns as they progress, and turn them into posts when ready. Feeds can fill this board automatically.')) ?></p>
  </section>

  <!-- Feeds -->
  <section x-show="tab==='feeds'" x-cloak class="mx-auto mt-4 max-w-6xl" aria-label="<?= e(__('Feeds')) ?>">
    <p class="text-sm text-muted"><?= e(__('Follow blogs, news sites and YouTube channels (RSS, Atom or JSON Feed). New articles appear below so you can share them or save them as ideas.')) ?></p>
    <form class="card mt-3 flex flex-wrap items-end gap-3 p-4" x-show="cfg.canFeeds" @submit.prevent="addFeed()">
      <div class="min-w-[14rem] flex-1">
        <label class="label" for="feed-url"><?= e(__('Website or feed address')) ?></label>
        <input id="feed-url" class="input" maxlength="700" x-model="feedForm.url" placeholder="https://example.com/blog" required :aria-invalid="!!errors.url">
        <p class="help text-danger" x-show="errors.url" x-text="errors.url"></p>
      </div>
      <div>
        <label class="label" for="feed-auto"><?= e(__('New articles')) ?></label>
        <select id="feed-auto" class="input" x-model="feedForm.auto">
          <option value="inbox"><?= e(__('Show in the inbox')) ?></option>
          <option value="idea"><?= e(__('Add to the ideas board')) ?></option>
        </select>
      </div>
      <button class="btn btn-primary" :disabled="busy"><span x-text="busy ? <?= js(__('Checking…')) ?> : <?= js(__('Follow')) ?>"></span></button>
    </form>

    <div class="mt-4 grid gap-4 lg:grid-cols-[18rem_1fr]">
      <div>
        <h2 class="text-sm font-semibold"><?= e(__('Following')) ?></h2>
        <ul class="mt-2 space-y-2" x-show="feeds.length">
          <template x-for="f in feeds" :key="f.id">
            <li class="card p-3">
              <div class="flex items-start gap-2">
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-semibold" x-text="f.title || host(f.url)"></p>
                  <p class="truncate text-xs text-muted" x-text="host(f.url) + ' · ' + f.format"></p>
                  <p class="mt-1 text-xs text-danger" x-show="f.error" x-text="f.error"></p>
                </div>
                <span class="badge" x-show="f.unread" x-text="f.unread"></span>
              </div>
              <div class="mt-2 flex flex-wrap items-center gap-1" x-show="cfg.canFeeds">
                <select class="input input-sm w-auto" :aria-label="<?= js(__('New articles')) ?>" x-model="f.auto" @change="setAuto(f)">
                  <option value="inbox"><?= e(__('Inbox')) ?></option>
                  <option value="idea"><?= e(__('Ideas board')) ?></option>
                </select>
                <button type="button" class="btn btn-ghost btn-icon size-8 min-h-0" @click="refreshFeed(f)" :aria-label="<?= js(__('Check now')) ?>" title="<?= e(__('Check now')) ?>"><?= icon('refresh', 'size-4') ?></button>
                <button type="button" class="btn btn-ghost btn-icon size-8 min-h-0 text-danger" @click="deleteFeed(f)" :aria-label="<?= js(__('Unfollow')) ?>" title="<?= e(__('Unfollow')) ?>"><?= icon('trash', 'size-4') ?></button>
              </div>
            </li>
          </template>
        </ul>
        <p class="mt-2 text-sm text-subtle" x-show="!feeds.length"><?= e(__('Not following any feeds yet.')) ?></p>
      </div>
      <div>
        <h2 class="text-sm font-semibold"><?= e(__('New articles')) ?></h2>
        <ul class="mt-2 space-y-2" x-show="items.length">
          <template x-for="it in items" :key="it.id">
            <li class="card flex gap-3 p-3">
              <img x-show="it.image_url" :src="it.image_url" alt="" class="hidden size-20 shrink-0 rounded-lg object-cover sm:block" loading="lazy" referrerpolicy="no-referrer">
              <div class="min-w-0 flex-1">
                <a class="font-semibold hover:underline" :href="it.link" target="_blank" rel="noopener noreferrer" x-text="it.title || it.link"></a>
                <p class="mt-1 line-clamp-2 text-sm text-muted" x-text="it.summary"></p>
                <p class="mt-1 text-xs text-subtle" x-text="(it.feed ? it.feed.title : '') + (it.published_at ? ' · ' + when(it.published_at) : '')"></p>
                <div class="mt-2 flex flex-wrap gap-1.5" x-show="cfg.canEdit">
                  <button type="button" class="btn btn-sm btn-primary" @click="itemToPost(it)"><?= icon('pencil', 'size-3.5') ?> <?= e(__('Write post')) ?></button>
                  <button type="button" class="btn btn-sm" @click="itemToIdea(it)"><?= icon('bulb', 'size-3.5') ?> <?= e(__('Save as idea')) ?></button>
                  <button type="button" class="btn btn-sm btn-ghost" @click="dismiss(it)"><?= e(__('Dismiss')) ?></button>
                </div>
              </div>
            </li>
          </template>
        </ul>
        <div class="card mt-2 p-8 text-center" x-show="!items.length">
          <p class="font-semibold"><?= e(__('You’re all caught up')) ?></p>
          <p class="mt-1 text-sm text-muted"><?= e(__('New articles from the feeds you follow show up here. Feeds are checked about every 30 minutes.')) ?></p>
        </div>
      </div>
    </div>
  </section>

  <!-- Idea dialog -->
  <div class="fixed inset-0 z-40 grid place-items-center bg-black/50 p-3" x-show="edit" x-cloak @keydown.escape.window="edit = null" role="dialog" aria-modal="true" aria-labelledby="idea-dialog-title">
    <form class="card max-h-[90vh] w-full max-w-lg overflow-y-auto p-5" @submit.prevent="saveIdea()">
      <h2 id="idea-dialog-title" class="text-base font-semibold"><?= e(__('Idea')) ?></h2>
      <template x-if="edit">
        <div class="mt-3 grid gap-3">
          <div>
            <label class="label" for="idea-title"><?= e(__('Title')) ?></label>
            <input id="idea-title" class="input" maxlength="200" x-model="edit.title" :readonly="!cfg.canEdit" :aria-invalid="!!errors.title">
            <p class="help text-danger" x-show="errors.title" x-text="errors.title"></p>
          </div>
          <div>
            <label class="label" for="idea-body"><?= e(__('Notes')) ?></label>
            <textarea id="idea-body" class="input" rows="5" maxlength="20000" x-model="edit.body" :readonly="!cfg.canEdit"></textarea>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="label" for="idea-link"><?= e(__('Link')) ?></label>
              <input id="idea-link" type="url" class="input" maxlength="700" x-model="edit.link" :readonly="!cfg.canEdit" placeholder="https://" :aria-invalid="!!errors.link">
              <p class="help text-danger" x-show="errors.link" x-text="errors.link"></p>
            </div>
            <div>
              <label class="label" for="idea-stage"><?= e(__('Column')) ?></label>
              <select id="idea-stage" class="input" x-model="edit.stage" :disabled="!cfg.canEdit">
                <template x-for="st in stages" :key="st.id"><option :value="st.id" x-text="st.name" :selected="edit.stage === st.id"></option></template>
              </select>
            </div>
          </div>
          <fieldset x-show="cfg.labels.length">
            <legend class="label"><?= e(__('Labels')) ?></legend>
            <div class="flex flex-wrap gap-1.5">
              <template x-for="l in cfg.labels" :key="l.id">
                <button type="button" class="label-chip" :class="edit.labels.includes(l.id) ? 'is-on' : ''" :style="'--lc:' + l.color" :aria-pressed="edit.labels.includes(l.id)" @click="toggleLabel(l.id)" :disabled="!cfg.canEdit" x-text="l.name"></button>
              </template>
            </div>
          </fieldset>
          <div class="flex flex-wrap items-center gap-2 pt-1">
            <button type="button" class="btn btn-ghost text-danger" x-show="cfg.canEdit && edit.id" @click="deleteIdea()"><?= e(__('Delete')) ?></button>
            <a class="btn ms-auto" x-show="cfg.canEdit && edit.id" :href="cfg.composeUrl + '?idea=' + edit.id"><?= e(__('Write post')) ?></a>
            <button type="button" class="btn btn-ghost" @click="edit = null"><?= e(__('Close')) ?></button>
            <button class="btn btn-primary" x-show="cfg.canEdit" :disabled="busy"><?= e(__('Save')) ?></button>
          </div>
        </div>
      </template>
    </form>
  </div>
</div>
<?php $head = '<script src="' . asset('js/ideas.js') . '"></script>'; ?>
