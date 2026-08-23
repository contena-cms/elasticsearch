<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Indexing\Event;

use Contena\Elasticsearch\Framework\AbstractElasticsearchDefinition;

class ElasticsearchIndexCreatedEvent
{
    public function __construct(
        private readonly string $indexName,
        private readonly AbstractElasticsearchDefinition $definition
    ) {
    }

    public function getIndexName(): string
    {
        return $this->indexName;
    }

    public function getDefinition(): AbstractElasticsearchDefinition
    {
        return $this->definition;
    }
}
