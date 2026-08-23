<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Indexing;

use Contena\Core\Framework\Context;
use Contena\Core\Framework\MessageQueue\AsyncMessageInterface;
use Contena\Core\Framework\MessageQueue\DeduplicatableMessageInterface;
use Contena\Core\Framework\Util\Hasher;

class ElasticsearchIndexingMessage implements AsyncMessageInterface, DeduplicatableMessageInterface
{
    /**
     * @internal
     */
    public function __construct(
        private readonly IndexingDto $data,
        private readonly ?IndexerOffset $offset,
        private readonly Context $context,
        private bool $lastMessage = false
    ) {
    }

    public function getData(): IndexingDto
    {
        return $this->data;
    }

    public function getOffset(): ?IndexerOffset
    {
        return $this->offset;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function deduplicationId(): ?string
    {
        $ids = $this->data->getIds();
        sort($ids);

        $data = serialize([
            $this->data->getEntity(),
            $this->data->getIndex(),
            $ids,
            $this->offset, // is not JSON serializable, so we use serialize
            $this->context, // relying on __serialize() to skip extensions
        ]);

        return Hasher::hash($data);
    }

    public function isLastMessage(): bool
    {
        return $this->lastMessage;
    }

    public function markAsLastMessage(): bool
    {
        return $this->lastMessage = true;
    }
}
