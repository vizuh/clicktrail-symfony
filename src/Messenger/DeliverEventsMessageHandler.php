<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\Messenger;

use ClickTrail\Client\BatchClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles DeliverEventsMessage by sending exactly the envelopes it carries.
 * Retry/backoff semantics live inside BatchClient; this handler only logs
 * outcomes and lets BatchClient exceptions propagate so Messenger retry
 * policies apply.
 */
final class DeliverEventsMessageHandler
{
    private LoggerInterface $logger;

    /** @param BatchClient $batchClient pristine (empty) client; cloned per message, never queued into */
    public function __construct(
        private readonly BatchClient $batchClient,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** @return int batches delivered */
    public function __invoke(DeliverEventsMessage $message): int
    {
        // Fresh queue per message: leftovers of a failed message are not re-sent
        // with the next one (Messenger retries the failed message itself).
        $client = clone $this->batchClient;
        $client->restore($message->events);
        $flushed = $client->flush();

        $this->logger->info('ClickTrail batch flushed', ['batches' => $flushed, 'events' => count($message->events)]);

        return $flushed;
    }
}
