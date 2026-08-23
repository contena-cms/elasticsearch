<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Admin\Indexer;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use OpenSearchDSL\Query\Compound\BoolQuery;
use OpenSearchDSL\Query\FullText\SimpleQueryStringQuery;
use OpenSearchDSL\Search;
use Contena\Core\Content\Blog\Aggregate\BlogCategory\BlogCategoryDefinition;
use Contena\Core\Content\Blog\Aggregate\BlogMedia\BlogMediaDefinition;
use Contena\Core\Content\Blog\Aggregate\BlogTag\BlogTagDefinition;
use Contena\Core\Content\Blog\Aggregate\BlogTranslation\BlogTranslationDefinition;
use Contena\Core\Content\Blog\Aggregate\BlogVisibility\BlogVisibilityDefinition;
use Contena\Core\Content\Blog\BlogCollection;
use Contena\Core\Content\Blog\BlogDefinition;
use Contena\Core\Defaults;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\Common\IterableQuery;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Contena\Core\Framework\DataAbstractionLayer\EntityRepository;
use Contena\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Contena\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\Feature;
use Contena\Core\Framework\Plugin\Exception\DecorationPatternException;
use Contena\Core\Framework\Uuid\Uuid;
use Contena\Elasticsearch\Framework\AbstractElasticsearchDefinition;
use Contena\Elasticsearch\Framework\ElasticsearchFieldBuilder;

final class BlogAdminSearchIndexer extends AbstractAdminIndexer
{
    /**
     * @internal
     *
     * @param EntityRepository<BlogCollection> $repository
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
        return BlogDefinition::ENTITY_NAME;
    }

    public function getName(): string
    {
        return 'blog-listing';
    }

    public function getIterator(): IterableQuery
    {
        return $this->factory->createIterator($this->getEntity(), null, $this->indexingBatchSize);
    }

    public function globalCriteria(string $term, Search $criteria): Search
    {
        $splitTerms = explode(' ', $term);
        $lastPart = (string) end($splitTerms);
        $textBoostedTerm = preg_match('/^[\p{L}0-9]+$/u', $lastPart) === 1 ? $term . '*' : $term;

        $criteria->addQuery(
            new SimpleQueryStringQuery($textBoostedTerm, [
                'fields' => ['textBoosted'],
                'boost' => SearchRanking::HIGH_SEARCH_RANKING,
                'lenient' => true,
            ]),
            BoolQuery::SHOULD
        );

        return $criteria;
    }

    public function getUpdatedIds(EntityWrittenContainerEvent $event): array
    {
        $ids = $event->getPrimaryKeysWithPropertyChange($this->getEntity(), [
            'active',
            'releaseDate',
            'coverId',
            'openGraphMediaId',
        ]);

        $associations = [
            BlogTranslationDefinition::ENTITY_NAME => ['name', 'customSearchKeywords'],
            BlogCategoryDefinition::ENTITY_NAME => ['categoryId'],
            BlogVisibilityDefinition::ENTITY_NAME => ['channelId', 'visibility'],
            BlogMediaDefinition::ENTITY_NAME => ['mediaId'],
            BlogTagDefinition::ENTITY_NAME => ['tagId'],
        ];

        foreach ($associations as $entityName => $properties) {
            foreach ($event->getPrimaryKeysWithPropertyChange($entityName, $properties) as $primaryKey) {
                if (isset($primaryKey['blogId'])) {
                    $ids[] = $primaryKey['blogId'];
                }
            }
        }

        return array_values(array_unique(array_filter($ids, '\is_string')));
    }

    public function mapping(array $mapping): array
    {
        if (!Feature::isActive('ENABLE_OPENSEARCH_FOR_ADMIN_API')) {
            return parent::mapping($mapping);
        }

        $languageFields = $this->fieldBuilder->translated(AbstractElasticsearchDefinition::KEYWORD_FIELD);
        $mapping['properties'] ??= [];
        $mapping['properties'] = array_merge($mapping['properties'], [
            'active' => AbstractElasticsearchDefinition::BOOLEAN_FIELD,
            'name' => $languageFields,
            'categoryIds' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'tagIds' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'channelIds' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'coverId' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'openGraphMediaId' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'releaseDate' => ElasticsearchFieldBuilder::datetime(),
            'createdAt' => ElasticsearchFieldBuilder::datetime(),
            'tags' => ElasticsearchFieldBuilder::nested(),
        ]);

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
     * @param array<string> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetch(array $ids): array
    {
        $data = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT LOWER(HEX(blog.id)) AS id,
                   LOWER(HEX(blog.tenant_id)) AS tenantId,
                   GROUP_CONCAT(DISTINCT blog_translation.name ORDER BY NULL SEPARATOR '\n') AS name,
                   JSON_ARRAYAGG(JSON_OBJECT(
                       'languageId', LOWER(HEX(blog_translation.language_id)),
                       'name', blog_translation.name
                   )) AS translatedNames,
                   CONCAT('[', GROUP_CONCAT(blog_translation.custom_search_keywords), ']') AS customSearchKeywords,
                   GROUP_CONCAT(DISTINCT tag.name SEPARATOR ' ') AS tags,
                   GROUP_CONCAT(DISTINCT LOWER(HEX(tag.id)) SEPARATOR ' ') AS tagIds,
                   GROUP_CONCAT(DISTINCT LOWER(HEX(blog_category.category_id)) SEPARATOR ' ') AS categoryIds,
                   GROUP_CONCAT(DISTINCT LOWER(HEX(blog_visibility.channel_id)) SEPARATOR ' ') AS channelIds,
                   blog.active,
                   LOWER(HEX(blog.blog_media_id)) AS coverId,
                   LOWER(HEX(blog.open_graph_media_id)) AS openGraphMediaId,
                   blog.release_date AS releaseDate,
                   blog.created_at AS createdAt
            FROM blog
                INNER JOIN blog_translation
                    ON blog_translation.blog_id = blog.id AND blog_translation.blog_version_id = blog.version_id
                LEFT JOIN blog_tag
                    ON blog_tag.blog_id = blog.id AND blog_tag.blog_version_id = blog.version_id
                LEFT JOIN tag ON tag.id = blog_tag.tag_id
                LEFT JOIN blog_category
                    ON blog_category.blog_id = blog.id AND blog_category.blog_version_id = blog.version_id
                LEFT JOIN blog_visibility
                    ON blog_visibility.blog_id = blog.id AND blog_visibility.blog_version_id = blog.version_id
            WHERE blog.id IN (:ids) AND blog.version_id = :versionId
            GROUP BY blog.id
SQL,
            [
                'ids' => Uuid::fromHexToBytesList($ids),
                'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
            ['ids' => ArrayParameterType::BINARY]
        );

        $mapped = [];
        foreach ($data as $row) {
            $id = (string) $row['id'];
            $names = $this->splitTranslatedNames(\is_string($row['name'] ?? null) ? $row['name'] : '');
            $textBoosted = '';

            if (\is_string($row['customSearchKeywords'] ?? null) && $row['customSearchKeywords'] !== '[]') {
                $customKeywords = json_decode($row['customSearchKeywords'], true, 512, \JSON_THROW_ON_ERROR);
                if (\is_array($customKeywords) && $customKeywords !== []) {
                    $flattened = array_merge([], ...array_filter($customKeywords, '\is_array'));
                    $textBoosted = trim($textBoosted . ' ' . implode(' ', array_unique($flattened)));
                }
            }

            $text = implode(' ', array_filter([
                $row['name'] ?? '',
                $row['tags'] ?? '',
                $id,
            ]));
            $mapped[$id] = [
                'id' => $id,
                'tenantId' => $row['tenantId'] ?? null,
                'text' => strtolower($text),
                'textBoosted' => strtolower($textBoosted),
                'completion' => $this->buildCompletion($names),
            ];

            if (!Feature::isActive('ENABLE_OPENSEARCH_FOR_ADMIN_API')) {
                continue;
            }

            $mapped[$id] += [
                'active' => (bool) $row['active'],
                'name' => $this->decodeTranslatedValues((string) ($row['translatedNames'] ?? '')),
                'categoryIds' => $this->splitIds($row['categoryIds'] ?? null),
                'tagIds' => $this->splitIds($row['tagIds'] ?? null),
                'channelIds' => $this->splitIds($row['channelIds'] ?? null),
                'coverId' => $row['coverId'] ?? null,
                'openGraphMediaId' => $row['openGraphMediaId'] ?? null,
                'releaseDate' => $this->formatDateTime($row, 'releaseDate'),
                'createdAt' => $this->formatDateTime($row, 'createdAt'),
                'tags' => $this->parseTagIds($row),
            ];
        }

        return $mapped;
    }

    /**
     * @return list<string>
     */
    private function splitTranslatedNames(string $names): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $names))));
    }

    /**
     * @return list<string>
     */
    private function splitIds(mixed $ids): array
    {
        return \is_string($ids) && $ids !== '' ? array_values(array_unique(explode(' ', $ids))) : [];
    }
}
