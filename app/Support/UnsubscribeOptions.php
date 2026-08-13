<?php

namespace App\Support;

/**
 * What a sender's List-Unsubscribe header offers.
 *
 * RFC 2369 defines the header as a comma-separated list of angle-bracketed
 * URIs, usually an https endpoint and/or a mailto. RFC 8058 adds
 * List-Unsubscribe-Post, whose presence means the https endpoint accepts a
 * plain POST and needs no navigation at all (ZERO-115).
 *
 * Everything in here came from the sender, so nothing is trusted: the URL is
 * re-checked before anything is sent to it.
 */
final class UnsubscribeOptions
{
    private function __construct(
        public readonly ?string $url,
        public readonly ?string $mailto,
        public readonly bool $oneClick,
    ) {}

    public static function parse(?string $header, ?string $postHeader = null): ?self
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $url = null;
        $mailto = null;

        // Angle brackets are the delimiter; anything outside them is noise.
        preg_match_all('/<([^>]+)>/', $header, $matches);

        foreach ($matches[1] as $uri) {
            $uri = trim($uri);

            if ($mailto === null && stripos($uri, 'mailto:') === 0) {
                $mailto = $uri;

                continue;
            }

            // http is accepted from the header but never posted to; see
            // isPostable(). https is the only thing worth keeping as a link.
            if ($url === null && preg_match('#^https?://#i', $uri) === 1) {
                $url = $uri;
            }
        }

        if ($url === null && $mailto === null) {
            return null;
        }

        return new self(
            url: $url,
            mailto: $mailto,
            oneClick: $url !== null && stripos((string) $postHeader, 'one-click') !== false,
        );
    }

    /**
     * Whether the one-click POST may actually be sent.
     *
     * The endpoint is chosen by whoever sent the mail, so this is the point
     * where an inbox stops being a way to make the server issue arbitrary
     * requests: https only, and never at a host that resolves somewhere
     * private.
     */
    public function isPostable(): bool
    {
        if (! $this->oneClick || $this->url === null) {
            return false;
        }

        if (! str_starts_with(strtolower($this->url), 'https://')) {
            return false;
        }

        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) && $host !== '' && $this->resolvesPublicly($host);
    }

    private function resolvesPublicly(string $host): bool
    {
        // A bare IP is checked directly; a name is resolved first, so a
        // sender cannot point a public-looking hostname at 127.0.0.1 or the
        // cloud metadata endpoint.
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_merge(gethostbynamel($host) ?: [], []);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
