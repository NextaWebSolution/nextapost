<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (!is_file(APP_ROOT . '/storage/installed.lock') || !is_file(APP_ROOT . '/.env')) {
    header('Location: ' . url('/install/'));
    exit;
}

if (is_file(APP_ROOT . '/storage/maintenance.flag')) {
    http_response_code(503);
    header('Retry-After: 60');
    readfile(APP_ROOT . '/views/errors/maintenance.html');
    exit;
}

App\Core\App::boot(APP_ROOT)->run();
