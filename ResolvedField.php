<?php declare(strict_types=1);

namespace Contena\Elasticsearch;

use Contena\Core\Framework\DataAbstractionLayer\Field\Field;

readonly class ResolvedField
{
    public function __construct(
        private Field $resolvedField,
        private ?string $root = null,
    ) {
    }

    public function getResolvedField(): Field
    {
        return $this->resolvedField;
    }

    public function getRoot(): ?string
    {
        return $this->root;
    }
}
