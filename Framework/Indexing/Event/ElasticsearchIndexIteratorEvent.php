<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Indexing\Event;

use Contena\Core\Framework\DataAbstractionLayer\Dbal\Common\IterableQuery;
use Contena\Elasticsearch\Framework\AbstractElasticsearchDefinition;

class ElasticsearchIndexIteratorEvent
{
    public function __construct(
        public readonly AbstractElasticsearchDefinition $elasticsearchDefinition,
        public IterableQuery $iterator,
    ) {
    }
}
