<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\DataAbstractionLayer;

use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;

abstract class AbstractElasticsearchSearchHydrator
{
    abstract public function getDecorated(): AbstractElasticsearchSearchHydrator;

    /**
     * @param array<string, mixed> $result
     */
    abstract public function hydrate(EntityDefinition $definition, Criteria $criteria, Context $context, array $result): IdSearchResult;
}
