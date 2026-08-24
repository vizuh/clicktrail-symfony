<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\Console;

use ClickTrail\Symfony\Support\WebhookSignature;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * clicktrail:diagnose - prints the effective bundle configuration (secrets
 * masked) and runs a local webhook-signature self-test. Makes NO network
 * calls by default.
 */
final class DiagnoseCommand extends Command
{
    protected static $defaultName = 'clicktrail:diagnose';
    protected static $defaultDescription = 'Show effective ClickTrail bundle configuration and run local self-tests.';

    public function __construct(
        private readonly string $siteId,
        private readonly string $endpoint,
        private readonly bool $consentRequired,
        private readonly string $deliveryTransport,
        private readonly ?string $resolverClass,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mask = static function (string $value): string {
            if ($value === '') {
                return '<not set>';
            }
            if (str_starts_with($value, '%env')) {
                return $value . ' (env placeholder, resolved at runtime)';
            }

            return substr($value, 0, 4) . str_repeat('*', max(0, strlen($value) - 4));
        };

        $rows = [
            'site_id' => $this->siteId === '' ? '<not set>' : $mask($this->siteId),
            'api_key' => '<masked>', // never printed, by design
            'endpoint' => $this->endpoint === '' ? '<not set>' : $this->endpoint,
            'consent_required' => $this->consentRequired ? 'true (unknown=denied)' : 'false',
            'delivery.transport' => $this->deliveryTransport,
            'resolver_class' => $this->resolverClass ?? ClickTrailConsentDefault::NULL_RESOLVER_HINT,
        ];
        foreach ($rows as $key => $value) {
            $output->writeln(sprintf('%-20s %s', $key, $value));
        }

        if ($this->endpoint === '') {
            $output->writeln('<comment>endpoint is empty: delivery will fail until configured</comment>');
        }

        // Local signature self-test (no network).
        $payload = '{"probe":true}';
        $sig = WebhookSignature::sign($payload, 'selftest-secret');
        $ok = WebhookSignature::verify($payload, $sig, 'selftest-secret');
        $bad = WebhookSignature::verify($payload . 'x', $sig, 'selftest-secret');
        $output->writeln('');
        $output->writeln('webhook signature: verify(valid)=' . var_export($ok, true) . ' verify(tampered)=' . var_export($bad, true));

        return ($ok && !$bad) ? Command::SUCCESS : Command::FAILURE;
    }
}

/**
 * Small internal constant holder to avoid a hard dependency on the Consent
 * namespace in this command's signature.
 */
final class ClickTrailConsentDefault
{
    public const NULL_RESOLVER_HINT = '(default) ClickTrail\Symfony\Consent\NullConsentResolver';
}
