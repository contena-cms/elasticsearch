<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Indexing;

class IndexingDto
{
    /**
     * @var list<string>
     */
    protected array $ids;

    /**
     * @param array<string> $ids
     */
    public function __construct(
        array $ids,
        protected string $index,
        protected string $entity
    ) {
        $this->ids = array_values($ids);
    }

    /**
     * @return list<string>
     */
    public function getIds(): array
    {
        return $this->ids;
    }

    public function getIndex(): string
    {
        return $this->index;
    }

    public function getEntity(): string
    {
        return $this->entity;
    }
}
