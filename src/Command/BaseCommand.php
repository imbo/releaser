<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use ImboReleaser\Config;
use ImboReleaser\Config\Resolver;
use ImboReleaser\ConfigInterface;
use ImboReleaser\GitHub\Client;
use ImboReleaser\GitHub\Repository;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

use function is_string;
use function sprintf;

abstract class BaseCommand extends Command
{
    /**
     * Exit code indicating that the command was aborted by the user.
     */
    public const int ABORTED = 3;

    /**
     * Maximum number of attempts for asking a question.
     */
    protected const int MAX_QUESTION_ATTEMPTS = 3;

    protected ConfigInterface $config;
    protected Resolver $configResolver;

    public function __construct(protected Client $gitHubClient, ?Resolver $configResolver = null)
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
                'The GitHub repository (e.g. "<info>imbo/releaser</info>").',
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

    protected function getRepository(InputInterface $input): Repository
    {
        /** @var ?string */
        $name = $input->getOption('repository');
        if (null === $name) {
            throw new InvalidArgumentException('Specify a GitHub repository using the -r|--repository option or override the getGitHubRepository method in your config.');
        }

        return Repository::fromString($name);
    }

    /**
     * Prompt the user to select a GitHub repository.
     *
     * @throws InvalidArgumentException
     */
    private function selectRepository(InputInterface $input, OutputInterface $output): string
    {
        $question =
            (new Question('Specify the repository (owner/repo): '))
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
}
