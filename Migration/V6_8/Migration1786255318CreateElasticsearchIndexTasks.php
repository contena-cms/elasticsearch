<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Migration\V6_8;

use Doctrine\DBAL\Connection;
use Contena\Core\Framework\Migration\MigrationStep;

/**
 * Development-baseline schema for Elasticsearch indexing tasks.
 *
 * Add future Elasticsearch tables and columns to this step while the baseline remains unreleased instead of
 * preserving the upstream historical migration chain.
 *
 * @internal
 */
class Migration1786255318CreateElasticsearchIndexTasks extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1786255318;
    }

    public function update(Connection $connection): void
    {
        foreach (['elasticsearch_index_task', 'admin_elasticsearch_index_task'] as $table) {
            $connection->executeStatement(str_replace('#table#', $table, <<<'SQL'
CREATE TABLE IF NOT EXISTS `#table#` (
    `id`        BINARY(16)                              NOT NULL,
    `index`     VARCHAR(500) COLLATE utf8mb4_unicode_ci NOT NULL,
    `alias`     VARCHAR(500) COLLATE utf8mb4_unicode_ci NOT NULL,
    `entity`    VARCHAR(500) COLLATE utf8mb4_unicode_ci NOT NULL,
    `doc_count` INT                                     NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL));
        }
    }
}
