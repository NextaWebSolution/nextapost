<?php

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_VERSION')) {
    $v = is_file(APP_ROOT . '/VERSION') ? trim((string) file_get_contents(APP_ROOT . '/VERSION')) : '';
    define('APP_VERSION', $v !== '' ? $v : '1.0.0');
}
if (!defined('APP_PRODUCT')) {
    define('APP_PRODUCT', 'nextapost');
}

require __DIR__ . '/autoload.php';
