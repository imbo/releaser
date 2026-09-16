<?php declare(strict_types=1);

namespace ImboReleaser\Console;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use ImboReleaser\GitHub\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string,array{response:Response}>
     */
    public static function apiFailureProvider(): iterable
    {
        yield 'permission denied' => ['response' => new Response(403)]; // releases
        yield 'malformed JSON' => ['response' => new Response(200, [], 'invalid')]; // releases
        yield 'invalid DTO' => ['response' => new Response(200, [], '[{}]')]; // releases
    }

    #[DataProvider('apiFailureProvider')]
    public function testApiFailuresRemainErrors(Response $response): void
    {
        $client = new Client(new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([$response]))]));
        $application = new Application($client);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $this->assertSame(Command::FAILURE, $tester->run([
            'command' => 'list', '--repository' => 'owner/repo', '--no-interaction' => true,
            '--config' => __DIR__.'/../fixtures/valid-custom-config-1.php',
        ]));
    }

    private function getApplication(): Application
    {
        $guzzleClient = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler())]);

        return new Application(new Client($guzzleClient));
    }
}
