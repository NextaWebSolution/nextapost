<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * Google Business Profile: updates, events and offers on a business location.
 * Google must first approve API access for the Google Cloud project (Business Profile API access request).
 */
final class GoogleBusinessProvider extends AbstractProvider
{
    private const CTA = ['BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'];

    public function meta(): array
    {
        return ['name' => 'Google Business Profile', 'color' => '#4285F4', 'letter' => 'G', 'kind' => 'oauth', 'app' => 'google',
            'description' => 'Post updates, events and offers to your business on Google Search and Maps.',
            'content_types' => ['update' => __('Update'), 'event' => __('Event'), 'offer' => __('Offer')],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 1500, 'media_max' => 1, 'types' => ['image'], 'max_bytes' => ['image' => 5 * 1024 * 1024]];
    }

    public function options(array $meta = []): array
    {
        return [
            ['key' => 'cta', 'label' => __('Button'), 'type' => 'select', 'types' => ['update', 'event'], 'empty' => __('No button'),
                'choices' => ['BOOK' => __('Book'), 'ORDER' => __('Order online'), 'SHOP' => __('Buy'), 'LEARN_MORE' => __('Learn more'), 'SIGN_UP' => __('Sign up'), 'CALL' => __('Call now')]],
            ['key' => 'cta_url', 'label' => __('Button link'), 'type' => 'url', 'types' => ['update', 'event'], 'help' => __('Not needed for “Call now”; Google uses your business phone number.')],
            ['key' => 'event_title', 'label' => __('Title'), 'type' => 'text', 'types' => ['event', 'offer'], 'required' => true, 'max' => 58],
            ['key' => 'start', 'label' => __('Starts'), 'type' => 'datetime', 'types' => ['event', 'offer'], 'required' => true],
            ['key' => 'end', 'label' => __('Ends'), 'type' => 'datetime', 'types' => ['event', 'offer'], 'required' => true],
            ['key' => 'coupon', 'label' => __('Coupon code'), 'type' => 'text', 'types' => ['offer'], 'max' => 58],
            ['key' => 'redeem_url', 'label' => __('Link to redeem the offer'), 'type' => 'url', 'types' => ['offer']],
            ['key' => 'terms', 'label' => __('Terms and conditions'), 'type' => 'textarea', 'types' => ['offer'], 'max' => 5000],
        ];
    }

    public function validateOptions(?string $contentType, array $options, string $text, array $media = [], array $meta = [], bool $strict = true): array
    {
        $errors = parent::validateOptions($contentType, $options, $text, $media, $meta, $strict);
        $type = $contentType ?? 'update';
        $cta = (string) ($options['cta'] ?? '');
        if ($type !== 'offer' && $cta !== '' && $cta !== 'CALL' && empty($options['cta_url'])) {
            $errors[] = __('Google Business Profile: add the link for the button.');
        }
        if (in_array($type, ['event', 'offer'], true) && !empty($options['start']) && !empty($options['end']) && strcmp((string) $options['end'], (string) $options['start']) <= 0) {
            $errors[] = __('Google Business Profile: the end must be after the start.');
        }
        return $errors;
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return GoogleOAuth::authorizeUrl($app, $redirectUri, $state, $codeChallenge, ['https://www.googleapis.com/auth/business.manage']);
    }

    private function get(string $url, string $token): array
    {
        return GoogleOAuth::check($this->http('GET', $url, ['headers' => ['Authorization: Bearer ' . $token]]), 'Google Business Profile');
    }

    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array
    {
        $creds = GoogleOAuth::exchange($app, $query, $redirectUri, $codeVerifier, 'Google Business Profile');
        $out = [];
        $accounts = $this->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=20', $creds['access_token']);
        foreach ((array) ($accounts['accounts'] ?? []) as $acc) {
            $locs = $this->get('https://mybusinessbusinessinformation.googleapis.com/v1/' . $acc['name'] . '/locations?readMask=name,title,storefrontAddress&pageSize=100', $creds['access_token']);
            foreach ((array) ($locs['locations'] ?? []) as $loc) {
                $addr = $loc['storefrontAddress'] ?? [];
                $out[] = [
                    'external_id' => (string) $loc['name'], 'name' => (string) ($loc['title'] ?? $loc['name']),
                    'username' => trim(implode(', ', array_filter([$addr['locality'] ?? null, $addr['regionCode'] ?? null]))) ?: null,
                    'avatar_url' => null,
                    'credentials' => $creds + ['account' => (string) $acc['name'], 'location' => (string) $loc['name']],
                    'meta' => ['account' => (string) $acc['name'], 'location' => (string) $loc['name']],
                ];
            }
        }
        if (!$out) {
            throw new ProviderException(__('No business locations were found for this Google account, or the Business Profile API isn’t enabled for your Google Cloud project yet.'));
        }
        return $out;
    }

    public function refresh(array $credentials, array $app): ?array
    {
        return GoogleOAuth::refresh($credentials, $app, 'Google Business Profile');
    }

    /** "2026-12-01T18:30" → Google date + time objects. */
    private static function when(string $local): array
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr(str_replace(' ', 'T', $local), 0, 16)) ?: new \DateTimeImmutable($local);
        return [['year' => (int) $d->format('Y'), 'month' => (int) $d->format('n'), 'day' => (int) $d->format('j')], ['hours' => (int) $d->format('G'), 'minutes' => (int) $d->format('i')]];
    }

    /** The request body for a local post (public for tests). */
    public function body(string $text, array $media, array $context): array
    {
        $type = $this->type($context);
        $post = ['languageCode' => 'en', 'summary' => $text, 'topicType' => ['update' => 'STANDARD', 'event' => 'EVENT', 'offer' => 'OFFER'][$type] ?? 'STANDARD'];
        if ($media) {
            $post['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $media[0]['url']]];
        }
        $cta = (string) $this->opt($context, 'cta', '');
        if ($type !== 'offer' && in_array($cta, self::CTA, true)) {
            $post['callToAction'] = ['actionType' => $cta] + ($cta !== 'CALL' ? ['url' => (string) $this->opt($context, 'cta_url', '')] : []);
        }
        if ($type === 'event' || $type === 'offer') {
            [$sd, $st] = self::when((string) $this->opt($context, 'start'));
            [$ed, $et] = self::when((string) $this->opt($context, 'end'));
            $post['event'] = ['title' => (string) $this->opt($context, 'event_title', ''), 'schedule' => ['startDate' => $sd, 'startTime' => $st, 'endDate' => $ed, 'endTime' => $et]];
        }
        if ($type === 'offer') {
            $post['offer'] = array_filter(['couponCode' => $this->opt($context, 'coupon'), 'redeemOnlineUrl' => $this->opt($context, 'redeem_url'), 'termsConditions' => $this->opt($context, 'terms')]);
        }
        return $post;
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $path = $credentials['account'] . '/' . $credentials['location'];
        $res = GoogleOAuth::check($this->http('POST', 'https://mybusiness.googleapis.com/v4/' . $path . '/localPosts', [
            'json' => $this->body($text, $media, $context), 'headers' => ['Authorization: Bearer ' . $credentials['access_token']],
        ]), 'Google Business Profile');
        return new PublishResult((string) ($res['name'] ?? ''), isset($res['searchUrl']) ? (string) $res['searchUrl'] : null);
    }
}
