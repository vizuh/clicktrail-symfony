<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\Messenger;

/**
 * Delivery message carrying the envelopes to send. Build it from the app's
 * BatchClient at dispatch time so the payloads cross the transport boundary
 * (a worker process starts with an empty BatchClient):
 *
 *     $bus->dispatch(new DeliverEventsMessage($batchClient->pending()));
 *
 * Unrouted messages are handled in-process. Route it to an async transport
 * (host app messenger.yaml) to send from a worker instead:
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
    /** @param array<int, array<string, mixed>> $events envelopes captured via BatchClient::pending() */
    public function __construct(
        public readonly array $events,
    ) {
    }
}
