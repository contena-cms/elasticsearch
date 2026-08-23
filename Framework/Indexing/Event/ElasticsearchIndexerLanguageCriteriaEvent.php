<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Indexing\Event;

use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\Event\ContenaEvent;
use Symfony\Contracts\EventDispatcher\Event;

class ElasticsearchIndexerLanguageCriteriaEvent extends Event implements ContenaEvent
{
    public function __construct(
        private readonly Criteria $criteria,
        private readonly Context $context
    ) {
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getCriteria(): Criteria
    {
        return $this->criteria;
    }
}
