<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Indexing;

use Contena\Core\Framework\Context;
use Contena\Elasticsearch\Framework\AbstractElasticsearchDefinition;

class IndexMappingProvider
{
    /**
     * @internal
     *
     * @param array<mixed> $mapping
     */
    public function __construct(
        private readonly array $mapping,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function build(AbstractElasticsearchDefinition $definition, Context $context): array
    {
        $mapping = $definition->getMapping($context);

        return array_merge_recursive($mapping, $this->mapping);
    }
}
