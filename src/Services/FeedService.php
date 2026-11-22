<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Http;
use App\Core\Logger;

/**
 * RSS 2.0, Atom and JSON Feed subscriptions. New items land in an inbox (or become ideas automatically);
 * from there they turn into ideas or posts. Feeds are checked about every 30 minutes by the cron worker.
 */
final class FeedService
{
    public const MAX_FEEDS = 30;
    public const INTERVAL = 1800;
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private DB $db)
    {
    }

    public function feeds(int $wsId): array
    {
        return $this->db->all(
            "SELECT f.*, (SELECT COUNT(*) FROM rss_items i WHERE i.feed_id = f.id AND i.idea_id IS NULL AND i.dismissed_at IS NULL) AS unread
             FROM rss_feeds f WHERE f.workspace_id = ? ORDER BY f.title, f.url",
            [$wsId]
        );
    }

    public function feed(int $wsId, string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM rss_feeds WHERE workspace_id = ? AND uuid = ?', [$wsId, $uuid]);
    }

    /** @return array{0:?array,1:?string} [feed, error] */
    public function add(array $ws, ?array $user, string $url, string $autoMode = 'inbox'): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (mb_strlen($url) > 700 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return [null, __('Enter the address of the feed or website.')];
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM rss_feeds WHERE workspace_id = ?', [$ws['id']]) >= self::MAX_FEEDS) {
            return [null, __('A workspace can follow up to {n} feeds.', ['n' => self::MAX_FEEDS])];
        }
        try {
            [$resp, $parsed, $url] = $this->download($url, true);
        } catch (\Throwable $e) {
            return [null, __('Couldn’t read that address: {reason}', ['reason' => mb_substr($e->getMessage(), 0, 200)])];
        }
        if ($parsed === null) {
            return [null, __('No RSS, Atom or JSON feed was found at that address.')];
        }
        if ($this->db->value('SELECT 1 FROM rss_feeds WHERE workspace_id = ? AND url = ?', [$ws['id'], $url])) {
            return [null, __('You already follow this feed.')];
        }
        $id = $this->db->insert('rss_feeds', [
            'uuid' => uuid4(), 'workspace_id' => $ws['id'], 'url' => $url, 'title' => mb_substr($parsed['title'] ?: (string) parse_url($url, PHP_URL_HOST), 0, 200),
            'format' => $parsed['format'], 'etag' => mb_substr((string) ($resp['headers']['etag'] ?? ''), 0, 200) ?: null,
            'last_modified' => mb_substr((string) ($resp['headers']['last-modified'] ?? ''), 0, 100) ?: null,
            'last_fetched_at' => now_utc(), 'next_fetch_at' => now_utc(self::INTERVAL), 'auto_mode' => $autoMode === 'idea' ? 'idea' : 'inbox', 'created_at' => now_utc(),
        ]);
        $feed = $this->db->one('SELECT * FROM rss_feeds WHERE id = ?', [$id]);
        // Only the newest few on the first read, so the inbox isn't flooded.
        $this->store($ws, $feed, array_slice($parsed['items'], 0, 10), false);
        return [$feed, null];
    }

    public function setMode(array $feed, string $mode): void
    {
        $this->db->update('rss_feeds', ['auto_mode' => $mode === 'idea' ? 'idea' : 'inbox'], 'id = ?', [$feed['id']]);
    }

    public function delete(array $feed): void
    {
        $this->db->run('DELETE FROM rss_feeds WHERE id = ?', [$feed['id']]);
    }

    /**
     * Fetch a URL; if it's an HTML page, follow its advertised feed link.
     * @return array{0:array,1:?array,2:string} [response, parsed feed or null, final feed URL]
     */
    private function download(string $url, bool $discover, array $headers = []): array
    {
        $resp = Http::request('GET', $url, ['public_only' => true, 'timeout' => 20, 'max_bytes' => self::MAX_BYTES,
            'headers' => array_merge(['Accept: application/rss+xml, application/atom+xml, application/feed+json, application/json, application/xml;q=0.9, text/html;q=0.8'], $headers)]);
        if ($resp['status'] === 304) {
            return [$resp, null, $url];
        }
        if ($resp['status'] >= 300 && $resp['status'] < 400 && !empty($resp['headers']['location']) && $discover) {
            $next = self::absolute((string) $resp['headers']['location'], $url);
            return $this->download($next, false, $headers);
        }
        if ($resp['status'] !== 200) {
            throw new \RuntimeException('HTTP ' . $resp['status']);
        }
        $parsed = self::parse((string) $resp['body']);
        if ($parsed === null && $discover && preg_match_all('#<link\b[^>]*>#i', (string) $resp['body'], $m)) {
            foreach ($m[0] as $tag) {
                if (preg_match('#rel=["\']?alternate#i', $tag) && preg_match('#type=["\']?application/(rss\+xml|atom\+xml|feed\+json)#i', $tag) && preg_match('#href=["\']([^"\']+)#i', $tag, $h)) {
                    return $this->download(self::absolute(html_entity_decode($h[1], ENT_QUOTES), $url), false, $headers);
                }
            }
        }
        return [$resp, $parsed, $url];
    }

    private static function absolute(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return ($b['scheme'] ?? 'https') . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        return $origin . rtrim(dirname($b['path'] ?? '/'), '/') . '/' . $href;
    }

    private static function text(?string $html, int $max): string
    {
        $t = html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#is', '', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $t)), 0, $max);
    }

    private static function date(?string $s): ?string
    {
        if ($s === null || trim($s) === '') {
            return null;
        }
        $ts = strtotime(trim($s));
        return $ts ? gmdate('Y-m-d H:i:s', min($ts, time())) : null;
    }

    private static function safeUrl(?string $u): ?string
    {
        $u = trim((string) $u);
        return preg_match('#^https?://\S+$#i', $u) && mb_strlen($u) <= 700 ? $u : null;
    }

    /**
     * Parse RSS 2.0 / RSS 1.0 / Atom / JSON Feed. External entities and network access are never used.
     * @return array{format:string,title:string,items:list<array{guid:string,title:string,link:?string,summary:string,image_url:?string,published_at:?string}>}|null
     */
    public static function parse(string $body): ?array
    {
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if ($body === '') {
            return null;
        }
        if ($body[0] === '{') {
            $j = json_decode($body, true);
            if (!is_array($j) || !isset($j['items']) || !is_array($j['items']) || !str_contains((string) ($j['version'] ?? ''), 'jsonfeed')) {
                return null;
            }
            $items = [];
            foreach ($j['items'] as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $link = self::safeUrl($it['url'] ?? $it['external_url'] ?? null);
                $items[] = [
                    'guid' => (string) ($it['id'] ?? $link ?? md5(json_encode($it))), 'title' => self::text($it['title'] ?? '', 300), 'link' => $link,
                    'summary' => self::text($it['summary'] ?? $it['content_text'] ?? $it['content_html'] ?? '', 1000),
                    'image_url' => self::safeUrl($it['image'] ?? $it['banner_image'] ?? null), 'published_at' => self::date($it['date_published'] ?? $it['date_modified'] ?? null),
                ];
            }
            return ['format' => 'json', 'title' => self::text($j['title'] ?? '', 200), 'items' => $items];
        }
        if (!str_starts_with($body, '<')) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$xml) {
            return null;
        }
        $name = strtolower($xml->getName());
        $items = [];
        if ($name === 'feed') {
            foreach ($xml->entry as $e) {
                $link = null;
                foreach ($e->link as $l) {
                    $rel = (string) ($l['rel'] ?? 'alternate');
                    if ($rel === 'alternate' || $link === null) {
                        $link = self::safeUrl((string) $l['href']);
                    }
                }
                $media = $e->children('http://search.yahoo.com/mrss/');
                $img = isset($media->thumbnail) ? (string) $media->thumbnail->attributes()['url'] : (isset($media->content) ? (string) $media->content->attributes()['url'] : null);
                $items[] = [
                    'guid' => (string) ($e->id ?: $link), 'title' => self::text((string) $e->title, 300), 'link' => $link,
                    'summary' => self::text((string) ($e->summary ?: $e->content), 1000), 'image_url' => self::safeUrl($img),
                    'published_at' => self::date((string) ($e->published ?: $e->updated)),
                ];
            }
            return ['format' => 'atom', 'title' => self::text((string) $xml->title, 200), 'items' => $items];
        }
        if ($name === 'rss' || $name === 'rdf') {
            $channel = $name === 'rss' ? $xml->channel : $xml;
            $list = $name === 'rss' ? $channel->item : $xml->children('http://purl.org/rss/1.0/')->item;
            $title = $name === 'rss' ? (string) $channel->title : (string) $xml->children('http://purl.org/rss/1.0/')->channel->title;
            foreach ($list as $it) {
                if ($name === 'rdf') {
                    $it = $it->children('http://purl.org/rss/1.0/');
                }
                $link = self::safeUrl((string) $it->link);
                $content = $it->children('http://purl.org/rss/1.0/modules/content/');
                $media = $it->children('http://search.yahoo.com/mrss/');
                $img = null;
                if (isset($it->enclosure) && str_starts_with((string) $it->enclosure['type'], 'image/')) {
                    $img = (string) $it->enclosure['url'];
                } elseif (isset($media->content) && (string) $media->content->attributes()['url'] !== '') {
                    $img = (string) $media->content->attributes()['url'];
                } elseif (isset($media->thumbnail)) {
                    $img = (string) $media->thumbnail->attributes()['url'];
                }
                $dc = $it->children('http://purl.org/dc/elements/1.1/');
                $items[] = [
                    'guid' => (string) ($it->guid ?: $link ?: $it->title), 'title' => self::text((string) $it->title, 300), 'link' => $link,
                    'summary' => self::text((string) ($it->description ?: ($content->encoded ?? '')), 1000), 'image_url' => self::safeUrl($img),
                    'published_at' => self::date((string) ($it->pubDate ?: ($dc->date ?? ''))),
                ];
            }
            return ['format' => 'rss', 'title' => self::text($title, 200), 'items' => $items];
        }
        return null;
    }

    /** Save new items; with auto mode "idea", each new item also becomes an idea. @return int new items */
    private function store(array $ws, array $feed, array $items, bool $auto = true): int
    {
        $new = 0;
        foreach (array_slice($items, 0, 50) as $it) {
            $hash = hash('sha256', $it['guid'] !== '' ? $it['guid'] : ($it['link'] ?? $it['title']));
            if ($this->db->value('SELECT 1 FROM rss_items WHERE feed_id = ? AND guid_hash = ?', [$feed['id'], $hash])) {
                continue;
            }
            $id = $this->db->insert('rss_items', [
                'uuid' => uuid4(), 'feed_id' => $feed['id'], 'guid_hash' => $hash, 'title' => $it['title'] ?: null, 'link' => $it['link'],
                'summary' => $it['summary'] ?: null, 'image_url' => $it['image_url'], 'published_at' => $it['published_at'], 'created_at' => now_utc(),
            ]);
            $new++;
            if ($auto && ($feed['auto_mode'] ?? 'inbox') === 'idea') {
                $this->toIdea($ws, null, (array) $this->db->one('SELECT * FROM rss_items WHERE id = ?', [$id]));
            }
        }
        return $new;
    }

    /** Check one feed now. @return int new items */
    public function fetch(array $feed): int
    {
        $ws = $this->db->one('SELECT * FROM workspaces WHERE id = ? AND deleted_at IS NULL', [$feed['workspace_id']]);
        if (!$ws) {
            return 0;
        }
        $headers = [];
        if ($feed['etag']) {
            $headers[] = 'If-None-Match: ' . $feed['etag'];
        }
        if ($feed['last_modified']) {
            $headers[] = 'If-Modified-Since: ' . $feed['last_modified'];
        }
        try {
            [$resp, $parsed] = $this->download((string) $feed['url'], false, $headers);
            if ($resp['status'] !== 304 && $parsed === null) {
                throw new \RuntimeException(__('The address no longer returns a feed.'));
            }
            $new = $parsed ? $this->store($ws, $feed, $parsed['items']) : 0;
            $this->db->update('rss_feeds', [
                'last_fetched_at' => now_utc(), 'next_fetch_at' => now_utc(self::INTERVAL), 'last_error' => null, 'fail_count' => 0,
                'etag' => mb_substr((string) ($resp['headers']['etag'] ?? $feed['etag'] ?? ''), 0, 200) ?: null,
                'last_modified' => mb_substr((string) ($resp['headers']['last-modified'] ?? $feed['last_modified'] ?? ''), 0, 100) ?: null,
            ] + ($parsed && $parsed['title'] && !$feed['title'] ? ['title' => mb_substr($parsed['title'], 0, 200)] : []), 'id = ?', [$feed['id']]);
            return $new;
        } catch (\Throwable $e) {
            $fails = (int) $feed['fail_count'] + 1;
            // Back off: 30 min, 1 h, 2 h … up to a day.
            $this->db->update('rss_feeds', ['last_fetched_at' => now_utc(), 'fail_count' => $fails, 'last_error' => mb_substr($e->getMessage(), 0, 500),
                'next_fetch_at' => now_utc(min(86400, self::INTERVAL * 2 ** min(6, $fails - 1)))], 'id = ?', [$feed['id']]);
            return 0;
        }
    }

    /** Called by the cron worker. */
    public function fetchDue(int $limit = 5): int
    {
        $n = 0;
        foreach ($this->db->all('SELECT * FROM rss_feeds WHERE next_fetch_at IS NULL OR next_fetch_at <= ? ORDER BY next_fetch_at LIMIT ' . max(1, $limit), [now_utc()]) as $f) {
            try {
                $n += $this->fetch($f);
            } catch (\Throwable $e) {
                Logger::write('warning', 'Feed fetch failed: ' . $e->getMessage(), ['feed' => $f['uuid']]);
            }
        }
        return $n;
    }

    /** Inbox: items not yet turned into ideas and not dismissed. */
    public function inbox(int $wsId, ?int $feedId = null, int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT i.*, f.uuid AS feed_uuid, f.title AS feed_title FROM rss_items i JOIN rss_feeds f ON f.id = i.feed_id
                WHERE f.workspace_id = ? AND i.idea_id IS NULL AND i.dismissed_at IS NULL';
        $args = [$wsId];
        if ($feedId !== null) {
            $sql .= ' AND f.id = ?';
            $args[] = $feedId;
        }
        return $this->db->all($sql . ' ORDER BY COALESCE(i.published_at, i.created_at) DESC, i.id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset), $args);
    }

    public function item(int $wsId, string $uuid): ?array
    {
        return $this->db->one('SELECT i.*, f.workspace_id FROM rss_items i JOIN rss_feeds f ON f.id = i.feed_id WHERE f.workspace_id = ? AND i.uuid = ?', [$wsId, $uuid]);
    }

    public function dismiss(array $item): void
    {
        $this->db->update('rss_items', ['dismissed_at' => now_utc()], 'id = ?', [$item['id']]);
    }

    /** @return ?array the idea */
    public function toIdea(array $ws, ?array $user, array $item): ?array
    {
        if ($item['idea_id']) {
            return (new IdeaService($this->db))->get((int) $ws['id'], (int) $item['idea_id']);
        }
        [$idea] = (new IdeaService($this->db))->save($ws, $user ?? [], [
            'title' => mb_substr((string) ($item['title'] ?: __('From your feed')), 0, 200), 'body' => (string) $item['summary'], 'link' => (string) $item['link'], 'source' => 'rss',
        ]);
        if ($idea) {
            $this->db->update('rss_items', ['idea_id' => $idea['id']], 'id = ?', [$item['id']]);
        }
        return $idea;
    }

    public static function presentFeed(array $f): array
    {
        return [
            'id' => $f['uuid'], 'url' => $f['url'], 'title' => $f['title'], 'format' => $f['format'], 'auto' => $f['auto_mode'] ?? 'inbox',
            'unread' => (int) ($f['unread'] ?? 0), 'last_checked_at' => Presenter::iso($f['last_fetched_at']), 'error' => $f['last_error'],
        ];
    }

    public static function presentItem(array $i): array
    {
        return [
            'id' => $i['uuid'], 'title' => $i['title'], 'link' => $i['link'], 'summary' => $i['summary'], 'image_url' => $i['image_url'],
            'published_at' => Presenter::iso($i['published_at']), 'feed' => isset($i['feed_uuid']) ? ['id' => $i['feed_uuid'], 'title' => $i['feed_title']] : null,
        ];
    }
}
