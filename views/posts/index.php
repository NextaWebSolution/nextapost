<?php $base = '/w/' . $ws['uuid']; ?>
<div class="mx-auto max-w-5xl p-3 sm:p-5">
  <div class="flex flex-wrap items-center gap-2">
    <nav class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1 scroll-thin" aria-label="Filter posts">
      <?php foreach ($tabs as $k => $label): ?>
        <a class="tab" href="<?= e(url($base . '/posts', array_filter(['status' => $k === 'all' ? null : $k, 'q' => $q ?: null]))) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <form class="ml-auto w-full sm:w-64" method="get" action="<?= e(url($base . '/posts')) ?>" role="search">
      <?php if ($tab !== 'all'): ?><input type="hidden" name="status" value="<?= e($tab) ?>"><?php endif; ?>
      <label for="q" class="sr-only">Search posts</label>
      <input id="q" name="q" value="<?= e($q) ?>" class="input" placeholder="Search posts…" type="search">
    </form>
  </div>

  <?php if (!$posts): ?>
    <div class="card mt-4 px-6 py-14 text-center">
      <p class="font-semibold"><?= $q !== '' ? 'No posts match “' . e($q) . '”' : ($tab === 'in_review' ? 'Nothing waiting for approval' : 'No posts here yet') ?></p>
      <p class="mt-1 text-sm text-muted"><?= $tab === 'in_review' ? 'Posts submitted for review will show up here.' : 'Create a post and it will appear in this list and on the calendar.' ?></p>
      <?php if ($canEdit && $q === ''): ?><a href="<?= e(url($base . '/posts/new')) ?>" class="btn btn-primary mt-4"><?= icon('plus', 'size-4') ?> Create post</a><?php endif; ?>
    </div>
  <?php else: ?>
    <ul class="mt-4 space-y-2">
      <?php foreach ($posts as $p): ?>
        <li class="card flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:p-4">
          <a href="<?= e(url($base . '/posts/' . $p['id'] . '/edit')) ?>" class="flex min-w-0 flex-1 items-center gap-3">
            <?php if ($p['thumb']): ?><img src="<?= e($p['thumb']) ?>" alt="" class="size-14 shrink-0 rounded-lg object-cover" loading="lazy"><?php else: ?><span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-raised text-subtle"><?= icon('pencil', 'size-5') ?></span><?php endif; ?>
            <span class="min-w-0 flex-1">
              <span class="flex flex-wrap items-center gap-2 text-xs text-muted">
                <?= status_badge($p['status']) ?>
                <span><?= $p['date'] ? e(date('D, d M Y', strtotime($p['date']))) . ' · ' . e($p['time']) : 'No date' ?></span>
              </span>
              <span class="mt-1 block truncate text-sm font-medium"><?= e($p['title']) ?></span>
              <span class="mt-1.5 flex flex-wrap items-center gap-1">
                <?php foreach ($p['channels'] as $c): ?>
                  <span class="inline-flex items-center gap-1 rounded-full bg-raised py-0.5 pl-0.5 pr-2 text-[11px]" title="<?= e($c['name'] . ' — ' . status_label($c['status'])) ?>">
                    <?= channel_avatar(['name' => $c['name'], 'network' => $c['network'], 'avatar_url' => $c['avatar']], 'size-4') ?>
                    <span class="max-w-[9rem] truncate"><?= e($c['name']) ?></span>
                    <?php if ($c['status'] === 'failed'): ?><span class="text-danger">✕</span><?php elseif ($c['status'] === 'published'): ?><span class="text-success">✓</span><?php endif; ?>
                  </span>
                <?php endforeach; ?>
              </span>
              <?php foreach ($p['errors'] as $err): ?><span class="mt-1 block text-xs text-danger"><?= e(str_excerpt($err, 220)) ?></span><?php endforeach; ?>
              <?php if (!empty($p['review_note']) && $p['status'] === 'draft'): ?><span class="mt-1 block text-xs text-warn">Changes requested: <?= e(str_excerpt($p['review_note'], 160)) ?></span><?php endif; ?>
            </span>
          </a>
          <div class="flex shrink-0 flex-wrap items-center gap-1.5 sm:justify-end">
            <?php if ($canApprove && $p['status'] === 'in_review'): ?>
              <form method="post" action="<?= e(url($base . '/posts/' . $p['id'] . '/approve')) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-sm"><?= icon('check', 'size-4') ?> Approve</button></form>
              <a class="btn btn-secondary btn-sm" href="<?= e(url($base . '/posts/' . $p['id'] . '/edit')) ?>">Review</a>
            <?php endif; ?>
            <?php if ($canEdit && in_array($p['status'], ['failed', 'partially_published'], true)): ?>
              <form method="post" action="<?= e(url($base . '/posts/' . $p['id'] . '/retry')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm"><?= icon('refresh', 'size-4') ?> Retry</button></form>
            <?php endif; ?>
            <?php if ($canEdit): ?>
              <form method="post" action="<?= e(url($base . '/posts/' . $p['id'] . '/duplicate')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-icon" title="Duplicate" aria-label="Duplicate"><?= icon('copy', 'size-4') ?></button></form>
              <?php if ($p['status'] !== 'publishing'): ?>
              <form method="post" action="<?= e(url($base . '/posts/' . $p['id'] . '/delete')) ?>" x-data="confirmForm('Delete this post?')" @submit="check"><?= csrf_field() ?><button class="btn btn-ghost btn-icon text-danger" title="Delete" aria-label="Delete"><?= icon('trash', 'size-4') ?></button></form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($pages > 1): ?>
      <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Pagination">
        <span class="text-muted"><?= (int) $total ?> posts · page <?= (int) $page ?> of <?= (int) $pages ?></span>
        <span class="flex gap-2">
          <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= e(url($base . '/posts', array_filter(['status' => $tab === 'all' ? null : $tab, 'q' => $q ?: null, 'page' => $page - 1]))) ?>">Previous</a><?php endif; ?>
          <?php if ($page < $pages): ?><a class="btn btn-secondary btn-sm" href="<?= e(url($base . '/posts', array_filter(['status' => $tab === 'all' ? null : $tab, 'q' => $q ?: null, 'page' => $page + 1]))) ?>">Next</a><?php endif; ?>
        </span>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
