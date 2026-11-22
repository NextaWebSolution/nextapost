<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#18181b">
  <h1 style="font-size:20px;margin:0 0 12px"><?= e(__('You’re invited to {workspace}', ['workspace' => $workspace])) ?></h1>
  <p style="line-height:1.5"><?= e($inviter) ?> added you to the <strong><?= e($workspace) ?></strong> workspace on <?= e(app_name()) ?> as <strong><?= e($role) ?></strong>.</p>
  <p style="margin:24px 0"><a href="<?= e($link) ?>" style="background:#6d28d9;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:600;display:inline-block"><?= $newUser ? 'Set your password' : 'Open ' . e(app_name()) ?></a></p>
  <?php if ($newUser): ?><p style="line-height:1.5;color:#5f5f6b;font-size:14px"><?= e(__('This link is valid for 7 days.')) ?></p><?php endif; ?>
</div>
