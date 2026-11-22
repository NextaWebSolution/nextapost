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
        'instagram_direct' => InstagramDirectProvider::class,
        'instagram' => InstagramProvider::class,
        'threads' => ThreadsProvider::class,
        'linkedin' => LinkedInProvider::class,
        'linkedin_page' => LinkedInPageProvider::class,
        'x' => XProvider::class,
        'tiktok' => TikTokProvider::class,
        'youtube' => YouTubeProvider::class,
        'pinterest' => PinterestProvider::class,
        'google_business' => GoogleBusinessProvider::class,
        'wordpress' => WordPressProvider::class,
        'telegram' => TelegramProvider::class,
        'discord' => DiscordProvider::class,
        'mastodon' => MastodonProvider::class,
        'bluesky' => BlueskyProvider::class,
        'sandbox' => SandboxProvider::class,
    ];

    /** OAuth app definitions shown in System → Integrations. */
    public const APPS = [
        'meta' => ['name' => 'Meta (Facebook Pages & Instagram via Facebook)', 'networks' => ['facebook', 'instagram'], 'id_label' => 'App ID', 'secret_label' => 'App secret', 'docs' => 'https://developers.facebook.com/apps/',
            'help' => 'Add “Facebook Login for Business” and the Instagram Graph API. Permissions: pages_show_list, pages_manage_posts, pages_read_engagement, business_management, instagram_basic, instagram_content_publish (needs Meta App Review before other people can use it).'],
        'instagram' => ['name' => 'Instagram (Instagram sign-in)', 'networks' => ['instagram_direct'], 'id_label' => 'Instagram app ID', 'secret_label' => 'Instagram app secret', 'docs' => 'https://developers.facebook.com/apps/',
            'help' => 'In your Meta app add the “Instagram” product → “API setup with Instagram login”, copy the Instagram app ID and secret (not the Facebook ones) and add the redirect URL under Business login settings. Permissions: instagram_business_basic, instagram_business_content_publish, instagram_business_manage_comments. Works for professional (Business/Creator) accounts; App Review is needed before other people can connect.'],
        'threads' => ['name' => 'Threads', 'networks' => ['threads'], 'id_label' => 'Threads app ID', 'secret_label' => 'Threads app secret', 'docs' => 'https://developers.facebook.com/apps/',
            'help' => 'Create a Meta app with the “Access the Threads API” use case, add the redirect URL as a callback, and use the Threads app ID and secret. Permissions: threads_basic, threads_content_publish. Until App Review (advanced access) only testers added to the app can connect. Threads allows 250 posts per profile per 24 hours.'],
        'linkedin' => ['name' => 'LinkedIn (personal profiles)', 'networks' => ['linkedin'], 'id_label' => 'Client ID', 'secret_label' => 'Client secret', 'docs' => 'https://www.linkedin.com/developers/apps',
            'help' => 'Add the products “Share on LinkedIn” and “Sign In with LinkedIn using OpenID Connect”. Self-serve, for personal profiles.'],
        'linkedin_page' => ['name' => 'LinkedIn Pages (company pages)', 'networks' => ['linkedin_page'], 'id_label' => 'Client ID', 'secret_label' => 'Client secret', 'docs' => 'https://www.linkedin.com/developers/apps',
            'help' => 'Create a separate LinkedIn app associated with your company Page, request the Community Management API product (keep it the only product on this app), and complete LinkedIn’s review (Development tier, then Standard tier with a screencast). Scopes used: w_organization_social, rw_organization_admin.'],
        'x' => ['name' => 'X (Twitter)', 'networks' => ['x'], 'id_label' => 'OAuth 2.0 Client ID', 'secret_label' => 'OAuth 2.0 Client secret', 'docs' => 'https://developer.x.com/en/portal/dashboard',
            'help' => 'User authentication settings → OAuth 2.0, type “Web App”, permissions Read and write. X bills API usage to your developer account.'],
        'tiktok' => ['name' => 'TikTok', 'networks' => ['tiktok'], 'id_label' => 'Client key', 'secret_label' => 'Client secret', 'docs' => 'https://developers.tiktok.com/apps/',
            'help' => 'Create an app, add “Login Kit” and “Content Posting API” with Direct Post turned on, scopes user.info.basic, video.upload, video.publish, and add the redirect URL. Until TikTok audits the app, posts can only be private (“Only me”) and at most 5 people can connect in 24 hours. Photo posts need your media domain verified in the TikTok app settings.'],
        'google' => ['name' => 'Google (YouTube & Business Profile)', 'networks' => ['youtube', 'google_business'], 'id_label' => 'OAuth client ID', 'secret_label' => 'OAuth client secret', 'docs' => 'https://console.cloud.google.com/apis/credentials',
            'help' => 'In Google Cloud create an OAuth client of type “Web application” with this redirect URL. For YouTube enable “YouTube Data API v3” (default quota ≈ 6 uploads a day; unverified apps upload as private until Google’s audit). For Business Profile enable the “My Business” APIs and request Business Profile API access (Google reviews it, usually within 2 weeks). Publish the OAuth consent screen so sign-ins don’t expire after 7 days.'],
        'pinterest' => ['name' => 'Pinterest', 'networks' => ['pinterest'], 'id_label' => 'App ID', 'secret_label' => 'App secret key', 'docs' => 'https://developers.pinterest.com/apps/',
            'help' => 'Create an app, add the redirect URL, scopes boards:read, pins:read, pins:write, user_accounts:read. New apps get Trial access, where pins can only be created in Pinterest’s sandbox: set PINTEREST_SANDBOX=1 in .env for testing, and request Standard access (with a short demo video) to post for real.'],
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
