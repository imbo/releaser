<?php declare(strict_types=1);

namespace ImboReleaser\Console;

use ImboReleaser\Command;
use ImboReleaser\GitHub\Client;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Command\ListCommand as SymfonyListCommand;

class Application extends BaseApplication
{
    /** @codeCoverageIgnore */
    public function __construct(Client $gitHubClient)
    {
        parent::__construct('Imbo releaser');
        $this->setDefaultCommand('commands');
        $this->addCommand(new Command\CreateRelease($gitHubClient));
        $this->addCommand(new Command\ListReleases($gitHubClient));
    }

    /**
     * @return array<SymfonyCommand>
     */
    protected function getDefaultCommands(): array
    {
        $defaults = parent::getDefaultCommands();

        foreach ($defaults as $command) {
            if ($command instanceof SymfonyListCommand) {
                $command->setName('commands');
                break;
            }
        }

        return $defaults;
    }
}
