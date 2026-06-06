<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use ImboReleaser\GitHub\Release;
use ImboReleaser\GitHub\Repository;
use ImboReleaser\Version;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

use function sprintf;

#[AsCommand(
    name: DeleteRelease::NAME,
    aliases: ['delete', 'rm'],
    description: 'Delete a release of a project on GitHub',
    help: 'This command will delete a GitHub release and its associated Git tag.',
)]
class DeleteRelease extends BaseCommand
{
    public const NAME = 'delete-release';

    /**
     * Configure the command options and arguments.
     */
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'keep-tag', null,
                InputOption::VALUE_NEGATABLE,
                'Keep the Git tag associated with the release you want to delete.',
                true,
            )
            ->addArgument(
                'version', InputArgument::OPTIONAL,
                sprintf('Which release to delete, identified by its version. If not specified, you will be prompted to select from available releases. You can view available releases using the <info>%s</info> command.', ListReleases::NAME),
            );
    }

    /**
     * Interact with the user.
     *
     * If no version argument was provided, present the user with a selection of available releases.
     */
    public function interact(InputInterface $input, OutputInterface $output): void
    {
        parent::interact($input, $output);

        if (null !== $input->getArgument('version')) {
            return;
        }

        $release = (string) $this->selectRelease($this->getRepository($input), $input, $output);
        $input->setArgument('version', $release);
    }

    /**
     * Prompt the user to select a release to delete.
     *
     * @throws RuntimeException
     */
    private function selectRelease(Repository $repository, InputInterface $input, OutputInterface $output): Release
    {
        $progress = new ProgressIndicator($output);
        $progress->start('Fetching releases...');

        $releases = [];
        foreach ($this->gitHubClient->getReleases($repository) as $release) {
            $progress->advance();
            if (!$this->config->filterRelease($release)) {
                continue;
            }

            $releases[] = $release;
        }

        $progress->finish('Fetched releases');
        if ([] === $releases) {
            throw new RuntimeException(sprintf('No releases found for repository "%s".', $repository));
        }

        $question = new ChoiceQuestion('Select the release to delete:', $releases);
        $question->setMaxAttempts(self::MAX_QUESTION_ATTEMPTS);

        /** @var Release */
        return (new QuestionHelper())->ask($input, $output, $question);
    }

    /**
     * Execute the commands main logic.
     *
     * @return int The exit code of the command (0 for success, non-zero for failure)
     *
     * @throws InvalidArgumentException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = $this->getRepository($input);

        /** @var string */
        $versionArg = $input->getArgument('version');
        $version = Version::fromString($versionArg);

        $this->gitHubClient->deleteRelease($repository, $version);
        $output->writeln(sprintf('Successfully deleted release <info>%s</info>', $version));

        if (!$input->getOption('keep-tag')) {
            $this->gitHubClient->deleteTag($repository, $version);
            $output->writeln(sprintf('Successfully deleted tag <info>%s</info>', $version));
        }

        return self::SUCCESS;
    }
}
