<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Config tree:
 *
 * clicktrail:
 *     site_id: '%env(string:CLICKTRAIL_SITE_ID)%'
 *     api_key: '%env(string:CLICKTRAIL_API_KEY)%'
 *     endpoint: '...'
 *     consent_required: true
 *     delivery:
 *         transport: sync|async   (informational, shown by clicktrail:diagnose;
 *                                  Messenger routing decides where delivery runs)
 *     resolver_class: null        (FQCN implementing ConsentResolverInterface)
 *
 * Defaults are env placeholders so values resolve through Symfony env
 * processors (%env(...)% with the `string` processor) at container compile
 * time; no secret ever lands in config files.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('clicktrail');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode->children()
            ->scalarNode('site_id')
                ->info('ClickTrail site identifier')
                ->defaultValue('%env(string:CLICKTRAIL_SITE_ID)%')
            ->end()
            ->scalarNode('api_key')
                ->info('ClickTrail API key (write-only; never rendered)')
                ->defaultValue('%env(string:CLICKTRAIL_API_KEY)%')
            ->end()
            ->scalarNode('endpoint')
                ->info("First-party ingestion endpoint base URL; may be an env placeholder like '%%env(CLICKTRAIL_ENDPOINT)%%'")
                ->defaultValue('')
            ->end()
            ->booleanNode('consent_required')
                ->info('When true, persistence/delivery is gated by ConsentResolverInterface (unknown = denied)')
                ->defaultTrue()
            ->end()
            ->arrayNode('delivery')
                ->addDefaultsIfNotSet()
                ->children()
                    ->enumNode('transport')
                        ->values(['sync', 'async'])
                        ->defaultValue('sync')
                        ->info('Informational (reported by clicktrail:diagnose). The bundle never flushes on its own: dispatch DeliverEventsMessage; framework.messenger.routing decides whether it is handled in-process or on a worker')
                    ->end()
                ->end()
            ->end()
            ->scalarNode('resolver_class')
                ->info('Optional FQCN of a custom ClickTrail\Symfony\Consent\ConsentResolverInterface implementation')
                ->defaultNull()
                ->validate()
                    ->ifTrue(static fn ($v): bool => $v !== null && !class_exists($v))
                    ->thenInvalid('clicktrail.resolver_class must reference an existing class')
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
