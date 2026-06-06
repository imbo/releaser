<?php declare(strict_types=1);

namespace ImboReleaser\Console;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use ImboReleaser\GitHub\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\ListCommand;

#[CoversClass(Application::class)]
class ApplicationTest extends TestCase
{
    public function testGetDefaultCommandsRenamesListCommandToCommands(): void
    {
        $guzzleClient = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler())]);
        $application = new Application(new Client($guzzleClient));

        $this->assertInstanceOf(ListCommand::class, $application->get('commands'));
    }
}
