<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\Consent;

use ClickTrail\Consent\ConsentSnapshot;
use Symfony\Component\HttpFoundation\Request;

/**
 * Safe default: every request resolves to null (unknown snapshot). Per the
 * consent contract unknown = denied, so session persistence is suppressed.
 * Use it until a real CMP adapter is wired (or set consent_required=false
 * deliberately).
 */
final class NullConsentResolver implements ConsentResolverInterface
{
    public function resolve(Request $request): ?ConsentSnapshot
    {
        return null;
    }
}
