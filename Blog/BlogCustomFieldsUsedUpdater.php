<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Blog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Contena\Core\Content\Blog\Channel\Sorting\BlogSortingDefinition;
use Contena\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Contena\Core\Framework\Uuid\Uuid;
use Contena\Elasticsearch\Framework\ElasticsearchHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal
 */
class BlogCustomFieldsUsedUpdater implements EventSubscriberInterface
{
    public function __construct(
        private readonly ElasticsearchHelper $elasticsearchHelper,
        private readonly ElasticsearchCustomFieldsMappingHelper $mappingHelper,
        private readonly Connection $connection
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BlogSortingDefinition::ENTITY_NAME . '.written' => 'blogSortingWritten',
        ];
    }

    public function blogSortingWritten(EntityWrittenEvent $event): void
    {
        if (!$this->elasticsearchHelper->allowIndexing()) {
            return;
        }

        $sortingIds = [];
        foreach ($event->getResults()->withPayloadProperties('fields') as $writeResult) {
            $key = $writeResult->getPrimaryKey();
            if (\is_string($key)) {
                $sortingIds[] = $key;
            }
        }

        if ($sortingIds === []) {
            return;
        }

        $rows = $this->connection->fetchFirstColumn(
            'SELECT `fields` FROM `blog_sorting` WHERE `id` IN (:ids) AND `fields` LIKE :pattern',
            ['ids' => Uuid::fromHexToBytesList($sortingIds), 'pattern' => '%customFields.%'],
            ['ids' => ArrayParameterType::BINARY]
        );
        $customFieldNames = self::extractCustomFieldNames($rows);
        if ($customFieldNames === []) {
            return;
        }

        $customFieldTypes = $this->connection->fetchAllKeyValue(
            'SELECT `name`, `type` FROM `custom_field` WHERE `name` IN (:names)',
            ['names' => $customFieldNames],
            ['names' => ArrayParameterType::STRING]
        );
        $this->mappingHelper->createFieldsInIndices(
            ElasticsearchCustomFieldsMappingHelper::mapCustomFieldsToEsTypes($customFieldTypes)
        );
    }

    /**
     * @param list<string> $rows
     *
     * @return list<string>
     */
    public static function extractCustomFieldNames(array $rows): array
    {
        $customFieldNames = [];
        foreach ($rows as $row) {
            try {
                $fields = json_decode($row, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!\is_array($fields)) {
                continue;
            }

            foreach ($fields as $field) {
                $name = $field['field'] ?? null;
                if (\is_string($name) && str_starts_with($name, 'customFields.')) {
                    $customFieldNames[substr($name, \strlen('customFields.'))] = true;
                }
            }
        }

        return array_keys($customFieldNames);
    }
}
