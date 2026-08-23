<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Admin\Indexer;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\Common\IterableQuery;
use Contena\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Contena\Core\Framework\DataAbstractionLayer\EntityRepository;
use Contena\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\Feature;
use Contena\Core\Framework\Plugin\Exception\DecorationPatternException;
use Contena\Core\Framework\Uuid\Uuid;
use Contena\Core\System\Member\Aggregate\MemberAddress\MemberAddressDefinition;
use Contena\Core\System\Member\Aggregate\MemberTag\MemberTagDefinition;
use Contena\Core\System\Member\MemberCollection;
use Contena\Core\System\Member\MemberDefinition;
use Contena\Elasticsearch\Framework\AbstractElasticsearchDefinition;
use Contena\Elasticsearch\Framework\ElasticsearchFieldBuilder;

final class MemberAdminSearchIndexer extends AbstractAdminIndexer
{
    /**
     * @internal
     *
     * @param EntityRepository<MemberCollection> $repository
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
        return MemberDefinition::ENTITY_NAME;
    }

    public function getName(): string
    {
        return 'member-listing';
    }

    public function getIterator(): IterableQuery
    {
        return $this->factory->createIterator($this->getEntity(), null, $this->indexingBatchSize);
    }

    public function getUpdatedIds(EntityWrittenContainerEvent $event): array
    {
        $memberIds = $event->getPrimaryKeysWithPropertyChange($this->getEntity(), [
            'name',
            'phoneNumber',
            'email',
            'memberNumber',
            'active',
            'groupId',
            'channelId',
            'languageId',
            'requestedGroupId',
        ]);

        $addresses = $event->getPrimaryKeysWithPropertyChange(MemberAddressDefinition::ENTITY_NAME, [
            'firstName',
            'lastName',
            'city',
            'street',
            'zipcode',
            'phoneNumber',
            'additionalAddressLine1',
            'additionalAddressLine2',
            'countryId',
            'regionId',
        ]);

        foreach ($addresses as $primaryKey) {
            if (isset($primaryKey['memberId'])) {
                $memberIds[] = $primaryKey['memberId'];
            }
        }

        $tags = $event->getPrimaryKeysWithPropertyChange(MemberTagDefinition::ENTITY_NAME, ['tagId']);
        foreach ($tags as $primaryKey) {
            if (isset($primaryKey['memberId'])) {
                $memberIds[] = $primaryKey['memberId'];
            }
        }

        return array_values(array_unique(array_filter($memberIds, '\is_string')));
    }

    public function mapping(array $mapping): array
    {
        if (!Feature::isActive('ENABLE_OPENSEARCH_FOR_ADMIN_API')) {
            return parent::mapping($mapping);
        }

        $mapping['properties'] ??= [];
        $mapping['properties'] = array_merge($mapping['properties'], [
            'active' => AbstractElasticsearchDefinition::BOOLEAN_FIELD,
            'email' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'name' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'phoneNumber' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'memberNumber' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'groupId' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'channelId' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'languageId' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
            'requestedGroupId' => AbstractElasticsearchDefinition::KEYWORD_FIELD,
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
            SELECT LOWER(HEX(member.id)) AS id,
                   LOWER(HEX(member.tenant_id)) AS tenantId,
                   tag_agg.tags,
                   tag_agg.tagIds,
                   address_agg.country,
                   address_agg.region,
                   address_agg.address_first_name,
                   address_agg.address_last_name,
                   address_agg.city,
                   address_agg.street,
                   address_agg.zipcode,
                   address_agg.phone_number,
                   address_agg.additional_address_line1,
                   address_agg.additional_address_line2,
                   member.name,
                   member.phone_number AS member_phone_number,
                   member.email,
                   member.member_number,
                   member.active,
                   LOWER(HEX(member.member_group_id)) AS groupId,
                   LOWER(HEX(member.channel_id)) AS channelId,
                   LOWER(HEX(member.language_id)) AS languageId,
                   LOWER(HEX(member.requested_member_group_id)) AS requestedGroupId,
                   member.created_at AS createdAt
            FROM member
                LEFT JOIN (
                    SELECT member_address.member_id,
                           GROUP_CONCAT(DISTINCT country_translation.name ORDER BY NULL SEPARATOR ' ') AS country,
                           GROUP_CONCAT(DISTINCT region_translation.name ORDER BY NULL SEPARATOR ' ') AS region,
                           GROUP_CONCAT(DISTINCT member_address.first_name ORDER BY NULL SEPARATOR ' ') AS address_first_name,
                           GROUP_CONCAT(DISTINCT member_address.last_name ORDER BY NULL SEPARATOR ' ') AS address_last_name,
                           GROUP_CONCAT(DISTINCT member_address.city ORDER BY NULL SEPARATOR ' ') AS city,
                           GROUP_CONCAT(DISTINCT member_address.street ORDER BY NULL SEPARATOR ' ') AS street,
                           GROUP_CONCAT(DISTINCT member_address.zipcode ORDER BY NULL SEPARATOR ' ') AS zipcode,
                           GROUP_CONCAT(DISTINCT member_address.phone_number ORDER BY NULL SEPARATOR ' ') AS phone_number,
                           GROUP_CONCAT(DISTINCT member_address.additional_address_line1 ORDER BY NULL SEPARATOR ' ') AS additional_address_line1,
                           GROUP_CONCAT(DISTINCT member_address.additional_address_line2 ORDER BY NULL SEPARATOR ' ') AS additional_address_line2
                    FROM member_address
                        LEFT JOIN country_translation ON country_translation.country_id = member_address.country_id
                        LEFT JOIN region_translation ON region_translation.region_id = member_address.region_id
                    WHERE member_address.member_id IN (:ids)
                    GROUP BY member_address.member_id
                ) AS address_agg ON address_agg.member_id = member.id
                LEFT JOIN (
                    SELECT member_tag.member_id,
                           GROUP_CONCAT(DISTINCT tag.name ORDER BY NULL SEPARATOR ' ') AS tags,
                           GROUP_CONCAT(LOWER(HEX(tag.id)) ORDER BY NULL SEPARATOR ' ') AS tagIds
                    FROM member_tag
                        LEFT JOIN tag ON member_tag.tag_id = tag.id
                    WHERE member_tag.member_id IN (:ids)
                    GROUP BY member_tag.member_id
                ) AS tag_agg ON tag_agg.member_id = member.id
            WHERE member.id IN (:ids)
SQL,
            ['ids' => Uuid::fromHexToBytesList($ids)],
            ['ids' => ArrayParameterType::BINARY]
        );

        $mapped = [];
        foreach ($data as $row) {
            $id = (string) $row['id'];
            $text = implode(' ', array_filter([
                $row['name'] ?? '',
                $row['member_phone_number'] ?? '',
                $row['email'] ?? '',
                $row['member_number'] ?? '',
                $row['tags'] ?? '',
                $row['country'] ?? '',
                $row['region'] ?? '',
                $row['address_first_name'] ?? '',
                $row['address_last_name'] ?? '',
                $row['city'] ?? '',
                $row['street'] ?? '',
                $row['zipcode'] ?? '',
                $row['phone_number'] ?? '',
                $row['additional_address_line1'] ?? '',
                $row['additional_address_line2'] ?? '',
                $id,
            ]));
            $completion = $this->buildCompletion([
                \is_string($row['name'] ?? null) ? $row['name'] : null,
                \is_string($row['member_phone_number'] ?? null) ? $row['member_phone_number'] : null,
                \is_string($row['email'] ?? null) ? $row['email'] : null,
            ]);

            $mapped[$id] = [
                'id' => $id,
                'tenantId' => $row['tenantId'] ?? null,
                'text' => strtolower($text),
                'completion' => $completion,
            ];

            if (!Feature::isActive('ENABLE_OPENSEARCH_FOR_ADMIN_API')) {
                continue;
            }

            $mapped[$id] += [
                'active' => (bool) $row['active'],
                'email' => $row['email'] ?? null,
                'name' => $row['name'] ?? null,
                'phoneNumber' => $row['member_phone_number'] ?? null,
                'memberNumber' => $row['member_number'] ?? null,
                'groupId' => $row['groupId'] ?? null,
                'channelId' => $row['channelId'] ?? null,
                'languageId' => $row['languageId'] ?? null,
                'requestedGroupId' => $row['requestedGroupId'] ?? null,
                'tags' => $this->parseTagIds($row),
                'createdAt' => $this->formatDateTime($row, 'createdAt'),
            ];
        }

        return $mapped;
    }
}
