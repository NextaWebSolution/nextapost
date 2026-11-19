<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Env;

/** LinkedIn personal profiles via "Share on LinkedIn" + "Sign In with LinkedIn using OpenID Connect" (self-serve products). */
class LinkedInProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'LinkedIn', 'color' => '#0A66C2', 'letter' => 'in', 'kind' => 'oauth', 'app' => 'linkedin',
            'description' => 'Post to your personal LinkedIn profile. For company Pages use “LinkedIn Page”.'];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 3000, 'media_max' => 9, 'types' => ['image', 'document'], 'document_alone' => true, 'max_pages' => 300,
            'max_bytes' => ['image' => 8 * 1024 * 1024, 'document' => 100 * 1024 * 1024]];
    }

    protected static function version(): string
    {
        $v = (string) Env::get('LINKEDIN_VERSION', '202510');
        return preg_match('/^\d{6}$/', $v) ? $v : '202510';
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
            'response_type' => 'code', 'client_id' => $app['client_id'], 'redirect_uri' => $redirectUri,
            'state' => $state, 'scope' => 'openid profile w_member_social',
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
        $me = $this->ok($this->http('GET', 'https://api.linkedin.com/v2/userinfo', ['headers' => ['Authorization: Bearer ' . $tok['access_token']]]));
        return [[
            'external_id' => (string) $me['sub'], 'name' => (string) ($me['name'] ?? 'LinkedIn member'), 'username' => null,
            'avatar_url' => $me['picture'] ?? null,
            'credentials' => ['access_token' => $tok['access_token'], 'person_urn' => 'urn:li:person:' . $me['sub'],
                'expires_at' => time() + (int) ($tok['expires_in'] ?? 5184000), 'refresh_token' => $tok['refresh_token'] ?? null],
            'meta' => [],
        ]];
    }

    public function refresh(array $credentials, array $app): ?array
    {
        $exp = (int) ($credentials['expires_at'] ?? 0);
        if ($exp === 0 || $exp > time() + 300) {
            return null;
        }
        if (empty($credentials['refresh_token'])) {
            throw new ProviderException('LinkedIn: the connection expired (LinkedIn tokens last 60 days). Reconnect this channel.', false, true);
        }
        $tok = $this->ok($this->http('POST', 'https://www.linkedin.com/oauth/v2/accessToken', ['form' => [
            'grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token'],
            'client_id' => $app['client_id'] ?? '', 'client_secret' => $app['client_secret'] ?? '',
        ]]));
        return ['access_token' => $tok['access_token'], 'expires_at' => time() + (int) ($tok['expires_in'] ?? 5184000), 'refresh_token' => $tok['refresh_token'] ?? $credentials['refresh_token']] + $credentials;
    }

    /** LinkedIn "little text" format: reserved characters must be escaped. */
    public static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $text);
    }

    /** Documents API: initialise, upload the bytes, wait until LinkedIn has processed the file. Returns urn:li:document:… */
    protected function uploadDocument(string $token, string $owner, array $m): string
    {
        $init = $this->ok($this->http('POST', 'https://api.linkedin.com/rest/documents?action=initializeUpload', [
            'headers' => $this->headers($token), 'json' => ['initializeUploadRequest' => ['owner' => $owner]],
        ]));
        $upload = (string) ($init['value']['uploadUrl'] ?? '');
        $urn = (string) ($init['value']['document'] ?? '');
        $host = strtolower((string) parse_url($upload, PHP_URL_HOST));
        if ($urn === '' || !str_starts_with($upload, 'https://') || !preg_match('/(^|\.)(linkedin\.com|licdn\.com)$/', $host)) {
            throw new ProviderException('LinkedIn did not return a valid document upload address.', true);
        }
        $this->ok($this->http('PUT', $upload, [
            'headers' => ['Authorization: Bearer ' . $token, 'Content-Type: application/octet-stream'],
            'body' => (string) file_get_contents($m['path']), 'timeout' => 300,
        ]));
        // LinkedIn converts the file before it can be posted (PDF quickly, PPTX/DOCX can take longer).
        for ($i = 0; $i < 30; $i++) {
            try {
                $st = $this->http('GET', 'https://api.linkedin.com/rest/documents/' . rawurlencode($urn), ['headers' => $this->headers($token), 'timeout' => 20]);
            } catch (ProviderException) {
                break;
            }
            $status = (string) ($st['json']['status'] ?? '');
            if ($st['status'] !== 200 || $status === 'AVAILABLE' || $status === '') {
                break;
            }
            if ($status === 'PROCESSING_FAILED') {
                throw new ProviderException('LinkedIn couldn’t process this document. Export it again as a standard PDF and retry.');
            }
            sleep(3);
        }
        return $urn;
    }

    protected function headers(string $token): array
    {
        return ['Authorization: Bearer ' . $token, 'LinkedIn-Version: ' . self::version(), 'X-Restli-Protocol-Version: 2.0.0'];
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $token = (string) $credentials['access_token'];
        $author = (string) ($credentials['author_urn'] ?? $credentials['person_urn']);
        $document = null;
        $images = [];
        foreach ($media as $m) {
            if ($m['type'] === 'document') {
                $document = ['id' => $this->uploadDocument($token, $author, $m), 'title' => (string) ($m['title'] ?? 'Document')];
                continue;
            }
            $init = $this->ok($this->http('POST', 'https://api.linkedin.com/rest/images?action=initializeUpload', [
                'headers' => $this->headers($token), 'json' => ['initializeUploadRequest' => ['owner' => $author]],
            ]));
            $upload = $init['value']['uploadUrl'] ?? '';
            $urn = $init['value']['image'] ?? '';
            $uploadHost = strtolower((string) parse_url((string) $upload, PHP_URL_HOST));
            if (!str_starts_with((string) $upload, 'https://') || !preg_match('/(^|\.)(linkedin\.com|licdn\.com)$/', $uploadHost)) {
                throw new ProviderException('LinkedIn did not return an upload URL.', true);
            }
            $this->ok($this->http('PUT', (string) $upload, ['headers' => ['Authorization: Bearer ' . $token, 'Content-Type: ' . $m['mime']], 'body' => (string) file_get_contents($m['path']), 'timeout' => 180]));
            $images[] = ['id' => $urn, 'altText' => mb_substr((string) ($m['alt'] ?? ''), 0, 4086)];
        }
        $body = [
            'author' => $author, 'commentary' => self::escape($text), 'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false,
        ];
        if ($document) {
            $body['content'] = ['media' => $document];
        } elseif (count($images) === 1) {
            $body['content'] = ['media' => $images[0]];
        } elseif ($images) {
            $body['content'] = ['multiImage' => ['images' => $images]];
        }
        $resp = $this->http('POST', 'https://api.linkedin.com/rest/posts', ['headers' => $this->headers($token), 'json' => $body]);
        $this->ok($resp);
        $id = (string) ($resp['headers']['x-restli-id'] ?? $resp['headers']['x-linkedin-id'] ?? '');
        return new PublishResult($id, $id !== '' ? 'https://www.linkedin.com/feed/update/' . $id . '/' : null);
    }
}
