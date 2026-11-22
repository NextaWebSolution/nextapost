<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Http;

/**
 * WordPress (self-hosted, 5.6+) through the REST API with an Application Password.
 * The first image becomes the featured image; other files are added to the post body.
 */
final class WordPressProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'WordPress', 'color' => '#21759B', 'letter' => 'W', 'kind' => 'token',
            'description' => 'Publish blog posts to your WordPress site (version 5.6 or newer) using an application password.',
            'content_types' => ['post' => __('Blog post')],
        ];
    }

    public function fields(): array
    {
        return [
            ['name' => 'site_url', 'label' => __('Site address'), 'type' => 'url', 'placeholder' => 'https://example.com', 'help' => __('The address of your WordPress site, starting with https://.')],
            ['name' => 'username', 'label' => __('WordPress username'), 'type' => 'text', 'placeholder' => '', 'help' => __('The user that will be shown as the author. It needs to be able to publish posts.')],
            ['name' => 'app_password', 'label' => __('Application password'), 'type' => 'password', 'placeholder' => 'xxxx xxxx xxxx xxxx xxxx xxxx', 'help' => __('In WordPress: Users → Profile → Application Passwords → add one named “NextaPost” and paste it here. This is not your login password.')],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 100000, 'media_max' => 20, 'types' => ['image', 'video'], 'max_bytes' => ['image' => 20 * 1024 * 1024, 'video' => 256 * 1024 * 1024]];
    }

    public function options(array $meta = []): array
    {
        $cats = [];
        foreach ((array) ($meta['categories'] ?? []) as $c) {
            $cats[(string) $c['id']] = (string) $c['name'];
        }
        return [
            ['key' => 'title', 'label' => __('Title'), 'type' => 'text', 'max' => 200, 'help' => __('Leave empty to use the first line of the text.')],
            ['key' => 'status', 'label' => __('Publish as'), 'type' => 'select', 'required' => true, 'default' => 'publish', 'choices' => ['publish' => __('Published'), 'draft' => __('Draft in WordPress'), 'pending' => __('Pending review in WordPress')]],
            ['key' => 'category', 'label' => __('Category'), 'type' => 'select', 'choices' => $cats, 'dynamic' => true, 'empty' => __('Default category')],
            ['key' => 'tags', 'label' => __('Tags'), 'type' => 'text', 'max' => 500, 'placeholder' => 'news, launch', 'help' => __('Separate tags with commas.')],
            ['key' => 'excerpt', 'label' => __('Excerpt'), 'type' => 'textarea', 'max' => 1000],
        ];
    }

    private static function base(string $url): string
    {
        $url = rtrim(trim($url), '/');
        return preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
    }

    private static function auth(array $c): array
    {
        return ['Authorization: Basic ' . base64_encode($c['username'] . ':' . preg_replace('/\s+/', '', (string) $c['app_password']))];
    }

    private function api(array $c, string $method, string $path, array $opts = []): array
    {
        $opts['headers'] = array_merge(self::auth($c), $opts['headers'] ?? []);
        $opts['public_only'] = true;
        $resp = $this->http($method, $c['site_url'] . '/wp-json/wp/v2/' . ltrim($path, '/'), $opts);
        if ($resp['status'] === 401 || $resp['status'] === 403) {
            $code = (string) ($resp['json']['code'] ?? '');
            throw new ProviderException('WordPress: ' . (string) ($resp['json']['message'] ?? 'access denied') . ($code ? ' (' . $code . ')' : ''), false, $resp['status'] === 401);
        }
        return $this->ok($resp);
    }

    public function connect(array $input): array
    {
        $this->require($input, 'site_url', 'username', 'app_password');
        $base = self::base(str_val($input['site_url']));
        if (!str_starts_with($base, 'https://')) {
            throw new ProviderException(__('Use the https:// address of your site. WordPress only allows application passwords over https.'));
        }
        Http::assertPublicUrl($base);
        $c = ['site_url' => $base, 'username' => trim(str_val($input['username'])), 'app_password' => trim(str_val($input['app_password']))];
        try {
            $me = $this->api($c, 'GET', 'users/me?context=edit');
        } catch (ProviderException $e) {
            throw new ProviderException(__('WordPress didn’t accept that username and application password, or the REST API is blocked by a security plugin. ({reason})', ['reason' => $e->getMessage()]));
        }
        if (isset($me['capabilities']) && empty($me['capabilities']['publish_posts'])) {
            throw new ProviderException(__('This WordPress user can’t publish posts. Use an Author, Editor or Administrator account.'));
        }
        $site = '';
        try {
            $info = $this->http('GET', $base . '/wp-json/', ['public_only' => true, 'timeout' => 15]);
            $site = (string) ($info['json']['name'] ?? '');
        } catch (\Throwable) {
        }
        $cats = [];
        try {
            foreach ($this->api($c, 'GET', 'categories?per_page=100&_fields=id,name') as $cat) {
                $cats[] = ['id' => (int) $cat['id'], 'name' => html_entity_decode((string) $cat['name'], ENT_QUOTES)];
            }
        } catch (\Throwable) {
        }
        $host = (string) parse_url($base, PHP_URL_HOST);
        return [
            'external_id' => $host . '#' . (int) $me['id'],
            'name' => $site !== '' ? html_entity_decode($site, ENT_QUOTES) : $host,
            'username' => (string) ($me['name'] ?? $c['username']),
            'avatar_url' => $me['avatar_urls']['96'] ?? null,
            'credentials' => $c,
            'meta' => ['site_url' => $base, 'categories' => $cats],
        ];
    }

    /** Plain text → simple HTML: paragraphs, line breaks and clickable links. */
    public static function toHtml(string $text): string
    {
        $out = [];
        foreach (preg_split("/\n{2,}/", trim($text)) ?: [] as $para) {
            $h = htmlspecialchars($para, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $h = (string) preg_replace('#(https?://[^\s<]+[^\s<.,;:!?)\]])#i', '<a href="$1">$1</a>', $h);
            $out[] = '<p>' . nl2br($h, false) . '</p>';
        }
        return implode("\n\n", $out);
    }

    /** @return array{0:string,1:string} [title, body] */
    public static function titleAndBody(string $text, ?string $title): array
    {
        $text = trim($text);
        if ($title !== null && trim($title) !== '') {
            return [trim($title), $text];
        }
        $lines = preg_split("/\n/", $text, 2) ?: [''];
        $first = trim((string) $lines[0]);
        if (isset($lines[1]) && mb_strlen($first) <= 200) {
            return [$first, trim($lines[1])];
        }
        return [str_excerpt($text, 80) ?: 'Untitled', $text];
    }

    private function upload(array $c, array $m): array
    {
        $name = preg_replace('/[^\w.\-]+/', '-', (string) ($m['name'] ?? basename($m['path']))) ?: 'upload';
        $res = $this->api($c, 'POST', 'media', [
            'infile' => $m['path'], 'timeout' => 300,
            'headers' => ['Content-Type: ' . $m['mime'], 'Content-Disposition: attachment; filename="' . $name . '"'],
        ]);
        if (!empty($m['alt'])) {
            try {
                $this->api($c, 'POST', 'media/' . (int) $res['id'], ['json' => ['alt_text' => (string) $m['alt']]]);
            } catch (\Throwable) {
            }
        }
        return $res;
    }

    private function tagIds(array $c, string $tags): array
    {
        $ids = [];
        foreach (array_slice(array_filter(array_map('trim', explode(',', $tags))), 0, 20) as $tag) {
            $found = $this->api($c, 'GET', 'tags?per_page=20&search=' . rawurlencode($tag) . '&_fields=id,name');
            $id = null;
            foreach ($found as $t) {
                if (mb_strtolower(html_entity_decode((string) $t['name'], ENT_QUOTES)) === mb_strtolower($tag)) {
                    $id = (int) $t['id'];
                }
            }
            $ids[] = $id ?? (int) $this->api($c, 'POST', 'tags', ['json' => ['name' => $tag]])['id'];
        }
        return $ids;
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $c = $credentials;
        [$title, $bodyText] = self::titleAndBody($text, $this->opt($context, 'title'));
        $html = self::toHtml($bodyText);
        $featured = null;
        foreach ($media as $m) {
            $up = $this->upload($c, $m);
            $src = htmlspecialchars((string) ($up['source_url'] ?? ''), ENT_QUOTES);
            if ($featured === null && $m['type'] === 'image') {
                $featured = (int) $up['id'];
                continue;
            }
            $alt = htmlspecialchars((string) ($m['alt'] ?? ''), ENT_QUOTES);
            $html .= $m['type'] === 'video'
                ? "\n\n<figure class=\"wp-block-video\"><video controls src=\"{$src}\"></video></figure>"
                : "\n\n<figure class=\"wp-block-image\"><img src=\"{$src}\" alt=\"{$alt}\"></figure>";
        }
        $post = ['title' => $title, 'content' => $html, 'status' => (string) $this->opt($context, 'status', 'publish')];
        if ($featured) {
            $post['featured_media'] = $featured;
        }
        if ($cat = $this->opt($context, 'category')) {
            $post['categories'] = [(int) $cat];
        }
        if ($tags = $this->opt($context, 'tags')) {
            $post['tags'] = $this->tagIds($c, (string) $tags);
        }
        if ($ex = $this->opt($context, 'excerpt')) {
            $post['excerpt'] = (string) $ex;
        }
        $res = $this->api($c, 'POST', 'posts', ['json' => $post]);
        return new PublishResult((string) $res['id'], isset($res['link']) ? (string) $res['link'] : null);
    }
}
