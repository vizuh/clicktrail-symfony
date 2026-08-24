<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\Attribution;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Read-side accessor for the per-request AttributionContext. The context is
 * stored on request attributes by RequestSubscriber; this service is a thin,
 * stateless lookup for services that cannot receive the Request directly.
 */
final class ContextHolder
{
    public const ATTRIBUTE = 'clicktrail.context';

    private RequestStack $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public function set(Request $request, AttributionContext $context): void
    {
        $request->attributes->set(self::ATTRIBUTE, $context);
    }

    public function get(): ?AttributionContext
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null || !$request->attributes->has(self::ATTRIBUTE)) {
            return null;
        }
        $context = $request->attributes->get(self::ATTRIBUTE);

        return $context instanceof AttributionContext ? $context : null;
    }
}
