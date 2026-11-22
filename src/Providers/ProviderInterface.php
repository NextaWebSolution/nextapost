<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * A social network adapter.
 *
 * Account array shape returned by connect()/handleCallback():
 *   ['external_id'=>string, 'name'=>string, 'username'=>?string, 'avatar_url'=>?string,
 *    'credentials'=>array (encrypted at rest), 'meta'=>array]
 *
 * Media item shape passed to validate()/publish():
 *   ['path'=>absolute file path, 'url'=>public https URL, 'mime'=>string, 'type'=>'image'|'video',
 *    'size'=>int bytes, 'alt'=>?string, 'width'=>?int, 'height'=>?int]
 */
interface ProviderInterface
{
    /** @return array{name:string,color:string,letter:string,kind:string,description:string,app?:string} */
    public function meta(): array;

    /** Form fields for token-based providers: [['name'=>,'label'=>,'type'=>,'help'=>,'placeholder'=>]] */
    public function fields(): array;

    /** Token-based connect. @return array account */
    public function connect(array $input): array;

    /** OAuth: URL to send the user to. $app = ['client_id'=>, 'client_secret'=>] */
    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string;

    /** OAuth: exchange the code. @return array[] accounts the user can pick from */
    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array;

    /** Refresh credentials if needed. Returns updated credentials or null if unchanged. */
    public function refresh(array $credentials, array $app): ?array;

    /** @return array{text_max:int,media_max:int,types:string[],caption_max?:int,media_required?:bool,max_bytes?:array} */
    public function limits(array $meta = []): array;

    /** @return string[] human readable problems (empty = OK) */
    public function validate(string $text, array $media, array $meta = []): array;

    /**
     * Extra fields the composer shows for this channel (title, privacy, board, poll…).
     * Field: ['key','label','type'=>text|textarea|select|checkbox|url|list|number|datetime,'types'=>?string[] content types it applies to,
     *         'required'=>bool,'max'=>int,'min'=>int,'choices'=>[value=>label],'help'=>string,'default'=>mixed,'dynamic'=>bool]
     */
    public function options(array $meta = []): array;

    /**
     * @param bool $strict false for drafts: only check the format of what was filled in, not required fields or media rules
     * @return list<string> errors for per-network options (media items as in validate())
     */
    public function validateOptions(?string $contentType, array $options, string $text, array $media = [], array $meta = [], bool $strict = true): array;

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult;
}
