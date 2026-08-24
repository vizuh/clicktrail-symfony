<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\Consent;

use ClickTrail\Consent\ConsentSnapshot;
use Symfony\Component\HttpFoundation\Request;

/**
 * Adapter-facing consent source for Symfony. CMP-specific logic (CookieYes,
 * Cookiebot, iubenda, Complianz, ...) lives behind this interface; capture
 * and delivery code only ever sees the normalized ConsentSnapshot.
 *
 * Contract: return null when no decision is known. Null means "unknown",
 * which is denied by default per the consent compatibility contract.
 */
interface ConsentResolverInterface
{
    public function resolve(Request $request): ?ConsentSnapshot;
}
