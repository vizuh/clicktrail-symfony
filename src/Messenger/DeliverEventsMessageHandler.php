<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\Messenger;

use ClickTrail\Client\BatchClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles DeliverEventsMessage by flushing the SDK BatchClient queue.
 * Retry/backoff semantics live inside BatchClient; this handler only logs
 * outcomes and lets BatchClient exceptions propagate so Messenger retry
 * policies apply.
 */
final class DeliverEventsMessageHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly BatchClient $batchClient,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(DeliverEventsMessage $message): int
    {
        $flushed = $this->batchClient->flush();

        $this->logger->info('ClickTrail batch flushed', ['events' => $flushed]);

        return $flushed;
    }
}
