<?php
$cfg = ['items' => $media, 'uploadUrl' => $uploadUrl, 'base' => url('/w/' . $ws['uuid'] . '/media'), 'canEdit' => $canEdit, 'maxUpload' => $maxUpload];
?>
<div class="mx-auto max-w-6xl p-3 sm:p-5" x-data="mediaLibrary(<?= json_attr($cfg) ?>)">
  <div class="flex flex-wrap items-center gap-3">
    <p class="text-sm text-muted"><span x-text="items.length"></span> files · <?= e(human_bytes($used)) ?> used</p>
    <?php if ($canEdit): ?>
      <label class="btn btn-primary btn-sm ml-auto cursor-pointer"><?= icon('upload', 'size-4') ?> Upload
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
            <a x-show="m.type==='document'" :href="m.url" class="absolute right-1.5 top-1.5 rounded-lg bg-black/60 p-1.5 text-white hover:bg-black/80" title="Download" aria-label="Download"><?= icon('download', 'size-3.5') ?></a>
            <video x-show="m.type==='video'" :src="m.url" class="size-full object-cover" muted preload="metadata" controls></video>
          </div>
          <figcaption class="flex items-center gap-1 p-2">
            <span class="min-w-0 flex-1"><span class="block truncate text-xs font-medium" x-text="m.name" :title="m.name"></span><span class="block text-[11px] text-muted" x-text="m.size_h + (m.width ? ' · ' + m.width + '×' + m.height : '') + (m.pages ? ' · ' + m.pages + ' pages' : '')"></span></span>
            <template x-if="canEdit">
              <span class="flex">
                <button type="button" class="btn btn-ghost btn-icon size-7 min-h-0" @click="editAlt(m)" :title="m.type==='document' ? 'Document title' : 'Alt text'" :aria-label="m.type==='document' ? 'Edit document title' : 'Edit alt text'"><?= icon('pencil', 'size-3.5') ?></button>
                <button type="button" class="btn btn-ghost btn-icon size-7 min-h-0 text-danger" @click="remove(m)" title="Delete" aria-label="Delete file"><?= icon('trash', 'size-3.5') ?></button>
              </span>
            </template>
          </figcaption>
        </figure>
      </template>
    </div>
    <div x-show="!items.length && !uploading" class="card px-6 py-14 text-center">
      <p class="font-semibold">Your media library is empty</p>
      <p class="mt-1 text-sm text-muted">Upload images, videos and documents (PDF, PPTX, DOCX for LinkedIn) once, then reuse them in any post. Drag files here or use Upload.</p>
    </div>
  </div>
</div>
<?php $head = '<script src="' . asset('js/media.js') . '"></script>'; ?>
