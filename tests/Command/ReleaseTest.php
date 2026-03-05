<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use GuzzleHttp\Psr7\Response;
use ImboReleaser\Config;
use ImboReleaser\Config\Resolver;
use ImboReleaser\GitHub\Client;
use ImboReleaser\TestHttpClientTrait;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Release::class)]
class ReleaseTest extends TestCase
{
    use TestHttpClientTrait;

    public function testMissingRepository(): void
    {
        [$guzzleClient] = $this->getGuzzleClient();
        $command = new Release(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Specify a GitHub repository');
        $commandTester->execute([], ['interactive' => false]);
    }

    public function testInvalidRepository(): void
    {
        [$guzzleClient] = $this->getGuzzleClient();
        $command = new Release(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['foo', 'bar', 'foobar']); // 3 attempts
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The repository must be in the format "owner/repo"');
        $commandTester->execute([]);
    }

    public function testMissingBranch(): void
    {
        [$guzzleClient] = $this->getGuzzleClient();
        $command = new Release(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Specify a branch');
        $commandTester->execute(['--repository' => 'owner/repo'], ['interactive' => false]);
    }

    public function testInvalidBranch(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'main'],
                ['name' => 'v1.x'],
            ])),
        );
        $command = new Release(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['foo', 'bar', 'baz']); // 3 attempts
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"baz" is not a valid branch');
        $commandTester->execute(['--repository' => 'owner/repo']);
    }

    public function testNoValidBranches(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'develop'],
            ])),
        );
        $command = new Release(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No valid branches found in the repository');
        $commandTester->execute(['--repository' => 'owner/repo']);
    }

    public function testOneValidBranch(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'main'],
                ['name' => 'develop'],
            ])),
        );
        $command = new Release(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo']);
        $this->assertStringContainsString('Only one branch available (main)', $commandTester->getDisplay());
        $this->assertSame(Release::SUCCESS, $commandTester->getStatusCode());
    }

    public function testSelectValidRepositoryAndBranch(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'main'],
                ['name' => 'v1'],
                ['name' => 'v2.x'],
            ])),
        );
        $command = new Release(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo', 'main']);
        $commandTester->execute([]);
        $this->assertSame(Release::SUCCESS, $commandTester->getStatusCode());
    }

    public function testUsingDefaultConfiguration(): void
    {
        [$guzzleClient] = $this->getGuzzleClient();
        $command = new Release(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main']);
        $this->assertStringContainsString('using default configuration', $commandTester->getDisplay());
        $this->assertSame(Release::SUCCESS, $commandTester->getStatusCode());
    }
}
