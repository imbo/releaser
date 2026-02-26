<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use ImboReleaser\GitHub\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'release',
    description: 'Release a new version of a project on GitHub',
    help: 'This command will create a new annotated Git tag and a GitHub release with release notes from a branch.',
)]
class Release extends Command
{
    public function __construct(private readonly Client $gitHubClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    public function initialize(InputInterface $input, OutputInterface $output): void
    {
    }

    public function interact(InputInterface $input, OutputInterface $output): void
    {
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
