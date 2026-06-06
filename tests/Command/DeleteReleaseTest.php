<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use GuzzleHttp\Psr7\Response;
use ImboReleaser\Config;
use ImboReleaser\Config\Resolver;
use ImboReleaser\GitHub\Client;
use ImboReleaser\TestHttpClientTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeleteRelease::class)]
class DeleteReleaseTest extends TestCase
{
    use TestHttpClientTrait;

    public function testDeleteReleaseKeepsTagByDefault(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 42, 'tag_name' => '1.0.0'])),
            new Response(204),
        );
        $command = new DeleteRelease(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo', 'version' => '1.0.0'], ['interactive' => false]);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Successfully deleted release', $commandTester->getDisplay());
        $this->assertStringNotContainsString('Successfully deleted tag', $commandTester->getDisplay());

        $this->assertCount(2, $history);
        $this->assertSame('/repos/owner/repo/releases/tags/1.0.0', (string) $history[0]['request']->getUri());
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/releases/42', (string) $history[1]['request']->getUri());
        $this->assertSame('DELETE', $history[1]['request']->getMethod());
    }

    public function testDeleteReleaseAlsoDeletesTagWhenNoKeepTag(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 42, 'tag_name' => '1.0.0'])),
            new Response(204),
            new Response(204),
        );
        $command = new DeleteRelease(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo', 'version' => '1.0.0', '--no-keep-tag' => true], ['interactive' => false]);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('Successfully deleted release', $display);
        $this->assertStringContainsString('Successfully deleted tag', $display);

        $this->assertCount(3, $history);
        $this->assertSame('/repos/owner/repo/git/refs/tags/1.0.0', (string) $history[2]['request']->getUri());
        $this->assertSame('DELETE', $history[2]['request']->getMethod());
    }

    public function testInteractPromptsForReleaseWhenNoVersionGiven(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'Release 1.0.0', 'tag_name' => '1.0.0', 'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z'],
                ['name' => 'Release 2.0.0', 'tag_name' => '2.0.0', 'html_url' => 'url', 'created_at' => '2026-01-02T00:00:00Z'],
            ])),
            new Response(200, [], $this->json(['id' => 99, 'tag_name' => '2.0.0'])),
            new Response(204),
        );
        $command = new DeleteRelease(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo', '1']);
        $commandTester->execute([]);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Successfully deleted release', $commandTester->getDisplay());
    }

    public function testInteractThrowsWhenNoReleasesFound(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([])),
        );
        $command = new DeleteRelease(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No releases found for repository');
        $commandTester->execute([]);
    }

    public function testInteractSkipsPromptWhenVersionProvided(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 10, 'tag_name' => '3.0.0'])),
            new Response(204),
        );
        $command = new DeleteRelease(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo']);
        $commandTester->execute(['version' => '3.0.0']);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertCount(2, $history);
        $this->assertSame('/repos/owner/repo/releases/tags/3.0.0', (string) $history[0]['request']->getUri());
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
                ['name' => 'Release 1.0.0', 'tag_name' => '1.0.0', 'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z'],
            ])),
        );
        $command = new DeleteRelease(new Client($guzzleClient), new Resolver($config, __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No releases found for repository');
        $commandTester->execute([]);
    }
}
