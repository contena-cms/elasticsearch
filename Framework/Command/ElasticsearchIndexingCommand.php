<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Command;

use Contena\Core\Framework\DataAbstractionLayer\Command\ConsoleProgressTrait;
use Contena\Elasticsearch\Framework\Indexing\CreateAliasTaskHandler;
use Contena\Elasticsearch\Framework\Indexing\ElasticsearchIndexer;
use Contena\Elasticsearch\Framework\Indexing\ElasticsearchIndexingMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Stopwatch\Stopwatch;

#[AsCommand(
    name: 'es:index',
    description: 'Index all entities into elasticsearch',
)]
class ElasticsearchIndexingCommand extends Command
{
    use ConsoleProgressTrait;

    /**
     * @internal
     */
    public function __construct(
        private readonly ElasticsearchIndexer $indexer,
        private readonly MessageBusInterface $messageBus,
        private readonly CreateAliasTaskHandler $aliasHandler,
        private readonly bool $enabled
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addOption('no-progress', null, null, 'Do not output progress bar');
        $this->addOption('no-queue', null, null, 'Do not use the queue for indexing');
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'Add entities separated by comma to indexing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start('es-indexing');
        $io = $this->io = new SymfonyStyle($input, $output);

        if (!$this->enabled) {
            $io->error('Elasticsearch indexing is disabled');

            return self::FAILURE;
        }

        $progressBar = new ProgressBar($input->getOption('no-progress') ? new NullOutput() : $output);
        $progressBar->start();

        $entities = $input->getOption('only') ? explode(',', $input->getOption('only')) : [];
        $offset = null;
        $pendingMessage = null;
        $useQueue = !$input->getOption('no-queue');

        while ($message = $this->indexer->iterate($offset, $entities)) {
            $offset = $message->getOffset();

            if ($pendingMessage instanceof ElasticsearchIndexingMessage) {
                $this->dispatch($pendingMessage, $useQueue, $progressBar);
            }

            $pendingMessage = $message;
        }

        if (!$pendingMessage instanceof ElasticsearchIndexingMessage) {
            $io->error('No messages found for indexing.');

            return self::SUCCESS;
        }

        $pendingMessage->markAsLastMessage();
        $this->dispatch($pendingMessage, $useQueue, $progressBar);

        $progressBar->finish();

        if (!$useQueue) {
            $this->aliasHandler->run();
        }

        $event = (string) $stopwatch->stop('es-indexing');

        $io->info($event);

        return self::SUCCESS;
    }

    private function dispatch(ElasticsearchIndexingMessage $message, bool $useQueue, ProgressBar $progressBar): void
    {
        if ($useQueue) {
            $this->messageBus->dispatch($message);
        } else {
            $this->indexer->__invoke($message);
        }

        $progressBar->advance(\count($message->getData()->getIds()));
    }
}
