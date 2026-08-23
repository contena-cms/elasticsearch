<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Indexing\Event;

class ElasticsearchIndexAliasSwitchedEvent
{
    /**
     * @param array<string, string> $changes
     */
    public function __construct(private readonly array $changes)
    {
    }

    /**
     * Returns the index as key and the alias as value.
     *
     * @return array<string, string>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }
}
