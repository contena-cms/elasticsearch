<?php declare(strict_types=1);

namespace Contena\Elasticsearch;

use OpenSearchDSL\BuilderInterface;
use Contena\Core\Framework\Context;
use Contena\Elasticsearch\Blog\SearchFieldConfig;

abstract class AbstractFieldQueryBuilder
{
    abstract public function getDecorated(): self;

    abstract public function build(
        ResolvedField $field,
        string $token,
        SearchFieldConfig $config,
        Context $context,
    ): ?BuilderInterface;
}
