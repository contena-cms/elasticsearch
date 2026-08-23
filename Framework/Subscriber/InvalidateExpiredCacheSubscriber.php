<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Subscriber;

use Contena\Core\Framework\Api\Event\InvalidateExpiredCacheRequestEvent;
use Contena\Elasticsearch\Framework\Indexing\IndexManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal
 */
class InvalidateExpiredCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly IndexManager $indexManager
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvalidateExpiredCacheRequestEvent::class => 'refreshOpensearchIndices',
        ];
    }

    public function refreshOpensearchIndices(InvalidateExpiredCacheRequestEvent $event): void
    {
        if ($event->request->query->getBoolean('refreshOpenSearch')) {
            $this->indexManager->refreshIndices();
        }
    }
}
