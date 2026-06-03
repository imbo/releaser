<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use DateTimeImmutable;
use ImboReleaser\GitHub\Branch;
use ImboReleaser\GitHub\PullRequest;
use ImboReleaser\GitHub\Repository;
use ImboReleaser\GitHub\Tag;
use ImboReleaser\TemplateData;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function count;
use function dirname;
use function sprintf;

#[AsCommand(
    name: 'create-release|create|release',
    description: 'Create a new release of a project on GitHub',
    help: 'This command will create a new annotated Git tag and a GitHub release with release notes from a branch.',
)]
class CreateRelease extends BaseCommand
{
    /**
     * Configure the command options and arguments.
     */
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'branch', 'b',
                InputOption::VALUE_REQUIRED,
                'The branch to create a release from. If not specified, the branch will be selected interactively from the list of branches in the repository.',
            )
            ->addOption(
                'template', 't',
                InputOption::VALUE_REQUIRED,
                'Path to the Twig template to use for the release notes.',
            )
            ->addOption(
                'no-edit', null,
                InputOption::VALUE_NONE,
                'Do not open an editor to edit the release notes before creating the release.',
            );
    }

    /**
     * Initialize the command.
     *
     * Load the configuration and set default values where applicable.
     */
    public function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        if (null === $input->getOption('branch')) {
            $input->setOption('branch', $this->config->branch());
        }

        if (null === $input->getOption('template')) {
            $input->setOption('template', $this->config->template());
        }
    }

    /**
     * Interact with the user.
     *
     * Gather any missing information required for the release process. This method is not executed
     * if the application is run in non-interactive mode (e.g. when the -n|--no-interaction option
     * is used).
     */
    public function interact(InputInterface $input, OutputInterface $output): void
    {
        parent::interact($input, $output);

        /** @var ?string */
        $branch = $input->getOption('branch');
        if (null === $branch) {
            $branch = $this->selectBranch($this->getRepository($input), $input, $output)->name;
        }

        $input->setOption('branch', $branch);
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
        $branchName = $input->getOption('branch');
        if (null === $branchName) {
            throw new InvalidArgumentException('Specify a branch using the -b|--branch option or override the getBranch method in your config.');
        }
        $branch = new Branch($branchName);

        /** @var string */
        $template = $input->getOption('template');
        if (!is_file($template) || !is_readable($template)) {
            throw new InvalidArgumentException(sprintf('The specified template file "%s" does not exist or is not readable.', $template));
        }

        $pullRequests = $this->getMergedPullRequests($branch, $repository, $output);
        if (empty($pullRequests)) {
            throw new RuntimeException('No pull requests found, aborting release. Either add pull requests, or override the filterPullRequest method in your config.');
        }

        $tags = $this->getTags($repository, $output);
        $tag = $this->config->getLatestTagForBranch($branch, $tags);
        $since = null;
        if (null === $tag) {
            $nextVersion = $this->config->initialVersion();
            $pullRequestsInRelease = $pullRequests;
        } else {
            $since = $this->gitHubClient->getShaDateTime($repository, $tag->sha);
            $nextVersion = $this->config->determineNextVersion($tag, $pullRequests);
            $pullRequestsInRelease = [];
            foreach ($pullRequests as $pullRequest) {
                if ($pullRequest->mergedAt <= $since) {
                    break;
                }

                $pullRequestsInRelease[] = $pullRequest;
            }
        }

        if (empty($pullRequestsInRelease)) {
            throw new RuntimeException('No pull requests found for the release. You need to merge pull requests before creating a release.');
        }

        $releaseNotes = $this->generateReleaseNotes($template, new TemplateData(
            $nextVersion,
            $repository,
            $pullRequestsInRelease,
            $this->groupedPullRequests($pullRequestsInRelease, $this->config->pullRequestGroups(), $this->config->fallbackGroup()),
            $this->getNewContributors($pullRequests, $since),
        ));

        if ($input->isInteractive() && !$input->getOption('no-edit')) {
            $releaseNotes = $this->editReleaseNotes($releaseNotes, $this->config->editor());
        }

        $question = new ConfirmationQuestion(sprintf('You are about release "%s". Do you want to continue? (Y/n)', $nextVersion), true);
        if (!(new QuestionHelper())->ask($input, $output, $question)) {
            return Command::SUCCESS;
        }

        $release = $this->gitHubClient->createRelease($repository, $branch, $nextVersion, $releaseNotes);

        $output->writeln(sprintf('Release created: <info>%s</info>', $release->htmlUrl));

        return self::SUCCESS;
    }

    /**
     * Get a list of new contributors.
     *
     * The pull requests are ordered by merged date in descending order, so if a contributor has
     * multiple pull requests, only the first one will be included in the list of new contributors.
     *
     * @param list<PullRequest> $pullRequests
     *
     * @return array<string,PullRequest> an associative array where the keys are the GitHub usernames of the new contributors and the values are the first pull request merged by the contributor
     */
    private function getNewContributors(array $pullRequests, ?DateTimeImmutable $since): array
    {
        $newContributors = [];
        foreach ($pullRequests as $pullRequest) {
            if (null !== $since && $pullRequest->mergedAt <= $since) {
                unset($newContributors[$pullRequest->user->login]);
                continue;
            }

            $newContributors[$pullRequest->user->login] = $pullRequest;
        }

        return $newContributors;
    }

    /**
     * Prompt the user to select a GitHub branch.
     *
     * @throws RuntimeException
     */
    private function selectBranch(Repository $repository, InputInterface $input, OutputInterface $output): Branch
    {
        $progress = new ProgressIndicator($output);
        $progress->start('Fetching branches...');

        $branches = [];
        foreach ($this->gitHubClient->getBranches($repository) as $branch) {
            $progress->advance();
            if (!$this->config->filterBranch($branch)) {
                continue;
            }

            $branches[] = $branch;
        }

        $progress->finish('Fetched branches');

        if (empty($branches)) {
            throw new RuntimeException('No valid branches found in the repository. Either add a branch, or override the filterBranch method in your config.');
        }

        if (1 === count($branches)) {
            $output->writeln(sprintf('Only one branch available (<info>%s</info>)', $branches[0]->name));

            return $branches[0];
        }

        $question =
            (new ChoiceQuestion('Select the branch you want to create a release for: ', $branches))
            ->setMaxAttempts(self::MAX_QUESTION_ATTEMPTS)
            ->setErrorMessage('"%s" is not a valid branch.');

        /** @var Branch */
        return (new QuestionHelper())->ask($input, $output, $question);
    }

    /**
     * @return list<Tag>
     */
    private function getTags(Repository $repository, OutputInterface $output): array
    {
        $progress = new ProgressIndicator($output);
        $progress->start('Fetching tags...');

        $tags = [];
        foreach ($this->gitHubClient->getTags($repository) as $tag) {
            $progress->advance();
            if (!$this->config->filterTag($tag)) {
                continue;
            }

            $tags[] = $tag;
        }

        $progress->finish('Fetched tags');

        return $tags;
    }

    /**
     * Get a list of pull requests merged to the given branch and repository.
     *
     * The returned pull requests are sorted by creation date in descending order.
     *
     * @return list<PullRequest>
     */
    private function getMergedPullRequests(Branch $branch, Repository $repository, OutputInterface $output): array
    {
        $progress = new ProgressIndicator($output);
        $progress->start('Fetching pull requests...');

        $pullRequests = [];
        foreach ($this->gitHubClient->getMergedPullRequests($branch, $repository) as $pullRequest) {
            $progress->advance();
            if (!$this->config->filterPullRequest($pullRequest)) {
                continue;
            }

            $pullRequests[] = $pullRequest;
        }

        $progress->finish('Fetched pull requests');

        return $pullRequests;
    }

    /**
     * @param list<PullRequest>          $pullRequests
     * @param array<string,list<string>> $groups
     *
     * @return array<string,list<PullRequest>>
     */
    private function groupedPullRequests(array $pullRequests, array $groups, string $fallbackGroup): array
    {
        $groupsByType = [];
        foreach ($groups as $name => $types) {
            foreach ($types as $type) {
                $groupsByType[$type] = $name;
            }
        }

        $groupedPullRequests = array_fill_keys([...array_keys($groups), $fallbackGroup], []);
        foreach ($pullRequests as $pullRequest) {
            $type = $pullRequest->message?->getType()->toString() ?? '';
            $groupedPullRequests[$groupsByType[$type] ?? $fallbackGroup][] = $pullRequest;
        }

        return array_filter($groupedPullRequests);
    }

    private function generateReleaseNotes(string $template, TemplateData $data): string
    {
        $twig = new Environment(new FilesystemLoader(dirname($template)));

        return $twig->render(basename($template), get_object_vars($data));
    }

    /**
     * @throws RuntimeException
     */
    private function editReleaseNotes(string $releaseNotes, string $defaultEditor): string
    {
        if (!Process::isTtySupported()) {
            throw new RuntimeException('Cannot launch interactive editor: no TTY detected. Use --no-edit or run in an interactive terminal.');
        }

        $editor = getenv('VISUAL') ?: getenv('EDITOR') ?: $defaultEditor;
        $tmpFile = tempnam(sys_get_temp_dir(), 'release_notes_');
        if (false === $tmpFile) {
            throw new RuntimeException('Failed to create a temporary file for editing release notes.');
        }

        try {
            if (false === file_put_contents($tmpFile, $releaseNotes)) {
                throw new RuntimeException('Failed to write release notes to temporary file.');
            }

            $process = Process::fromShellCommandline($editor.' '.escapeshellarg($tmpFile));
            $process->setTty(true);

            try {
                $process->mustRun();
            } catch (ProcessFailedException $e) {
                throw new RuntimeException(sprintf('Editor exited with code %d', $process->getExitCode() ?? -1), 0, $e);
            }

            $contents = file_get_contents($tmpFile);
            if (false === $contents) {
                throw new RuntimeException('Failed to read edited release notes from temporary file.');
            }

            return $contents;
        } finally {
            is_file($tmpFile) && unlink($tmpFile);
        }
    }
}
