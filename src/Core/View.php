<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    /** Sections a page template can hand to its layout by defining $head, $scripts or $actions. */
    private const SECTIONS = ['head', 'scripts', 'actions'];
    private static array $sections = [];

    public static function render(string $template, array $data = [], ?string $layout = 'app'): string
    {
        self::$sections = [];
        $content = self::partial($template, $data, true);
        if ($layout === null) {
            return $content;
        }
        return self::partial('layouts/' . $layout, self::$sections + ['content' => $content] + $data);
    }

    public static function partial(string $template, array $data = [], bool $collectSections = false): string
    {
        $__file = APP_ROOT . '/views/' . $template . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        if ($collectSections) {
            foreach (self::SECTIONS as $__k) {
                if (isset($$__k) && is_string($$__k) && !array_key_exists($__k, $data)) {
                    self::$sections[$__k] = $$__k;
                }
            }
        }
        return (string) ob_get_clean();
    }
}
