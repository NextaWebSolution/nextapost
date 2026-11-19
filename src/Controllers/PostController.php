<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Providers\ProviderFactory;
use App\Services\MediaService;
use App\Services\PostService;
use App\Services\PublishService;
use App\Services\Queue;

final class PostController extends Controller
{
    private function svc(): PostService
    {
        return new PostService($this->db);
    }

    private function post(string $id): array
    {
        $p = $this->svc()->find((int) $this->ws['id'], $id);
        if (!$p) {
            throw new HttpException(404, 'That post doesn’t exist or was deleted.');
        }
        return $p;
    }

    public function index(string $ws): Response
    {
        $this->workspace($ws);
        $tabs = ['all' => 'All', 'scheduled' => 'Scheduled', 'in_review' => 'In review', 'draft' => 'Drafts', 'published' => 'Published', 'failed' => 'Failed'];
        $tab = $this->req->str('status', 'all');
        $tab = isset($tabs[$tab]) ? $tab : 'all';
        $where = 'workspace_id = ? AND deleted_at IS NULL';
        $params = [$this->ws['id']];
        if ($tab === 'failed') {
            $where .= " AND status IN ('failed','partially_published')";
        } elseif ($tab === 'scheduled') {
            $where .= " AND status IN ('scheduled','publishing')";
        } elseif ($tab !== 'all') {
            $where .= ' AND status = ?';
            $params[] = $tab;
        }
        $q = $this->req->str('q');
        if ($q !== '') {
            $where .= " AND (title LIKE ? ESCAPE '!' OR body LIKE ? ESCAPE '!')";
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            array_push($params, $like, $like);
        }
        $page = max(1, (int) $this->req->str('page', '1'));
        $per = 25;
        $total = (int) $this->db->value("SELECT COUNT(*) FROM posts WHERE $where", $params);
        $order = in_array($tab, ['published', 'failed', 'all'], true) ? 'COALESCE(publish_at, created_at) DESC' : 'COALESCE(publish_at, created_at) ASC';
        $rows = $this->db->all("SELECT id, uuid, title, body, status, publish_at, version, author_id, review_note FROM posts WHERE $where ORDER BY $order, id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
        $posts = $this->svc()->decorate($rows, (string) $this->ws['timezone']);
        $errors = [];
        if ($rows) {
            $ids = array_map('intval', array_column($rows, 'id'));
            foreach ($this->db->all("SELECT t.post_id, c.name, t.last_error FROM post_targets t JOIN channels c ON c.id = t.channel_id WHERE t.status = 'failed' AND t.post_id IN (" . DB::in($ids) . ')', $ids) as $e) {
                $errors[(int) $e['post_id']][] = $e['name'] . ': ' . $e['last_error'];
            }
        }
        foreach ($rows as $i => $r) {
            $posts[$i]['errors'] = $errors[(int) $r['id']] ?? [];
            $posts[$i]['review_note'] = $r['review_note'];
        }
        return $this->view('posts/index', [
            'title' => 'Posts', 'posts' => $posts, 'tabs' => $tabs, 'tab' => $tab, 'q' => $q,
            'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'total' => $total,
            'canEdit' => $this->can('edit'), 'canApprove' => $this->can('approve'),
        ]);
    }

    public function create(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $at = $this->req->str('at');
        $default = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $at) ? $at : '';
        if ($default === '') {
            $next = (int) ceil((time() + 3600) / 1800) * 1800;
            $default = to_local(gmdate('Y-m-d H:i:s', $next), (string) $this->ws['timezone'], 'Y-m-d\TH:i');
        }
        $preselect = array_values(array_filter(explode(',', $this->req->str('channels'))));
        return $this->composer(null, ['publish_at' => $default, 'channels' => $preselect]);
    }

    public function edit(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $post = $this->post($id);
        return $this->composer($post, []);
    }

    private function composer(?array $post, array $defaults): Response
    {
        $channels = [];
        foreach ($this->db->all('SELECT * FROM channels WHERE workspace_id = ? ORDER BY network, name', [$this->ws['id']]) as $c) {
            $p = ProviderFactory::make($c['network']);
            $meta = json_decode((string) ($c['meta_json'] ?? ''), true) ?: [];
            $l = $p->limits($meta);
            $m = $p->meta();
            $channels[] = [
                'id' => $c['uuid'], 'name' => $c['name'], 'username' => $c['username'], 'network' => $c['network'], 'networkName' => $m['name'],
                'color' => $m['color'], 'letter' => $m['letter'], 'avatar' => safe_url($c['avatar_url']), 'active' => $c['status'] === 'active',
                'limit' => $l['text_max'], 'captionLimit' => $l['caption_max'] ?? null, 'mediaMax' => $l['media_max'], 'types' => $l['types'],
                'mediaRequired' => !empty($l['media_required']), 'countUrlsAs' => $c['network'] === 'x' ? 23 : null,
            ];
        }
        $targets = $post ? $this->svc()->targets((int) $post['id']) : [];
        $data = [
            'id' => $post['uuid'] ?? null,
            'status' => $post['status'] ?? 'new',
            'statusLabel' => $post ? status_label($post['status']) : 'New post',
            'version' => (int) ($post['version'] ?? 0),
            'body' => (string) ($post['body'] ?? ''),
            'publish_at' => $post && $post['publish_at'] ? to_local($post['publish_at'], (string) $this->ws['timezone'], 'Y-m-d\TH:i') : ($defaults['publish_at'] ?? ''),
            'channels' => $post ? array_column($targets, 'channel_uuid') : ($defaults['channels'] ?? []),
            'overrides' => (object) array_column(array_filter($targets, static fn ($t) => $t['body_override'] !== null), 'body_override', 'channel_uuid'),
            'media' => $post ? array_map([MediaService::class, 'toJson'], $this->svc()->media((int) $post['id'])) : [],
            'mediaOverrides' => (object) array_column(array_map(fn ($t) => [
                'channel' => $t['channel_uuid'], 'media' => array_map([MediaService::class, 'toJson'], $this->svc()->targetMedia($t)),
            ], array_values(array_filter($targets, static fn ($t) => (int) $t['media_custom'] === 1))), 'media', 'channel'),
            'review_note' => $post['review_note'] ?? null,
            'targets' => array_map(static fn ($t) => ['channel' => $t['channel_uuid'], 'status' => $t['status'], 'statusLabel' => status_label($t['status']), 'url' => safe_url($t['external_url']), 'error' => $t['last_error']], $targets),
        ];
        $editable = $this->can('edit') && (!$post || in_array($post['status'], PostService::EDITABLE, true));
        return $this->view('posts/composer', [
            'title' => $post ? 'Edit post' : 'Create post',
            'post' => $post,
            'cfg' => [
                'post' => $data,
                'channels' => $channels,
                'editable' => $editable,
                'canPublish' => $this->can('publish'),
                'canApprove' => $this->can('approve') && $post && $post['status'] === 'in_review',
                'tz' => $this->ws['timezone'],
                'saveUrl' => $post ? $this->wsUrl('posts/' . $post['uuid']) : $this->wsUrl('posts'),
                'uploadUrl' => $this->wsUrl('media'),
                'libraryUrl' => $this->wsUrl('media.json'),
                'backUrl' => $this->wsUrl('calendar'),
                'channelsUrl' => $this->wsUrl('channels'),
                'maxUpload' => MediaService::maxBytes(),
                'author' => Auth::user()['name'] ?? '',
            ],
        ]);
    }

    public function store(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        return $this->persist(null);
    }

    public function update(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        return $this->persist($this->post($id));
    }

    private function persist(?array $existing): Response
    {
        $res = $this->svc()->save($this->ws, Auth::user(), $this->role, $this->req->body, $existing);
        if ($res['errors']) {
            return $this->json(['error' => ['message' => 'Please fix the highlighted issues and try again.'], 'errors' => $res['errors']], 422);
        }
        $post = $res['post'];
        if ($res['publish_now']) {
            @set_time_limit(180);
            (new PublishService($this->db, new Queue($this->db)))->publishPostNow((int) $post['id']);
            $post = $this->db->one('SELECT * FROM posts WHERE id = ?', [$post['id']]);
        }
        $msg = match ($post['status']) {
            'draft' => 'Draft saved.',
            'in_review' => 'Sent for review. An approver will check it.',
            'scheduled' => 'Scheduled for ' . to_local($post['publish_at'], (string) $this->ws['timezone'], 'D, d M · H:i') . '.',
            'published' => 'Published.',
            'publishing' => 'Publishing… we’ll keep trying any channel that didn’t go through.',
            'partially_published' => 'Published to some channels — open the post to see what failed.',
            'failed' => 'Publishing failed — open the post to see why.',
            default => 'Saved.',
        };
        Session::flash(in_array($post['status'], ['failed', 'partially_published'], true) ? 'error' : 'success', $msg);
        return $this->json(['ok' => true, 'redirect' => $this->wsUrl('calendar'), 'post' => ['id' => $post['uuid'], 'status' => $post['status']]]);
    }

    public function move(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $post = $this->post($id);
        $err = $this->svc()->move($this->ws, $post, $this->req->str('at'), $this->req->input('version') !== null ? (int) $this->req->input('version') : null);
        if ($err) {
            return $this->json(['error' => ['message' => $err]], 422);
        }
        $p = (array) $this->svc()->find((int) $this->ws['id'], $id);
        return $this->json(['ok' => true, 'message' => 'Moved to ' . to_local($p['publish_at'], (string) $this->ws['timezone'], 'D, d M · H:i') . '.', 'version' => (int) $p['version']]);
    }

    public function delete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $err = $this->svc()->delete($this->ws, $this->post($id));
        return $this->back($this->wsUrl('posts'), $err ? ['error' => $err] : ['success' => 'Post deleted.']);
    }

    public function approve(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('approve');
        $err = $this->svc()->approve($this->ws, $this->post($id));
        if ($this->req->wantsJson()) {
            return $err ? $this->json(['error' => ['message' => $err]], 422) : $this->json(['ok' => true]);
        }
        return $this->back($this->wsUrl('posts?status=in_review'), $err ? ['error' => $err] : ['success' => 'Approved and scheduled.']);
    }

    public function reject(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('approve');
        $err = $this->svc()->reject($this->ws, $this->post($id), $this->req->str('note'));
        return $this->back($this->wsUrl('posts?status=in_review'), $err ? ['error' => $err] : ['success' => 'Sent back to the author with your note.']);
    }

    public function retry(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $post = $this->post($id);
        $err = $this->svc()->retry($this->ws, $post);
        if (!$err) {
            @set_time_limit(180);
            (new PublishService($this->db, new Queue($this->db)))->publishPostNow((int) $post['id']);
            $status = (string) $this->db->value('SELECT status FROM posts WHERE id = ?', [$post['id']]);
            return $this->back($this->wsUrl('posts'), $status === 'published' ? ['success' => 'Published on retry.'] : ['error' => 'Retry finished with status: ' . status_label($status) . '.']);
        }
        return $this->back($this->wsUrl('posts'), ['error' => $err]);
    }

    public function duplicate(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $copy = $this->svc()->duplicate($this->ws, Auth::user(), $this->post($id));
        Session::flash('success', 'Copy created as a draft.');
        return $this->redirect($this->wsUrl('posts/' . $copy['uuid'] . '/edit'));
    }
}
