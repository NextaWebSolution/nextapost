<?php

declare(strict_types=1);

namespace App\Services;

/** Small GD helpers used when a network needs a different image format or size. */
final class ImageTool
{
    private static function load(string $path): ?\GdImage
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $data = @file_get_contents($path);
        $img = $data !== false ? @imagecreatefromstring($data) : false;
        return $img instanceof \GdImage ? $img : null;
    }

    private static function flatten(\GdImage $img): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255));
        imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
        return $out;
    }

    private static function scale(\GdImage $img, int $maxDim): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if (max($w, $h) <= $maxDim) {
            return $img;
        }
        $r = $maxDim / max($w, $h);
        $nw = max(1, (int) round($w * $r));
        $nh = max(1, (int) round($h * $r));
        $out = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $out;
    }

    /** Returns $path unchanged if already small enough, else a temporary JPEG under the size limit. */
    public static function shrinkToBytes(string $path, int $maxBytes, int $maxDim = 2000): string
    {
        if ((int) @filesize($path) <= $maxBytes) {
            return $path;
        }
        $img = self::load($path);
        if (!$img) {
            return $path;
        }
        $img = self::flatten(self::scale($img, $maxDim));
        $tmp = APP_ROOT . '/storage/tmp/img_' . bin2hex(random_bytes(6)) . '.jpg';
        foreach ([85, 75, 65, 55, 45] as $q) {
            imagejpeg($img, $tmp, $q);
            clearstatcache(true, $tmp);
            if ((int) filesize($tmp) <= $maxBytes) {
                return $tmp;
            }
            $img = self::scale($img, (int) (max(imagesx($img), imagesy($img)) * 0.8));
        }
        return $tmp;
    }

    /**
     * Creates (once) a public JPEG sibling for a PNG/WebP/GIF so networks that only accept JPEG
     * (Instagram) can fetch it. Returns the public URL of the JPEG.
     */
    public static function publicJpegCopy(string $path, string $url): string
    {
        $jpgPath = (string) preg_replace('/\.\w+$/', '', $path) . '.ig.jpg';
        $jpgUrl = (string) preg_replace('/\.\w+$/', '', $url) . '.ig.jpg';
        if (!is_file($jpgPath)) {
            $img = self::load($path);
            if (!$img) {
                return $url;
            }
            imagejpeg(self::flatten(self::scale($img, 1440)), $jpgPath, 90);
        }
        return $jpgUrl;
    }

    /** Thumbnail for the media library grid. Returns true on success. */
    public static function thumbnail(string $src, string $dest, int $maxDim = 480): bool
    {
        $img = self::load($src);
        if (!$img) {
            return false;
        }
        $hasAlpha = in_array(@getimagesize($src)['mime'] ?? '', ['image/png', 'image/webp', 'image/gif'], true);
        $img = self::scale($img, $maxDim);
        if ($hasAlpha) {
            $img = self::flatten($img);
        }
        return imagejpeg($img, $dest, 80);
    }
}
