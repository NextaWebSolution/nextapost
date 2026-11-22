<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiError;
use App\Core\DB;
use App\Core\Gate;
use App\Core\Http;

/**
 * Every operation an AI agent or integration can perform, in one place.
 * The REST API (/api/v1) and the MCP server (/mcp) both call these, so they behave the same.
 *
 * Each operation: description, ability (Gate), params (JSON Schema properties), required, write/destructive hints.
 */
final class AgentApi
{
    private const STR = ['type' => 'string'];
    private const MAX_BATCH = 25;
    private const MAX_INLINE_BYTES = 15 * 1024 * 1024;

    private PostService $posts;
    private Presenter $presenter;

    /** @param array{user:array,ws:array,role:string,via:string,token_id:int,client:?string} $ctx */
    public function __construct(private DB $db, private array $ctx, private string $source = 'api')
    {
        $this->posts = new PostService($db);
        $this->presenter = new Presenter($db);
    }

    public function context(): array
    {
        return $this->ctx;
    }

    /** @return array<string, array{title:string,description:string,ability:string,params:array,required?:string[],write?:bool,destructive?:bool}> */
    public static function operations(): array
    {
        $postFields = [
            'text' => ['type' => 'string', 'description' => 'Main text, used for every channel unless channel_text overrides it.'],
            'channels' => ['type' => 'array', 'items' => self::STR, 'description' => 'Channel ids (from list_channels).'],
            'channel_text' => ['type' => 'object', 'additionalProperties' => self::STR, 'description' => 'Per-channel text: {channel_id: text}.'],
            'media' => ['type' => 'array', 'items' => self::STR, 'description' => 'Media ids (from list_media / upload_media) attached for every channel.'],
            'channel_media' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => self::STR], 'description' => 'Different media for a channel: {channel_id: [media_id]}.'],
            'action' => ['type' => 'string', 'enum' => ['draft', 'schedule', 'review', 'now'], 'description' => 'draft = save only; schedule = publish at publish_at (sent for review if your role needs approval); review = ask an approver; now = publish immediately.'],
            'publish_at' => ['type' => 'string', 'description' => 'ISO 8601 time. With Z or an offset it is exact; without, it is in the workspace time zone (see get_workspace).'],
            'queue' => ['type' => 'boolean', 'description' => 'Instead of publish_at, give each channel its next free time from its posting schedule.'],
            'labels' => ['type' => 'array', 'items' => self::STR, 'description' => 'Label ids (from list_labels). Replaces the post’s labels.'],
            'recurrence' => ['type' => ['object', 'null'], 'description' => 'Repeat: {freq: daily|weekly|monthly|yearly, interval: 1-30, until: YYYY-MM-DD, count: 2-500}. null removes it.'],
            'channel_options' => ['type' => 'object', 'description' => 'Per-channel post type and extras: {channel_id: {content_type, ...}}. See get_channel for supported types and option fields.'],
            'idea_id' => ['type' => 'string', 'description' => 'Link the new post to this idea (create only).'],
        ];
        $id = fn (string $what) => ['type' => 'string', 'description' => $what . ' id.'];
        return [
            'get_workspace' => ['title' => 'Get workspace', 'description' => 'Workspace name, time zone, approval mode, your role and what you are allowed to do. Call this first.', 'ability' => 'view', 'params' => []],
            'list_channels' => ['title' => 'List channels', 'description' => 'Connected social accounts you can post to.', 'ability' => 'view', 'params' => ['status' => ['type' => 'string', 'enum' => ['active', 'needs_reconnect', 'all']]]],
            'get_channel' => ['title' => 'Get channel', 'description' => 'One channel with its character limit, supported post types and posting schedule.', 'ability' => 'view', 'params' => ['channel_id' => $id('Channel')], 'required' => ['channel_id']],
            'get_posting_schedule' => ['title' => 'Get posting schedule', 'description' => 'A channel’s weekly posting times and its next free times.', 'ability' => 'view', 'params' => ['channel_id' => $id('Channel'), 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30]], 'required' => ['channel_id']],
            'set_posting_schedule' => ['title' => 'Set posting schedule', 'description' => 'Replace a channel’s weekly posting times. schedule: {mon: ["09:00"], tue: [...], ...}. Or set suggest=true with posting_goal to fill it with recommended times.', 'ability' => 'channels', 'write' => true,
                'params' => ['channel_id' => $id('Channel'), 'schedule' => ['type' => 'object'], 'timezone' => ['type' => ['string', 'null'], 'description' => 'IANA time zone, or null for the workspace time zone.'], 'posting_goal' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 84], 'suggest' => ['type' => 'boolean']], 'required' => ['channel_id']],

            'list_posts' => ['title' => 'List posts', 'description' => 'Posts newest first, with filters. Returns next_cursor when there are more.', 'ability' => 'view',
                'params' => ['status' => ['type' => 'string', 'enum' => ['draft', 'in_review', 'scheduled', 'publishing', 'published', 'partially_published', 'failed']], 'from' => ['type' => 'string', 'description' => 'Publish time from (ISO 8601).'], 'to' => ['type' => 'string', 'description' => 'Publish time before (ISO 8601).'],
                    'label_id' => self::STR, 'channel_id' => self::STR, 'search' => ['type' => 'string', 'description' => 'Text the post contains.'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], 'cursor' => self::STR]],
            'get_post' => ['title' => 'Get post', 'description' => 'A post with its channels, per-channel status, links and errors.', 'ability' => 'view', 'params' => ['post_id' => $id('Post')], 'required' => ['post_id']],
            'create_post' => ['title' => 'Create post', 'description' => 'Create a draft, schedule a post, send it for review or publish now.', 'ability' => 'edit', 'write' => true, 'params' => $postFields],
            'create_posts' => ['title' => 'Create several posts', 'description' => 'Create up to ' . self::MAX_BATCH . ' posts in one call. Each item takes the same fields as create_post. Each result says whether that post was created.', 'ability' => 'edit', 'write' => true,
                'params' => ['posts' => ['type' => 'array', 'items' => ['type' => 'object'], 'maxItems' => self::MAX_BATCH]], 'required' => ['posts']],
            'update_post' => ['title' => 'Update post', 'description' => 'Change a draft, in-review or scheduled post. Only the fields you send change. Pass version (from get_post) to avoid overwriting someone else’s edit.', 'ability' => 'edit', 'write' => true,
                'params' => ['post_id' => $id('Post'), 'version' => ['type' => 'integer']] + $postFields, 'required' => ['post_id']],
            'delete_post' => ['title' => 'Delete post', 'description' => 'Delete a post from NextaPost. A scheduled post is cancelled; a published post stays on the networks.', 'ability' => 'edit', 'write' => true, 'destructive' => true, 'params' => ['post_id' => $id('Post')], 'required' => ['post_id']],
            'schedule_post' => ['title' => 'Schedule post', 'description' => 'Schedule (or reschedule) a post for publish_at, or into the queue with queue=true.', 'ability' => 'edit', 'write' => true,
                'params' => ['post_id' => $id('Post'), 'publish_at' => $postFields['publish_at'], 'queue' => $postFields['queue']], 'required' => ['post_id']],
            'unschedule_post' => ['title' => 'Unschedule post', 'description' => 'Turn a scheduled post back into a draft.', 'ability' => 'edit', 'write' => true, 'params' => ['post_id' => $id('Post')], 'required' => ['post_id']],
            'submit_post_for_review' => ['title' => 'Send post for review', 'description' => 'Ask an approver to check a post. It needs a publish time (or queue=true).', 'ability' => 'edit', 'write' => true,
                'params' => ['post_id' => $id('Post'), 'publish_at' => $postFields['publish_at'], 'queue' => $postFields['queue']], 'required' => ['post_id']],
            'approve_post' => ['title' => 'Approve post', 'description' => 'Approve a post that is in review; it will publish at its time.', 'ability' => 'approve', 'write' => true, 'params' => ['post_id' => $id('Post')], 'required' => ['post_id']],
            'reject_post' => ['title' => 'Request changes', 'description' => 'Send a post in review back to its author with a note.', 'ability' => 'approve', 'write' => true, 'params' => ['post_id' => $id('Post'), 'note' => ['type' => 'string', 'maxLength' => 2000]], 'required' => ['post_id', 'note']],
            'publish_post_now' => ['title' => 'Publish now', 'description' => 'Publish a draft or scheduled post immediately. Needs permission to publish without approval.', 'ability' => 'publish', 'write' => true, 'params' => ['post_id' => $id('Post')], 'required' => ['post_id']],
            'duplicate_post' => ['title' => 'Duplicate post', 'description' => 'Copy a post into a new draft.', 'ability' => 'edit', 'write' => true, 'params' => ['post_id' => $id('Post')], 'required' => ['post_id']],
            'retry_post' => ['title' => 'Retry failed channels', 'description' => 'Try again on the channels where publishing failed.', 'ability' => 'edit', 'write' => true, 'params' => ['post_id' => $id('Post')], 'required' => ['post_id']],

            'list_media' => ['title' => 'List media', 'description' => 'Images, videos and documents in the media library, newest first.', 'ability' => 'view',
                'params' => ['type' => ['type' => 'string', 'enum' => ['image', 'video', 'document']], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'cursor' => self::STR]],
            'get_media' => ['title' => 'Get media', 'description' => 'One media item.', 'ability' => 'view', 'params' => ['media_id' => $id('Media')], 'required' => ['media_id']],
            'upload_media' => ['title' => 'Upload media', 'description' => 'Upload a file sent as base64 (up to 15 MB). For bigger files use import_media_from_url or the REST upload.', 'ability' => 'media', 'write' => true,
                'params' => ['filename' => ['type' => 'string', 'description' => 'File name with extension, e.g. photo.jpg'], 'data_base64' => self::STR, 'alt_text' => ['type' => 'string', 'maxLength' => 1000]], 'required' => ['filename', 'data_base64']],
            'import_media_from_url' => ['title' => 'Import media from URL', 'description' => 'Download a public https file into the media library.', 'ability' => 'media', 'write' => true,
                'params' => ['url' => self::STR, 'alt_text' => ['type' => 'string', 'maxLength' => 1000]], 'required' => ['url']],
            'update_media' => ['title' => 'Update media', 'description' => 'Change a file’s alt text.', 'ability' => 'media', 'write' => true, 'params' => ['media_id' => $id('Media'), 'alt_text' => ['type' => 'string', 'maxLength' => 1000]], 'required' => ['media_id', 'alt_text']],
            'delete_media' => ['title' => 'Delete media', 'description' => 'Delete a file that no unpublished post uses.', 'ability' => 'media', 'write' => true, 'destructive' => true, 'params' => ['media_id' => $id('Media')], 'required' => ['media_id']],

            'list_labels' => ['title' => 'List labels', 'description' => 'Labels used to group posts.', 'ability' => 'view', 'params' => []],
            'create_label' => ['title' => 'Create label', 'description' => 'Add a label. color is #RRGGBB.', 'ability' => 'library', 'write' => true, 'params' => ['name' => ['type' => 'string', 'maxLength' => 40], 'color' => self::STR], 'required' => ['name']],
            'update_label' => ['title' => 'Update label', 'description' => 'Rename or recolour a label.', 'ability' => 'library', 'write' => true, 'params' => ['label_id' => $id('Label'), 'name' => ['type' => 'string', 'maxLength' => 40], 'color' => self::STR], 'required' => ['label_id']],
            'delete_label' => ['title' => 'Delete label', 'description' => 'Delete a label (posts keep their content).', 'ability' => 'library', 'write' => true, 'destructive' => true, 'params' => ['label_id' => $id('Label')], 'required' => ['label_id']],
            'list_signatures' => ['title' => 'List signatures', 'description' => 'Reusable sign-offs (hashtags, links, contact lines).', 'ability' => 'view', 'params' => []],
            'create_signature' => ['title' => 'Create signature', 'description' => 'Add a signature.', 'ability' => 'library', 'write' => true, 'params' => ['name' => ['type' => 'string', 'maxLength' => 60], 'content' => ['type' => 'string', 'maxLength' => 2000], 'is_default' => ['type' => 'boolean']], 'required' => ['name', 'content']],
            'update_signature' => ['title' => 'Update signature', 'description' => 'Change a signature.', 'ability' => 'library', 'write' => true, 'params' => ['signature_id' => $id('Signature'), 'name' => ['type' => 'string', 'maxLength' => 60], 'content' => ['type' => 'string', 'maxLength' => 2000], 'is_default' => ['type' => 'boolean']], 'required' => ['signature_id']],
            'delete_signature' => ['title' => 'Delete signature', 'description' => 'Delete a signature.', 'ability' => 'library', 'write' => true, 'destructive' => true, 'params' => ['signature_id' => $id('Signature')], 'required' => ['signature_id']],
            'list_templates' => ['title' => 'List templates', 'description' => 'Post templates shared with the workspace plus your own.', 'ability' => 'view', 'params' => []],
            'create_template' => ['title' => 'Create template', 'description' => 'Save text as a reusable template.', 'ability' => 'edit', 'write' => true,
                'params' => ['name' => ['type' => 'string', 'maxLength' => 120], 'content' => ['type' => 'string', 'maxLength' => 20000], 'visibility' => ['type' => 'string', 'enum' => ['workspace', 'private']]], 'required' => ['name', 'content']],
            'update_template' => ['title' => 'Update template', 'description' => 'Change a template you can edit.', 'ability' => 'edit', 'write' => true,
                'params' => ['template_id' => $id('Template'), 'name' => ['type' => 'string', 'maxLength' => 120], 'content' => ['type' => 'string', 'maxLength' => 20000], 'visibility' => ['type' => 'string', 'enum' => ['workspace', 'private']]], 'required' => ['template_id']],
            'delete_template' => ['title' => 'Delete template', 'description' => 'Delete a template.', 'ability' => 'edit', 'write' => true, 'destructive' => true, 'params' => ['template_id' => $id('Template')], 'required' => ['template_id']],

            'list_notes' => ['title' => 'List notes', 'description' => 'Team notes on a post, oldest first.', 'ability' => 'view', 'params' => ['post_id' => $id('Post')], 'required' => ['post_id']],
            'add_note' => ['title' => 'Add note', 'description' => 'Add a team note to a post. Mentioned members (ids from get_workspace members) get an email.', 'ability' => 'view', 'write' => true,
                'params' => ['post_id' => $id('Post'), 'text' => ['type' => 'string', 'maxLength' => 5000], 'mentions' => ['type' => 'array', 'items' => self::STR]], 'required' => ['post_id', 'text']],
            'delete_note' => ['title' => 'Delete note', 'description' => 'Delete a note you wrote (admins can delete any).', 'ability' => 'view', 'write' => true, 'destructive' => true, 'params' => ['post_id' => $id('Post'), 'note_id' => $id('Note')], 'required' => ['post_id', 'note_id']],

            'list_idea_columns' => ['title' => 'List idea columns', 'description' => 'Columns of the ideas board, in order.', 'ability' => 'view', 'params' => []],
            'list_ideas' => ['title' => 'List ideas', 'description' => 'Ideas on the board, by column. Optional search text.', 'ability' => 'view',
                'params' => ['column_id' => self::STR, 'search' => self::STR, 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200], 'cursor' => self::STR]],
            'create_idea' => ['title' => 'Create idea', 'description' => 'Add an idea to the board (first column unless column_id is given).', 'ability' => 'edit', 'write' => true,
                'params' => ['title' => ['type' => 'string', 'maxLength' => 200], 'notes' => ['type' => 'string', 'maxLength' => 20000], 'link' => self::STR, 'column_id' => self::STR, 'labels' => ['type' => 'array', 'items' => self::STR], 'media' => ['type' => 'array', 'items' => self::STR]]],
            'update_idea' => ['title' => 'Update idea', 'description' => 'Change an idea, or move it with column_id and position (0 = top).', 'ability' => 'edit', 'write' => true,
                'params' => ['idea_id' => $id('Idea'), 'title' => ['type' => 'string', 'maxLength' => 200], 'notes' => ['type' => 'string', 'maxLength' => 20000], 'link' => self::STR, 'column_id' => self::STR, 'position' => ['type' => 'integer', 'minimum' => 0], 'labels' => ['type' => 'array', 'items' => self::STR], 'media' => ['type' => 'array', 'items' => self::STR]], 'required' => ['idea_id']],
            'delete_idea' => ['title' => 'Delete idea', 'description' => 'Delete an idea.', 'ability' => 'edit', 'write' => true, 'destructive' => true, 'params' => ['idea_id' => $id('Idea')], 'required' => ['idea_id']],
            'list_feeds' => ['title' => 'List feeds', 'description' => 'RSS/Atom/JSON feeds the workspace follows.', 'ability' => 'view', 'params' => []],
            'follow_feed' => ['title' => 'Follow feed', 'description' => 'Follow a website or feed. auto=idea adds new articles to the ideas board.', 'ability' => 'library', 'write' => true,
                'params' => ['url' => self::STR, 'auto' => ['type' => 'string', 'enum' => ['inbox', 'idea']]], 'required' => ['url']],
            'unfollow_feed' => ['title' => 'Unfollow feed', 'description' => 'Stop following a feed.', 'ability' => 'library', 'write' => true, 'destructive' => true, 'params' => ['feed_id' => $id('Feed')], 'required' => ['feed_id']],
            'list_feed_items' => ['title' => 'List new articles', 'description' => 'New articles from followed feeds that haven’t been used or dismissed.', 'ability' => 'view',
                'params' => ['feed_id' => self::STR, 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]]],
            'save_feed_item_as_idea' => ['title' => 'Save article as idea', 'description' => 'Turn a feed article into an idea on the board.', 'ability' => 'edit', 'write' => true, 'params' => ['item_id' => $id('Article')], 'required' => ['item_id']],

            'list_webhooks' => ['title' => 'List webhooks', 'description' => 'Endpoints that receive post events.', 'ability' => 'settings', 'params' => []],
            'create_webhook' => ['title' => 'Create webhook', 'description' => 'Send post events to a public https URL. The signing secret is returned once.', 'ability' => 'settings', 'write' => true,
                'params' => ['url' => self::STR, 'events' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_merge(['*'], array_keys(WebhookService::EVENTS))]]], 'required' => ['url', 'events']],
            'update_webhook' => ['title' => 'Update webhook', 'description' => 'Change the URL, events or status.', 'ability' => 'settings', 'write' => true,
                'params' => ['webhook_id' => $id('Webhook'), 'url' => self::STR, 'events' => ['type' => 'array', 'items' => self::STR], 'status' => ['type' => 'string', 'enum' => ['enabled', 'disabled']]], 'required' => ['webhook_id']],
            'delete_webhook' => ['title' => 'Delete webhook', 'description' => 'Delete a webhook.', 'ability' => 'settings', 'write' => true, 'destructive' => true, 'params' => ['webhook_id' => $id('Webhook')], 'required' => ['webhook_id']],
            'rotate_webhook_secret' => ['title' => 'Rotate webhook secret', 'description' => 'Make a new signing secret (returned once). The old one stops working.', 'ability' => 'settings', 'write' => true, 'destructive' => true, 'params' => ['webhook_id' => $id('Webhook')], 'required' => ['webhook_id']],
            'list_webhook_deliveries' => ['title' => 'List webhook deliveries', 'description' => 'Recent deliveries with status codes.', 'ability' => 'settings', 'params' => ['webhook_id' => $id('Webhook')], 'required' => ['webhook_id']],
            'replay_webhook_delivery' => ['title' => 'Resend webhook delivery', 'description' => 'Send a past delivery again now.', 'ability' => 'settings', 'write' => true, 'params' => ['webhook_id' => $id('Webhook'), 'delivery_id' => $id('Delivery')], 'required' => ['webhook_id', 'delivery_id']],
        ];
    }

    /** Run an operation after checking the caller's role and the argument types. */
    public function call(string $name, array $args): array
    {
        $ops = self::operations();
        if (!isset($ops[$name])) {
            throw new ApiError(404, __('Unknown operation “{name}”.', ['name' => $name]), 'unknown_operation');
        }
        $op = $ops[$name];
        if (!Gate::can($this->ctx['role'], $op['ability'], $this->ctx['ws'])) {
            throw ApiError::forbidden(__('Your role ({role}) can’t do “{op}”.', ['role' => $this->ctx['role'], 'op' => $op['title']]));
        }
        $this->checkArgs($op, $args);
        $method = 'op' . str_replace('_', '', ucwords($name, '_'));
        return $this->{$method}($args);
    }

    private function checkArgs(array $op, array $args): void
    {
        $errors = [];
        foreach ($op['required'] ?? [] as $k) {
            if (!array_key_exists($k, $args) || $args[$k] === '' || $args[$k] === null) {
                $errors[$k] = __('This field is required.');
            }
        }
        foreach ($args as $k => $v) {
            $spec = $op['params'][$k] ?? null;
            if ($spec === null || $v === null && in_array('null', (array) $spec['type'], true)) {
                continue;
            }
            $ok = false;
            foreach ((array) $spec['type'] as $t) {
                $ok = $ok || match ($t) {
                    'string' => is_string($v),
                    'integer' => is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v)),
                    'boolean' => is_bool($v),
                    'array' => is_array($v) && array_is_list($v),
                    'object' => is_array($v) && (!array_is_list($v) || $v === []),
                    default => true,
                };
            }
            if (!$ok) {
                $errors[$k] = __('Must be of type {type}.', ['type' => implode(' or ', (array) $spec['type'])]);
                continue;
            }
            if (isset($spec['enum']) && !in_array($v, $spec['enum'], true)) {
                $errors[$k] = __('Must be one of: {list}.', ['list' => implode(', ', $spec['enum'])]);
            } elseif (isset($spec['maxLength']) && is_string($v) && mb_strlen($v) > $spec['maxLength']) {
                $errors[$k] = __('Can be up to {n} characters.', ['n' => $spec['maxLength']]);
            } elseif (isset($spec['maxItems']) && is_array($v) && count($v) > $spec['maxItems']) {
                $errors[$k] = __('Can have up to {n} items.', ['n' => $spec['maxItems']]);
            } elseif ($spec['type'] === 'integer' && ((isset($spec['minimum']) && (int) $v < $spec['minimum']) || (isset($spec['maximum']) && (int) $v > $spec['maximum']))) {
                $errors[$k] = __('Must be between {min} and {max}.', ['min' => $spec['minimum'] ?? '', 'max' => $spec['maximum'] ?? '']);
            }
        }
        if ($errors) {
            throw ApiError::invalid($errors);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function ws(): array
    {
        return $this->ctx['ws'];
    }

    private function wsId(): int
    {
        return (int) $this->ctx['ws']['id'];
    }

    private function can(string $ability): bool
    {
        return Gate::can($this->ctx['role'], $ability, $this->ctx['ws']);
    }

    /** ISO 8601 (exact with Z/offset, otherwise workspace-local) -> workspace-local "Y-m-d H:i". */
    private function localTime(string $iso, string $field): string
    {
        $iso = trim($iso);
        $tz = new \DateTimeZone((string) $this->ws()['timezone']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+\-]\d{2}:?\d{2})$/i', $iso)) {
            try {
                return (new \DateTimeImmutable($iso))->setTimezone($tz)->format('Y-m-d H:i');
            } catch (\Throwable) {
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $iso) && to_utc($iso, (string) $this->ws()['timezone']) !== null) {
            return substr(str_replace('T', ' ', $iso), 0, 16);
        }
        throw ApiError::invalid([$field => __('Use an ISO 8601 time such as 2026-12-01T09:30:00+05:30.')]);
    }

    private function utcTime(string $iso, string $field): string
    {
        return (string) to_utc($this->localTime($iso, $field), (string) $this->ws()['timezone']);
    }

    /** A post this caller may change (contributors: only their own). */
    private function ownPost(string $uuid): array
    {
        $p = $this->post($uuid);
        if (!\App\Core\Gate::canChangePost((string) $this->ctx['role'], $p, (int) $this->ctx['user']['id'])) {
            throw new ApiError(403, __('Contributors can only change their own posts.'), 'forbidden');
        }
        return $p;
    }

    private function post(string $uuid): array
    {
        $p = $this->posts->find($this->wsId(), $uuid);
        if (!$p) {
            throw ApiError::notFound(__('Post'));
        }
        return $p;
    }

    private function channel(string $uuid): array
    {
        $c = $this->db->one('SELECT * FROM channels WHERE workspace_id = ? AND uuid = ?', [$this->wsId(), $uuid]);
        if (!$c) {
            throw ApiError::notFound(__('Channel'));
        }
        return $c;
    }

    private function mediaRow(string $uuid): array
    {
        $m = $this->db->one('SELECT * FROM media WHERE workspace_id = ? AND uuid = ?', [$this->wsId(), $uuid]);
        if (!$m) {
            throw ApiError::notFound(__('Media'));
        }
        return $m;
    }

    private function fresh(array $p): array
    {
        return $this->presenter->post((array) $this->db->one('SELECT * FROM posts WHERE id = ?', [$p['id']]), $this->ws());
    }

    /** Errors from PostService keyed by channel uuid / field -> API field names. */
    private function saveErrors(array $errors): ApiError
    {
        $map = ['body' => 'text', 'overrides' => 'channel_text', 'general' => 'post'];
        $out = [];
        foreach ($errors as $k => $msgs) {
            $out[$map[$k] ?? $k] = array_values((array) $msgs);
        }
        return ApiError::invalid($out, __('The post could not be saved. See details.'));
    }

    private static function cursor(?string $c): int
    {
        if ($c === null || $c === '') {
            return 0;
        }
        $d = base64_decode(strtr($c, '-_', '+/'), true);
        return $d !== false && preg_match('/^o:(\d{1,7})$/', $d, $m) ? (int) $m[1] : 0;
    }

    private static function nextCursor(int $offset, int $limit, int $got): ?string
    {
        return $got > $limit ? rtrim(strtr(base64_encode('o:' . ($offset + $limit)), '+/', '-_'), '=') : null;
    }

    /** Composer input for PostService::save, starting from an existing post when updating. */
    private function postInput(array $a, ?array $existing): array
    {
        $in = ['source' => $this->source];
        if ($existing) {
            $targets = $this->posts->targets((int) $existing['id']);
            $in['body'] = (string) $existing['body'];
            $in['channels'] = array_column($targets, 'channel_uuid');
            $in['overrides'] = [];
            $in['media_overrides'] = [];
            $in['target_options'] = [];
            foreach ($targets as $t) {
                if ($t['body_override'] !== null && $t['body_override'] !== '') {
                    $in['overrides'][$t['channel_uuid']] = (string) $t['body_override'];
                }
                if ((int) $t['media_custom'] === 1) {
                    $in['media_overrides'][$t['channel_uuid']] = array_column($this->posts->targetMedia($t), 'uuid');
                }
                if ($t['content_type'] !== null || $t['options_json'] !== null) {
                    $in['target_options'][$t['channel_uuid']] = ['content_type' => $t['content_type']] + (json_decode((string) $t['options_json'], true) ?: []);
                }
            }
            $in['media'] = array_column($this->posts->media((int) $existing['id']), 'uuid');
            $in['schedule_mode'] = $existing['schedule_mode'] ?? 'custom';
            $in['recurrence'] = json_decode((string) ($existing['recurrence'] ?? ''), true) ?: null;
            if ($existing['publish_at']) {
                $in['publish_at'] = to_local((string) $existing['publish_at'], (string) $this->ws()['timezone'], 'Y-m-d H:i');
            }
            $in['action'] = match ($existing['status']) {
                'in_review' => 'review',
                'scheduled' => 'schedule',
                default => 'draft',
            };
        }
        $set = [
            'text' => 'body', 'channels' => 'channels', 'channel_text' => 'overrides', 'media' => 'media',
            'channel_media' => 'media_overrides', 'labels' => 'labels', 'channel_options' => 'target_options', 'action' => 'action', 'version' => 'version',
        ];
        foreach ($set as $from => $to) {
            if (array_key_exists($from, $a)) {
                $in[$to] = $a[$from];
            }
        }
        if (!$existing && isset($a['idea_id'])) {
            $in['idea'] = (string) $a['idea_id'];
        }
        if (array_key_exists('recurrence', $a)) {
            $in['recurrence'] = $a['recurrence'] ?? false;
        }
        if (!empty($a['publish_at'])) {
            $in['publish_at'] = $this->localTime((string) $a['publish_at'], 'publish_at');
            $in['schedule_mode'] = 'custom';
        }
        if (array_key_exists('queue', $a)) {
            $in['schedule_mode'] = $a['queue'] ? 'queue' : 'custom';
        }
        if (!$existing && !isset($in['action'])) {
            $in['action'] = !empty($a['publish_at']) || !empty($a['queue']) ? 'schedule' : 'draft';
        }
        if (($in['action'] ?? '') === 'now' && !$this->can('publish')) {
            throw ApiError::forbidden(__('Your role can’t publish without approval. Use action “review” instead.'));
        }
        return $in;
    }

    private function savePost(array $a, ?array $existing): array
    {
        $res = $this->posts->save($this->ws(), $this->ctx['user'], $this->ctx['role'], $this->postInput($a, $existing), $existing);
        if ($res['errors']) {
            throw $this->saveErrors($res['errors']);
        }
        $post = $res['post'];
        if ($res['publish_now']) {
            @set_time_limit(180);
            (new PublishService($this->db, new Queue($this->db)))->publishPostNow((int) $post['id']);
        }
        return $this->fresh($post);
    }

    // ---------------------------------------------------------------- workspace & channels

    private function opGetWorkspace(array $a): array
    {
        $ws = $this->ws();
        $abilities = [];
        foreach (['edit', 'publish', 'approve', 'media', 'library', 'channels', 'settings'] as $ab) {
            $abilities[$ab] = $this->can($ab);
        }
        $members = $this->db->all(
            'SELECT u.uuid, u.name, m.role FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND u.deleted_at IS NULL ORDER BY u.name',
            [$ws['id']]
        );
        return [
            'id' => $ws['uuid'], 'name' => $ws['name'], 'timezone' => $ws['timezone'], 'approval_mode' => $ws['approval_mode'] ?? 'single',
            'now' => Presenter::iso(now_utc()), 'now_local' => to_local(now_utc(), (string) $ws['timezone'], 'Y-m-d\TH:i'),
            'you' => ['id' => $this->ctx['user']['uuid'], 'name' => $this->ctx['user']['name'], 'role' => $this->ctx['role'], 'can' => $abilities],
            'members' => array_map(fn ($m) => ['id' => $m['uuid'], 'name' => $m['name'], 'role' => $m['role']], $members),
            'url' => abs_url('/w/' . $ws['uuid'] . '/calendar'),
        ];
    }

    private function opListChannels(array $a): array
    {
        $status = $a['status'] ?? 'all';
        $sql = 'SELECT * FROM channels WHERE workspace_id = ?';
        $args = [$this->wsId()];
        if ($status === 'active') {
            $sql .= " AND status = 'active'";
        } elseif ($status === 'needs_reconnect') {
            $sql .= " AND status <> 'active'";
        }
        $rows = $this->db->all($sql . ' ORDER BY name', $args);
        return ['channels' => array_map([$this->presenter, 'channel'], $rows)];
    }

    private function opGetChannel(array $a): array
    {
        $c = $this->channel((string) $a['channel_id']);
        $provider = \App\Providers\ProviderFactory::make($c['network']);
        $meta = $provider->meta();
        $limits = $provider->limits(json_decode((string) ($c['meta_json'] ?? ''), true) ?: []);
        $types = [];
        foreach ($meta['content_types'] ?? [] as $k => $label) {
            $types[] = ['id' => $k, 'name' => is_array($label) ? ($label['label'] ?? $k) : $label];
        }
        return $this->presenter->channel($c) + [
            'max_characters' => $limits['text_max'] ?? null,
            'max_media' => $limits['media_max'] ?? null,
            'media_types' => $limits['types'] ?? [],
            'content_types' => $types,
            'options' => array_map(fn ($f) => array_intersect_key($f, array_flip(['key', 'label', 'type', 'types', 'required', 'max', 'min', 'choices', 'default'])), $provider->options(json_decode((string) ($c['meta_json'] ?? ''), true) ?: [])),
            'schedule' => ScheduleService::decode($c['posting_schedule'] ?? null),
        ];
    }

    private function opGetPostingSchedule(array $a): array
    {
        $c = $this->channel((string) $a['channel_id']);
        $svc = new ScheduleService($this->db);
        return [
            'channel_id' => $c['uuid'], 'timezone' => $svc->timezone($c, $this->ws()), 'posting_goal' => $c['posting_goal'] !== null ? (int) $c['posting_goal'] : null,
            'schedule' => ScheduleService::decode($c['posting_schedule'] ?? null),
            'next_free' => array_map([Presenter::class, 'iso'], $svc->freeSlots($c, $this->ws(), (int) ($a['limit'] ?? 10))),
        ];
    }

    private function opSetPostingSchedule(array $a): array
    {
        $c = $this->channel((string) $a['channel_id']);
        $tz = $a['timezone'] ?? null;
        if ($tz !== null && !valid_timezone((string) $tz)) {
            throw ApiError::invalid(['timezone' => __('Use an IANA time zone such as Asia/Kolkata.')]);
        }
        $goal = isset($a['posting_goal']) ? (int) $a['posting_goal'] : null;
        if (!empty($a['suggest'])) {
            $schedule = ScheduleService::generate((string) $c['network'], $goal ?? 7);
        } else {
            if (!isset($a['schedule'])) {
                throw ApiError::invalid(['schedule' => __('Send schedule, or suggest=true.')]);
            }
            [$schedule, $err] = ScheduleService::validate($a['schedule']);
            if ($err) {
                throw ApiError::invalid(['schedule' => $err]);
            }
        }
        (new ScheduleService($this->db))->save($c, $schedule, $tz, $goal);
        return $this->opGetPostingSchedule(['channel_id' => $c['uuid']]);
    }

    // ---------------------------------------------------------------- posts

    private function opListPosts(array $a): array
    {
        $limit = (int) ($a['limit'] ?? 20);
        $offset = self::cursor($a['cursor'] ?? null);
        $sql = 'SELECT p.* FROM posts p WHERE p.workspace_id = ? AND p.deleted_at IS NULL';
        $args = [$this->wsId()];
        if (!empty($a['status'])) {
            $sql .= ' AND p.status = ?';
            $args[] = $a['status'];
        }
        if (!empty($a['from'])) {
            $sql .= ' AND p.publish_at >= ?';
            $args[] = $this->utcTime((string) $a['from'], 'from');
        }
        if (!empty($a['to'])) {
            $sql .= ' AND p.publish_at < ?';
            $args[] = $this->utcTime((string) $a['to'], 'to');
        }
        if (!empty($a['label_id'])) {
            $sql .= ' AND EXISTS (SELECT 1 FROM post_labels pl JOIN labels l ON l.id = pl.label_id WHERE pl.post_id = p.id AND l.uuid = ?)';
            $args[] = $a['label_id'];
        }
        if (!empty($a['channel_id'])) {
            $sql .= ' AND EXISTS (SELECT 1 FROM post_targets t JOIN channels c ON c.id = t.channel_id WHERE t.post_id = p.id AND c.uuid = ?)';
            $args[] = $a['channel_id'];
        }
        if (!empty($a['search'])) {
            $sql .= " AND p.body LIKE ? ESCAPE '!'";
            $args[] = like_pattern((string) $a['search']);
        }
        $sql .= ' ORDER BY COALESCE(p.publish_at, p.created_at) DESC, p.id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;
        $rows = $this->db->all($sql, $args);
        $out = array_map(fn ($p) => $this->presenter->post($p, $this->ws()), array_slice($rows, 0, $limit));
        return ['posts' => $out, 'next_cursor' => self::nextCursor($offset, $limit, count($rows))];
    }

    private function opGetPost(array $a): array
    {
        return $this->presenter->post($this->post((string) $a['post_id']), $this->ws());
    }

    private function opCreatePost(array $a): array
    {
        return $this->savePost($a, null);
    }

    private function opCreatePosts(array $a): array
    {
        $fields = self::operations()['create_post'];
        $results = [];
        foreach ($a['posts'] as $i => $item) {
            try {
                if (!is_array($item)) {
                    throw ApiError::invalid(['post' => __('Each item must be an object.')]);
                }
                $this->checkArgs($fields, $item);
                $results[] = ['index' => $i, 'ok' => true, 'post' => $this->savePost($item, null)];
            } catch (ApiError $e) {
                $results[] = ['index' => $i, 'ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details ?: null]];
            }
        }
        return ['created' => count(array_filter($results, fn ($r) => $r['ok'])), 'results' => $results];
    }

    private function opUpdatePost(array $a): array
    {
        return $this->savePost($a, $this->ownPost((string) $a['post_id']));
    }

    private function opDeletePost(array $a): array
    {
        $p = $this->ownPost((string) $a['post_id']);
        if ($err = $this->posts->delete($this->ws(), $p)) {
            throw new ApiError(409, $err, 'conflict');
        }
        return ['deleted' => true, 'id' => $p['uuid']];
    }

    private function opSchedulePost(array $a): array
    {
        if (empty($a['publish_at']) && empty($a['queue'])) {
            throw ApiError::invalid(['publish_at' => __('Send publish_at, or queue=true.')]);
        }
        $p = $this->ownPost((string) $a['post_id']);
        return $this->savePost(['action' => 'schedule'] + array_intersect_key($a, ['publish_at' => 1, 'queue' => 1]), $p);
    }

    private function opUnschedulePost(array $a): array
    {
        $p = $this->ownPost((string) $a['post_id']);
        return $this->savePost(['action' => 'draft'], $p);
    }

    private function opSubmitPostForReview(array $a): array
    {
        $p = $this->ownPost((string) $a['post_id']);
        return $this->savePost(['action' => 'review'] + array_intersect_key($a, ['publish_at' => 1, 'queue' => 1]), $p);
    }

    private function opApprovePost(array $a): array
    {
        $p = $this->post((string) $a['post_id']);
        if ($err = $this->posts->approve($this->ws(), $p)) {
            throw new ApiError(409, $err, 'conflict');
        }
        return $this->fresh($p);
    }

    private function opRejectPost(array $a): array
    {
        $p = $this->post((string) $a['post_id']);
        if ($err = $this->posts->reject($this->ws(), $p, trim((string) $a['note']))) {
            throw new ApiError(409, $err, 'conflict');
        }
        return $this->fresh($p);
    }

    private function opPublishPostNow(array $a): array
    {
        $p = $this->ownPost((string) $a['post_id']);
        return $this->savePost(['action' => 'now'], $p);
    }

    private function opDuplicatePost(array $a): array
    {
        $copy = $this->posts->duplicate($this->ws(), $this->ctx['user'], $this->post((string) $a['post_id']));
        return $this->fresh($copy);
    }

    private function opRetryPost(array $a): array
    {
        $p = $this->ownPost((string) $a['post_id']);
        if ($err = $this->posts->retry($this->ws(), $p)) {
            throw new ApiError(409, $err, 'conflict');
        }
        return $this->fresh($p);
    }

    // ---------------------------------------------------------------- media

    private function opListMedia(array $a): array
    {
        $limit = (int) ($a['limit'] ?? 30);
        $offset = self::cursor($a['cursor'] ?? null);
        $sql = 'SELECT * FROM media WHERE workspace_id = ?';
        $args = [$this->wsId()];
        if (!empty($a['type'])) {
            $sql .= ' AND type = ?';
            $args[] = $a['type'];
        }
        $rows = $this->db->all($sql . ' ORDER BY created_at DESC, id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset, $args);
        return ['media' => array_map([$this->presenter, 'media'], array_slice($rows, 0, $limit)), 'next_cursor' => self::nextCursor($offset, $limit, count($rows))];
    }

    private function opGetMedia(array $a): array
    {
        return $this->presenter->media($this->mediaRow((string) $a['media_id']));
    }

    /** Store a local temp file in the library (used by upload_media, import and the REST multipart upload). */
    public function storeFile(string $tmp, string $name, ?string $alt, ?array $attribution = null, string $source = 'upload', bool $fromHttpUpload = false): array
    {
        if (!\App\Core\RateLimiter::hit($this->db, 'upload:' . $this->ctx['user']['id'], 120, 3600)) {
            throw new ApiError(429, __('Too many uploads. Wait a while and try again.'), 'rate_limited');
        }
        try {
            $m = (new MediaService($this->db))->upload($this->ws(), (int) $this->ctx['user']['id'], ['tmp_name' => $tmp, 'name' => $name, 'error' => UPLOAD_ERR_OK], !$fromHttpUpload);
        } catch (\InvalidArgumentException $e) {
            throw ApiError::invalid(['file' => $e->getMessage()]);
        } finally {
            if (!$fromHttpUpload && is_file($tmp)) {
                @unlink($tmp);
            }
        }
        $upd = ['source' => $source];
        if ($alt !== null && $alt !== '') {
            $upd['alt_text'] = mb_substr(trim($alt), 0, 1000);
        }
        if ($attribution) {
            $upd['attribution'] = json_encode($attribution, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $this->db->update('media', $upd, 'id = ?', [$m['id']]);
        return $this->presenter->media((array) $this->db->one('SELECT * FROM media WHERE id = ?', [$m['id']]));
    }

    private static function safeName(string $name, string $fallback = 'upload'): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\w.\- ]+/u', '', $name);
        return mb_substr($name !== '' ? $name : $fallback, 0, 200);
    }

    private function opUploadMedia(array $a): array
    {
        $b64 = preg_replace('/^data:[^;]+;base64,/', '', (string) $a['data_base64']);
        if (strlen((string) $b64) > (int) ceil(self::MAX_INLINE_BYTES * 4 / 3) + 4) {
            throw ApiError::invalid(['data_base64' => __('Inline uploads can be up to 15 MB. Use import_media_from_url for bigger files.')]);
        }
        $bin = base64_decode((string) $b64, true);
        if ($bin === false || $bin === '') {
            throw ApiError::invalid(['data_base64' => __('This is not valid base64 data.')]);
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'npup');
        file_put_contents($tmp, $bin);
        return $this->storeFile($tmp, self::safeName((string) $a['filename']), $a['alt_text'] ?? null);
    }

    private function opImportMediaFromUrl(array $a): array
    {
        $url = trim((string) $a['url']);
        if (!preg_match('#^https://#i', $url)) {
            throw ApiError::invalid(['url' => __('Use a public https:// link.')]);
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'npdl');
        try {
            $res = Http::request('GET', $url, ['sink' => $tmp, 'public_only' => true, 'timeout' => 90, 'max_bytes' => MediaService::maxBytes()]);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw ApiError::invalid(['url' => __('Couldn’t download that file: {reason}', ['reason' => $e->getMessage()])]);
        }
        if ($res['status'] !== 200) {
            @unlink($tmp);
            throw ApiError::invalid(['url' => __('The server answered with HTTP {code}. Check the link is public.', ['code' => $res['status']])]);
        }
        $name = self::safeName(rawurldecode((string) parse_url($url, PHP_URL_PATH)), 'download');
        return $this->storeFile($tmp, $name, $a['alt_text'] ?? null, ['source_url' => $url], 'url');
    }

    private function opUpdateMedia(array $a): array
    {
        $m = $this->mediaRow((string) $a['media_id']);
        $this->db->update('media', ['alt_text' => mb_substr(trim((string) $a['alt_text']), 0, 1000)], 'id = ?', [$m['id']]);
        return $this->presenter->media((array) $this->db->one('SELECT * FROM media WHERE id = ?', [$m['id']]));
    }

    private function opDeleteMedia(array $a): array
    {
        $m = $this->mediaRow((string) $a['media_id']);
        $svc = new MediaService($this->db);
        if ($n = $svc->inUseByPendingPosts((int) $m['id'])) {
            throw new ApiError(409, __('This file is used by {n} post(s) that haven’t been published. Remove it from them first.', ['n' => $n]), 'in_use');
        }
        $svc->delete($m);
        return ['deleted' => true, 'id' => $m['uuid']];
    }

    // ---------------------------------------------------------------- library

    private static function label(array $l): array
    {
        return ['id' => $l['uuid'], 'name' => $l['name'], 'color' => $l['color']];
    }

    private function lib(): LibraryService
    {
        return new LibraryService($this->db);
    }

    private function opListLabels(array $a): array
    {
        return ['labels' => array_map([self::class, 'label'], $this->lib()->labels($this->wsId()))];
    }

    private function findLabel(string $id): array
    {
        return $this->lib()->label($this->wsId(), $id) ?? throw ApiError::notFound(__('Label'));
    }

    private function opCreateLabel(array $a): array
    {
        [$l, $err] = $this->lib()->saveLabel($this->wsId(), $a);
        return $err ? throw ApiError::invalid($err) : self::label($l);
    }

    private function opUpdateLabel(array $a): array
    {
        $l = $this->findLabel((string) $a['label_id']);
        [$l, $err] = $this->lib()->saveLabel($this->wsId(), $a + ['name' => $l['name'], 'color' => $l['color']], $l);
        return $err ? throw ApiError::invalid($err) : self::label($l);
    }

    private function opDeleteLabel(array $a): array
    {
        $l = $this->findLabel((string) $a['label_id']);
        $this->lib()->deleteLabel($l);
        return ['deleted' => true, 'id' => $l['uuid']];
    }

    private static function signature(array $s): array
    {
        return ['id' => $s['uuid'], 'name' => $s['name'], 'content' => $s['content'], 'is_default' => (bool) $s['is_default']];
    }

    private function opListSignatures(array $a): array
    {
        return ['signatures' => array_map([self::class, 'signature'], $this->lib()->signatures($this->wsId()))];
    }

    private function findSignature(string $id): array
    {
        return $this->lib()->signature($this->wsId(), $id) ?? throw ApiError::notFound(__('Signature'));
    }

    private function opCreateSignature(array $a): array
    {
        [$s, $err] = $this->lib()->saveSignature($this->wsId(), $a);
        return $err ? throw ApiError::invalid($err) : self::signature($s);
    }

    private function opUpdateSignature(array $a): array
    {
        $s = $this->findSignature((string) $a['signature_id']);
        [$s, $err] = $this->lib()->saveSignature($this->wsId(), $a + ['name' => $s['name'], 'content' => $s['content'], 'is_default' => (bool) $s['is_default']], $s);
        return $err ? throw ApiError::invalid($err) : self::signature($s);
    }

    private function opDeleteSignature(array $a): array
    {
        $s = $this->findSignature((string) $a['signature_id']);
        $this->lib()->deleteSignature($s);
        return ['deleted' => true, 'id' => $s['uuid']];
    }

    private function template(array $t): array
    {
        return ['id' => $t['uuid'], 'name' => $t['title'], 'content' => $t['body'], 'visibility' => $t['visibility'], 'mine' => (int) $t['user_id'] === (int) $this->ctx['user']['id']];
    }

    /** API field names (name, content) -> LibraryService names (title, body), and errors back. */
    private function saveTemplate(array $a, ?array $existing): array
    {
        $in = ['title' => $a['name'] ?? ($existing['title'] ?? ''), 'body' => $a['content'] ?? ($existing['body'] ?? ''), 'visibility' => $a['visibility'] ?? ($existing['visibility'] ?? 'workspace')];
        if ($in['visibility'] === 'workspace' && !$this->can('library')) {
            $in['visibility'] = 'private';
        }
        [$t, $err] = $this->lib()->saveTemplate($this->wsId(), (int) $this->ctx['user']['id'], $in, $existing);
        if ($err) {
            $map = ['title' => 'name', 'body' => 'content'];
            throw ApiError::invalid(array_combine(array_map(fn ($k) => $map[$k] ?? $k, array_keys($err)), array_values($err)));
        }
        return $this->template($t);
    }

    private function opListTemplates(array $a): array
    {
        return ['templates' => array_map([$this, 'template'], $this->lib()->templates($this->wsId(), (int) $this->ctx['user']['id']))];
    }

    private function findTemplate(string $id, bool $forWrite = false): array
    {
        $t = $this->lib()->template($this->wsId(), (int) $this->ctx['user']['id'], $id) ?? throw ApiError::notFound(__('Template'));
        if ($forWrite && (int) $t['user_id'] !== (int) $this->ctx['user']['id'] && !$this->can('library')) {
            throw ApiError::forbidden(__('Only the person who made this template or an editor can change it.'));
        }
        return $t;
    }

    private function opCreateTemplate(array $a): array
    {
        return $this->saveTemplate($a, null);
    }

    private function opUpdateTemplate(array $a): array
    {
        return $this->saveTemplate($a, $this->findTemplate((string) $a['template_id'], true));
    }

    private function opDeleteTemplate(array $a): array
    {
        $t = $this->findTemplate((string) $a['template_id'], true);
        $this->lib()->deleteTemplate($t);
        return ['deleted' => true, 'id' => $t['uuid']];
    }

    // ---------------------------------------------------------------- notes

    private function note(array $n): array
    {
        return ['id' => $n['uuid'], 'text' => $n['body'], 'author' => $n['user_name'] ?? null, 'mine' => (int) $n['user_id'] === (int) $this->ctx['user']['id'],
            'created_at' => Presenter::iso($n['created_at']), 'edited' => !empty($n['edited_at'])];
    }

    private function opListNotes(array $a): array
    {
        $p = $this->post((string) $a['post_id']);
        return ['notes' => array_map([$this, 'note'], (new NoteService($this->db))->list((int) $p['id']))];
    }

    private function opAddNote(array $a): array
    {
        $p = $this->post((string) $a['post_id']);
        $svc = new NoteService($this->db);
        [$n, $err] = $svc->add($this->ws(), $p, $this->ctx['user'], (string) $a['text'], (array) ($a['mentions'] ?? []));
        if ($err) {
            throw ApiError::invalid(['text' => $err]);
        }
        foreach ($svc->list((int) $p['id']) as $row) {
            if ($row['uuid'] === $n['uuid']) {
                return $this->note($row);
            }
        }
        return $this->note($n);
    }

    private function opDeleteNote(array $a): array
    {
        $p = $this->post((string) $a['post_id']);
        $svc = new NoteService($this->db);
        $n = $svc->find((int) $p['id'], (string) $a['note_id']) ?? throw ApiError::notFound(__('Note'));
        if ($err = $svc->delete($n, $this->ctx['user'], $this->ctx['role'])) {
            throw ApiError::forbidden($err);
        }
        return ['deleted' => true, 'id' => $n['uuid']];
    }

    // ---------------------------------------------------------------- ideas & feeds

    private function ideaSvc(): IdeaService
    {
        return new IdeaService($this->db);
    }

    private function findIdea(string $id): array
    {
        return $this->ideaSvc()->find($this->wsId(), $id) ?? throw ApiError::notFound(__('Idea'));
    }

    private function ideaInput(array $a): array
    {
        $in = [];
        foreach (['title' => 'title', 'notes' => 'body', 'link' => 'link', 'column_id' => 'stage', 'labels' => 'labels', 'media' => 'media'] as $from => $to) {
            if (array_key_exists($from, $a)) {
                $in[$to] = $a[$from];
            }
        }
        return $in + ['source' => $this->source === 'mcp' ? 'mcp' : 'api'];
    }

    private function ideaOut(int $id): array
    {
        return IdeaService::present((array) $this->ideaSvc()->get($this->wsId(), $id));
    }

    private function opListIdeaColumns(array $a): array
    {
        return ['columns' => array_map(fn ($s) => ['id' => $s['uuid'], 'name' => $s['name']], $this->ideaSvc()->stages($this->wsId()))];
    }

    private function opListIdeas(array $a): array
    {
        $limit = (int) ($a['limit'] ?? 50);
        $offset = self::cursor($a['cursor'] ?? null);
        $stage = null;
        if (!empty($a['column_id'])) {
            $stage = (int) ($this->ideaSvc()->stage($this->wsId(), (string) $a['column_id'])['id'] ?? 0) ?: throw ApiError::notFound(__('Column'));
        }
        $rows = $this->ideaSvc()->list($this->wsId(), $stage, $a['search'] ?? null, $limit + 1, $offset);
        return ['ideas' => array_map([IdeaService::class, 'present'], array_slice($rows, 0, $limit)), 'next_cursor' => self::nextCursor($offset, $limit, count($rows))];
    }

    private function opCreateIdea(array $a): array
    {
        [$idea, $err] = $this->ideaSvc()->save($this->ws(), $this->ctx['user'], $this->ideaInput($a));
        return $err ? throw ApiError::invalid($this->renameErrors($err)) : IdeaService::present($idea);
    }

    private function renameErrors(array $err): array
    {
        $map = ['body' => 'notes', 'stage' => 'column_id'];
        return array_combine(array_map(fn ($k) => $map[$k] ?? $k, array_keys($err)), array_values($err));
    }

    private function opUpdateIdea(array $a): array
    {
        $idea = $this->findIdea((string) $a['idea_id']);
        $in = $this->ideaInput($a);
        $moveTo = $in['stage'] ?? null;
        if (isset($a['position'])) {
            unset($in['stage']);
        }
        if (count($in) > 1) {
            [, $err] = $this->ideaSvc()->save($this->ws(), $this->ctx['user'], $in, $idea);
            if ($err) {
                throw ApiError::invalid($this->renameErrors($err));
            }
        }
        if (isset($a['position'])) {
            $fresh = (array) $this->ideaSvc()->find($this->wsId(), $idea['uuid']);
            $stageUuid = $moveTo ?? (string) $this->db->value('SELECT uuid FROM idea_stages WHERE id = ?', [$fresh['stage_id']]);
            if ($err = $this->ideaSvc()->move($this->wsId(), $fresh, $stageUuid, (int) $a['position'])) {
                throw ApiError::invalid(['column_id' => $err]);
            }
        }
        return $this->ideaOut((int) $idea['id']);
    }

    private function opDeleteIdea(array $a): array
    {
        $idea = $this->findIdea((string) $a['idea_id']);
        $this->ideaSvc()->delete($idea);
        return ['deleted' => true, 'id' => $idea['uuid']];
    }

    private function opListFeeds(array $a): array
    {
        return ['feeds' => array_map([FeedService::class, 'presentFeed'], (new FeedService($this->db))->feeds($this->wsId()))];
    }

    private function opFollowFeed(array $a): array
    {
        [$feed, $err] = (new FeedService($this->db))->add($this->ws(), $this->ctx['user'], (string) $a['url'], (string) ($a['auto'] ?? 'inbox'));
        return $err ? throw ApiError::invalid(['url' => $err]) : FeedService::presentFeed($feed);
    }

    private function opUnfollowFeed(array $a): array
    {
        $svc = new FeedService($this->db);
        $feed = $svc->feed($this->wsId(), (string) $a['feed_id']) ?? throw ApiError::notFound(__('Feed'));
        $svc->delete($feed);
        return ['deleted' => true, 'id' => $feed['uuid']];
    }

    private function opListFeedItems(array $a): array
    {
        $svc = new FeedService($this->db);
        $feedId = null;
        if (!empty($a['feed_id'])) {
            $feedId = (int) ($svc->feed($this->wsId(), (string) $a['feed_id'])['id'] ?? 0) ?: throw ApiError::notFound(__('Feed'));
        }
        return ['items' => array_map([FeedService::class, 'presentItem'], $svc->inbox($this->wsId(), $feedId, (int) ($a['limit'] ?? 30)))];
    }

    private function opSaveFeedItemAsIdea(array $a): array
    {
        $svc = new FeedService($this->db);
        $item = $svc->item($this->wsId(), (string) $a['item_id']) ?? throw ApiError::notFound(__('Article'));
        $idea = $svc->toIdea($this->ws(), $this->ctx['user'], $item) ?? throw new ApiError(500, __('Could not save the idea.'), 'server_error');
        return IdeaService::present($idea);
    }

    // ---------------------------------------------------------------- webhooks

    private function hooks(): WebhookService
    {
        return new WebhookService($this->db);
    }

    private function findHook(string $id): array
    {
        return $this->hooks()->find($this->wsId(), $id) ?? throw ApiError::notFound(__('Webhook'));
    }

    private function opListWebhooks(array $a): array
    {
        return ['webhooks' => array_map([WebhookService::class, 'present'], $this->hooks()->list($this->wsId()))];
    }

    private function opCreateWebhook(array $a): array
    {
        [$w, $err, $secret] = $this->hooks()->save($this->wsId(), $a);
        return $err ? throw ApiError::invalid($err) : WebhookService::present($w) + ['secret' => $secret];
    }

    private function opUpdateWebhook(array $a): array
    {
        $w = $this->findHook((string) $a['webhook_id']);
        [$w, $err] = $this->hooks()->save($this->wsId(), $a, $w);
        return $err ? throw ApiError::invalid($err) : WebhookService::present($w);
    }

    private function opDeleteWebhook(array $a): array
    {
        $w = $this->findHook((string) $a['webhook_id']);
        $this->hooks()->delete($w);
        return ['deleted' => true, 'id' => $w['uuid']];
    }

    private function opRotateWebhookSecret(array $a): array
    {
        $w = $this->findHook((string) $a['webhook_id']);
        return ['id' => $w['uuid'], 'secret' => $this->hooks()->rotateSecret($w)];
    }

    private function opListWebhookDeliveries(array $a): array
    {
        $w = $this->findHook((string) $a['webhook_id']);
        return ['deliveries' => $this->hooks()->logs($w)];
    }

    private function opReplayWebhookDelivery(array $a): array
    {
        $w = $this->findHook((string) $a['webhook_id']);
        $d = $this->hooks()->replay($w, (string) $a['delivery_id']) ?? throw ApiError::notFound(__('Delivery'));
        return ['id' => $d['uuid'], 'status' => $d['status'], 'attempts' => (int) $d['attempts']];
    }
}
