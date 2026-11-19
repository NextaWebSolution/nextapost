<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Crypto;
use App\Core\Settings;

final class ProviderFactory
{
    /** Display order in the UI. */
    private const MAP = [
        'facebook' => FacebookProvider::class,
        'instagram' => InstagramProvider::class,
        'linkedin' => LinkedInProvider::class,
        'linkedin_page' => LinkedInPageProvider::class,
        'x' => XProvider::class,
        'telegram' => TelegramProvider::class,
        'discord' => DiscordProvider::class,
        'mastodon' => MastodonProvider::class,
        'bluesky' => BlueskyProvider::class,
        'sandbox' => SandboxProvider::class,
    ];

    /** OAuth app definitions shown in System → Integrations. */
    public const APPS = [
        'meta' => ['name' => 'Meta (Facebook & Instagram)', 'networks' => ['facebook', 'instagram'], 'id_label' => 'App ID', 'secret_label' => 'App secret', 'docs' => 'https://developers.facebook.com/apps/'],
        'linkedin' => ['name' => 'LinkedIn (personal profiles)', 'networks' => ['linkedin'], 'id_label' => 'Client ID', 'secret_label' => 'Client secret', 'docs' => 'https://www.linkedin.com/developers/apps'],
        'linkedin_page' => ['name' => 'LinkedIn Pages (company pages)', 'networks' => ['linkedin_page'], 'id_label' => 'Client ID', 'secret_label' => 'Client secret', 'docs' => 'https://www.linkedin.com/developers/apps'],
        'x' => ['name' => 'X (Twitter)', 'networks' => ['x'], 'id_label' => 'OAuth 2.0 Client ID', 'secret_label' => 'OAuth 2.0 Client secret', 'docs' => 'https://developer.x.com/en/portal/dashboard'],
    ];

    private static array $instances = [];

    public static function has(string $network): bool
    {
        return isset(self::MAP[$network]);
    }

    public static function make(string $network): ProviderInterface
    {
        if (!self::has($network)) {
            throw new ProviderException('Unknown network: ' . $network);
        }
        return self::$instances[$network] ??= new (self::MAP[$network])();
    }

    /** @return array<string, ProviderInterface> */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::MAP) as $k) {
            $out[$k] = self::make($k);
        }
        return $out;
    }

    public static function meta(string $network): array
    {
        if (!self::has($network)) {
            return ['name' => ucfirst($network), 'color' => '#71717A', 'letter' => '?', 'kind' => 'token', 'description' => ''];
        }
        return self::make($network)->meta();
    }

    /** Decrypted OAuth app credentials for a network, or [] if not configured. */
    public static function appConfig(string $network): array
    {
        $appKey = self::make($network)->meta()['app'] ?? null;
        if (!$appKey) {
            return [];
        }
        $all = (array) Settings::get('integrations', []);
        $c = $all[$appKey] ?? [];
        if (empty($c['client_id']) || empty($c['client_secret_enc'])) {
            return [];
        }
        try {
            return ['client_id' => (string) $c['client_id'], 'client_secret' => Crypto::decrypt((string) $c['client_secret_enc'])];
        } catch (\Throwable) {
            return [];
        }
    }

    public static function redirectUri(string $network): string
    {
        $appKey = self::make($network)->meta()['app'] ?? $network;
        return abs_url('oauth/' . $appKey . '/callback');
    }
}
