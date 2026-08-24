<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\Messenger;

/**
 * Delivery trigger message. Queued when delivery.transport=async so the
 * BatchClient flush happens on a worker, never during the request.
 *
 * Async routing (host app messenger.yaml):
 *
 *     framework:
 *         messenger:
 *             routing:
 *                 ClickTrail\Symfony\Messenger\DeliverEventsMessage: async
 *
 * # DEFERRED — live verification of transport routing against a real worker +
 * # broker before first release (reason: no local Symfony kernel in this repo's
 * # test harness; needs an integration app to verify end-to-end).
 */
final class DeliverEventsMessage
{
    /** @param array<int, array<string, mixed>>|null $events optional pre-queued events; null flushes whatever BatchClient holds */
    public function __construct(
        public readonly ?array $events = null,
    ) {
    }
}
