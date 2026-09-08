<?php declare(strict_types=1);

namespace Contena\Elasticsearch;

use Contena\Core\Framework\Context;
use Contena\Elasticsearch\Blog\SearchFieldConfig;
use OpenSearchDSL\BuilderInterface;

abstract class AbstractTokenQueryBuilder
{
    abstract public function getDecorated(): self;

    /**
     * @param SearchFieldConfig[] $configs
     */
    abstract public function build(string $entity, string $token, array $configs, Context $context): ?BuilderInterface;
}
