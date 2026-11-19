<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function error(string|\Throwable $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function write(string $level, string|\Throwable $message, array $context = []): void
    {
        if ($message instanceof \Throwable) {
            $e = $message;
            $message = get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
        }
        $line = sprintf("[%s] %s: %s%s\n", gmdate('Y-m-d H:i:s'), strtoupper($level), $message, $context ? ' ' . json_encode($context) : '');
        $dir = APP_ROOT . '/storage/logs';
        if (is_dir($dir)) {
            @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        }
    }

    public static function prune(int $days = 14): void
    {
        foreach (glob(APP_ROOT . '/storage/logs/*.log') ?: [] as $f) {
            if (filemtime($f) < time() - $days * 86400) {
                @unlink($f);
            }
        }
    }
}
