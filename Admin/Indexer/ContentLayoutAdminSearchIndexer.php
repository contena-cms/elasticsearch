<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Admin\Indexer;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Contena\Core\Framework\ContentSystem\Layout\Entity\ContentLayoutCollection;
use Contena\Core\Framework\ContentSystem\Layout\Entity\ContentLayoutDefinition;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\Common\IterableQuery;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Contena\Core\Framework\DataAbstractionLayer\EntityRepository;
use Contena\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\Plugin\Exception\DecorationPatternException;
use Contena\Core\Framework\Uuid\Uuid;
use Contena\Elasticsearch\Framework\AbstractElasticsearchDefinition;

final class ContentLayoutAdminSearchIndexer extends AbstractAdminIndexer
{
    /**
     * @internal
     *
     * @param EntityRepository<ContentLayoutCollection> $repository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly IteratorFactory $factory,
        private readonly EntityRepository $repository,
        private readonly int $indexingBatchSize
    ) {
    }

    public function getDecorated(): AbstractAdminIndexer
    {
        throw new DecorationPatternException(self::class);
    }

    public function getEntity(): string
    {
        return ContentLayoutDefinition::ENTITY_NAME;
    }

    public function getName(): string
    {
        return 'content-layout-listing';
    }

    public function getIterator(): IterableQuery
    {
        return $this->factory->createIterator($this->getEntity(), null, $this->indexingBatchSize);
    }

    public function getUpdatedIds(EntityWrittenContainerEvent $event): array
    {
        return $event->getPrimaryKeysWithPropertyChange($this->getEntity(), [
            'name',
            'version',
            'rootSource',
        ]);
    }

    public function mapping(array $mapping): array
    {
        $mapping['properties'] ??= [];
        $mapping['properties'] = array_merge($mapping['properties'], [
            'name' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'version' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'rootSource' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
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
     * @return array<string, array{id: string, tenantId: mixed, text: string, completion: list<string>, name: string, version: string, rootSource: string}>
     */
    public function fetch(array $ids): array
    {
        $data = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT LOWER(HEX(content_layout.id)) AS id,
                   LOWER(HEX(content_layout.tenant_id)) AS tenantId,
                   content_layout.name,
                   content_layout.version,
                   content_layout.root_source AS rootSource
            FROM content_layout
            WHERE content_layout.id IN (:ids)
SQL,
            ['ids' => Uuid::fromHexToBytesList($ids)],
            ['ids' => ArrayParameterType::BINARY]
        );

        $mapped = [];
        foreach ($data as $row) {
            $id = (string) $row['id'];
            $name = (string) $row['name'];
            $version = (string) $row['version'];
            $rootSource = (string) $row['rootSource'];
            $mapped[$id] = [
                'id' => $id,
                'tenantId' => $row['tenantId'] ?? null,
                'text' => strtolower(implode(' ', [$name, $version, $rootSource, $id])),
                'completion' => $this->buildCompletion([$name]),
                'name' => $name,
                'version' => $version,
                'rootSource' => $rootSource,
            ];
        }

        return $mapped;
    }
}
