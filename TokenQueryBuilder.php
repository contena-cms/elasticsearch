<?php declare(strict_types=1);

namespace Contena\Elasticsearch;

use OpenSearchDSL\BuilderInterface;
use OpenSearchDSL\Query\Compound\BoolQuery;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Contena\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Contena\Core\Framework\DataAbstractionLayer\Field\TranslatedField;
use Contena\Core\Framework\Plugin\Exception\DecorationPatternException;
use Contena\Core\System\CustomField\CustomFieldService;
use Contena\Elasticsearch\Blog\SearchFieldConfig;

/**
 * @phpstan-type SearchConfig array{and_logic: string, field: string, tokenize: int, ranking: float|int}
 *
 * @final
 */
class TokenQueryBuilder extends AbstractTokenQueryBuilder
{
    /**
     * @internal
     */
    public function __construct(
        private readonly DefinitionInstanceRegistry $definitionRegistry,
        private readonly CustomFieldService $customFieldService,
        private readonly AbstractFieldQueryBuilder $fieldQueryBuilder,
    ) {
    }

    public function getDecorated(): AbstractTokenQueryBuilder
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @param SearchFieldConfig[] $configs
     */
    public function build(string $entity, string $token, array $configs, Context $context): ?BuilderInterface
    {
        $token = mb_strtolower(trim($token));
        $tokenQueries = [];

        $definition = $this->definitionRegistry->getByEntityName($entity);

        foreach ($configs as $config) {
            $field = EntityDefinitionQueryHelper::getField($config->getField(), $definition, $definition->getEntityName(), false);
            $fieldDefinition = EntityDefinitionQueryHelper::getAssociatedDefinition($definition, $config->getField());
            $real = $field instanceof TranslatedField ? EntityDefinitionQueryHelper::getTranslatedField($fieldDefinition, $field) : $field;

            if (str_contains($config->getField(), 'customFields')) {
                $real = $this->customFieldService->getCustomField(str_replace('customFields.', '', $config->getField()));
            }

            if (!$real) {
                continue;
            }

            $root = EntityDefinitionQueryHelper::getRoot($config->getField(), $definition);
            $resolvedField = $field instanceof TranslatedField
                ? new TranslatedResolvedField($real, $field, $root)
                : new ResolvedField($real, $root);
            $fieldQuery = $this->fieldQueryBuilder->build(
                $resolvedField,
                $token,
                $config,
                $context,
            );

            if (!$fieldQuery) {
                continue;
            }

            $tokenQueries[] = $fieldQuery;
        }

        if ($tokenQueries === []) {
            return null;
        }

        if (\count($tokenQueries) === 1) {
            return $tokenQueries[0];
        }

        return new BoolQuery([BoolQuery::SHOULD => $tokenQueries]);
    }
}
