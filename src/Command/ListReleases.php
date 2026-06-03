<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use ImboReleaser\Config;
use ImboReleaser\Config\Resolver;
use ImboReleaser\ConfigInterface;
use ImboReleaser\GitHub\Client;
use ImboReleaser\GitHub\Release;
use ImboReleaser\GitHub\Repository;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

use function is_string;
use function sprintf;

#[AsCommand(
    name: 'list-releases|ls|releases',
    description: 'List all releases of a project on GitHub',
    help: 'This command will list all releases of a project on GitHub.',
)]
class ListReleases extends Command
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
                'The GitHub repository to list releases from (e.g. "<info>imbo/releaser</info>").',
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
        $this->config = $this->configResolver->getConfig($configFile);

        $configFilePath = $this->configResolver->configFilePath();
        if (null !== $configFilePath) {
            $output->writeln(sprintf('Using configuration file: <info>%s</info>', $configFilePath));
        } else {
            $output->writeln('No configuration file found, using default configuration');
        }

        if (null === $input->getOption('repository')) {
            $input->setOption('repository', $this->config->gitHubRepository());
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

        $input->setOption('repository', $repository);
    }

    /**
     * Execute the command's main logic.
     *
     * @return int The exit code of the command (0 for success, non-zero for failure)
     *
     * @throws InvalidArgumentException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ?string */
        $repositoryName = $input->getOption('repository');
        if (null === $repositoryName) {
            throw new InvalidArgumentException('Specify a GitHub repository using the -r|--repository option or override the getGitHubRepository method in your config.');
        }
        $repository = Repository::fromString($repositoryName);

        $releases = $this->getReleases($repository, $output);
        if (empty($releases)) {
            throw new RuntimeException('No releases found for the repository.');
        }

        (new Table($output))
            ->setHeaders(['Name', 'Tag name', 'Release date'])
            ->setRows(array_map(
                static fn (Release $release): array => [
                    sprintf('<href=%s>%s</>', $release->htmlUrl, $release->name),
                    $release->tagName,
                    $release->createdAt->format('Y-m-d H:i:s'),
                ],
                $releases,
            ))
            ->render();

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
            (new Question('Which repository do you want to list releases for: '))
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
     * @return list<Release>
     */
    private function getReleases(Repository $repository, OutputInterface $output): array
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

        return $releases;
    }
}
