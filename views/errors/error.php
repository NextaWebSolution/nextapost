<div class="text-center">
  <p class="text-sm font-semibold text-brand-strong dark:text-violet-300"><?= e((string) $status) ?></p>
  <h1 class="mt-1 text-xl font-semibold"><?= e($heading) ?></h1>
  <p class="mt-2 text-sm text-muted"><?= e($message) ?></p>
  <div class="mt-6 flex justify-center gap-2">
    <button type="button" class="btn btn-secondary" x-data @click="history.back()"><?= e(__('Go back')) ?></button>
    <a href="<?= e(url('/')) ?>" class="btn btn-primary"><?= e(__('Go to calendar')) ?></a>
  </div>
</div>
