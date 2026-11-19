<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Env;

final class MediaService
{
    public const TYPES = [
        'image/jpeg' => ['jpg', 'image'],
        'image/png' => ['png', 'image'],
        'image/gif' => ['gif', 'image'],
        'image/webp' => ['webp', 'image'],
        'video/mp4' => ['mp4', 'video'],
        'video/quicktime' => ['mov', 'video'],
    ];
    /** Documents never go in the public folder: they're served only through an authenticated download. */
    public const DOC_MAX_BYTES = 100 * 1024 * 1024;
    public const DOC_ICONS = [DocumentInspector::PDF => 'doc-pdf.svg', DocumentInspector::PPTX => 'doc-pptx.svg', DocumentInspector::DOCX => 'doc-docx.svg'];

    public function __construct(private DB $db)
    {
    }

    public static function maxBytes(): int
    {
        $mb = (int) Env::get('MAX_UPLOAD_MB', 100);
        return max(1, $mb) * 1024 * 1024;
    }

    /** @throws \InvalidArgumentException with a user-facing message */
    public function upload(array $ws, int $userId, array $file, bool $trustedLocal = false): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than your server allows (' . ini_get('upload_max_filesize') . '). Raise upload_max_filesize in cPanel → MultiPHP INI Editor.',
                UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
                UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
                default => 'The server couldn’t save the upload (error ' . $err . ').',
            });
        }
        $tmp = (string) $file['tmp_name'];
        if (!$trustedLocal && !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Invalid upload.');
        }
        $size = (int) filesize($tmp);
        if ($size > self::maxBytes()) {
            throw new \InvalidArgumentException('Files can be up to ' . human_bytes(self::maxBytes()) . '.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $pages = null;
        if (isset(self::TYPES[$mime])) {
            [$ext, $type] = self::TYPES[$mime];
        } elseif (in_array($mime, ['application/pdf', 'application/zip', 'application/octet-stream', 'application/x-zip-compressed', DocumentInspector::PPTX, DocumentInspector::DOCX, 'application/vnd.ms-powerpoint', 'application/msword', 'application/CDFV2', 'application/x-ole-storage'], true)) {
            if ($size > self::DOC_MAX_BYTES) {
                throw new \InvalidArgumentException('Documents can be up to 100 MB (LinkedIn’s limit).');
            }
            $doc = DocumentInspector::inspect($tmp, (string) ($file['name'] ?? ''));
            [$mime, $ext, $type, $pages] = [$doc['mime'], $doc['ext'], 'document', $doc['pages']];
            if ($pages !== null && $pages > 300) {
                throw new \InvalidArgumentException('Documents can have up to 300 pages (LinkedIn’s limit). This one has ' . $pages . '.');
            }
        } else {
            throw new \InvalidArgumentException('Upload JPG, PNG, GIF, WebP images, MP4/MOV videos, or PDF, PPTX and DOCX documents.');
        }
        $width = $height = null;
        if ($type === 'image') {
            $info = @getimagesize($tmp);
            if (!$info) {
                throw new \InvalidArgumentException('That image appears to be damaged.');
            }
            [$width, $height] = $info;
            if ($width < 1 || $height < 1 || $width > 12000 || $height > 12000 || $width * $height > 50_000_000) {
                throw new \InvalidArgumentException('That image is too large in pixels (max 12000×12000, 50 megapixels).');
            }
        }
        $quota = max(1, (int) Env::get('MAX_WORKSPACE_STORAGE_MB', 10240)) * 1024 * 1024;
        $used = (int) $this->db->value('SELECT COALESCE(SUM(size_bytes), 0) FROM media WHERE workspace_id = ?', [$ws['id']]);
        if ($used + $size > $quota) {
            throw new \InvalidArgumentException('This workspace has used its storage allowance (' . human_bytes($quota) . '). Delete old files or turn on automatic clean-up in Team & settings.');
        }
        $rel = ($type === 'document' ? 'documents/' : 'media/') . $ws['uuid'] . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        $abs = self::absPath(['type' => $type, 'path' => $rel]);
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0755, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('Could not create the upload folder. Check that public/media and storage are writable.');
        }
        // Updates never touch storage/, so add the folder guard here for sites upgraded from 1.0.x.
        $guard = APP_ROOT . '/storage/documents/.htaccess';
        if ($type === 'document' && !is_file($guard)) {
            @file_put_contents($guard, "Require all denied\n");
        }
        $moved = $trustedLocal ? copy($tmp, $abs) : move_uploaded_file($tmp, $abs);
        if (!$moved) {
            throw new \RuntimeException('Could not save the file. Check that public/media is writable.');
        }
        @chmod($abs, $type === 'document' ? 0600 : 0644);
        if ($type === 'image') {
            ImageTool::thumbnail($abs, self::thumbPath($abs));
        }
        $original = mb_substr(basename((string) ($file['name'] ?? 'upload.' . $ext)), 0, 200);
        $id = $this->db->insert('media', [
            'uuid' => uuid4(), 'workspace_id' => $ws['id'], 'uploader_id' => $userId, 'type' => $type, 'mime' => $mime,
            'path' => $rel, 'original_name' => $original, 'size_bytes' => $size, 'width' => $width, 'height' => $height,
            'pages' => $pages, 'created_at' => now_utc(),
        ]);
        return (array) $this->db->one('SELECT * FROM media WHERE id = ?', [$id]);
    }

    /** Absolute file path: images/videos live in public/media, documents in storage/documents (not web-reachable). */
    public static function absPath(array $m): string
    {
        return ($m['type'] === 'document' ? APP_ROOT . '/storage/' : APP_ROOT . '/public/') . $m['path'];
    }

    private static function wsUuid(int $wsId): string
    {
        static $map = [];
        return $map[$wsId] ??= (string) app()->db->value('SELECT uuid FROM workspaces WHERE id = ?', [$wsId]);
    }

    /** Title shown on LinkedIn for a document: its alt text if set, else the file name without extension. */
    public static function documentTitle(array $m): string
    {
        $t = trim((string) ($m['alt_text'] ?? ''));
        if ($t === '') {
            $t = (string) preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', (string) ($m['original_name'] ?? 'Document'));
            $t = trim((string) preg_replace('/[_-]+/', ' ', $t));
        }
        return mb_substr($t !== '' ? $t : 'Document', 0, 400);
    }

    public static function thumbPath(string $abs): string
    {
        return (string) preg_replace('/\.\w+$/', '', $abs) . '.thumb.jpg';
    }

    public static function toJson(array $m): array
    {
        if ($m['type'] === 'document') {
            $file = url('/w/' . self::wsUuid((int) $m['workspace_id']) . '/media/' . $m['uuid'] . '/file');
            return [
                'id' => $m['uuid'], 'type' => 'document', 'mime' => $m['mime'], 'name' => $m['original_name'],
                'url' => $file, 'thumb' => asset('img/' . (self::DOC_ICONS[$m['mime']] ?? 'doc-pdf.svg')),
                'width' => null, 'height' => null, 'pages' => $m['pages'] !== null ? (int) $m['pages'] : null,
                'kind' => strtoupper((string) pathinfo((string) $m['path'], PATHINFO_EXTENSION)),
                'size' => (int) $m['size_bytes'], 'size_h' => human_bytes((int) $m['size_bytes']), 'alt' => $m['alt_text'] ?? '',
                'title' => self::documentTitle($m),
            ];
        }
        $abs = APP_ROOT . '/public/' . $m['path'];
        $thumb = self::thumbPath($abs);
        $thumbRel = (string) preg_replace('/\.\w+$/', '', (string) $m['path']) . '.thumb.jpg';
        return [
            'id' => $m['uuid'], 'type' => $m['type'], 'mime' => $m['mime'], 'name' => $m['original_name'],
            'url' => url($m['path']), 'thumb' => is_file($thumb) ? url($thumbRel) : url($m['path']),
            'width' => $m['width'] !== null ? (int) $m['width'] : null, 'height' => $m['height'] !== null ? (int) $m['height'] : null,
            'size' => (int) $m['size_bytes'], 'size_h' => human_bytes((int) $m['size_bytes']), 'alt' => $m['alt_text'] ?? '',
        ];
    }

    /** Media item shape for providers. */
    public static function toItem(array $m): array
    {
        return [
            'path' => self::absPath($m), 'url' => $m['type'] === 'document' ? null : abs_url($m['path']), 'mime' => $m['mime'], 'type' => $m['type'],
            'size' => (int) $m['size_bytes'], 'alt' => $m['alt_text'] ?? null,
            'name' => $m['original_name'] ?? null, 'pages' => isset($m['pages']) && $m['pages'] !== null ? (int) $m['pages'] : null,
            'title' => $m['type'] === 'document' ? self::documentTitle($m) : null,
            'width' => $m['width'] !== null ? (int) $m['width'] : null, 'height' => $m['height'] !== null ? (int) $m['height'] : null,
        ];
    }

    public function delete(array $media): void
    {
        $abs = self::absPath($media);
        $root = $media['type'] === 'document' ? realpath(APP_ROOT . '/storage/documents') : realpath(APP_ROOT . '/public/media');
        $this->db->run('DELETE FROM media WHERE id = ?', [$media['id']]);
        foreach ([$abs, self::thumbPath($abs), (string) preg_replace('/\.\w+$/', '', $abs) . '.ig.jpg'] as $f) {
            if ($root && is_file($f) && str_starts_with(realpath($f) ?: '', $root . DIRECTORY_SEPARATOR)) {
                @unlink($f);
            }
        }
    }

    public function inUseByPendingPosts(int $mediaId): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM post_media pm JOIN posts p ON p.id = pm.post_id
             WHERE pm.media_id = ? AND p.deleted_at IS NULL AND p.status IN ('draft','in_review','scheduled','publishing')",
            [$mediaId]
        ) + (int) $this->db->value(
            "SELECT COUNT(*) FROM target_media tm JOIN post_targets t ON t.id = tm.target_id JOIN posts p ON p.id = t.post_id
             WHERE tm.media_id = ? AND t.media_custom = 1 AND p.deleted_at IS NULL AND p.status IN ('draft','in_review','scheduled','publishing')",
            [$mediaId]
        );
    }
}
