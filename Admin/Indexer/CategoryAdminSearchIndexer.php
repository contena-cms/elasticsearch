<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Admin\Indexer;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Contena\Core\Content\Category\Aggregate\CategoryTag\CategoryTagDefinition;
use Contena\Core\Content\Category\Aggregate\CategoryTranslation\CategoryTranslationDefinition;
use Contena\Core\Content\Category\CategoryCollection;
use Contena\Core\Content\Category\CategoryDefinition;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\Common\IterableQuery;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Contena\Core\Framework\DataAbstractionLayer\EntityRepository;
use Contena\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\Feature;
use Contena\Core\Framework\Plugin\Exception\DecorationPatternException;
use Contena\Core\Framework\Uuid\Uuid;
use Contena\Elasticsearch\Framework\AbstractElasticsearchDefinition;
use Contena\Elasticsearch\Framework\ElasticsearchFieldBuilder;

final class CategoryAdminSearchIndexer extends AbstractAdminIndexer
{
    /**
     * @internal
     *
     * @param EntityRepository<CategoryCollection> $repository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly IteratorFactory $factory,
        private readonly EntityRepository $repository,
        private readonly ElasticsearchFieldBuilder $fieldBuilder,
        private readonly int $indexingBatchSize
    ) {
    }

    public function getDecorated(): AbstractAdminIndexer
    {
        throw new DecorationPatternException(self::class);
    }

    public function getEntity(): string
    {
        return CategoryDefinition::ENTITY_NAME;
    }

    public function getName(): string
    {
        return 'category-listing';
    }

    public function getIterator(): IterableQuery
    {
        return $this->factory->createIterator($this->getEntity(), null, $this->indexingBatchSize);
    }

    public function getUpdatedIds(EntityWrittenContainerEvent $event): array
    {
        $categoryIds = $event->getPrimaryKeysWithPropertyChange($this->getEntity(), [
            'active',
            'parentId',
            'visible',
            'type',
        ]);

        $translations = $event->getPrimaryKeysWithPropertyChange(CategoryTranslationDefinition::ENTITY_NAME, [
            'name',
        ]);

        $tags = $event->getPrimaryKeysWithPropertyChange(CategoryTagDefinition::ENTITY_NAME, [
            'tagId',
        ]);

        foreach (array_merge($translations, $tags) as $pks) {
            if (isset($pks['categoryId'])) {
                $categoryIds[] = $pks['categoryId'];
            }
        }

        return array_values(array_unique(array_filter($categoryIds, '\is_string')));
    }

    public function mapping(array $mapping): array
    {
        if (!Feature::isActive('ENABLE_OPENSEARCH_FOR_ADMIN_API')) {
            return parent::mapping($mapping);
        }

        $languageFields = $this->fieldBuilder->translated(AbstractElasticsearchDefinition::KEYWORD_FIELD);

        $override = [
            'active' => AbstractElasticsearchDefinition::BOOLEAN_FIELD,
            'visible' => AbstractElasticsearchDefinition::BOOLEAN_FIELD,
            'type' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'parentId' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'name' => $languageFields,
            'createdAt' => ElasticsearchFieldBuilder::datetime(),
            'tags' => ElasticsearchFieldBuilder::nested(),
        ];

        $mapping['properties'] ??= [];
        $mapping['properties'] = array_merge($mapping['properties'], $override);

        return $mapping;
    }

    public function globalData(array $result, Context $context): array
    {
        $ids = array_column($result['hits'], 'id');

        return [
            'total' => (int) $result['total'],
            'data' => $this->repository->search(new Criteria($ids), $context)->getEntities(),
        ];
    }

    /**
     * @return array<string, array{
     *     id: string,
     *     text: string,
     *     completion: list<string>}|array{
     *         id: string,
     *         parentId: mixed,
     *         text: string,
     *         completion: list<string>,
     *         name: array<string, string>,
     *         active: bool,
     *         visible: bool,
     *         type: mixed,
     *         tags: list<array{
     *             id: string,
     *             _count: int
     *         }>,
     *         createdAt: string|null
     * }>
     */
    public function fetch(array $ids): array
    {
        $data = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT LOWER(HEX(category.id)) as id,
                   LOWER(HEX(category.tenant_id)) as tenantId,
                   LOWER(HEX(category.parent_id)) as parentId,
                   GROUP_CONCAT(DISTINCT category_translation.name SEPARATOR " ") as name,
                   JSON_ARRAYAGG(JSON_OBJECT(
                       'languageId', LOWER(HEX(category_translation.language_id)),
                       'name', category_translation.name
                   )) as translatedNames,
                   GROUP_CONCAT(DISTINCT tag.name SEPARATOR " ") as tags,
                   GROUP_CONCAT(LOWER(HEX(tag.id)) SEPARATOR " ") as tagIds,
                   category.active AS active,
                   category.visible AS visible,
                   category.type AS type,
                   category.created_at as createdAt
            FROM category
                INNER JOIN category_translation
                    ON category_translation.category_id = category.id
                LEFT JOIN category_tag
                    ON category_tag.category_id = category.id
                LEFT JOIN tag
                    ON category_tag.tag_id = tag.id
            WHERE category.id IN (:ids)
            GROUP BY category.id
SQL,
            [
                'ids' => Uuid::fromHexToBytesList($ids),
            ],
            [
                'ids' => ArrayParameterType::BINARY,
            ]
        );

        $mapped = [];
        foreach ($data as $row) {
            $id = (string) $row['id'];
            $text = \implode(' ', array_filter([$row['name'] ?? '', $row['tags'] ?? '', $id]));
            $translatedNames = $this->decodeTranslatedValues((string) ($row['translatedNames'] ?? ''));
            $completion = $this->buildCompletion(array_values($translatedNames) ?: [(string) ($row['name'] ?? '')]);

            if (!Feature::isActive('ENABLE_OPENSEARCH_FOR_ADMIN_API')) {
                $mapped[$id] = [
                    'id' => $id,
                    'tenantId' => $row['tenantId'] ?? null,
                    'text' => \strtolower($text),
                    'completion' => $completion,
                ];

                continue;
            }

            $mapped[$id] = [
                'id' => $id,
                'tenantId' => $row['tenantId'] ?? null,
                'parentId' => $row['parentId'] ?? null,
                'text' => \strtolower($text),
                'completion' => $completion,
                'name' => $translatedNames,
                'active' => (bool) $row['active'],
                'visible' => (bool) $row['visible'],
                'type' => $row['type'] ?? null,
                'tags' => $this->parseTagIds($row),
                'createdAt' => $this->formatDateTime($row, 'createdAt'),
            ];
        }

        return $mapped;
    }
}
