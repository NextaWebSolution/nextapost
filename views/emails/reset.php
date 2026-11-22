<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#18181b">
  <h1 style="font-size:20px;margin:0 0 12px"><?= e(__('Reset your password')) ?></h1>
  <p style="line-height:1.5"><?= e(__('Hi {name}, someone (hopefully you) asked to reset the password for your {app} account.', ['name' => $name, 'app' => app_name()])) ?></p>
  <p style="margin:24px 0"><a href="<?= e($link) ?>" style="background:#6d28d9;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:600;display:inline-block"><?= e(__('Choose a new password')) ?></a></p>
  <p style="line-height:1.5;color:#5f5f6b;font-size:14px"><?= e(__('This link expires in 1 hour. If you didn’t ask for this, you can ignore this email — your password won’t change.')) ?></p>
</div>
