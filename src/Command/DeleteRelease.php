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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;

use function sprintf;

#[AsCommand(
    name: DeleteRelease::NAME,
    description: 'Delete a release',
)]
class DeleteRelease extends BaseCommand
{
    public const NAME = 'delete';

    /**
     * Configure the command options and arguments.
     */
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument(
                'version', InputArgument::OPTIONAL,
                'The version of the release to delete.',
            );
    }

    protected function commandHelp(): string
    {
        return sprintf(
            <<<'HELP'
            This command will delete a GitHub release and its associated Git tag.

            <comment>Version</comment>
              The <info>version</info> argument identifies the release to delete. If it is not
              specified, you will be prompted to select from the available releases when
              running interactively. Use the <info>%s</info> command to view the available releases.
            HELP,
            ListReleases::NAME,
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

        /** @var ?string */
        $versionArg = $input->getArgument('version');
        if (null === $versionArg) {
            throw new RuntimeException('Specify the version to delete when running non-interactively.');
        }

        try {
            $version = Version::fromString($versionArg);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(sprintf('Invalid version "%s": %s', $versionArg, $e->getMessage()), previous: $e);
        }

        $question = new ConfirmationQuestion(sprintf(
            'You are about to delete release "%s" and its associated Git tag. Do you want to continue? (y/N)',
            $version,
        ), false);
        if ($input->isInteractive() && !(new QuestionHelper())->ask($input, $output, $question)) {
            $output->writeln('Aborting.');

            return self::ABORTED;
        }

        $this->gitHubClient->deleteRelease($repository, $version);
        $output->writeln(sprintf('Successfully deleted release <info>%s</info>', $version));

        $this->gitHubClient->deleteTag($repository, $version);
        $output->writeln(sprintf('Successfully deleted tag <info>%s</info>', $version));

        return self::SUCCESS;
    }
}
