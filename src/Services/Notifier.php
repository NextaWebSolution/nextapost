<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Lang;
use App\Core\View;

/**
 * Queues notification emails (sent by the cron worker, so a request never waits on SMTP).
 * Each user can switch kinds off in Account settings (users.notify_json).
 */
final class Notifier
{
    public const KINDS = [
        'note' => 'Someone adds a note to a post I wrote or was mentioned in',
        'mention' => 'Someone mentions me in a note',
        'review' => 'A post is waiting for my approval',
        'decision' => 'My post is approved or sent back',
        'failed' => 'A post I wrote fails to publish',
    ];

    public function __construct(private DB $db)
    {
    }

    public static function wants(array $user, string $kind): bool
    {
        $prefs = json_decode((string) ($user['notify_json'] ?? ''), true);
        return !is_array($prefs) || !array_key_exists($kind, $prefs) || (bool) $prefs[$kind];
    }

    /**
     * Texts are English with {placeholders}: a string, or [text, vars]. They are translated into
     * the recipient's language here, so each person reads the email in their own language.
     *
     * @param array{heading:string|array, lines:list<string|array>, quote?:string, link?:string, button?:string|array} $msg
     */
    public function email(array $user, string $kind, string|array $subject, array $msg): bool
    {
        if (empty($user['email']) || !empty($user['deleted_at']) || !(int) ($user['is_active'] ?? 1) || !self::wants($user, $kind)) {
            return false;
        }
        $tr = static fn (string|array $t): string => is_array($t) ? __((string) $t[0], (array) ($t[1] ?? [])) : __($t);
        $prev = Lang::current();
        Lang::set($user['locale'] ?? 'en');
        try {
            $subject = $tr($subject);
            $data = [
                'heading' => $tr($msg['heading']),
                'lines' => array_map($tr, $msg['lines'] ?? []),
                'quote' => $msg['quote'] ?? null,
                'link' => $msg['link'] ?? null,
                'button' => isset($msg['button']) ? $tr($msg['button']) : null,
            ];
            $html = View::render('emails/notice', $data, null);
        } finally {
            Lang::set($prev);
        }
        return (new Queue($this->db))->push('email', ['to' => $user['email'], 'subject' => $subject, 'html' => $html], null, null, 3);
    }
}
