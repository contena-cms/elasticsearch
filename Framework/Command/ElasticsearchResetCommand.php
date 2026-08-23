<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Command;

use Doctrine\DBAL\Connection;
use OpenSearch\Client;
use Contena\Elasticsearch\Framework\ElasticsearchOutdatedIndexDetector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'es:reset',
    description: 'Reset the elasticsearch index',
)]
class ElasticsearchResetCommand extends Command
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Client $client,
        private readonly ElasticsearchOutdatedIndexDetector $detector,
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $confirm = $io->confirm('Are you sure you want to reset the Elasticsearch indexing?');

        if (!$confirm) {
            $io->caution('Canceled clearing indexing process');

            return self::SUCCESS;
        }

        $indices = $this->detector->getAllUsedIndices();

        foreach ($indices as $index) {
            $this->client->indices()->delete(['index' => $index]);
        }

        $this->connection->executeStatement('TRUNCATE elasticsearch_index_task');

        $this->connection->executeStatement('DELETE FROM `messenger_messages` WHERE `headers` LIKE "%ElasticsearchIndexingMessage%"');

        $io->success('Elasticsearch indices deleted and queue cleared');

        return self::SUCCESS;
    }
}
