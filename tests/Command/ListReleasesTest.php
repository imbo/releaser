<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use GuzzleHttp\Psr7\Response;
use ImboReleaser\Config;
use ImboReleaser\Config\Resolver;
use ImboReleaser\GitHub\Client;
use ImboReleaser\TestHttpClientTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ListReleases::class)]
class ListReleasesTest extends TestCase
{
    use TestHttpClientTrait;

    public function testNoReleases(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([])),
        );
        $command = new ListReleases(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo'], ['interactive' => false]);

        $this->assertSame(ListReleases::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('No releases found for the repository.', $commandTester->getDisplay());
    }

    public function testListReleases(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'Release 1.0.0', 'tag_name' => '1.0.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/1.0.0', 'created_at' => '2026-01-01T00:00:00Z'],
                ['name' => 'Release 1.1.0', 'tag_name' => '1.1.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/1.1.0', 'created_at' => '2026-01-02T00:00:00Z'],
            ])),
        );
        $command = new ListReleases(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
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
        $command = new ListReleases(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo']);
        $commandTester->execute([]);

        $this->assertSame(ListReleases::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Release 1.0.0', $commandTester->getDisplay());
    }

    public function testFilterRelease(): void
    {
        $config = new class extends Config {
            public function filterRelease(\ImboReleaser\GitHub\Release $release): bool
            {
                return '1.0.0' !== $release->tagName;
            }
        };

        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'Release 1.0.0', 'tag_name' => '1.0.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/1.0.0', 'created_at' => '2026-01-01T00:00:00Z'],
                ['name' => 'Release 2.0.0', 'tag_name' => '2.0.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/2.0.0', 'created_at' => '2026-01-02T00:00:00Z'],
            ])),
        );
        $command = new ListReleases(new Client($guzzleClient), new Resolver($config, __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo'], ['interactive' => false]);

        $this->assertSame(ListReleases::SUCCESS, $commandTester->getStatusCode());
        $display = $commandTester->getDisplay();
        $this->assertStringNotContainsString('Release 1.0.0', $display);
        $this->assertStringContainsString('Release 2.0.0', $display);
    }
}
