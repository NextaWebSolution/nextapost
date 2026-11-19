<?php

declare(strict_types=1);

namespace App\Core;

/** Workspace role permissions. */
final class Gate
{
    public const ROLES = [
        'admin' => 'Admin — full access, channels, settings, members',
        'editor' => 'Editor — create and edit posts, media',
        'contributor' => 'Contributor — create drafts and submit for review',
        'approver' => 'Approver — review and approve posts (client)',
        'viewer' => 'Viewer — read-only',
    ];

    public static function can(string $role, string $ability, array $workspace): bool
    {
        $approvalOff = ($workspace['approval_mode'] ?? 'single') === 'none';
        return match ($ability) {
            'view' => isset(self::ROLES[$role]),
            'edit', 'media' => in_array($role, ['admin', 'editor', 'contributor'], true),
            'publish' => $role === 'admin' || ($approvalOff && in_array($role, ['editor', 'contributor'], true)) || ($role === 'editor' && ($workspace['approval_mode'] ?? '') === 'editors'),
            'approve' => in_array($role, ['admin', 'approver'], true),
            'channels', 'settings', 'members' => $role === 'admin',
            default => false,
        };
    }
}
