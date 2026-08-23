<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework;

use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\EntityRepository;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\NandFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Contena\Core\System\Language\LanguageCollection;
use Contena\Elasticsearch\Framework\Indexing\Event\ElasticsearchIndexerLanguageCriteriaEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ElasticsearchLanguageProvider
{
    /**
     * @internal
     *
     * @param EntityRepository<LanguageCollection> $languageRepository
     */
    public function __construct(
        private readonly EntityRepository $languageRepository,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function getLanguages(Context $context): LanguageCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new NandFilter([new EqualsFilter('channels.id', null)]));
        $criteria->addSorting(new FieldSorting('id'));

        $this->eventDispatcher->dispatch(new ElasticsearchIndexerLanguageCriteriaEvent($criteria, $context));

        return $this->languageRepository->search($criteria, $context)->getEntities();
    }
}
