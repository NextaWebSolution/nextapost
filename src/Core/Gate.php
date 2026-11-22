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

    /** Role names and descriptions in the interface language. @return array<string, string> */
    public static function roles(): array
    {
        return [
            'admin' => __('Admin — full access, channels, settings, members'),
            'editor' => __('Editor — create and edit posts, media'),
            'contributor' => __('Contributor — create drafts and submit for review'),
            'approver' => __('Approver — review and approve posts (client)'),
            'viewer' => __('Viewer — read-only'),
        ];
    }

    /** Short role name in the interface language. */
    public static function roleName(string $role): string
    {
        return match ($role) {
            'admin' => __('Admin'), 'editor' => __('Editor'), 'contributor' => __('Contributor'),
            'approver' => __('Approver'), 'viewer' => __('Viewer'), default => ucfirst($role),
        };
    }

    /** Contributors may only change posts they wrote; admins and editors may change any post they can edit. */
    public static function canChangePost(string $role, array $post, int $userId): bool
    {
        return $role !== 'contributor' || (int) ($post['author_id'] ?? 0) === $userId;
    }

    public static function can(string $role, string $ability, array $workspace): bool
    {
        $approvalOff = ($workspace['approval_mode'] ?? 'single') === 'none';
        return match ($ability) {
            'view' => isset(self::ROLES[$role]),
            'edit', 'media' => in_array($role, ['admin', 'editor', 'contributor'], true),
            'publish' => $role === 'admin' || ($approvalOff && in_array($role, ['editor', 'contributor'], true)) || ($role === 'editor' && ($workspace['approval_mode'] ?? '') === 'editors'),
            'approve' => in_array($role, ['admin', 'approver'], true),
            'library' => in_array($role, ['admin', 'editor'], true),
            'channels', 'settings', 'members' => $role === 'admin',
            default => false,
        };
    }
}
