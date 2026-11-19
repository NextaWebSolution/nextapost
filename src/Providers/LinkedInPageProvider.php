<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * LinkedIn Company Pages via the Community Management API (scope w_organization_social).
 *
 * LinkedIn approves this product separately (Development tier, then Standard tier with a screencast)
 * and expects it in its own developer app, so it has its own keys in System → Integrations.
 * The member who signs in must be a Page ADMINISTRATOR, CONTENT_ADMIN or DIRECT_SPONSORED_CONTENT_POSTER.
 */
final class LinkedInPageProvider extends LinkedInProvider
{
    private const ROLES = ['ADMINISTRATOR', 'CONTENT_ADMIN', 'DIRECT_SPONSORED_CONTENT_POSTER'];

    public function meta(): array
    {
        return ['name' => 'LinkedIn Page', 'color' => '#0A66C2', 'letter' => 'in', 'kind' => 'oauth', 'app' => 'linkedin_page',
            'description' => 'Post as your company Page. Needs LinkedIn’s Community Management API approval and a Page admin or content-admin role.'];
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
            'response_type' => 'code', 'client_id' => $app['client_id'], 'redirect_uri' => $redirectUri,
            'state' => $state, 'scope' => 'w_organization_social rw_organization_admin',
        ]);
    }

    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array
    {
        if (!empty($query['error'])) {
            throw new ProviderException('LinkedIn sign-in was cancelled: ' . ($query['error_description'] ?? $query['error']));
        }
        $tok = $this->ok($this->http('POST', 'https://www.linkedin.com/oauth/v2/accessToken', ['form' => [
            'grant_type' => 'authorization_code', 'code' => (string) ($query['code'] ?? ''), 'redirect_uri' => $redirectUri,
            'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'],
        ]]));
        $token = (string) $tok['access_token'];
        $acls = $this->ok($this->http('GET', 'https://api.linkedin.com/rest/organizationAcls?q=roleAssignee&state=APPROVED&count=100', ['headers' => $this->headers($token)]));
        $orgIds = [];
        foreach ($acls['elements'] ?? [] as $acl) {
            if (in_array($acl['role'] ?? '', self::ROLES, true) && preg_match('/^urn:li:organization:(\d+)$/', (string) ($acl['organization'] ?? ''), $m)) {
                $orgIds[$m[1]] = true;
            }
        }
        if (!$orgIds) {
            throw new ProviderException('Your LinkedIn account isn’t an admin or content admin of any company Page. Ask a Page admin to give you that role, then connect again.');
        }
        $accounts = [];
        foreach (array_keys($orgIds) as $id) {
            $org = [];
            try {
                $org = $this->ok($this->http('GET', 'https://api.linkedin.com/rest/organizations/' . $id, ['headers' => $this->headers($token)]));
            } catch (ProviderException) {
            }
            $vanity = $org['vanityName'] ?? null;
            $accounts[] = [
                'external_id' => 'org:' . $id,
                'name' => (string) (($org['localizedName'] ?? '') ?: 'LinkedIn Page ' . $id),
                'username' => $vanity ? 'linkedin.com/company/' . $vanity : null,
                'avatar_url' => null,
                'credentials' => [
                    'access_token' => $token, 'author_urn' => 'urn:li:organization:' . $id,
                    'expires_at' => time() + (int) ($tok['expires_in'] ?? 5184000), 'refresh_token' => $tok['refresh_token'] ?? null,
                ],
                'meta' => ['vanity' => $vanity],
            ];
        }
        return $accounts;
    }
}
