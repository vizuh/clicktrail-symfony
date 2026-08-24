<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

/**
 * Loads Resources/config/services.xml and flattens processed config into
 * named parameters consumed there.
 */
final class ClickTrailExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('clicktrail.site_id', $config['site_id']);
        $container->setParameter('clicktrail.api_key', $config['api_key']);
        $container->setParameter('clicktrail.endpoint', $config['endpoint']);
        $container->setParameter('clicktrail.consent_required', $config['consent_required']);
        $container->setParameter('clicktrail.delivery.transport', $config['delivery']['transport']);
        $container->setParameter('clicktrail.resolver_class', $config['resolver_class']);

        // Custom resolver (when configured) replaces NullConsentResolver as
        // the ConsentResolverInterface alias used by RequestSubscriber.
        if (is_string($config['resolver_class']) && $config['resolver_class'] !== '') {
            $container->register('clicktrail.consent.resolver.custom', $config['resolver_class']);
            $container->setAlias('clicktrail.consent_resolver', 'clicktrail.consent.resolver.custom');
        }
    }

    public function getAlias(): string
    {
        return 'clicktrail';
    }
}
