<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Blog;

use OpenSearchDSL\BuilderInterface;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;

abstract class AbstractBlogSearchQueryBuilder
{
    abstract public function getDecorated(): AbstractBlogSearchQueryBuilder;

    abstract public function build(Criteria $criteria, Context $context): BuilderInterface;
}
