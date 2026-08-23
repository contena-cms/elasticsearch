<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\DataAbstractionLayer\Event;

use OpenSearchDSL\Search;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Contena\Core\Framework\Event\ContenaEvent;
use Symfony\Contracts\EventDispatcher\Event;

class ElasticsearchEntitySearcherSearchedEvent extends Event implements ContenaEvent
{
    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        public readonly IdSearchResult $result,
        public readonly Search $search,
        public readonly EntityDefinition $definition,
        public readonly Criteria $criteria,
        private readonly Context $context,
        public readonly array $response = [],
    ) {
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
