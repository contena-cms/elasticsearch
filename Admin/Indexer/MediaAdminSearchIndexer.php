<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Admin\Indexer;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Contena\Core\Content\Media\Aggregate\MediaTag\MediaTagDefinition;
use Contena\Core\Content\Media\Aggregate\MediaTranslation\MediaTranslationDefinition;
use Contena\Core\Content\Media\MediaCollection;
use Contena\Core\Content\Media\MediaDefinition;
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

final class MediaAdminSearchIndexer extends AbstractAdminIndexer
{
    /**
     * @internal
     *
     * @param EntityRepository<MediaCollection> $repository
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
        return MediaDefinition::ENTITY_NAME;
    }

    public function getName(): string
    {
        return 'media-listing';
    }

    public function getIterator(): IterableQuery
    {
        return $this->factory->createIterator($this->getEntity(), null, $this->indexingBatchSize);
    }

    public function getUpdatedIds(EntityWrittenContainerEvent $event): array
    {
        $mediaIds = $event->getPrimaryKeysWithPropertyChange($this->getEntity(), [
            'fileName',
            'fileExtension',
            'fileSize',
            'path',
            'mediaFolderId',
        ]);

        $tags = $event->getPrimaryKeysWithPropertyChange(MediaTagDefinition::ENTITY_NAME, [
            'tagId',
        ]);

        $translations = $event->getPrimaryKeysWithPropertyChange(MediaTranslationDefinition::ENTITY_NAME, [
            'title',
            'alt',
        ]);

        foreach (array_merge($tags, $translations) as $pks) {
            if (isset($pks['mediaId'])) {
                $mediaIds[] = $pks['mediaId'];
            }
        }

        return array_values(array_unique(array_filter($mediaIds, '\is_string')));
    }

    public function mapping(array $mapping): array
    {
        if (!Feature::isActive('ENABLE_OPENSEARCH_FOR_ADMIN_API')) {
            return parent::mapping($mapping);
        }

        $languageFields = $this->fieldBuilder->translated(AbstractElasticsearchDefinition::KEYWORD_FIELD);

        $override = [
            'fileName' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'fileExtension' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'fileSize' => AbstractElasticsearchDefinition::INT_FIELD,
            'path' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'private' => AbstractElasticsearchDefinition::BOOLEAN_FIELD,
            'mediaFolderId' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'title' => $languageFields,
            'alt' => $languageFields,
            'mediaFolder' => ElasticsearchFieldBuilder::nested([
                'name' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
                'path' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
                'defaultFolder' => ElasticsearchFieldBuilder::nested([
                    'entity' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
                ]),
            ]),
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
     *     completion: list<string>
     * }|array{
     *     id: string,
     *     text: string,
     *     fileName: mixed,
     *     private: bool,
     *     fileExtension: mixed,
     *     fileSize: int|null,
     *     path: mixed,
     *     mediaFolderId: mixed,
     *     title: array<string, string>,
     *     mediaFolder: array{
     *         name?: string,
     *         path?: string,
     *         defaultFolder?: array{entity: string}
     *     },
     *     alt: array<string, string>,
     *     tags: list<array{
     *         id: string,
     *         _count: int
     *     }>,
     *     createdAt: string|null
     * }>
     */
    public function fetch(array $ids): array
    {
        $data = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT LOWER(HEX(media.id)) as id,
                   LOWER(HEX(media.tenant_id)) as tenantId,
                   tag_agg.tags as tags,
                   tag_agg.tagIds as tagIds,
                   translation_agg.alt as alt,
                   translation_agg.title as title,
                   translation_agg.translatedFields as translatedFields,
                   media_folder.name as folderName,
                   media_folder.path as folderPath,
                   media_default_folder.entity,
                   media.private,
                   media.file_name,
                   media.file_extension,
                   media.file_size,
                   media.path,
                   LOWER(HEX(media.media_folder_id)) AS mediaFolderId,
                   media.created_at as createdAt
            FROM media
                LEFT JOIN (
                    SELECT media_translation.media_id,
                           GROUP_CONCAT(DISTINCT media_translation.alt ORDER BY NULL SEPARATOR ' ') as alt,
                           GROUP_CONCAT(DISTINCT media_translation.title ORDER BY NULL SEPARATOR ' ') as title,
                           JSON_ARRAYAGG(JSON_OBJECT(
                               'languageId', LOWER(HEX(media_translation.language_id)),
                               'title', media_translation.title,
                               'alt', media_translation.alt
                           )) as translatedFields
                    FROM media_translation
                    WHERE media_translation.media_id IN (:ids)
                    GROUP BY media_translation.media_id
                ) as translation_agg
                    ON media.id = translation_agg.media_id
                LEFT JOIN media_folder
                    ON media.media_folder_id = media_folder.id
                LEFT JOIN media_default_folder
                    ON media_folder.default_folder_id = media_default_folder.id
                LEFT JOIN (
                    SELECT media_tag.media_id,
                           GROUP_CONCAT(DISTINCT tag.name ORDER BY NULL SEPARATOR ' ') as tags,
                           GROUP_CONCAT(LOWER(HEX(tag.id)) ORDER BY NULL SEPARATOR ' ') as tagIds
                    FROM media_tag
                    LEFT JOIN tag
                        ON media_tag.tag_id = tag.id
                    WHERE media_tag.media_id IN (:ids)
                    GROUP BY media_tag.media_id
                ) as tag_agg
                    ON media.id = tag_agg.media_id
            WHERE media.id IN (:ids)
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
            $text = \implode(' ', array_filter([
                $row['file_name'] ?? '',
                $row['file_extension'] ?? '',
                $row['path'] ?? '',
                $row['alt'] ?? '',
                $row['title'] ?? '',
                $row['folderName'] ?? '',
                $row['tags'] ?? '',
                $id,
            ]));

            $translatedTitles = $this->decodeTranslatedValues((string) ($row['translatedFields'] ?? ''), 'title');
            $translatedAlts = $this->decodeTranslatedValues((string) ($row['translatedFields'] ?? ''), 'alt');
            $completion = $this->buildCompletion([
                ...array_values($translatedTitles),
                ...array_values($translatedAlts),
                \is_string($row['file_name'] ?? null) ? $row['file_name'] : null,
            ]);

            if (!Feature::isActive('ENABLE_OPENSEARCH_FOR_ADMIN_API')) {
                $mapped[$id] = [
                    'id' => $id,
                    'tenantId' => $row['tenantId'] ?? null,
                    'text' => \strtolower($text),
                    'completion' => $completion,
                ];

                continue;
            }

            $mediaFolder = [];

            if (isset($row['folderName']) && \is_string($row['folderName'])) {
                $mediaFolder['name'] = $row['folderName'];
            }

            if (isset($row['folderPath']) && \is_string($row['folderPath'])) {
                $mediaFolder['path'] = $row['folderPath'];
            }

            if (isset($row['entity']) && \is_string($row['entity'])) {
                $mediaFolder['defaultFolder'] = [
                    'entity' => $row['entity'],
                ];
            }

            $mapped[$id] = [
                'id' => $id,
                'tenantId' => $row['tenantId'] ?? null,
                'text' => \strtolower($text),
                'fileName' => $row['file_name'] ?? null,
                'private' => (bool) $row['private'],
                'fileExtension' => $row['file_extension'] ?? null,
                'fileSize' => isset($row['file_size']) ? (int) $row['file_size'] : null,
                'path' => $row['path'] ?? null,
                'mediaFolderId' => $row['mediaFolderId'] ?? null,
                'title' => $translatedTitles,
                'mediaFolder' => $mediaFolder,
                'alt' => $translatedAlts,
                'tags' => $this->parseTagIds($row),
                'createdAt' => $this->formatDateTime($row, 'createdAt'),
            ];
        }

        return $mapped;
    }
}
