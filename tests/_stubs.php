<?php

declare(strict_types=1);

/**
 * Minimal Twig stubs so the standalone runner can exercise the render-only
 * extension without composer install. Pattern: clicktrail-twig/tests/_twig_stubs.php.
 */

namespace Twig\Extension {
    if (!interface_exists(ExtensionInterface::class)) {
        interface ExtensionInterface
        {
            public function getTokenParsers(): array;
            public function getNodeVisitors(): array;
            public function getFilters(): array;
            public function getTests(): array;
            public function getFunctions(): array;
            public function getOperators(): array;
        }
    }

    if (!class_exists(AbstractExtension::class)) {
        abstract class AbstractExtension implements ExtensionInterface
        {
            public function getTokenParsers(): array { return []; }
            public function getNodeVisitors(): array { return []; }
            public function getFilters(): array { return []; }
            public function getTests(): array { return []; }
            public function getOperators(): array { return [[], []]; }
        }
    }
}

namespace Twig {
    if (!class_exists(TwigFunction::class)) {
        final class TwigFunction
        {
            /** @var callable */
            public $callable;

            /** @param callable $callable @param array<string, mixed> $options */
            public function __construct(
                public readonly string $name,
                callable $callable,
                public readonly array $options = [],
            ) {
                $this->callable = $callable;
            }
        }
    }
}
