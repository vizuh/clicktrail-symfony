<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\EventSubscriber;

use ClickTrail\Consent\ConsentBehavior;
use ClickTrail\Consent\ConsentSnapshot;
use ClickTrail\Core\AttributionInput;
use ClickTrail\Core\StoredState;
use ClickTrail\Core\TouchMerger;
use ClickTrail\Symfony\Attribution\AttributionContext;
use ClickTrail\Symfony\Attribution\ContextHolder;
use ClickTrail\Symfony\Consent\ConsentResolverInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Clock\ClockInterface;

/**
 * Captures attribution on the main request:
 *  - builds AttributionInput from the Request (query, host, URI, Referer);
 *  - merges via the deterministic core (TouchMerger::observe);
 *  - gates persistence through ConsentResolverInterface (unknown=denied);
 *  - persists StoredState to the session ONLY when permitted;
 *  - stores AttributionContext on request attributes for downstream use.
 *
 * CONTRACT: no remote HTTP calls here. Event delivery belongs to the
 * Messenger layer (DeliverEventsMessage).
 */
final class RequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConsentResolverInterface $consentResolver,
        private readonly ClockInterface $clock,
        private readonly ContextHolder $contextHolder,
        private readonly bool $consentRequired,
        private readonly string $siteId,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // High priority: run before routing/subdomain-sensitive listeners,
        // after framework boot. 128 mirrors early-middleware ordering.
        // // TODO verify exact priority interplay with session listeners on
        // Symfony 6.4 vs 7.x before release.
        return [KernelEvents::REQUEST => ['onKernelRequest', 128]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $referrer = $request->headers->get('Referer');

        $input = new AttributionInput(
            query: $request->query->all(),
            host: strtolower($request->getHost()),
            landingPage: $request->getUri(),
            referrer: $referrer !== '' ? $referrer : null,
            touchTimestamp: $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
        );

        // Read previously stored state only from an already-started session;
        // hasPreviousSession() avoids booting a session for first-time/bot hits.
        $session = $request->hasPreviousSession() ? $request->getSession() : null;
        $stored = StoredState::fromJson($session?->get('clicktrail.stored_state'));

        $state = TouchMerger::observe($stored, $input);

        $snapshot = $this->consentResolver->resolve($request);
        $suppression = [];
        $persisted = false;

        if (!$this->consentRequired) {
            $session?->set('clicktrail.stored_state', $state->toJson());
            $persisted = true;
        } elseif ($snapshot !== null && ConsentBehavior::can($snapshot, ConsentSnapshot::CAP_ANALYTICS)) {
            $session?->set('clicktrail.stored_state', $state->toJson());
            $persisted = true;
        } elseif ($snapshot === null) {
            $suppression[] = 'analytics_storage unknown at capture (source: none) - persistence blocked';
        } else {
            $reason = ConsentBehavior::suppressionReason($snapshot, ConsentSnapshot::CAP_ANALYTICS);
            if ($reason !== null) {
                $suppression[] = $reason;
            }
            // Denied: no write of any kind. Withdrawal clearing belongs to a
            // dedicated consent-change listener in the host app (same policy
            // as clicktrail-psr-middleware).
        }

        $context = new AttributionContext(
            attribution: $state,
            consent: $snapshot,
            suppressionReasons: $suppression,
            persisted: $persisted,
        );

        $this->contextHolder->set($request, $context);
    }
}
