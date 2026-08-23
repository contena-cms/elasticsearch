<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework;

use OpenSearch\Client;
use OpenSearchDSL\Query\Compound\BoolQuery;
use OpenSearchDSL\Query\FullText\MatchQuery;
use OpenSearchDSL\Query\TermLevel\ExistsQuery;
use OpenSearchDSL\Query\TermLevel\TermQuery;
use OpenSearchDSL\Search;
use Psr\Log\LoggerInterface;
use Contena\Core\Framework\Api\Context\ChannelApiSource;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Contena\Core\Framework\DataAbstractionLayer\Field\TenantField;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Contena\Core\System\SystemConfig\SystemConfigService;
use Contena\Elasticsearch\ElasticsearchException;
use Contena\Elasticsearch\Framework\DataAbstractionLayer\CriteriaParser;

class ElasticsearchHelper
{
    // max for default configuration
    final public const MAX_SIZE_VALUE = 10000;

    /**
     * @internal
     */
    public function __construct(
        private readonly string $environment,
        private bool $searchEnabled,
        private bool $indexingEnabled,
        private readonly string $prefix,
        private readonly bool $throwException,
        private readonly Client $client,
        private readonly ElasticsearchRegistry $registry,
        private readonly CriteriaParser $parser,
        private readonly LoggerInterface $logger,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function logAndThrowException(\Throwable $exception): bool
    {
        $this->logger->critical($exception->getMessage());

        if ($this->environment === 'test' || $this->throwException) {
            throw $exception;
        }

        return false;
    }

    /**
     * Created the index alias
     */
    public function getIndexName(EntityDefinition $definition): string
    {
        return $this->prefix . '_' . $definition->getEntityName();
    }

    public function allowIndexing(): bool
    {
        if (!$this->indexingEnabled) {
            return false;
        }

        try {
            $reachable = $this->client->ping();
        } catch (\Throwable $e) {
            return $this->logAndThrowException($e);
        }

        if (!$reachable) {
            return $this->logAndThrowException(ElasticsearchException::serverNotAvailable());
        }

        return true;
    }

    /**
     * Validates if it is allowed do execute the search request over elasticsearch
     */
    public function allowSearch(EntityDefinition $definition, Context $context, Criteria $criteria): bool
    {
        if (!$this->searchEnabled) {
            return false;
        }

        if (!$this->isSupported($definition)) {
            return false;
        }

        return $criteria->hasState(Criteria::STATE_ELASTICSEARCH_AWARE);
    }

    public function handleIds(EntityDefinition $definition, Criteria $criteria, Search $search, Context $context): void
    {
        $ids = $criteria->getIds();

        if ($ids === []) {
            return;
        }

        $ids = array_values($ids);

        $query = $this->parser->parseFilter(
            new EqualsAnyFilter('id', $ids),
            $definition,
            $definition->getEntityName(),
            $context
        );

        $search->addQuery($query, BoolQuery::FILTER);
    }

    public function addFilters(EntityDefinition $definition, Criteria $criteria, Search $search, Context $context): void
    {
        $this->addTenantFilter($definition, $search, $context);

        $filters = $criteria->getFilters();
        if ($filters === []) {
            return;
        }

        $query = $this->parser->parseFilter(
            new MultiFilter(MultiFilter::CONNECTION_AND, $filters),
            $definition,
            $definition->getEntityName(),
            $context
        );

        $search->addQuery($query, BoolQuery::FILTER);
    }

    public function addPostFilters(EntityDefinition $definition, Criteria $criteria, Search $search, Context $context): void
    {
        $postFilters = $criteria->getPostFilters();
        if ($postFilters === []) {
            return;
        }

        $query = $this->parser->parseFilter(
            new MultiFilter(MultiFilter::CONNECTION_AND, $postFilters),
            $definition,
            $definition->getEntityName(),
            $context
        );

        $search->addPostFilter($query, BoolQuery::FILTER);
    }

    public function addTerm(Criteria $criteria, Search $search, Context $context, EntityDefinition $definition): void
    {
        if (!$criteria->getTerm()) {
            return;
        }

        $esDefinition = $this->registry->get($definition->getEntityName());

        if (!$esDefinition) {
            throw ElasticsearchException::unsupportedElasticsearchDefinition($definition->getEntityName());
        }

        $query = $esDefinition->buildTermQuery($context, $criteria);

        $search->addQuery($query);

        $minScore = $this->resolveMinScore($context);
        if ($minScore > 0.0) {
            $search->setMinScore($minScore);
        }
    }

    public function addQueries(EntityDefinition $definition, Criteria $criteria, Search $search, Context $context): void
    {
        $queries = $criteria->getQueries();
        if ($queries === []) {
            return;
        }

        $hasTermQuery = $criteria->getTerm() !== null && $criteria->getTerm() !== '';

        $bool = new BoolQuery();

        foreach ($queries as $query) {
            $parsed = $this->parser->parseFilter($query->getQuery(), $definition, $definition->getEntityName(), $context);

            if ($query->getScore() && method_exists($parsed, 'addParameter')) {
                $score = (string) $query->getScore();
                $parsed->addParameter('boost', $score);
            }

            if ($parsed instanceof MatchQuery) {
                $parsed->addParameter('fuzziness', '2');
            }

            // in mysql implementation (@See: \Contena\Core\Framework\DataAbstractionLayer\Dbal\CriteriaQueryBuilder::build), the term query is split into separate score queries and added to the main query with the OR operator.
            // in Elasticsearch implementation, the term query can be added directly into the main query (@See: \Contena\Elasticsearch\Framework\ElasticsearchHelper::addTerm, so the term query and score queries can co-exist (without extract term as mysql implementation) but if there is already a term query, we need to group it with other queries to the main query with the OR operator.
            if ($hasTermQuery) {
                $search->addQuery($parsed, BoolQuery::SHOULD);

                continue;
            }

            $bool->add($parsed, BoolQuery::SHOULD);
        }

        if ($hasTermQuery) {
            return;
        }

        $bool->addParameter('minimum_should_match', '1');
        $search->addQuery($bool);
    }

    public function addSortings(EntityDefinition $definition, Criteria $criteria, Search $search, Context $context): void
    {
        foreach ($criteria->getSorting() as $sorting) {
            $search->addSort(
                $this->parser->parseSorting($sorting, $definition, $context)
            );
        }
    }

    public function addAggregations(EntityDefinition $definition, Criteria $criteria, Search $search, Context $context): void
    {
        $aggregations = $criteria->getAggregations();
        if ($aggregations === []) {
            return;
        }

        foreach ($aggregations as $aggregation) {
            $agg = $this->parser->parseAggregation($aggregation, $definition, $context);

            if (!$agg) {
                continue;
            }

            $search->addAggregation($agg);
        }
    }

    /**
     * Only used for unit tests because the container parameter bag is frozen and can not be changed at runtime.
     * Therefore this function can be used to test different behaviours
     *
     * @internal
     */
    public function setEnabled(bool $enabled): self
    {
        $this->searchEnabled = $enabled;
        $this->indexingEnabled = $enabled;

        return $this;
    }

    public function isSupported(EntityDefinition $definition): bool
    {
        $entityName = $definition->getEntityName();

        return $this->registry->has($entityName);
    }

    private function addTenantFilter(EntityDefinition $definition, Search $search, Context $context): void
    {
        if ($context->hasGlobalTenantAccess()) {
            return;
        }

        if (!$definition->getFields()->filterInstance(TenantField::class)->first() instanceof TenantField) {
            return;
        }

        $tenantId = $context->getTenantId();
        $query = $tenantId === null
            ? new BoolQuery([BoolQuery::MUST_NOT => new ExistsQuery('tenantId')])
            : new TermQuery('tenantId', $tenantId);

        $search->addQuery($query, BoolQuery::FILTER);
    }

    private function resolveMinScore(Context $context): float
    {
        $channelId = null;
        $source = $context->getSource();
        if ($source instanceof ChannelApiSource) {
            $channelId = $source->getChannelId();
        }

        return $this->systemConfigService->getFloat('core.search.minScore', $channelId);
    }
}
