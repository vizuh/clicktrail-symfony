<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\Attribution;

use ClickTrail\Consent\ConsentSnapshot;
use ClickTrail\Core\StoredState;

/**
 * Per-request attribution result. Attached to the Request as a request
 * attribute (see RequestSubscriber::ATTRIBUTE) so controllers, form listeners
 * and Twig can read it without re-running the merge.
 *
 * Mirrors clicktrail-psr-middleware's AttributionContext shape.
 */
final class AttributionContext
{
    /**
     * @param string[] $suppressionReasons audit trail of blocked actions and why
     */
    public function __construct(
        public readonly StoredState $attribution,
        public readonly ?ConsentSnapshot $consent,
        public readonly array $suppressionReasons = [],
        public readonly bool $persisted = false,
    ) {
    }

    /** @return array<string, mixed> flat render-ready attribution fields */
    public function toRenderArray(): array
    {
        $out = [];
        foreach (['first', 'last'] as $slot) {
            $touch = $slot === 'first' ? $this->attribution->first : $this->attribution->last;
            if ($touch === null) {
                continue;
            }
            $prefix = $slot . '_';
            $out += [
                $prefix . 'source' => $touch->source,
                $prefix . 'medium' => $touch->medium,
                $prefix . 'campaign' => $touch->campaign,
                $prefix . 'content' => $touch->content,
                $prefix . 'term' => $touch->term,
                $prefix . 'utm_id' => $touch->utmId,
                $prefix . 'referrer' => $touch->referrer,
                $prefix . 'landing_page' => $touch->landingPage,
                $prefix . 'touch_timestamp' => $touch->touchTimestamp,
            ];
        }
        $out['consent_state'] = $this->consent?->analyticsStorage->value ?? 'unknown';

        return $out;
    }
}
