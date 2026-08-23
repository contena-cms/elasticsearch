<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Blog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use OpenSearchDSL\BuilderInterface;
use Contena\Core\Content\Blog\BlogDefinition;
use Contena\Core\Defaults;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\SqlHelper;
use Contena\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\Uuid\Uuid;
use Contena\Core\System\Language\ChannelLanguageLoader;
use Contena\Core\System\Language\LanguageLoaderInterface;
use Contena\Elasticsearch\Framework\AbstractElasticsearchDefinition;
use Contena\Elasticsearch\Framework\ElasticsearchFieldBuilder;
use Contena\Elasticsearch\Framework\ElasticsearchFieldMapper;
use Contena\Elasticsearch\Framework\ElasticsearchIndexingUtils;

/**
 * @internal
 */
class ElasticsearchBlogDefinition extends AbstractElasticsearchDefinition
{
    public function __construct(
        private readonly EntityDefinition $definition,
        private readonly Connection $connection,
        private readonly AbstractBlogSearchQueryBuilder $searchQueryBuilder,
        private readonly ElasticsearchFieldBuilder $fieldBuilder,
        private readonly ElasticsearchFieldMapper $fieldMapper,
        private readonly ChannelLanguageLoader $channelLanguageLoader,
        private readonly bool $excludeSource,
        private readonly string $environment,
        private readonly LanguageLoaderInterface $languageLoader
    ) {
    }

    public function getEntityDefinition(): EntityDefinition
    {
        return $this->definition;
    }

    /**
     * {@inheritdoc}
     */
    public function getMapping(Context $context): array
    {
        $languageFields = $this->fieldBuilder->translated(self::buildTextFieldConfig());
        $languageFieldsWithLengthNorm = $this->fieldBuilder->translated(self::buildTextFieldConfig(lengthNorm: true));
        $technicalLanguageFieldsWithExact = $this->fieldBuilder->translated(self::buildTextFieldConfig(withExact: true, technicalTerms: true));
        $channelsByLanguage = $this->channelLanguageLoader->loadLanguages();
        $channelIds = $channelsByLanguage === []
            ? []
            : array_values(array_unique(array_merge(...array_values($channelsByLanguage))));

        $visibilities = [];
        foreach ($channelIds as $channelId) {
            $visibilities['visibility_' . $channelId] = self::INT_FIELD;
        }

        $mapping = [
            'dynamic_templates' => [[
                'long_to_double' => [
                    'match_mapping_type' => 'long',
                    'mapping' => ['type' => 'double'],
                ],
            ]],
            'properties' => [
                'id' => self::KEYWORD_FIELD,
                'tenantId' => self::KEYWORD_FIELD,
                'name' => $technicalLanguageFieldsWithExact,
                'description' => $languageFieldsWithLengthNorm,
                'descriptionTeaser' => $languageFields,
                'keywords' => $languageFields,
                'metaTitle' => $languageFields,
                'metaDescription' => $languageFieldsWithLengthNorm,
                'customSearchKeywords' => $this->fieldBuilder->translated(self::buildTextFieldConfig(withExact: true, technicalTerms: true, lengthNorm: true)),
                'categories' => ElasticsearchFieldBuilder::nested(['name' => $languageFields]),
                'categoriesRo' => ElasticsearchFieldBuilder::nested(),
                'active' => self::BOOLEAN_FIELD,
                'type' => self::KEYWORD_FIELD,
                'categoryTree' => self::KEYWORD_FIELD,
                'categoryIds' => self::KEYWORD_FIELD,
                'tagIds' => self::KEYWORD_FIELD,
                'autoIncrement' => self::INT_FIELD,
                'releaseDate' => ElasticsearchFieldBuilder::datetime(),
                'createdAt' => ElasticsearchFieldBuilder::datetime(),
                'tags' => ElasticsearchFieldBuilder::nested(['name' => self::buildTextFieldConfig()]),
                'visibilities' => ElasticsearchFieldBuilder::nested([
                    'id' => null,
                    'channelId' => self::KEYWORD_FIELD,
                    'visibility' => self::INT_FIELD,
                ]),
                'coverId' => self::KEYWORD_FIELD,
                'openGraphMediaId' => self::KEYWORD_FIELD,
                'customFields' => $this->fieldBuilder->customFields($this->getEntityDefinition()->getEntityName(), $context),
                ...$visibilities,
            ],
        ];

        $debug = $this->environment === 'dev' || $this->environment === 'test';
        if (!$this->excludeSource && !$debug) {
            $mapping['_source'] = ['includes' => ['id', 'autoIncrement']];
        }

        return $mapping;
    }

    public function buildTermQuery(Context $context, Criteria $criteria): BuilderInterface
    {
        return $this->searchQueryBuilder->build($criteria, $context);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \JsonException
     */
    public function fetch(array $ids, Context $context): array
    {
        $data = $this->fetchBlogs($ids, $context);
        if ($data === []) {
            return [];
        }

        $languageMapping = $this->getLanguageMapping();
        $documents = [];

        foreach ($data as $id => $item) {
            /** @var list<array<string, mixed>> $translations */
            $translations = $item['translation'] ?? [];
            /** @var list<array<string, mixed>> $categories */
            $categories = $item['categories'] ?? [];

            $names = $this->fillFallbackTranslation(
                $languageMapping,
                ElasticsearchFieldMapper::translated(field: 'name', items: $translations)
            );
            $translatedCustomFields = array_filter(
                ElasticsearchFieldMapper::translated(field: 'customFields', items: $translations, stripText: false),
                static fn (mixed $customField): bool => $customField !== null
            );
            $customFields = $this->fieldMapper->customFields(
                BlogDefinition::ENTITY_NAME,
                $translatedCustomFields,
                $context
            );

            /** @var list<array{id?: string, channelId?: string, visibility?: int}> $visibilityRows */
            $visibilityRows = ElasticsearchIndexingUtils::parseJson($item, 'visibilities');
            $visibilityFields = [];
            foreach ($visibilityRows as $key => $visibility) {
                if (!isset($visibility['channelId'])) {
                    unset($visibilityRows[$key]);
                    continue;
                }

                $visibilityFields['visibility_' . $visibility['channelId']] = $visibility['visibility'] ?? 0;
            }

            if ($visibilityFields === []) {
                continue;
            }

            $categoryTree = ElasticsearchIndexingUtils::parseJson($item, 'categoryTree');

            $documents[$id] = [
                'id' => $id,
                'tenantId' => $item['tenantId'],
                'autoIncrement' => (float) $item['autoIncrement'],
                'active' => (bool) $item['active'],
                'type' => $item['type'],
                'releaseDate' => isset($item['releaseDate']) ? new \DateTime($item['releaseDate'])->format('c') : null,
                'createdAt' => isset($item['createdAt']) ? new \DateTime($item['createdAt'])->format('c') : null,
                'categoryTree' => $categoryTree,
                'categoriesRo' => array_values(array_map(
                    static fn (string $categoryId): array => ['id' => $categoryId, '_count' => 1],
                    $categoryTree
                )),
                'categoryIds' => ElasticsearchIndexingUtils::parseJson($item, 'categoryIds'),
                'tagIds' => ElasticsearchIndexingUtils::parseJson($item, 'tagIds'),
                'tags' => array_values(array_filter(array_map(static function (array $tag): ?array {
                    return ($tag['id'] ?? '') === '' ? null : [
                        'id' => $tag['id'],
                        'name' => ElasticsearchIndexingUtils::stripText($tag['name'] ?? ''),
                        '_count' => 1,
                    ];
                }, ElasticsearchIndexingUtils::parseJson($item, 'tags')))),
                'visibilities' => array_values(array_map(static fn (array $visibility): array => [
                    ...$visibility,
                    '_count' => 1,
                ], $visibilityRows)),
                'coverId' => $item['coverId'],
                'openGraphMediaId' => $item['openGraphMediaId'],
                'categories' => ElasticsearchFieldMapper::toManyAssociations(items: $categories, translatedFields: ['name']),
                'customFields' => $customFields,
                'name' => $names,
                'description' => ElasticsearchFieldMapper::translated(field: 'description', items: $translations),
                'descriptionTeaser' => ElasticsearchFieldMapper::translated(field: 'descriptionTeaser', items: $translations),
                'keywords' => ElasticsearchFieldMapper::translated(field: 'keywords', items: $translations),
                'metaTitle' => ElasticsearchFieldMapper::translated(field: 'metaTitle', items: $translations),
                'metaDescription' => ElasticsearchFieldMapper::translated(field: 'metaDescription', items: $translations),
                'customSearchKeywords' => ElasticsearchFieldMapper::translated(field: 'customSearchKeywords', items: $translations),
                ...$visibilityFields,
            ];
        }

        return $documents;
    }

    /**
     * @param array<string> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchBlogs(array $ids, Context $context): array
    {
        $languages = array_keys($this->channelLanguageLoader->loadLanguages());
        if (!\in_array(Defaults::LANGUAGE_SYSTEM, $languages, true)) {
            $languages[] = Defaults::LANGUAGE_SYSTEM;
        }

        $sql = <<<'SQL'
SELECT
    LOWER(HEX(blog.id)) AS id,
    LOWER(HEX(blog.tenant_id)) AS tenantId,
    blog.active,
    blog.type,
    blog.auto_increment AS autoIncrement,
    blog.release_date AS releaseDate,
    blog.created_at AS createdAt,
    blog.category_tree AS categoryTree,
    blog.category_ids AS categoryIds,
    blog.tag_ids AS tagIds,
    LOWER(HEX(blog.blog_media_id)) AS coverId,
    LOWER(HEX(blog.open_graph_media_id)) AS openGraphMediaId,
    #tags#,
    #visibilities#
FROM blog
    LEFT JOIN blog_visibility
        ON blog_visibility.blog_id = blog.id AND blog_visibility.blog_version_id = blog.version_id
    LEFT JOIN blog_tag
        ON blog_tag.blog_id = blog.id AND blog_tag.blog_version_id = blog.version_id
    LEFT JOIN tag ON tag.id = blog_tag.tag_id
WHERE blog.id IN (:ids) AND blog.version_id = :liveVersionId #tenant-filter#
GROUP BY blog.id
SQL;

        $tenantFilter = '';
        $parameters = [
            'ids' => $ids,
            'liveVersionId' => Uuid::fromHexToBytes($context->getVersionId()),
        ];
        if (!$context->hasGlobalTenantAccess()) {
            $tenantId = $context->getTenantId();
            if ($tenantId === null) {
                $tenantFilter = 'AND blog.tenant_id IS NULL';
            } else {
                $tenantFilter = 'AND blog.tenant_id = :tenantId';
                $parameters['tenantId'] = Uuid::fromHexToBytes($tenantId);
            }
        }

        $mapping = [
            '#tenant-filter#' => $tenantFilter,
            '#tags#' => SqlHelper::objectArray([
                'name' => 'tag.name',
                'id' => 'LOWER(HEX(tag.id))',
            ], 'tags'),
            '#visibilities#' => SqlHelper::objectArray([
                'id' => 'LOWER(HEX(blog_visibility.id))',
                'visibility' => 'blog_visibility.visibility',
                'channelId' => 'LOWER(HEX(blog_visibility.channel_id))',
            ], 'visibilities'),
        ];

        /** @var array<string, array<string, mixed>> $base */
        $base = $this->connection->fetchAllAssociativeIndexed(
            str_replace(array_keys($mapping), array_values($mapping), $sql),
            $parameters,
            ['ids' => ArrayParameterType::BINARY]
        );

        if ($base === []) {
            return [];
        }

        $translationSql = <<<'SQL'
SELECT
    LOWER(HEX(blog.id)) AS id,
    blog_translation.name,
    blog_translation.description,
    blog_translation.description_teaser AS descriptionTeaser,
    blog_translation.keywords,
    blog_translation.meta_title AS metaTitle,
    blog_translation.meta_description AS metaDescription,
    blog_translation.custom_fields AS customFields,
    blog_translation.custom_search_keywords AS customSearchKeywords,
    #categories#
FROM blog
    LEFT JOIN blog_translation
        ON blog_translation.blog_id = blog.id
        AND blog_translation.blog_version_id = blog.version_id
        AND blog_translation.language_id = :languageId
    LEFT JOIN blog_category
        ON blog_category.blog_id = blog.id AND blog_category.blog_version_id = blog.version_id
    LEFT JOIN category_translation category
        ON category.category_id = blog_category.category_id
        AND category.category_version_id = blog_category.category_version_id
        AND category.language_id = :languageId
        AND category.name IS NOT NULL
WHERE blog.id IN (:ids) AND blog.version_id = :liveVersionId
GROUP BY blog.id
SQL;

        $translationSql = str_replace('#categories#', SqlHelper::objectArray([
            'languageId' => 'LOWER(HEX(category.language_id))',
            'id' => 'LOWER(HEX(category.category_id))',
            'name' => 'category.name',
        ], 'categories'), $translationSql);

        foreach ($languages as $languageId) {
            /** @var array<string, array<string, mixed>> $translations */
            $translations = $this->connection->fetchAllAssociativeIndexed(
                $translationSql,
                [
                    'ids' => Uuid::fromHexToBytesList(array_keys($base)),
                    'languageId' => Uuid::fromHexToBytes($languageId),
                    'liveVersionId' => Uuid::fromHexToBytes($context->getVersionId()),
                ],
                ['ids' => ArrayParameterType::BINARY]
            );

            foreach ($translations as $id => $translation) {
                $translation['languageId'] = $languageId;
                if (($translation['customSearchKeywords'] ?? null) !== null && $translation['customSearchKeywords'] !== '') {
                    $translation['customSearchKeywords'] = ElasticsearchIndexingUtils::parseJson($translation, 'customSearchKeywords');
                }

                $categories = $base[$id]['categories'] ?? [];
                \assert(\is_array($categories));
                $translatedCategories = ElasticsearchIndexingUtils::parseJson($translation, 'categories');

                $base[$id]['translation'] ??= [];
                \assert(\is_array($base[$id]['translation']));
                $base[$id]['translation'][] = $translation;
                $base[$id]['categories'] = [...$categories, ...$translatedCategories];
            }
        }

        return $base;
    }

    /**
     * @return array<string, string>
     */
    private function getLanguageMapping(): array
    {
        $languages = $this->languageLoader->loadLanguages();
        $channelLanguages = $this->channelLanguageLoader->loadLanguages();
        $mapping = [];

        foreach ($languages as $languageId => $language) {
            if (!isset($channelLanguages[$languageId])) {
                continue;
            }

            if (isset($language['parentId'])) {
                $mapping[$language['parentId']] = Defaults::LANGUAGE_SYSTEM;
            }

            $mapping[$languageId] = $language['parentId'] ?? Defaults::LANGUAGE_SYSTEM;
        }

        return $mapping;
    }

    /**
     * @param array<string, string> $languageMapping
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function fillFallbackTranslation(array $languageMapping, array $value): array
    {
        foreach ($languageMapping as $languageId => $fallback) {
            if ($languageId === Defaults::LANGUAGE_SYSTEM || isset($value[$languageId])) {
                continue;
            }

            $value[$languageId] = $value[$fallback] ?? $value[Defaults::LANGUAGE_SYSTEM] ?? null;
        }

        return $value;
    }
}
