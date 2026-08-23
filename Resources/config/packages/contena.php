<?php declare(strict_types=1);

use Contena\Core\DevOps\Environment\EnvironmentHelper;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $esIndexingEnabled = filter_var(
        EnvironmentHelper::getVariable('CONTENA_ES_INDEXING_ENABLED', false),
        \FILTER_VALIDATE_BOOL
    );

    $container->parameters()->set('contena.blog.search_keyword.indexing', !$esIndexingEnabled);
};
