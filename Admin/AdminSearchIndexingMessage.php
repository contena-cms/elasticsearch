<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Admin;

use Contena\Core\Framework\MessageQueue\AsyncMessageInterface;
use Contena\Core\Framework\MessageQueue\DeduplicatableMessageInterface;
use Contena\Core\Framework\Util\Hasher;

/**
 * @internal
 */
final readonly class AdminSearchIndexingMessage implements AsyncMessageInterface, DeduplicatableMessageInterface
{
    /**
     * @param array<string, string> $indices
     * @param list<string> $ids
     * @param list<string> $toRemoveIds
     */
    public function __construct(
        private string $entity,
        private string $indexer,
        private array $indices,
        private array $ids,
        private array $toRemoveIds = []
    ) {
    }

    public function getEntity(): string
    {
        return $this->entity;
    }

    public function getIndexer(): string
    {
        return $this->indexer;
    }

    /**
     * @return array<string, string>
     */
    public function getIndices(): array
    {
        return $this->indices;
    }

    /**
     * @return list<string>
     */
    public function getIds(): array
    {
        return $this->ids;
    }

    public function deduplicationId(): ?string
    {
        $sortedIds = $this->ids;
        sort($sortedIds);

        $sortedIndices = $this->indices;
        ksort($sortedIndices);

        $data = json_encode([
            $this->entity,
            $this->indexer,
            $sortedIndices,
            $sortedIds,
        ]);

        if ($data === false) {
            return null;
        }

        return Hasher::hash($data);
    }

    /**
     * @return list<string>
     */
    public function getToRemoveIds(): array
    {
        return $this->toRemoveIds;
    }
}
