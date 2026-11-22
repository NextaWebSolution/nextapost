<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#18181b">
  <h1 style="font-size:20px;margin:0 0 12px"><?= e($heading) ?></h1>
  <?php foreach ($lines as $line): ?><p style="line-height:1.5;margin:0 0 10px"><?= e($line) ?></p><?php endforeach; ?>
  <?php if (!empty($quote)): ?><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #6D28D9;background:#f6f5f2;border-radius:6px;white-space:pre-wrap;line-height:1.5"><?= e($quote) ?></blockquote><?php endif; ?>
  <?php if (!empty($link)): ?><p style="margin:24px 0"><a href="<?= e($link) ?>" style="background:#16161A;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:600;display:inline-block"><?= e($button ?? __('Open')) ?></a></p><?php endif; ?>
  <p style="line-height:1.5;color:#5f5f6b;font-size:13px;margin-top:28px"><?= e(__('You get this email because of your notification settings in {app}.', ['app' => app_name()])) ?></p>
</div>
