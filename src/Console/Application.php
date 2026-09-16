<?php declare(strict_types=1);

namespace ImboReleaser\Console;

use ImboReleaser\Command;
use ImboReleaser\Console\Application\Version;
use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\GitHub\Client;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Command\ListCommand as SymfonyListCommand;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends BaseApplication
{
    public function __construct(Client $gitHubClient)
    {
        parent::__construct('Imbo releaser', (new Version())->getVersion());
        $this->addCommand(new Command\Commands());
        $this->addCommand(new Command\CreateRelease($gitHubClient));
        $this->addCommand(new Command\DeleteRelease($gitHubClient));
        $this->addCommand(new Command\ListReleases($gitHubClient));
        $this->setDefaultCommand(Command\Commands::NAME);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (CommandNotFoundException|ConsoleInvalidArgumentException|ConsoleRuntimeException $e) {
            throw new InvalidArgumentException($e->getMessage(), SymfonyCommand::INVALID, $e);
        }
    }

    protected function getDefaultCommands(): array
    {
        return array_values(array_filter(
            parent::getDefaultCommands(),
            static fn (SymfonyCommand $command): bool => !$command instanceof SymfonyListCommand,
        ));
    }
}
