<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Blog;

use OpenSearchDSL\BuilderInterface;
use OpenSearchDSL\Query\Compound\BoolQuery;
use OpenSearchDSL\Query\TermLevel\ExistsQuery;
use OpenSearchDSL\Query\TermLevel\RangeQuery;
use OpenSearchDSL\Query\TermLevel\TermQuery;
use OpenSearchDSL\Query\TermLevel\TermsQuery;
use Contena\Core\Content\Blog\BlogDefinition;
use Contena\Core\Content\Blog\Channel\BlogAvailableFilter;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Contena\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Contena\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Contena\Core\System\CustomField\CustomFieldService;
use Contena\Elasticsearch\Framework\DataAbstractionLayer\CriteriaParser;

/**
 * @internal - This class is part of the internal API, optimized for read and should not be used directly.
 */
class BlogCriteriaParser extends CriteriaParser
{
    public function __construct(
        EntityDefinitionQueryHelper $helper,
        CustomFieldService $customFieldService
    ) {
        parent::__construct($helper, $customFieldService);
    }

    public function parseFilter(Filter $filter, EntityDefinition $definition, string $root, Context $context): BuilderInterface
    {
        if (!$definition instanceof BlogDefinition) {
            return parent::parseFilter($filter, $definition, $root, $context);
        }

        if ($filter instanceof BlogAvailableFilter) {
            $query = new BoolQuery();

            // Listing previews can add BlogAvailableFilter without a separate active filter.
            foreach ($filter->getQueries() as $subFilter) {
                if ($subFilter instanceof EqualsFilter && \in_array($subFilter->getField(), ['blog.active', 'active'], true)) {
                    $query->add(
                        new TermQuery('active', true),
                    );

                    break;
                }
            }

            $query->add(
                new RangeQuery('visibility_' . $filter->getChannelId(), [RangeFilter::GTE => $filter->getVisibility()]),
            );

            return $query;
        }

        if ($filter instanceof EqualsFilter && \str_contains($filter->getField(), 'categoriesRo.id')) {
            if ($filter->getValue() === null) {
                return new BoolQuery([
                    BoolQuery::MUST_NOT => new ExistsQuery('categoryTree'),
                ]);
            }

            return new TermQuery('categoryTree', $filter->getValue());
        }

        if ($filter instanceof EqualsAnyFilter && \str_contains($filter->getField(), 'categoriesRo.id')) {
            return new TermsQuery('categoryTree', array_values($filter->getValue()));
        }

        return parent::parseFilter($filter, $definition, $root, $context);
    }
}
