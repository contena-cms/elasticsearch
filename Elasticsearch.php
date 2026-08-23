<?php declare(strict_types=1);

namespace Contena\Elasticsearch;

use Contena\Core\Framework\Bundle;
use Contena\Elasticsearch\DependencyInjection\ElasticsearchExtension;
use Contena\Elasticsearch\DependencyInjection\ElasticsearchMigrationCompilerPass;
use Contena\Elasticsearch\Profiler\ElasticsearchProfileCompilerPass;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * @internal
 */
class Elasticsearch extends Bundle
{
    public function getTemplatePriority(): int
    {
        return -1;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->buildDefaultConfig($container);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/DependencyInjection'));
        $loader->load('services.php');

        $container->addCompilerPass(new ElasticsearchMigrationCompilerPass());

        // Needs to run before the ProfilerPass
        $container->addCompilerPass(new ElasticsearchProfileCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 5000);
    }

    protected function createContainerExtension(): ?ExtensionInterface
    {
        return new ElasticsearchExtension();
    }
}
