<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use ImboReleaser\Config;
use ImboReleaser\Config\Resolver;
use ImboReleaser\ConfigInterface;
use ImboReleaser\GitHub\Branch;
use ImboReleaser\GitHub\Client;
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
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

use function count;
use function is_string;
use function sprintf;

#[AsCommand(
    name: 'release',
    description: 'Release a new version of a project on GitHub',
    help: 'This command will create a new annotated Git tag and a GitHub release with release notes from a branch.',
)]
class Release extends Command
{
    private const MAX_QUESTION_ATTEMPTS = 3;
    private ConfigInterface $config;
    private Resolver $configResolver;

    /**
     * Construct the command.
     */
    public function __construct(private Client $gitHubClient, ?Resolver $configResolver = null)
    {
        if (null === $configResolver) {
            $configResolver = new Resolver(new Config(), getcwd() ?: null);
        }

        $this->configResolver = $configResolver;

        parent::__construct();
    }

    /**
     * Configure the command options and arguments.
     */
    protected function configure(): void
    {
        $this
            ->addOption(
                'config', 'c',
                InputOption::VALUE_REQUIRED,
                'Path to the configuration file. If not specified, the command will look for a config file named <info>.imbo-releaser[.dist].php</info> in the current working directory.',
            )
            ->addOption(
                'repository', 'r',
                InputOption::VALUE_REQUIRED,
                'The GitHub repository to create a release from (e.g. "<info>imbo/releaser</info>").',
            )
            ->addOption(
                'branch', 'b',
                InputOption::VALUE_REQUIRED,
                'The branch to create a release from. If not specified, the branch will be selected interactively from the list of branches in the repository.',
            );
    }

    /**
     * Initialize the command.
     *
     * Load the configuration and set default values where applicable.
     */
    public function initialize(InputInterface $input, OutputInterface $output): void
    {
        /** @var ?string */
        $configFile = $input->getOption('config');
        $this->config = $this->configResolver->getConfig();

        $configFilePath = $this->configResolver->configFilePath();
        if (null !== $configFilePath) {
            $output->writeln(sprintf('Using configuration file: <info>%s</info>', $configFilePath));
        } else {
            $output->writeln('No configuration file found, using default configuration');
        }

        if (null === $input->getOption('repository')) {
            $input->setOption('repository', $this->config->gitHubRepository());
        }

        if (null === $input->getOption('branch')) {
            $input->setOption('branch', $this->config->branch());
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
        /** @var ?string */
        $repository = $input->getOption('repository');
        if (null === $repository) {
            $repository = $this->selectRepository($input, $output);
        }

        /** @var ?string */
        $branch = $input->getOption('branch');
        if (null === $branch) {
            $branch = $this->selectBranch($repository, $input, $output)->name;
        }

        $input->setOption('repository', $repository);
        $input->setOption('branch', $branch);
    }

    /**
     * Execute the application's main logic.
     *
     * @return int The exit code of the command (0 for success, non-zero for failure)
     *
     * @throws InvalidArgumentException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ?string */
        $repository = $input->getOption('repository');
        if (null === $repository) {
            throw new InvalidArgumentException('Specify a GitHub repository using the -r|--repository option or override the getGitHubRepository method in your config.');
        }

        /** @var ?string */
        $branch = $input->getOption('branch');
        if (null === $branch) {
            throw new InvalidArgumentException('Specify a branch using the -b|--branch option or override the getBranch method in your config.');
        }

        // ...

        return self::SUCCESS;
    }

    /**
     * Prompt the user to select a GitHub repository.
     *
     * @throws InvalidArgumentException
     */
    private function selectRepository(InputInterface $input, OutputInterface $output): string
    {
        $question =
            (new Question('Which repository do you want to create a release for: '))
            ->setValidator(static function ($answer): string {
                if (!is_string($answer) || 0 === preg_match('#^[^\s/]+/[^\s/]+$#', $answer)) {
                    throw new InvalidArgumentException('The repository must be in the format "owner/repo".');
                }

                return $answer;
            })
            ->setMaxAttempts(self::MAX_QUESTION_ATTEMPTS);

        /** @var string */
        return (new QuestionHelper())->ask($input, $output, $question);
    }

    /**
     * Prompt the user to select a GitHub branch.
     *
     * @throws RuntimeException
     */
    private function selectBranch(string $repository, InputInterface $input, OutputInterface $output): Branch
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
}
