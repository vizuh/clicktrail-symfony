<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\Support;

/**
 * SHA-256 webhook signature helper with constant-time comparison
 * (hash_equals). Signatures are exchanged as bare hex; a "sha256=" prefix is
 * accepted on verify.
 *
 * // TODO verify against ingest/webhook API docs before release whether the
 * canonical scheme is plain SHA-256 or HMAC-SHA256 (clicktrail-laravel uses
 * HMAC); align all packages on one scheme before first release.
 */
final class WebhookSignature
{
    public const ALGO = 'sha256';

    public static function sign(string $payload, string $secret): string
    {
        return hash(self::ALGO, $payload . ':' . $secret);
    }

    public static function verify(string $payload, string $signatureHeader, string $secret): bool
    {
        $provided = strtolower(trim($signatureHeader));
        if ($provided === '') {
            return false;
        }
        if (str_starts_with($provided, self::ALGO . '=')) {
            $provided = substr($provided, strlen(self::ALGO) + 1);
        }

        return hash_equals(self::sign($payload, $secret), $provided);
    }
}
