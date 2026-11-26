<div class="mx-auto max-w-5xl p-3 sm:p-5" x-data="libraryPage(<?= json_attr($cfg) ?>)">
  <div class="flex flex-wrap gap-1 rounded-lg bg-canvas p-0.5" role="tablist" aria-label="<?= e(__('Labels & templates')) ?>">
    <button type="button" class="tab" role="tab" :aria-selected="tab==='labels'" @click="tab='labels'"><?= icon('tag', 'size-4') ?> <?= e(__('Labels')) ?></button>
    <button type="button" class="tab" role="tab" :aria-selected="tab==='signatures'" @click="tab='signatures'"><?= icon('pencil', 'size-4') ?> <?= e(__('Signatures')) ?></button>
    <button type="button" class="tab" role="tab" :aria-selected="tab==='templates'" @click="tab='templates'"><?= icon('copy', 'size-4') ?> <?= e(__('Templates')) ?></button>
  </div>

  <!-- Labels -->
  <section x-show="tab==='labels'" class="mt-4" aria-label="<?= e(__('Labels')) ?>">
    <p class="text-sm text-muted"><?= e(__('Colour-code posts by campaign, client or topic, then filter the calendar by label.')) ?></p>
    <form class="card mt-3 flex flex-wrap items-end gap-3 p-4" x-show="cfg.canManage" @submit.prevent="saveLabel()">
      <div class="min-w-[12rem] flex-1">
        <label class="label" for="lbl-name" x-text="labelForm.id ? <?= js(__('Rename label')) ?> : <?= js(__('New label')) ?>"></label>
        <input id="lbl-name" class="input" maxlength="40" x-model="labelForm.name" required :aria-invalid="!!errors.name">
        <p class="help text-danger" x-show="errors.name" x-text="errors.name"></p>
      </div>
      <fieldset>
        <legend class="label"><?= e(__('Colour')) ?></legend>
        <div class="flex flex-wrap gap-1.5">
          <template x-for="c in cfg.colors" :key="c">
            <button type="button" class="size-7 rounded-full ring-offset-2 ring-offset-[var(--np-panel)]" :style="'background:' + c" :class="labelForm.color === c ? 'ring-2 ring-[var(--np-ink)]' : ''" @click="labelForm.color = c" :aria-label="c" :aria-pressed="labelForm.color === c"></button>
          </template>
        </div>
      </fieldset>
      <div class="flex gap-2">
        <button class="btn btn-primary" :disabled="busy" x-text="labelForm.id ? <?= js(__('Save')) ?> : <?= js(__('Add label')) ?>"></button>
        <button type="button" class="btn btn-ghost" x-show="labelForm.id" @click="resetLabel()"><?= e(__('Cancel')) ?></button>
      </div>
    </form>
    <ul class="card mt-3 divide-y divide-[var(--np-line)]" x-show="labels.length">
      <template x-for="l in labels" :key="l.uuid">
        <li class="flex items-center gap-3 px-4 py-3">
          <span class="label-chip is-on" :style="'--lc:' + l.color"><span class="size-2 rounded-full" :style="'background:' + l.color"></span><span x-text="l.name"></span></span>
          <span class="ml-auto flex gap-1" x-show="cfg.canManage">
            <button type="button" class="btn btn-ghost btn-icon" @click="editLabel(l)" :aria-label="<?= js(__('Edit')) ?> + ' ' + l.name"><?= icon('pencil', 'size-4') ?></button>
            <button type="button" class="btn btn-ghost btn-icon text-danger" @click="remove('labels', l)" :aria-label="<?= js(__('Delete')) ?> + ' ' + l.name"><?= icon('trash', 'size-4') ?></button>
          </span>
        </li>
      </template>
    </ul>
    <div class="card mt-3 p-8 text-center" x-show="!labels.length">
      <p class="font-semibold"><?= e(__('No labels yet')) ?></p>
      <p class="mt-1 text-sm text-muted"><?= e(__('Labels you add here appear in the composer and as a filter on the calendar.')) ?></p>
    </div>
  </section>

  <!-- Signatures -->
  <section x-show="tab==='signatures'" x-cloak class="mt-4" aria-label="<?= e(__('Signatures')) ?>">
    <p class="text-sm text-muted"><?= e(__('Reusable blocks such as hashtags, a call to action or contact details. Insert them from the composer with one click.')) ?></p>
    <form class="card mt-3 grid gap-3 p-4" x-show="cfg.canManage" @submit.prevent="saveSignature()">
      <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
        <div>
          <label class="label" for="sig-name"><?= e(__('Name')) ?></label>
          <input id="sig-name" class="input" maxlength="60" x-model="sigForm.name" required :aria-invalid="!!errors.name">
          <p class="help text-danger" x-show="errors.name" x-text="errors.name"></p>
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" class="checkbox" x-model="sigForm.is_default"> <?= e(__('Show first in the list')) ?></label>
      </div>
      <div>
        <label class="label" for="sig-content"><?= e(__('Text')) ?></label>
        <textarea id="sig-content" class="input" rows="3" maxlength="2000" x-model="sigForm.content" required :aria-invalid="!!errors.content"></textarea>
        <p class="help text-danger" x-show="errors.content" x-text="errors.content"></p>
      </div>
      <div class="flex gap-2">
        <button class="btn btn-primary" :disabled="busy" x-text="sigForm.id ? <?= js(__('Save')) ?> : <?= js(__('Add signature')) ?>"></button>
        <button type="button" class="btn btn-ghost" x-show="sigForm.id" @click="resetSig()"><?= e(__('Cancel')) ?></button>
      </div>
    </form>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
      <template x-for="sg in signatures" :key="sg.uuid">
        <article class="card p-4">
          <div class="flex items-center gap-2">
            <h3 class="min-w-0 flex-1 truncate font-semibold" x-text="sg.name"></h3>
            <span class="badge" x-show="Number(sg.is_default)"><?= e(__('First')) ?></span>
            <span class="flex" x-show="cfg.canManage">
              <button type="button" class="btn btn-ghost btn-icon" @click="editSig(sg)" :aria-label="<?= js(__('Edit')) ?> + ' ' + sg.name"><?= icon('pencil', 'size-4') ?></button>
              <button type="button" class="btn btn-ghost btn-icon text-danger" @click="remove('signatures', sg)" :aria-label="<?= js(__('Delete')) ?> + ' ' + sg.name"><?= icon('trash', 'size-4') ?></button>
            </span>
          </div>
          <p class="mt-2 whitespace-pre-wrap break-words text-sm text-muted" x-text="sg.content"></p>
        </article>
      </template>
    </div>
    <div class="card mt-3 p-8 text-center" x-show="!signatures.length">
      <p class="font-semibold"><?= e(__('No signatures yet')) ?></p>
      <p class="mt-1 text-sm text-muted"><?= e(__('Add your usual hashtags or call to action once and reuse them in every post.')) ?></p>
    </div>
  </section>

  <!-- Templates -->
  <section x-show="tab==='templates'" x-cloak class="mt-4" aria-label="<?= e(__('Templates')) ?>">
    <p class="text-sm text-muted"><?= e(__('Starting points for posts you write often. Pick one from the composer’s Template menu.')) ?></p>
    <form class="card mt-3 grid gap-3 p-4" x-show="cfg.canEdit" @submit.prevent="saveTemplate()">
      <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
        <div>
          <label class="label" for="tpl-title"><?= e(__('Title')) ?></label>
          <input id="tpl-title" class="input" maxlength="120" x-model="tplForm.title" required :aria-invalid="!!errors.title">
          <p class="help text-danger" x-show="errors.title" x-text="errors.title"></p>
        </div>
        <div>
          <label class="label" for="tpl-vis"><?= e(__('Who can use it')) ?></label>
          <select id="tpl-vis" class="input" x-model="tplForm.visibility">
            <option value="workspace"><?= e(__('Everyone in this workspace')) ?></option>
            <option value="private"><?= e(__('Only me')) ?></option>
          </select>
        </div>
      </div>
      <div>
        <label class="label" for="tpl-body"><?= e(__('Text')) ?></label>
        <textarea id="tpl-body" class="input" rows="5" maxlength="20000" x-model="tplForm.body" required :aria-invalid="!!errors.body"></textarea>
        <p class="help text-danger" x-show="errors.body" x-text="errors.body"></p>
      </div>
      <div class="flex gap-2">
        <button class="btn btn-primary" :disabled="busy" x-text="tplForm.id ? <?= js(__('Save')) ?> : <?= js(__('Add template')) ?>"></button>
        <button type="button" class="btn btn-ghost" x-show="tplForm.id" @click="resetTpl()"><?= e(__('Cancel')) ?></button>
      </div>
    </form>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
      <template x-for="t in templates" :key="t.uuid">
        <article class="card p-4">
          <div class="flex items-center gap-2">
            <h3 class="min-w-0 flex-1 truncate font-semibold" x-text="t.title"></h3>
            <span class="badge" x-text="t.visibility === 'private' ? <?= js(__('Only me')) ?> : (t.author || '')"></span>
            <span class="flex" x-show="t.mine || cfg.isAdmin">
              <button type="button" class="btn btn-ghost btn-icon" @click="editTpl(t)" :aria-label="<?= js(__('Edit')) ?> + ' ' + t.title"><?= icon('pencil', 'size-4') ?></button>
              <button type="button" class="btn btn-ghost btn-icon text-danger" @click="remove('templates', t)" :aria-label="<?= js(__('Delete')) ?> + ' ' + t.title"><?= icon('trash', 'size-4') ?></button>
            </span>
          </div>
          <p class="mt-2 line-clamp-4 whitespace-pre-wrap break-words text-sm text-muted" x-text="t.body"></p>
        </article>
      </template>
    </div>
    <div class="card mt-3 p-8 text-center" x-show="!templates.length">
      <p class="font-semibold"><?= e(__('No templates yet')) ?></p>
      <p class="mt-1 text-sm text-muted"><?= e(__('Save the posts you write every week, such as a weekly tip or a hiring post, as templates.')) ?></p>
    </div>
  </section>
</div>
<?php $head = '<script data-cfasync="false" src="' . asset('js/library.js') . '"></script>'; ?>
