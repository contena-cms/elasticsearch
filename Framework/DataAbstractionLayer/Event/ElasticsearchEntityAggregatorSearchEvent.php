<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\DataAbstractionLayer\Event;

use OpenSearchDSL\Search;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\Event\ContenaEvent;
use Symfony\Contracts\EventDispatcher\Event;

class ElasticsearchEntityAggregatorSearchEvent extends Event implements ContenaEvent
{
    public function __construct(
        private readonly Search $search,
        private readonly EntityDefinition $definition,
        private readonly Criteria $criteria,
        private readonly Context $context
    ) {
    }

    public function getSearch(): Search
    {
        return $this->search;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getDefinition(): EntityDefinition
    {
        return $this->definition;
    }

    public function getCriteria(): Criteria
    {
        return $this->criteria;
    }
}
