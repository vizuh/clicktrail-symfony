<?php

declare(strict_types=1);

namespace ClickTrail\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * ClickTrail attribution bundle for Symfony 6.4 / 7.x.
 *
 * Thin adapter over clicktrail/php-sdk: capture, consent gating, session
 * persistence, queued delivery, Twig rendering, diagnostics. The deterministic
 * core lives in the SDK; this bundle only wires effects.
 */
final class ClickTrailBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // No compiler passes needed at scaffold stage: services.xml carries all
        // wiring. // TODO verify whether a custom Messenger transport tag is
        // wanted for async routing before first release.
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
