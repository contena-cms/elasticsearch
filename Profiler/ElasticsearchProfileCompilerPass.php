<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Profiler;

use OpenSearch\Client;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
class ElasticsearchProfileCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $isDebugEnabled = $container->getParameterBag()->resolveValue($container->getParameter('kernel.debug'));

        if (!$isDebugEnabled) {
            $container->removeDefinition(DataCollector::class);

            return;
        }

        // we need direct access to the ClientProfiler, so it cannot be wrapped in a lazy proxy
        $container->getDefinition(Client::class)->setLazy(false);
        $container->getDefinition('admin.openSearch.client')->setLazy(false);
    }
}
