<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\DataAbstractionLayer;

use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Contena\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;

abstract class AbstractElasticsearchAggregationHydrator
{
    abstract public function getDecorated(): AbstractElasticsearchAggregationHydrator;

    /**
     * @param array<string, mixed> $result
     */
    abstract public function hydrate(EntityDefinition $definition, Criteria $criteria, Context $context, array $result): AggregationResultCollection;
}
