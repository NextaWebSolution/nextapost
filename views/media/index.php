<?php
$cfg = ['items' => $media, 'uploadUrl' => $uploadUrl, 'base' => url('/w/' . $ws['uuid'] . '/media'), 'canEdit' => $canEdit, 'maxUpload' => $maxUpload, 'ai' => !empty($aiEnabled),
    'sources' => $sources, 'connected' => $connected, 'srcBase' => url('/w/' . $ws['uuid'] . '/media-sources')];
?>
<div class="mx-auto max-w-6xl p-3 sm:p-5" x-data="mediaLibrary(<?= json_attr($cfg) ?>)">
  <div class="flex flex-wrap items-center gap-3">
    <p class="text-sm text-muted"><span x-text="items.length"></span> files · <?= e(human_bytes($used)) ?> used</p>
    <?php if ($canEdit): ?>
      <div class="relative ms-auto" x-data="{open:false}">
        <button type="button" class="btn btn-sm" @click="open=!open" :aria-expanded="open"><?= icon('plus', 'size-4') ?> <?= e(__('Add from…')) ?></button>
        <div x-cloak x-show="open" @click.outside="open=false" class="menu end-0 top-full z-20 mt-1 w-56">
          <button type="button" class="menu-item" @click="openSource('url'); open=false"><?= icon('link', 'size-4') ?> <?= e(__('A link')) ?></button>
          <button type="button" class="menu-item" x-show="cfg.sources.unsplash" @click="openSource('unsplash'); open=false"><?= icon('image', 'size-4') ?> <?= e(__('Unsplash')) ?></button>
          <button type="button" class="menu-item" x-show="cfg.sources.canva" @click="openSource('canva'); open=false"><?= icon('sparkles', 'size-4') ?> <?= e(__('Canva')) ?></button>
          <button type="button" class="menu-item" x-show="cfg.sources.google_photos" @click="openSource('photos'); open=false"><?= icon('image', 'size-4') ?> <?= e(__('Google Photos')) ?></button>
          <button type="button" class="menu-item" x-show="cfg.sources.google_drive" @click="openSource('drive'); open=false"><?= icon('upload', 'size-4') ?> <?= e(__('Google Drive')) ?></button>
        </div>
      </div>
      <label class="btn btn-primary btn-sm cursor-pointer"><?= icon('upload', 'size-4') ?> <?= e(__('Upload')) ?>
        <input type="file" class="sr-only" multiple accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,application/pdf,.pdf,.pptx,.docx" @change="upload($event.target.files); $event.target.value=''">
      </label>
    <?php endif; ?>
  </div>
  <div class="mt-4 rounded-2xl border-2 border-dashed border-transparent transition" :class="dropping ? 'border-[var(--np-brand)] bg-brand-soft' : ''"
       @dragover.prevent="dropping=canEdit" @dragleave="dropping=false" @drop.prevent="dropping=false; canEdit && upload($event.dataTransfer.files)">
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
      <template x-for="n in uploading" :key="'u'+n"><div class="skeleton aspect-square"></div></template>
      <template x-for="m in items" :key="m.id">
        <figure class="card group overflow-hidden">
          <div class="relative aspect-square bg-raised">
            <img x-show="m.type!=='video'" :src="m.thumb" :alt="m.alt || m.name" class="size-full object-cover" loading="lazy">
            <a x-show="m.type==='document'" :href="m.url" class="absolute right-1.5 top-1.5 rounded-lg bg-black/60 p-1.5 text-white hover:bg-black/80" title="<?= e(__('Download')) ?>" aria-label="<?= e(__('Download')) ?>"><?= icon('download', 'size-3.5') ?></a>
            <video x-show="m.type==='video'" :src="m.url" class="size-full object-cover" muted preload="metadata" controls></video>
          </div>
          <figcaption class="flex items-center gap-1 p-2">
            <span class="min-w-0 flex-1"><span class="block truncate text-xs font-medium" x-text="m.name" :title="m.name"></span><span class="block text-[11px] text-muted" x-text="m.size_h + (m.width ? ' · ' + m.width + '×' + m.height : '') + (m.pages ? ' · ' + np.t('{n} pages', {n: m.pages}) : '')"></span></span>
            <template x-if="canEdit">
              <span class="flex">
                <button type="button" class="btn btn-ghost btn-icon size-7 min-h-0" x-show="cfg.ai && m.type==='image'" @click="aiAlt(m)" :disabled="aiBusy===m.id" title="<?= e(__('Write alt text with AI')) ?>" aria-label="<?= e(__('Write alt text with AI')) ?>"><?= icon('sparkles', 'size-3.5') ?></button>
                <button type="button" class="btn btn-ghost btn-icon size-7 min-h-0" @click="editAlt(m)" :title="m.type==='document' ? np.t('Document title') : np.t('Alt text')" :aria-label="m.type==='document' ? np.t('Edit document title') : np.t('Edit alt text')"><?= icon('pencil', 'size-3.5') ?></button>
                <button type="button" class="btn btn-ghost btn-icon size-7 min-h-0 text-danger" @click="remove(m)" title="<?= e(__('Delete')) ?>" aria-label="<?= e(__('Delete file')) ?>"><?= icon('trash', 'size-3.5') ?></button>
              </span>
            </template>
          </figcaption>
        </figure>
      </template>
    </div>
    <div x-show="!items.length && !uploading" class="card px-6 py-14 text-center">
      <p class="font-semibold"><?= e(__('Your media library is empty')) ?></p>
      <p class="mt-1 text-sm text-muted"><?= e(__('Upload images, videos and documents (PDF, PPTX, DOCX for LinkedIn) once, then reuse them in any post. Drag files here or use Upload.')) ?></p>
    </div>
  </div>

  <!-- Add from a source -->
  <div class="fixed inset-0 z-40 grid place-items-center bg-black/50 p-3" x-show="src" x-cloak @keydown.escape.window="src = null" role="dialog" aria-modal="true" aria-labelledby="src-title">
    <div class="card flex max-h-[90vh] w-full max-w-3xl flex-col p-5">
      <div class="flex items-center gap-2">
        <h2 id="src-title" class="text-base font-semibold" x-text="srcTitle()"></h2>
        <button type="button" class="btn btn-ghost btn-icon ms-auto" @click="src = null" aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-4') ?></button>
      </div>

      <form class="mt-4 grid gap-3" x-show="src === 'url'" @submit.prevent="importUrl()">
        <div><label class="label" for="src-url"><?= e(__('Link to an image, video or PDF')) ?></label><input id="src-url" type="url" class="input" x-model="url" placeholder="https://" required></div>
        <div class="flex justify-end"><button class="btn btn-primary" :disabled="srcBusy"><span x-text="srcBusy ? <?= js(__('Importing…')) ?> : <?= js(__('Import')) ?>"></span></button></div>
      </form>

      <div class="mt-4 flex min-h-0 flex-1 flex-col" x-show="src === 'unsplash'">
        <form class="flex gap-2" @submit.prevent="searchUnsplash(1)">
          <label class="sr-only" for="src-q"><?= e(__('Search free photos')) ?></label>
          <input id="src-q" class="input" x-model="q" placeholder="<?= e(__('Search free photos, e.g. office, laptop, Diwali')) ?>">
          <button class="btn btn-primary" :disabled="srcBusy || !q.trim()"><?= e(__('Search')) ?></button>
        </form>
        <div class="mt-3 grid min-h-0 grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-3 md:grid-cols-4">
          <template x-for="ph in photos" :key="ph.id">
            <figure class="overflow-hidden rounded-lg bg-canvas">
              <button type="button" class="block w-full" @click="importUnsplash(ph)" :disabled="srcBusy" :aria-label="<?= js(__('Add')) ?> + ' ' + (ph.alt || ph.id)">
                <img :src="ph.thumb" :alt="ph.alt" class="aspect-square w-full object-cover" loading="lazy" referrerpolicy="no-referrer">
              </button>
              <figcaption class="truncate px-1.5 py-1 text-[11px] text-muted"><?= e(__('Photo by')) ?> <a :href="ph.author_url + '?utm_source=nextapost&utm_medium=referral'" target="_blank" rel="noopener" class="underline" x-text="ph.author"></a> · <a href="https://unsplash.com/?utm_source=nextapost&utm_medium=referral" target="_blank" rel="noopener" class="underline"><?= e(__('Unsplash')) ?></a></figcaption>
            </figure>
          </template>
        </div>
        <div class="mt-2 flex justify-center gap-2" x-show="pages > 1">
          <button type="button" class="btn btn-sm" :disabled="page <= 1 || srcBusy" @click="searchUnsplash(page - 1)"><?= e(__('Previous')) ?></button>
          <button type="button" class="btn btn-sm" :disabled="page >= pages || srcBusy" @click="searchUnsplash(page + 1)"><?= e(__('Next')) ?></button>
        </div>
      </div>

      <div class="mt-4 flex min-h-0 flex-1 flex-col" x-show="src === 'canva'">
        <template x-if="!cfg.connected.canva">
          <div class="py-6 text-center"><p class="text-sm text-muted"><?= e(__('Connect your Canva account to add your designs.')) ?></p><a class="btn btn-primary mt-3" :href="cfg.srcBase + '/canva/connect'"><?= e(__('Connect Canva')) ?></a></div>
        </template>
        <template x-if="cfg.connected.canva">
          <div class="flex min-h-0 flex-1 flex-col">
            <form class="flex flex-wrap gap-2" @submit.prevent="loadDesigns(true)">
              <label class="sr-only" for="src-cq"><?= e(__('Search your designs')) ?></label>
              <input id="src-cq" class="input min-w-0 flex-1" x-model="q" placeholder="<?= e(__('Search your designs')) ?>">
              <label class="sr-only" for="src-fmt"><?= e(__('File type')) ?></label>
              <select id="src-fmt" class="input w-auto" x-model="format"><option value="png">PNG</option><option value="jpg">JPG</option><option value="pdf">PDF</option><option value="mp4"><?= e(__('MP4 video')) ?></option></select>
              <button class="btn" :disabled="srcBusy"><?= e(__('Search')) ?></button>
            </form>
            <div class="mt-3 grid min-h-0 grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-3 md:grid-cols-4">
              <template x-for="d in designs" :key="d.id">
                <button type="button" class="overflow-hidden rounded-lg bg-canvas text-start" @click="importCanva(d)" :disabled="srcBusy">
                  <img :src="d.thumb" alt="" class="aspect-square w-full object-cover" loading="lazy" referrerpolicy="no-referrer">
                  <span class="block truncate px-1.5 py-1 text-xs" x-text="d.title + (d.pages > 1 ? ' · ' + d.pages + ' ' + np.t('pages') : '')"></span>
                </button>
              </template>
            </div>
            <div class="mt-2 flex items-center gap-2">
              <button type="button" class="btn btn-sm" x-show="continuation" @click="loadDesigns(false)" :disabled="srcBusy"><?= e(__('Load more')) ?></button>
              <button type="button" class="btn btn-ghost btn-sm ms-auto text-danger" @click="disconnect('canva')"><?= e(__('Disconnect Canva')) ?></button>
            </div>
          </div>
        </template>
      </div>

      <div class="mt-4" x-show="src === 'photos' || src === 'drive'">
        <template x-if="!cfg.connected.google">
          <div class="py-6 text-center"><p class="text-sm text-muted"><?= e(__('Connect your Google account to pick photos and files.')) ?></p><a class="btn btn-primary mt-3" :href="cfg.srcBase + '/google/connect'"><?= e(__('Connect Google')) ?></a></div>
        </template>
        <template x-if="cfg.connected.google">
          <div class="py-6 text-center">
            <p class="text-sm text-muted" x-show="src === 'photos'"><?= e(__('Google Photos opens in a new tab. Pick your photos there and press Done; they’ll be added here.')) ?></p>
            <p class="text-sm text-muted" x-show="src === 'drive'"><?= e(__('Choose images, videos or PDFs from your Google Drive.')) ?></p>
            <button type="button" class="btn btn-primary mt-3" @click="src === 'photos' ? pickPhotos() : pickDrive()" :disabled="srcBusy">
              <span x-text="srcBusy ? (waiting ? <?= js(__('Waiting for your choice…')) ?> : <?= js(__('Importing…')) ?>) : (src === 'photos' ? <?= js(__('Choose in Google Photos')) ?> : <?= js(__('Choose from Drive')) ?>)"></span>
            </button>
            <p class="mt-4"><button type="button" class="btn btn-ghost btn-sm text-danger" @click="disconnect('google')"><?= e(__('Disconnect Google')) ?></button></p>
          </div>
        </template>
      </div>
    </div>
  </div>
</div>
<?php $head = '<script src="' . asset('js/media.js') . '"></script>'; ?>
