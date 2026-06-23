<?php declare(strict_types=1);

namespace ImboReleaser\Console;

use ImboReleaser\Command;
use ImboReleaser\Console\Application\Version;
use ImboReleaser\GitHub\Client;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Command\ListCommand as SymfonyListCommand;

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

    protected function getDefaultCommands(): array
    {
        return array_values(array_filter(
            parent::getDefaultCommands(),
            static fn (SymfonyCommand $command): bool => !$command instanceof SymfonyListCommand,
        ));
    }
}
