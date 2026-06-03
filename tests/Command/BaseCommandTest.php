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
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BaseCommand::class)]
class BaseCommandTest extends TestCase
{
    use TestHttpClientTrait;

    public function testMissingRepository(): void
    {
        [$guzzleClient] = $this->getGuzzleClient();
        $command = new ListReleases(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Specify a GitHub repository');
        $commandTester->execute([], ['interactive' => false]);
    }

    public function testInvalidRepository(): void
    {
        [$guzzleClient] = $this->getGuzzleClient();
        $command = new ListReleases(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['foo', 'bar', 'foobar']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The repository must be in the format "owner/repo"');
        $commandTester->execute([]);
    }

    public function testUsingDefaultConfiguration(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'Release 1.0.0', 'tag_name' => '1.0.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/1.0.0', 'created_at' => '2026-01-01T00:00:00Z'],
            ])),
        );
        $command = new ListReleases(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo'], ['interactive' => false]);

        $this->assertStringContainsString('using default configuration', $commandTester->getDisplay());
    }
}
