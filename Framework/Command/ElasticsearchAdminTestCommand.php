<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Command;

use Contena\Core\Content\Blog\BlogDefinition;
use Contena\Core\Content\Category\CategoryDefinition;
use Contena\Core\Content\LandingPage\LandingPageDefinition;
use Contena\Core\Content\Media\MediaDefinition;
use Contena\Core\Framework\ContentSystem\Layout\Entity\ContentLayoutDefinition;
use Contena\Core\Framework\Context;
use Contena\Core\System\Channel\ChannelDefinition;
use Contena\Core\System\Member\Aggregate\MemberGroup\MemberGroupDefinition;
use Contena\Core\System\Member\MemberDefinition;
use Contena\Elasticsearch\Admin\AdminSearcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(
    name: 'es:admin:test',
    description: 'Allows you to test the admin search index',
)]
final class ElasticsearchAdminTestCommand extends Command
{
    private SymfonyStyle $io;

    /**
     * @internal
     */
    public function __construct(private readonly AdminSearcher $searcher)
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addArgument('term', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $term = $input->getArgument('term');
        $entities = [
            ContentLayoutDefinition::ENTITY_NAME,
            MemberDefinition::ENTITY_NAME,
            MemberGroupDefinition::ENTITY_NAME,
            LandingPageDefinition::ENTITY_NAME,
            MediaDefinition::ENTITY_NAME,
            BlogDefinition::ENTITY_NAME,
            ChannelDefinition::ENTITY_NAME,
            CategoryDefinition::ENTITY_NAME,
        ];

        $result = $this->searcher->search($term, $entities, Context::createCLIContext());

        $rows = [];
        foreach ($result as $data) {
            $rows[] = [$data['index'] ?? '', $data['indexer'] ?? '', $data['total']];
        }

        $this->io->table(['Index', 'Indexer', 'total'], $rows);

        return self::SUCCESS;
    }
}
