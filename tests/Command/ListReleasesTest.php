<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use GuzzleHttp\ClientInterface as GuzzleClient;
use GuzzleHttp\Psr7\Response;
use ImboReleaser\Config;
use ImboReleaser\Config\Resolver;
use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\GitHub\Client;
use ImboReleaser\TestHttpClientTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function dirname;
use function sprintf;

#[CoversClass(ListReleases::class)]
class ListReleasesTest extends TestCase
{
    use TestHttpClientTrait;

    public function testNoReleases(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([])),
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo'], ['interactive' => false]);

        $this->assertSame(ListReleases::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('No releases found for the repository.', $commandTester->getDisplay());
    }

    public function testReportsResolvedConfigurationFile(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([])),
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $configFile = dirname(__DIR__).'/fixtures/valid-custom-config-1.php';
        $commandTester->execute([
            '--repository' => 'owner/repo',
            '--config' => $configFile,
        ], ['interactive' => false]);

        $this->assertSame(ListReleases::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString(sprintf('Using configuration file: %s', $configFile), $commandTester->getDisplay());
    }

    public function testRejectsInvalidRepositoryOption(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient();
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected format "owner/repo"');
        try {
            $commandTester->execute(['--repository' => 'owner/'], ['interactive' => false]);
        } finally {
            $this->assertCount(0, $history);
        }
    }

    public function testListReleases(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'Release 1.0.0', 'tag_name' => '1.0.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/1.0.0', 'created_at' => '2026-01-01T00:00:00Z'],
                ['name' => 'Release 1.1.0', 'tag_name' => '1.1.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/1.1.0', 'created_at' => '2026-01-02T00:00:00Z'],
            ])),
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo'], ['interactive' => false]);

        $this->assertSame(ListReleases::SUCCESS, $commandTester->getStatusCode());
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('Release 1.0.0', $display);
        $this->assertStringContainsString('1.0.0', $display);
        $this->assertStringContainsString('Release 1.1.0', $display);
        $this->assertStringContainsString('1.1.0', $display);
        $this->assertStringContainsString('2026-01-01', $display);
        $this->assertStringContainsString('2026-01-02', $display);
        $this->assertStringNotContainsString('Fetching releases...', $display);
        $this->assertStringNotContainsString('Fetched releases', $display);

        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/releases', $history[0]['request']->getUri()->getPath());
    }

    public function testSelectValidRepository(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'Release 1.0.0', 'tag_name' => '1.0.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/1.0.0', 'created_at' => '2026-01-01T00:00:00Z'],
            ])),
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo']);
        $commandTester->execute([]);

        $this->assertSame(ListReleases::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Release 1.0.0', $commandTester->getDisplay());
    }

    public function testSkipsUnversionedReleases(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'Nightly release', 'tag_name' => 'nightly', 'html_url' => 'https://github.com/owner/repo/releases/tag/nightly', 'created_at' => '2026-01-01T00:00:00Z'],
                ['name' => 'Release 2.0.0', 'tag_name' => '2.0.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/2.0.0', 'created_at' => '2026-01-02T00:00:00Z'],
            ])),
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo'], ['interactive' => false]);

        $this->assertSame(ListReleases::SUCCESS, $commandTester->getStatusCode());
        $display = $commandTester->getDisplay();
        $this->assertStringNotContainsString('Nightly release', $display);
        $this->assertStringContainsString('Release 2.0.0', $display);
    }

    private function createCommand(GuzzleClient $guzzleClient): ListReleases
    {
        return new ListReleases(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
    }
}
