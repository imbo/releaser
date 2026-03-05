<?php declare(strict_types=1);

namespace ImboReleaser\Console;

use ImboReleaser\Command\Release;
use ImboReleaser\GitHub\Client;
use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
    /** @codeCoverageIgnore */
    public function __construct(Client $gitHubClient)
    {
        parent::__construct('Imbo releaser');
        $this->addCommand(new Release($gitHubClient));
    }
}
