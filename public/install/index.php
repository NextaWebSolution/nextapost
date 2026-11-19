<?php

declare(strict_types=1);

/**
 * NextaPost web installer. Locks itself (storage/installed.lock) after a successful install.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Services\Installer;

$lock = Installer::lockFile();
$appBase = strip_public_segment(rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))), '/'));
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$guessUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $appBase;

session_name('np_install');
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
header('Cache-Control: no-store');
header_remove('X-Powered-By');

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$requirements = [
    ['PHP 8.1 or newer (you have ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.1.0', '>=')],
    ['PDO MySQL extension', extension_loaded('pdo_mysql')],
    ['cURL extension', extension_loaded('curl')],
    ['mbstring extension', extension_loaded('mbstring')],
    ['Sodium extension (encryption)', extension_loaded('sodium')],
    ['Fileinfo extension', extension_loaded('fileinfo')],
    ['GD extension (images)', extension_loaded('gd')],
    ['Zip extension (backups & updates)', class_exists(ZipArchive::class)],
    ['OpenSSL extension', extension_loaded('openssl')],
    ['Intl extension (recommended)', extension_loaded('intl'), true],
    ['storage/ is writable', is_writable(APP_ROOT . '/storage')],
    ['public/media/ is writable', is_writable(APP_ROOT . '/public/media')],
    ['App folder writable (to create .env)', is_writable(APP_ROOT) || (is_file(APP_ROOT . '/.env') && is_writable(APP_ROOT . '/.env'))],
];
$blocking = array_filter($requirements, static fn ($r) => !$r[1] && empty($r[2]));

$errors = [];
$done = false;
$old = $_POST + [
    'app_url' => $guessUrl, 'app_name' => 'NextaPost', 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
    'admin_name' => '', 'admin_email' => '', 'timezone' => 'Asia/Kolkata', 'company' => '',
];

if (is_file($lock)) {
    $already = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Reload the page and try again.';
    }
    if ($blocking) {
        $errors[] = 'Fix the server requirements marked in red first.';
    }
    $url = rtrim(trim((string) $old['app_url']), '/');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $url)) {
        $errors[] = 'Enter the full site address, e.g. https://social.yourdomain.com';
    }
    if (!filter_var(trim((string) $old['admin_email']), FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid admin email address.';
    }
    if (trim((string) $old['admin_name']) === '') {
        $errors[] = 'Enter the admin’s name.';
    }
    if (mb_strlen((string) ($_POST['admin_password'] ?? '')) < 8) {
        $errors[] = 'Use at least 8 characters for the admin password.';
    }
    if (!in_array((string) $old['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $errors[] = 'Choose a valid time zone.';
    }
    // Anti-hijack: until installation is finished anyone can open this page. Requiring a local database
    // (cPanel default) stops an outsider from pointing your site at a database they control.
    $dbHost = strtolower(trim((string) $old['db_host']));
    if (!in_array($dbHost, ['localhost', '127.0.0.1', '::1'], true) && !str_starts_with($dbHost, '/') && !is_file(APP_ROOT . '/storage/allow-remote-db')) {
        $errors[] = 'For security, only a local database (localhost) is allowed by default. To use a remote database server, create an empty file named allow-remote-db inside the storage folder with cPanel File Manager, then submit again.';
    }
    if (!$errors) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $old['db_host'], (int) $old['db_port'], $old['db_name']);
            $pdo = new PDO($dsn, (string) $old['db_user'], (string) ($_POST['db_pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
            $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            if (!str_contains(strtolower($ver), 'mariadb') && version_compare($ver, '5.7.0', '<')) {
                $errors[] = 'MySQL 5.7+ or MariaDB 10.3+ is required (found ' . $ver . ').';
            }
            $pdo = null;
        } catch (Throwable $e) {
            $errors[] = 'Could not connect to the database: ' . preg_replace('/\s+/', ' ', $e->getMessage()) . ' — check the name, user, password, and that the user is added to the database with ALL PRIVILEGES in cPanel → MySQL Databases.';
        }
    }
    if (!$errors) {
        try {
            Installer::install([
                'APP_NAME' => trim((string) $old['app_name']) ?: 'NextaPost',
                'APP_URL' => $url,
                'DB_DRIVER' => 'mysql',
                'DB_HOST' => trim((string) $old['db_host']),
                'DB_PORT' => (string) (int) $old['db_port'],
                'DB_NAME' => trim((string) $old['db_name']),
                'DB_USER' => trim((string) $old['db_user']),
                'DB_PASS' => (string) ($_POST['db_pass'] ?? ''),
            ], [
                'name' => (string) $old['admin_name'], 'email' => (string) $old['admin_email'], 'password' => (string) $_POST['admin_password'],
                'timezone' => (string) $old['timezone'], 'company' => (string) $old['company'],
            ]);
            $done = true;
            $_SESSION = [];
        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
$cron = '/usr/local/bin/php ' . APP_ROOT . '/bin/cron.php >/dev/null 2>&1';
$css = $appBase . '/assets/css/app.css?v=' . APP_VERSION;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Install NextaPost</title>
<link rel="icon" href="<?= $h($appBase) ?>/assets/img/favicon.svg" type="image/svg+xml">
<script nonce="<?= $h($nonce) ?>">document.documentElement.classList.toggle('dark', matchMedia('(prefers-color-scheme: dark)').matches);
document.addEventListener('submit', function (e) { var b = e.target.querySelector('button[type=submit]'); if (b) { setTimeout(function () { b.disabled = true; b.textContent = 'Installing…'; }, 0); } });</script>
<link rel="stylesheet" href="<?= $h($css) ?>">
</head>
<body class="hero-gradient min-h-full">
<main class="mx-auto max-w-2xl px-4 py-10">
  <div class="mb-6 flex items-center justify-center gap-2.5 text-white">
    <img src="<?= $h($appBase) ?>/assets/img/logo.svg" alt="" class="size-9"><span class="text-xl font-bold">NextaPost installer</span>
  </div>
  <div class="card p-6 sm:p-8">
<?php if (!empty($already)): ?>
    <h1 class="text-xl font-semibold">Already installed</h1>
    <p class="mt-2 text-sm text-muted">NextaPost is installed and the installer is locked. To reinstall, delete <code>storage/installed.lock</code> on the server (your data stays in the database).</p>
    <a href="<?= $h($appBase) ?>/" class="btn btn-primary mt-5">Open NextaPost</a>
<?php elseif ($done): ?>
    <h1 class="text-xl font-semibold">🎉 Installation complete</h1>
    <p class="mt-2 text-sm text-muted">One last step: add the scheduler cron job so posts publish on time.</p>
    <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm">
      <li>In cPanel open <strong>Cron Jobs</strong>.</li>
      <li>Choose <strong>Once Per Minute (* * * * *)</strong>.</li>
      <li>Paste this command and click <strong>Add New Cron Job</strong>:</li>
    </ol>
    <pre class="mt-3 overflow-x-auto rounded-xl bg-canvas p-3 text-xs"><?= $h($cron) ?></pre>
    <p class="help">If your host uses a versioned PHP path, use e.g. <code>/usr/local/bin/ea-php83</code>.</p>
    <div class="alert alert-info mt-5"><span>For extra safety you can delete the <code>public/install</code> folder now — it’s already locked.</span></div>
    <a href="<?= $h($appBase) ?>/login" class="btn btn-primary mt-5 w-full">Sign in to NextaPost</a>
<?php else: ?>
    <h1 class="text-xl font-semibold">Set up NextaPost</h1>
    <p class="mt-1 text-sm text-muted">Takes about two minutes. Create a MySQL database and user in cPanel → <strong>MySQL Databases</strong> first.</p>

    <h2 class="mt-6 text-sm font-semibold">Server check</h2>
    <ul class="mt-2 grid gap-1.5 text-sm sm:grid-cols-2">
      <?php foreach ($requirements as $r): ?>
        <li class="flex items-center gap-2"><span class="<?= $r[1] ? 'text-success' : (empty($r[2]) ? 'text-danger' : 'text-warn') ?>"><?= $r[1] ? '✓' : (empty($r[2]) ? '✕' : '!') ?></span><?= $h($r[0]) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($blocking): ?><div class="alert alert-error mt-3"><span>Enable the missing extensions in cPanel → <strong>Select PHP Version → Extensions</strong>, and make sure the folders are writable, then reload this page.</span></div><?php endif; ?>

    <?php if ($errors): ?><div class="alert alert-error mt-5" role="alert"><div><?php foreach ($errors as $err): ?><p><?= $h($err) ?></p><?php endforeach; ?></div></div><?php endif; ?>

    <form method="post" class="mt-6 space-y-6">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
      <fieldset class="space-y-4">
        <legend class="text-sm font-semibold">Site</legend>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2"><label class="label" for="app_url">Site address</label><input id="app_url" name="app_url" class="input" required value="<?= $h($old['app_url']) ?>"><p class="help">Use https:// — Facebook, Instagram, LinkedIn and X require it.</p></div>
          <div><label class="label" for="app_name">Product name</label><input id="app_name" name="app_name" class="input" value="<?= $h($old['app_name']) ?>" maxlength="60"></div>
          <div><label class="label" for="timezone">Your time zone</label>
            <select id="timezone" name="timezone" class="input"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option<?= $tz === $old['timezone'] ? ' selected' : '' ?>><?= $h($tz) ?></option><?php endforeach; ?></select></div>
        </div>
      </fieldset>
      <fieldset class="space-y-4">
        <legend class="text-sm font-semibold">Database (MySQL / MariaDB)</legend>
        <div class="grid gap-4 sm:grid-cols-3">
          <div class="sm:col-span-2"><label class="label" for="db_host">Host</label><input id="db_host" name="db_host" class="input" required value="<?= $h($old['db_host']) ?>"></div>
          <div><label class="label" for="db_port">Port</label><input id="db_port" name="db_port" type="number" class="input" required value="<?= $h($old['db_port']) ?>"></div>
          <div><label class="label" for="db_name">Database name</label><input id="db_name" name="db_name" class="input" required value="<?= $h($old['db_name']) ?>" placeholder="cpuser_nextapost" autocomplete="off"></div>
          <div><label class="label" for="db_user">Database user</label><input id="db_user" name="db_user" class="input" required value="<?= $h($old['db_user']) ?>" placeholder="cpuser_np" autocomplete="off"></div>
          <div><label class="label" for="db_pass">Password</label><input id="db_pass" name="db_pass" type="password" class="input" autocomplete="new-password"></div>
        </div>
      </fieldset>
      <fieldset class="space-y-4">
        <legend class="text-sm font-semibold">Administrator account</legend>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label class="label" for="admin_name">Your name</label><input id="admin_name" name="admin_name" class="input" required value="<?= $h($old['admin_name']) ?>" autocomplete="name"></div>
          <div><label class="label" for="company">Company / first workspace</label><input id="company" name="company" class="input" value="<?= $h($old['company']) ?>" placeholder="Nexta Web Solution"></div>
          <div><label class="label" for="admin_email">Email</label><input id="admin_email" name="admin_email" type="email" class="input" required value="<?= $h($old['admin_email']) ?>" autocomplete="email"></div>
          <div><label class="label" for="admin_password">Password</label><input id="admin_password" name="admin_password" type="password" class="input" required minlength="8" autocomplete="new-password"></div>
        </div>
      </fieldset>
      <button type="submit" class="btn btn-primary w-full" <?= $blocking ? 'disabled' : '' ?>>Install NextaPost</button>
    </form>
<?php endif; ?>
  </div>
</main>
</body>
</html>
