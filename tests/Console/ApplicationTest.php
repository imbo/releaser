<?php declare(strict_types=1);

namespace ImboReleaser\Console;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use ImboReleaser\GitHub\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(Application::class)]
class ApplicationTest extends TestCase
{
    public function testRegistersCommandsAsTheDefaultCommand(): void
    {
        $application = $this->getApplication();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $exitCode = $tester->run([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Available commands:', $tester->getDisplay());
    }

    public function testDoesNotRegisterTheSymfonyListCommand(): void
    {
        $application = $this->getApplication();

        foreach ($application->all() as $command) {
            $this->assertNotInstanceOf(ListCommand::class, $command);
        }
    }

    private function getApplication(): Application
    {
        $guzzleClient = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler())]);

        return new Application(new Client($guzzleClient));
    }
}
