<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\DataAbstractionLayer;

use OpenSearchDSL\Aggregation\AbstractAggregation;
use OpenSearchDSL\Aggregation\Bucketing;
use OpenSearchDSL\Aggregation\Bucketing\CompositeAggregation;
use OpenSearchDSL\Aggregation\Bucketing\NestedAggregation;
use OpenSearchDSL\Aggregation\Bucketing\ReverseNestedAggregation;
use OpenSearchDSL\Aggregation\Metric;
use OpenSearchDSL\Aggregation\Metric\ValueCountAggregation;
use OpenSearchDSL\BuilderInterface;
use OpenSearchDSL\Query\Compound\BoolQuery;
use OpenSearchDSL\Query\Compound\DisMaxQuery;
use OpenSearchDSL\Query\FullText\MultiMatchQuery;
use OpenSearchDSL\Query\Joining\NestedQuery;
use OpenSearchDSL\Query\TermLevel\ExistsQuery;
use OpenSearchDSL\Query\TermLevel\PrefixQuery;
use OpenSearchDSL\Query\TermLevel\RangeQuery;
use OpenSearchDSL\Query\TermLevel\TermQuery;
use OpenSearchDSL\Query\TermLevel\TermsQuery;
use OpenSearchDSL\Query\TermLevel\WildcardQuery;
use OpenSearchDSL\Sort\FieldSort;
use OpenSearchDSL\Sort\NestedSort;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Contena\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Contena\Core\Framework\DataAbstractionLayer\Field\AssociationField;
use Contena\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Contena\Core\Framework\DataAbstractionLayer\Field\CustomFields;
use Contena\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Contena\Core\Framework\DataAbstractionLayer\Field\Field;
use Contena\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Contena\Core\Framework\DataAbstractionLayer\Field\IntField;
use Contena\Core\Framework\DataAbstractionLayer\Field\TranslatedField;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Aggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\DateHistogramAggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\FilterAggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\AvgAggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\EntityAggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MinAggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\RangeAggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\StatsAggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\NotEqualsFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\SingleFieldFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\SuffixFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\XOrFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Sorting\CountSorting;
use Contena\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Contena\Core\System\CustomField\CustomFieldService;
use Contena\Elasticsearch\ElasticsearchException;
use Contena\Elasticsearch\Framework\ElasticsearchDateHistogramAggregation;
use Contena\Elasticsearch\Framework\ElasticsearchHelper;
use Contena\Elasticsearch\Sort\CountSort;

class CriteriaParser
{
    /**
     * @internal
     */
    public function __construct(
        private readonly EntityDefinitionQueryHelper $helper,
        private readonly CustomFieldService $customFieldService
    ) {
    }

    public function buildAccessor(EntityDefinition $definition, string $fieldName, Context $context): string
    {
        $root = $definition->getEntityName();

        $parts = explode('.', $fieldName);
        if ($root === $parts[0]) {
            array_shift($parts);
        }

        if ($this->helper->getField($fieldName, $definition, $root, false) instanceof TranslatedField) {
            $ordered = [];
            foreach ($parts as $part) {
                $ordered[] = $part;
            }
            $parts = $ordered;
        }

        return implode('.', $parts);
    }

    public function parseSorting(FieldSorting $sorting, EntityDefinition $definition, Context $context): FieldSort
    {
        $fieldSort = $this->buildFieldSort($sorting, $definition, $context);

        $path = $this->getNestedPath($definition, $sorting->getField());

        if ($path) {
            $fieldSort->setNestedFilter(new NestedSort($path));
        }

        return $fieldSort;
    }

    public function parseAggregation(Aggregation $aggregation, EntityDefinition $definition, Context $context): ?AbstractAggregation
    {
        $fieldName = $this->buildAccessor($definition, $aggregation->getField(), $context);

        $fields = $aggregation->getFields();

        $path = null;
        if ($fields !== []) {
            $path = $this->getNestedPath($definition, $fields[0]);
        }

        $esAggregation = $this->createAggregation($aggregation, $fieldName, $definition, $context);

        if (!$path || $aggregation instanceof FilterAggregation) {
            return $esAggregation;
        }

        $nested = new NestedAggregation($aggregation->getName(), $path);
        $nested->addAggregation($esAggregation);

        return $nested;
    }

    public function parseFilter(Filter $filter, EntityDefinition $definition, string $root, Context $context): BuilderInterface
    {
        return match (true) {
            $filter instanceof NotFilter => $this->parseNotFilter($filter, $definition, $root, $context),
            $filter instanceof MultiFilter => $this->parseMultiFilter($filter, $definition, $root, $context),
            $filter instanceof EqualsFilter => $this->parseEqualsFilter($filter, $definition, $context),
            $filter instanceof EqualsAnyFilter => $this->parseEqualsAnyFilter($filter, $definition, $context),
            $filter instanceof ContainsFilter => $this->parseContainsFilter($filter, $definition, $context),
            $filter instanceof PrefixFilter => $this->parsePrefixFilter($filter, $definition, $context),
            $filter instanceof SuffixFilter => $this->parseSuffixFilter($filter, $definition, $context),
            $filter instanceof RangeFilter => $this->parseRangeFilter($filter, $definition, $context),
            default => throw ElasticsearchException::unsupportedFilter($filter::class),
        };
    }

    protected function parseFilterAggregation(FilterAggregation $aggregation, EntityDefinition $definition, Context $context): AbstractAggregation
    {
        if ($aggregation->getAggregation() === null) {
            throw ElasticsearchException::nestedAggregationMissingInFilterAggregation($aggregation->getName());
        }

        $nested = $this->parseAggregation($aggregation->getAggregation(), $definition, $context);
        if ($nested === null) {
            throw ElasticsearchException::nestedAggregationParseError($aggregation->getName());
        }

        // when aggregation inside the filter aggregation points to a nested object (e.g. blog.properties.id) we have to add all filters
        // which points to the same association to the same "nesting" level like the nested aggregation for this association
        $path = $nested instanceof NestedAggregation ? $nested->getPath() : null;
        $bool = new BoolQuery();

        $filters = [];
        foreach ($aggregation->getFilter() as $filter) {
            $query = $this->parseFilter($filter, $definition, $definition->getEntityName(), $context);

            if (!$query instanceof NestedQuery) {
                $filters[] = new Bucketing\FilterAggregation($aggregation->getName(), $query);

                continue;
            }

            // same property path as the "real" aggregation
            if ($query->getPath() === $path) {
                $bool->add($query->getQuery());

                continue;
            }

            // query points to a nested document property - we have to define that the filter points to this field
            $parsed = new NestedAggregation($aggregation->getName(), $query->getPath());

            // now we can defined a filter which points to the nested field (remove NestedQuery layer)
            $filter = new Bucketing\FilterAggregation($aggregation->getName(), $query->getQuery());

            // afterwards we reset the nesting to allow following filters to point to another nested property
            $reverse = new ReverseNestedAggregation($aggregation->getName());

            $filter->addAggregation($reverse);

            $parsed->addAggregation($filter);

            $filters[] = $parsed;
        }

        // nested aggregation should have filters - we have to remap the nesting
        $mapped = $nested;
        if ($bool->getQueries() !== [] && $nested instanceof NestedAggregation) {
            $real = $nested->getAggregation($nested->getName());
            if (!$real instanceof AbstractAggregation) {
                throw ElasticsearchException::nestedAggregationParseError($aggregation->getName());
            }

            $filter = new Bucketing\FilterAggregation($aggregation->getName(), $bool);
            $filter->addAggregation($real);

            $mapped = new NestedAggregation($aggregation->getName(), $nested->getPath());
            $mapped->addAggregation($filter);
        }

        // at this point we have to walk over all filters and create one nested filter for it
        $parent = null;
        $root = $mapped;
        foreach ($filters as $filter) {
            if ($parent === null) {
                $parent = $filter;
                $root = $filter;

                continue;
            }

            $parent->addAggregation($filter);

            if (!$filter instanceof NestedAggregation) {
                $parent = $filter;

                continue;
            }

            $filterName = $filter->getName();
            $filter = $filter->getAggregation($filterName);
            if (!$filter instanceof AbstractAggregation) {
                throw ElasticsearchException::parentFilterError($filterName);
            }

            $parent = $filter->getAggregation($filter->getName());
            if (!$parent instanceof AbstractAggregation) {
                throw ElasticsearchException::parentFilterError($filter->getName());
            }
        }

        // it can happen, that $parent is not defined if the "real" aggregation is a nested and all filters points to the same property
        // than we return the following structure:  [nested-agg] + filter-agg + real-agg    ( [] = optional )
        if ($parent === null) {
            return $root;
        }

        // at this point we have some other filters which point to another nested object as the "real" aggregation
        // than we return the following structure:  [nested-agg] + filter-agg + [reverse-nested-agg] + [nested-agg] + real-agg   ( [] = optional )
        $parent->addAggregation($mapped);

        return $root;
    }

    protected function parseTermsAggregation(TermsAggregation $aggregation, string $fieldName, EntityDefinition $definition, Context $context): AbstractAggregation
    {
        if ($aggregation->getSorting() === null) {
            $terms = new Bucketing\TermsAggregation($aggregation->getName(), $fieldName);

            if ($nested = $aggregation->getAggregation()) {
                $terms->addAggregation(
                    $this->parseNestedAggregation($nested, $definition, $context)
                );
            }

            // set default size to 10.000 => max for default configuration
            $terms->addParameter('size', ElasticsearchHelper::MAX_SIZE_VALUE);

            if ($aggregation->getLimit()) {
                $terms->addParameter('size', (string) $aggregation->getLimit());
            }

            return $terms;
        }

        $composite = new CompositeAggregation($aggregation->getName());

        $accessor = $this->buildAccessor($definition, $aggregation->getSorting()->getField(), $context);

        $sorting = new Bucketing\TermsAggregation($aggregation->getName() . '.sorting', $accessor);
        $sorting->addParameter('order', $aggregation->getSorting()->getDirection());
        $composite->addSource($sorting);

        $terms = new Bucketing\TermsAggregation($aggregation->getName() . '.key', $fieldName);
        $composite->addSource($terms);

        if ($nested = $aggregation->getAggregation()) {
            $composite->addAggregation(
                $this->parseNestedAggregation($nested, $definition, $context)
            );
        }

        // set default size to 10.000 => max for default configuration
        $composite->addParameter('size', ElasticsearchHelper::MAX_SIZE_VALUE);

        if ($aggregation->getLimit()) {
            $composite->addParameter('size', (string) $aggregation->getLimit());
        }

        return $composite;
    }

    protected function parseStatsAggregation(StatsAggregation $aggregation, string $fieldName, Context $context): Metric\StatsAggregation
    {
        return new Metric\StatsAggregation($aggregation->getName(), $fieldName);
    }

    protected function parseEntityAggregation(EntityAggregation $aggregation, string $fieldName): Bucketing\TermsAggregation
    {
        $bucketingAggregation = new Bucketing\TermsAggregation($aggregation->getName(), $fieldName);

        $bucketingAggregation->addParameter('size', ElasticsearchHelper::MAX_SIZE_VALUE);

        return $bucketingAggregation;
    }

    protected function parseDateHistogramAggregation(DateHistogramAggregation $aggregation, string $fieldName, EntityDefinition $definition, Context $context): CompositeAggregation
    {
        $composite = new CompositeAggregation($aggregation->getName());

        if ($fieldSorting = $aggregation->getSorting()) {
            $accessor = $this->buildAccessor($definition, $fieldSorting->getField(), $context);

            $sorting = new Bucketing\TermsAggregation($aggregation->getName() . '.sorting', $accessor);
            $sorting->addParameter('order', $fieldSorting->getDirection());

            $composite->addSource($sorting);
        }

        $histogram = new ElasticsearchDateHistogramAggregation(
            $aggregation->getName() . '.key',
            $fieldName,
            $aggregation->getInterval(),
            'yyyy-MM-dd HH:mm:ss'
        );

        if ($aggregation->getTimeZone()) {
            $histogram->addParameter('time_zone', $aggregation->getTimeZone());
        }

        $composite->addSource($histogram);

        if ($nested = $aggregation->getAggregation()) {
            $composite->addAggregation(
                $this->parseNestedAggregation($nested, $definition, $context)
            );
        }

        return $composite;
    }

    protected function parseRangeAggregation(RangeAggregation $aggregation, string $fieldName): Bucketing\RangeAggregation
    {
        return new Bucketing\RangeAggregation(
            $aggregation->getName(),
            $fieldName,
            $aggregation->getRanges()
        );
    }

    private function parseNestedAggregation(Aggregation $aggregation, EntityDefinition $definition, Context $context): AbstractAggregation
    {
        $fieldName = $this->buildAccessor($definition, $aggregation->getField(), $context);

        return $this->createAggregation($aggregation, $fieldName, $definition, $context);
    }

    private function createAggregation(Aggregation $aggregation, string $fieldName, EntityDefinition $definition, Context $context): AbstractAggregation
    {
        $field = $this->getField($definition, $fieldName);

        if ($field instanceof TranslatedField) {
            $fieldName = $this->getTranslatedFieldName($fieldName, $context->getLanguageId());
        }

        return match (true) {
            $aggregation instanceof StatsAggregation => $this->parseStatsAggregation($aggregation, $fieldName, $context),
            $aggregation instanceof AvgAggregation => new Metric\AvgAggregation($aggregation->getName(), $fieldName),
            $aggregation instanceof EntityAggregation => $this->parseEntityAggregation($aggregation, $fieldName),
            $aggregation instanceof MaxAggregation => new Metric\MaxAggregation($aggregation->getName(), $fieldName),
            $aggregation instanceof MinAggregation => new Metric\MinAggregation($aggregation->getName(), $fieldName),
            $aggregation instanceof SumAggregation => new Metric\SumAggregation($aggregation->getName(), $fieldName),
            $aggregation instanceof CountAggregation => new ValueCountAggregation($aggregation->getName(), $fieldName),
            $aggregation instanceof FilterAggregation => $this->parseFilterAggregation($aggregation, $definition, $context),
            $aggregation instanceof TermsAggregation => $this->parseTermsAggregation($aggregation, $fieldName, $definition, $context),
            $aggregation instanceof DateHistogramAggregation => $this->parseDateHistogramAggregation($aggregation, $fieldName, $definition, $context),
            $aggregation instanceof RangeAggregation => $this->parseRangeAggregation($aggregation, $fieldName),
            default => throw ElasticsearchException::unsupportedAggregation($aggregation::class),
        };
    }

    private function parseEqualsFilter(EqualsFilter $filter, EntityDefinition $definition, Context $context): BuilderInterface
    {
        $fieldName = $this->buildAccessor($definition, $filter->getField(), $context);
        $contextLanguageFieldName = $this->getTranslatedFieldName($fieldName, $context->getLanguageId());

        $field = $this->getField($definition, $fieldName);

        if ($filter->getValue() === null) {
            $query = new BoolQuery();

            if ($field instanceof TranslatedField) {
                if ($this->shouldUseMainContextLanguage($field, $context)) {
                    $query->add(new ExistsQuery($contextLanguageFieldName), BoolQuery::MUST_NOT);
                }

                foreach ($context->getLanguageIdChain() as $languageId) {
                    $query->add(new ExistsQuery($this->getTranslatedFieldName($fieldName, $languageId)), BoolQuery::MUST_NOT);
                }

                return $this->createNestedQuery($query, $definition, $filter->getField());
            }

            $path = $this->getNestedPath($definition, $filter->getField());

            if ($path) {
                $query->add(new NestedQuery($path, new ExistsQuery($fieldName)), BoolQuery::MUST_NOT);

                return $query;
            }

            $query->add(new ExistsQuery($fieldName), BoolQuery::MUST_NOT);

            return $query;
        }

        $value = $this->parseValue($definition, $filter, $filter->getValue());
        $query = new TermQuery($fieldName, $value);

        if ($field instanceof TranslatedField) {
            if ($this->shouldUseMainContextLanguage($field, $context)) {
                $query = new TermQuery($contextLanguageFieldName, $value);
            } else {
                $multiMatchFields = [];

                foreach ($context->getLanguageIdChain() as $languageId) {
                    $multiMatchFields[] = $this->getTranslatedFieldName($fieldName, $languageId);
                }

                $query = new MultiMatchQuery($multiMatchFields, $value, [
                    'type' => 'best_fields',
                ]);
            }
        }

        return $this->createNestedQuery($query, $definition, $filter->getField());
    }

    private function parseEqualsAnyFilter(EqualsAnyFilter $filter, EntityDefinition $definition, Context $context): BuilderInterface
    {
        $fieldName = $this->buildAccessor($definition, $filter->getField(), $context);

        $field = $this->getField($definition, $fieldName);

        $value = $this->parseValue($definition, $filter, \array_values($filter->getValue()));

        $query = $this->prepareTermsQueryWithNullSupport($fieldName, $value);

        if ($field instanceof TranslatedField) {
            if ($this->shouldUseMainContextLanguage($field, $context)) {
                $fieldName = $this->getTranslatedFieldName($fieldName, $context->getLanguageId());
                $query = $this->prepareTermsQueryWithNullSupport($fieldName, $value);
            } else {
                $query = new DisMaxQuery();
                foreach ($context->getLanguageIdChain() as $languageId) {
                    $accessor = $this->getTranslatedFieldName($fieldName, $languageId);
                    $query->addQuery($this->prepareTermsQueryWithNullSupport($accessor, $value));
                }
            }
        }

        return $this->createNestedQuery(
            $query,
            $definition,
            $filter->getField()
        );
    }

    /**
     * @param array<string|null> $values
     */
    private function prepareTermsQueryWithNullSupport(string $fieldName, array $values): BuilderInterface
    {
        $nonNullValues = array_values(array_filter($values, static fn ($value) => $value !== null));
        $hasNull = \count($nonNullValues) !== \count($values);

        if (!$hasNull) {
            return new TermsQuery($fieldName, $values);
        }

        $boolQuery = new BoolQuery();
        if ($nonNullValues !== []) {
            $boolQuery->add(new TermsQuery($fieldName, $nonNullValues), BoolQuery::SHOULD);
        }

        $nullQuery = new BoolQuery();
        $nullQuery->add(new ExistsQuery($fieldName), BoolQuery::MUST_NOT);
        $boolQuery->add($nullQuery, BoolQuery::SHOULD);

        return $boolQuery;
    }

    private function parseContainsFilter(ContainsFilter $filter, EntityDefinition $definition, Context $context): BuilderInterface
    {
        $accessor = $this->buildAccessor($definition, $filter->getField(), $context);

        /** @var string $value */
        $value = $filter->getValue();

        $field = $this->getField($definition, $filter->getField());

        $query = new WildcardQuery($accessor, '*' . $value . '*');

        if ($field instanceof TranslatedField) {
            if ($this->shouldUseMainContextLanguage($field, $context)) {
                $query = new WildcardQuery($this->getTranslatedFieldName($accessor, $context->getLanguageId()), '*' . $value . '*');
            } else {
                $query = new DisMaxQuery();
                foreach ($context->getLanguageIdChain() as $languageId) {
                    $fieldName = $this->getTranslatedFieldName($accessor, $languageId);
                    $query->addQuery(new WildcardQuery($fieldName, '*' . $value . '*'));
                }
            }
        }

        return $this->createNestedQuery(
            $query,
            $definition,
            $filter->getField()
        );
    }

    private function parsePrefixFilter(PrefixFilter $filter, EntityDefinition $definition, Context $context): BuilderInterface
    {
        $accessor = $this->buildAccessor($definition, $filter->getField(), $context);

        $value = $filter->getValue();

        $field = $this->getField($definition, $filter->getField());

        $query = new PrefixQuery($accessor, $value);

        if ($field instanceof TranslatedField) {
            if ($this->shouldUseMainContextLanguage($field, $context)) {
                $query = new PrefixQuery($this->getTranslatedFieldName($accessor, $context->getLanguageId()), $value);
            } else {
                $query = new DisMaxQuery();

                foreach ($context->getLanguageIdChain() as $languageId) {
                    $query->addQuery(new WildcardQuery($this->getTranslatedFieldName($accessor, $languageId), $value . '*'));
                }
            }
        }

        return $this->createNestedQuery(
            $query,
            $definition,
            $filter->getField()
        );
    }

    private function parseSuffixFilter(SuffixFilter $filter, EntityDefinition $definition, Context $context): BuilderInterface
    {
        $accessor = $this->buildAccessor($definition, $filter->getField(), $context);

        $value = $filter->getValue();

        $field = $this->getField($definition, $filter->getField());

        $query = new WildcardQuery($accessor, '*' . $value);

        if ($field instanceof TranslatedField) {
            if ($this->shouldUseMainContextLanguage($field, $context)) {
                $query = new WildcardQuery($this->getTranslatedFieldName($accessor, $context->getLanguageId()), '*' . $value);
            } else {
                $query = new DisMaxQuery();
                foreach ($context->getLanguageIdChain() as $languageId) {
                    $fieldName = $this->getTranslatedFieldName($accessor, $languageId);
                    $query->addQuery(new WildcardQuery($fieldName, '*' . $value));
                }
            }
        }

        return $this->createNestedQuery(
            $query,
            $definition,
            $filter->getField()
        );
    }

    private function parseRangeFilter(RangeFilter $filter, EntityDefinition $definition, Context $context): BuilderInterface
    {
        $accessor = $this->buildAccessor($definition, $filter->getField(), $context);

        $field = $this->getField($definition, $filter->getField());

        $value = $this->parseValue($definition, $filter, $filter->getParameters());
        $query = new RangeQuery($accessor, $value);

        if ($field instanceof TranslatedField) {
            if ($this->shouldUseMainContextLanguage($field, $context)) {
                $query = new RangeQuery($this->getTranslatedFieldName($accessor, $context->getLanguageId()), $value);
            } else {
                $query = new DisMaxQuery();
                foreach ($context->getLanguageIdChain() as $languageId) {
                    $fieldName = $this->getTranslatedFieldName($accessor, $languageId);
                    $query->addQuery(new RangeQuery($fieldName, $value));
                }
            }
        }

        return $this->createNestedQuery(
            $query,
            $definition,
            $filter->getField()
        );
    }

    private function parseNotFilter(NotFilter $filter, EntityDefinition $definition, string $root, Context $context): BuilderInterface
    {
        $bool = new BoolQuery();
        if ($filter->getQueries() === []) {
            return $bool;
        }

        if ($filter instanceof NotEqualsFilter && $filter->getValue() === null) {
            return new ExistsQuery(
                $this->buildAccessor($definition, $filter->getField(), $context)
            );
        }

        if (\count($filter->getQueries()) === 1) {
            $bool->add(
                $this->parseFilter($filter->getQueries()[0], $definition, $root, $context),
                BoolQuery::MUST_NOT
            );

            return $bool;
        }

        $multiFilter = match ($filter->getOperator()) {
            MultiFilter::CONNECTION_OR => new OrFilter(),
            MultiFilter::CONNECTION_XOR => new XOrFilter(),
            default => new AndFilter(),
        };

        foreach ($filter->getQueries() as $query) {
            $multiFilter->addQuery($query);
        }

        $bool->add(
            $this->parseFilter($multiFilter, $definition, $root, $context),
            BoolQuery::MUST_NOT
        );

        return $bool;
    }

    private function parseMultiFilter(MultiFilter $filter, EntityDefinition $definition, string $root, Context $context): BuilderInterface
    {
        return match ($filter->getOperator()) {
            MultiFilter::CONNECTION_OR => $this->parseOrMultiFilter($filter, $definition, $root, $context),
            MultiFilter::CONNECTION_AND => $this->parseAndMultiFilter($filter, $definition, $root, $context),
            MultiFilter::CONNECTION_XOR => $this->parseXorMultiFilter($filter, $definition, $root, $context),
            default => throw ElasticsearchException::operatorNotAllowed($filter->getOperator()),
        };
    }

    private function parseAndMultiFilter(MultiFilter $filter, EntityDefinition $definition, string $root, Context $context): BuilderInterface
    {
        $grouped = [];
        $bool = new BoolQuery();

        foreach ($filter->getQueries() as $nested) {
            $query = $this->parseFilter($nested, $definition, $root, $context);

            if (!$query instanceof NestedQuery) {
                $bool->add($query, BoolQuery::MUST);

                continue;
            }

            if (!\array_key_exists($query->getPath(), $grouped)) {
                $grouped[$query->getPath()] = new BoolQuery();
                $bool->add(new NestedQuery($query->getPath(), $grouped[$query->getPath()]));
            }

            $grouped[$query->getPath()]->add($query->getQuery());
        }

        return $bool;
    }

    private function parseOrMultiFilter(MultiFilter $filter, EntityDefinition $definition, string $root, Context $context): BuilderInterface
    {
        $bool = new BoolQuery();

        foreach ($filter->getQueries() as $nested) {
            $bool->add(
                $this->parseFilter($nested, $definition, $root, $context),
                BoolQuery::SHOULD
            );
        }

        return $bool;
    }

    private function parseXorMultiFilter(MultiFilter $filter, EntityDefinition $definition, string $root, Context $context): BuilderInterface
    {
        $bool = new BoolQuery();

        foreach ($filter->getQueries() as $nested) {
            $xorQuery = new BoolQuery();
            foreach ($filter->getQueries() as $mustNot) {
                if ($nested === $mustNot) {
                    $xorQuery->add($this->parseFilter($nested, $definition, $root, $context), BoolQuery::MUST);

                    continue;
                }

                $xorQuery->add($this->parseFilter($mustNot, $definition, $root, $context), BoolQuery::MUST_NOT);
            }

            $bool->add(
                $xorQuery,
                BoolQuery::SHOULD
            );
        }

        return $bool;
    }

    private function createNestedQuery(BuilderInterface $query, EntityDefinition $definition, string $field): BuilderInterface
    {
        $path = $this->getNestedPath($definition, $field);

        if ($path) {
            return new NestedQuery($path, $query);
        }

        return $query;
    }

    private function getField(EntityDefinition $definition, string $fieldName): ?Field
    {
        $root = $definition->getEntityName();

        $parts = explode('.', $fieldName);
        if ($root === $parts[0]) {
            array_shift($parts);
        }

        return $this->helper->getField($fieldName, $definition, $root, false);
    }

    private function getNestedPath(EntityDefinition $definition, string $accessor): ?string
    {
        if (mb_strpos($accessor, $definition->getEntityName() . '.') === false) {
            $accessor = $definition->getEntityName() . '.' . $accessor;
        }

        $fields = EntityDefinitionQueryHelper::getFieldsOfAccessor($definition, $accessor);

        $path = [];
        foreach ($fields as $field) {
            if (!$field instanceof AssociationField) {
                break;
            }

            $path[] = $field->getPropertyName();
        }

        if ($path === []) {
            return null;
        }

        return implode('.', $path);
    }

    private function parseValue(EntityDefinition $definition, SingleFieldFilter $filter, mixed $value): mixed
    {
        $field = $this->getField($definition, $filter->getField());
        $definition = EntityDefinitionQueryHelper::getAssociatedDefinition($definition, $filter->getField());

        if ($field instanceof TranslatedField) {
            $field = EntityDefinitionQueryHelper::getTranslatedField($definition, $field);
        }

        if ($field instanceof CustomFields) {
            $accessor = \explode('.', $filter->getField());
            $last = \array_pop($accessor);

            $field = $this->customFieldService->getCustomField($last);
        }

        if ($field instanceof BoolField) {
            return match (true) {
                $value === null => null,
                \is_array($value) => \array_map(static fn ($value) => (bool) $value, $value),
                default => (bool) $value,
            };
        }

        if ($field instanceof DateTimeField) {
            return match (true) {
                $value === null => null,
                \is_array($value) => \array_map(static fn ($value) => new \DateTime($value)->format('Y-m-d H:i:s.000'), $value),
                default => new \DateTime($value)->format('Y-m-d H:i:s.000'),
            };
        }

        if ($field instanceof FloatField) {
            return match (true) {
                $value === null => null,
                \is_array($value) => \array_map(static fn ($value) => (float) $value, $value),
                default => (float) $value,
            };
        }

        if ($field instanceof IntField) {
            return match (true) {
                $value === null => null,
                \is_array($value) => \array_map(static fn ($value) => (int) $value, $value),
                default => (int) $value,
            };
        }

        return $value;
    }

    private function createTranslatedSorting(string $root, FieldSorting $sorting, Context $context): FieldSort
    {
        $parts = explode('.', $sorting->getField());
        if ($root === $parts[0]) {
            array_shift($parts);
        }

        $translatedFieldSortingScript = $this->getScript('translated_field_sorting');
        if ($parts[0] === 'customFields') {
            $customField = $this->customFieldService->getCustomField($parts[1]);

            if ($customField instanceof IntField || $customField instanceof FloatField) {
                $numericTranslatedFieldSortingScript = $this->getScript('numeric_translated_field_sorting');

                return new FieldSort('_script', $sorting->getDirection(), null, [
                    'type' => 'number',
                    'script' => array_merge($numericTranslatedFieldSortingScript, [
                        'params' => [
                            'field' => 'customFields',
                            'languages' => $context->getLanguageIdChain(),
                            'suffix' => $parts[1] ?? '',
                            'order' => strtolower($sorting->getDirection()),
                        ],
                    ]),
                ]);
            }

            return new FieldSort('_script', $sorting->getDirection(), null, [
                'type' => 'string',
                'script' => array_merge($translatedFieldSortingScript, [
                    'params' => [
                        'field' => 'customFields',
                        'languages' => $context->getLanguageIdChain(),
                        'suffix' => $parts[1] ?? '',
                    ],
                ]),
            ]);
        }

        return new FieldSort('_script', $sorting->getDirection(), null, [
            'type' => 'string',
            'script' => array_merge($translatedFieldSortingScript, [
                'params' => [
                    'field' => implode('.', $parts),
                    'languages' => $context->getLanguageIdChain(),
                ],
            ]),
        ]);
    }

    private function getTranslatedFieldName(string $accessor, string $languageId): string
    {
        $parts = explode('.', $accessor);

        if ($parts[0] !== 'customFields') {
            return \sprintf('%s.%s', $accessor, $languageId);
        }

        return \sprintf('%s.%s.%s', $parts[0], $languageId, $parts[1]);
    }

    private function loadScriptContent(string $filename): string
    {
        $scriptPath = realpath(__DIR__ . '/../../Framework/Indexing/Scripts/' . $filename);

        // Check if the file exists and is readable
        if ($scriptPath === false || !is_readable($scriptPath)) {
            return '';
        }

        $scriptContent = file_get_contents($scriptPath);

        // Check for reading issues
        if ($scriptContent === false) {
            return '';
        }

        return $scriptContent;
    }

    /**
     * @return array{source?: string, lang?: string, id?: string}
     */
    private function getScript(string $scriptName): array
    {
        return [
            'source' => $this->loadScriptContent($scriptName . '.groovy'),
            'lang' => 'painless',
        ];
    }

    private function buildFieldSort(FieldSorting $sorting, EntityDefinition $definition, Context $context): FieldSort
    {
        $field = $this->helper->getField($sorting->getField(), $definition, $definition->getEntityName(), false);
        $accessor = $this->buildAccessor($definition, $sorting->getField(), $context);

        if ($field instanceof TranslatedField) {
            if (!$this->shouldUseMainContextLanguage($field, $context)) {
                return $this->createTranslatedSorting($definition->getEntityName(), $sorting, $context);
            }

            $accessor = $this->getTranslatedFieldName($accessor, $context->getLanguageId());
        }

        if ($sorting instanceof CountSorting) {
            return new CountSort($accessor, $sorting->getDirection());
        }

        return new FieldSort($accessor, $sorting->getDirection());
    }

    private function shouldUseMainContextLanguage(TranslatedField $field, Context $context): bool
    {
        if (\count($context->getLanguageIdChain()) === 1) {
            return true;
        }

        return $field->useForSorting();
    }
}
