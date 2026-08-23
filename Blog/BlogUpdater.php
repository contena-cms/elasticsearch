<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Blog;

use Contena\Core\Content\Blog\Events\BlogIndexerEvent;
use Contena\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Contena\Elasticsearch\Framework\Indexing\ElasticsearchIndexer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal
 */
class BlogUpdater implements EventSubscriberInterface
{
    /**
     * @internal
     */
    public function __construct(
        private readonly ElasticsearchIndexer $indexer,
        private readonly EntityDefinition $definition
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BlogIndexerEvent::class => 'update',
        ];
    }

    public function update(BlogIndexerEvent $event): void
    {
        $this->indexer->updateIds($this->definition, $event->getIds(), $event->getContext());
    }
}
