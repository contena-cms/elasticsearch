<?php declare(strict_types=1);

namespace Contena\Elasticsearch;

use Contena\Core\Framework\DataAbstractionLayer\Field\Field;
use Contena\Core\Framework\DataAbstractionLayer\Field\TranslatedField;

final readonly class TranslatedResolvedField extends ResolvedField
{
    public function __construct(
        Field $resolvedField,
        private TranslatedField $translatedField,
        ?string $root = null,
    ) {
        parent::__construct($resolvedField, $root);
    }

    public function getTranslatedField(): TranslatedField
    {
        return $this->translatedField;
    }
}
