<?php declare(strict_types=1);

namespace Contena\Elasticsearch;

use OpenSearchDSL\BuilderInterface;
use OpenSearchDSL\Query\Joining\NestedQuery;
use Contena\Core\Framework\Context;
use Contena\Elasticsearch\Blog\SearchFieldConfig;

/**
 * @internal
 */
class NestedFieldQueryBuilder extends AbstractFieldQueryBuilder
{
    /**
     * @internal
     */
    public function __construct(
        private readonly AbstractFieldQueryBuilder $fieldQueryBuilder,
    ) {
    }

    public function getDecorated(): AbstractFieldQueryBuilder
    {
        return $this->fieldQueryBuilder;
    }

    public function build(
        ResolvedField $field,
        string $token,
        SearchFieldConfig $config,
        Context $context,
    ): ?BuilderInterface {
        $query = $this->getDecorated()->build($field, $token, $config, $context);

        if (!$query || $field->getRoot() === null) {
            return $query;
        }

        return new NestedQuery($field->getRoot(), $query);
    }
}
