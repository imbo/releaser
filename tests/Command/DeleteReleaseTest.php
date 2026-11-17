<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response;
use ImboReleaser\Config;
use ImboReleaser\Config\Resolver;
use ImboReleaser\ConfigInterface;
use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\Exception\RuntimeException;
use ImboReleaser\GitHub\Client;
use ImboReleaser\TestHttpClientTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeleteRelease::class)]
class DeleteReleaseTest extends TestCase
{
    use TestHttpClientTrait;

    public function testInvalidVersionArgument(): void
    {
        [$guzzleClient] = $this->getGuzzleClient();
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid version "not-a-version"');
        $commandTester->execute(['--repository' => 'owner/repo', 'version' => 'not-a-version'], ['interactive' => false]);
    }

    public function testRejectsVersionArgumentWithLeadingZero(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient();
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid version "01.2.3"');
        try {
            $commandTester->execute(['--repository' => 'owner/repo', 'version' => '01.2.3'], ['interactive' => false]);
        } finally {
            $this->assertCount(0, $history);
        }
    }

    public function testNonInteractiveModeRequiresVersionArgument(): void
    {
        [$guzzleClient] = $this->getGuzzleClient();
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Specify the version to delete when running non-interactively or using --tag-only or --dry-run.');
        $commandTester->execute(['--repository' => 'owner/repo'], ['interactive' => false]);
    }

    public function testDeleteRelease(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 42, 'tag_name' => '1.0.0'])), // release by tag
            new Response(204), // Delete release
            new Response(204), // Delete tag
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo', 'version' => '1.0.0'], ['interactive' => false]);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('Successfully deleted release', $display);
        $this->assertStringContainsString('Successfully deleted tag', $display);

        $this->assertCount(3, $history);
        $this->assertSame('/repos/owner/repo/git/refs/tags/1.0.0', (string) $history[2]['request']->getUri());
        $this->assertSame('DELETE', $history[2]['request']->getMethod());
    }

    public function testDeleteReleasePreservesZeroPrerelease(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 42, 'tag_name' => 'v1.2.3-0'])), // release by tag
            new Response(204), // delete release
            new Response(204), // delete tag
        );
        $commandTester = new CommandTester($this->createCommand($guzzleClient));
        $commandTester->execute(['--repository' => 'owner/repo', 'version' => 'v1.2.3-0'], ['interactive' => false]);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertCount(3, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/releases/tags/v1.2.3-0', (string) $history[0]['request']->getUri());
        $this->assertSame('DELETE', $history[1]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/releases/42', (string) $history[1]['request']->getUri());
        $this->assertSame('DELETE', $history[2]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/git/refs/tags/v1.2.3-0', (string) $history[2]['request']->getUri());
    }

    public function testDeleteTagOnlyPreservesZeroPrerelease(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(new Response(204)); // delete tag
        $commandTester = new CommandTester($this->createCommand($guzzleClient));
        $commandTester->execute(['--repository' => 'owner/repo', '--tag-only' => true, 'version' => 'v1.2.3-0'], ['interactive' => false]);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertCount(1, $history);
        $this->assertSame('DELETE', $history[0]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/git/refs/tags/v1.2.3-0', (string) $history[0]['request']->getUri());
    }

    public function testDeleteTagOnly(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(204), // delete tag
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo', '--tag-only' => true, 'version' => '1.0.0'], ['interactive' => false]);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Successfully deleted tag', $commandTester->getDisplay());
        $this->assertCount(1, $history);
        $this->assertSame('/repos/owner/repo/git/refs/tags/1.0.0', (string) $history[0]['request']->getUri());
    }

    public function testDryRunDoesNotDeleteReleaseOrTag(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient();
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo', '-d' => true, 'version' => '1.0.0'], ['interactive' => false]);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Would delete release "1.0.0" and its associated Git tag from repository "owner/repo".', $commandTester->getDisplay());
        $this->assertCount(0, $history);
    }

    public function testDryRunDoesNotDeleteTag(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient();
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--repository' => 'owner/repo', '--tag-only' => true, '--dry-run' => true, 'version' => '1.0.0'], ['interactive' => false]);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Would delete Git tag "1.0.0" from repository "owner/repo".', $commandTester->getDisplay());
        $this->assertCount(0, $history);
    }

    public function testReportsRecoveryCommandWhenTagDeletionFails(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 42, 'tag_name' => '1.0.0'])), // release by tag
            new Response(204), // delete release
            new Response(500), // delete tag
        );
        $config = new class extends Config {
            public function gitHubRepository(): string
            {
                return 'default/repo';
            }
        };
        $command = $this->createCommand($guzzleClient, $config);
        $commandTester = new CommandTester($command);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The release was deleted, but the tag remains. Retry with: imbo-releaser delete --repository '.escapeshellarg('owner/repo').' --tag-only -- '.escapeshellarg('1.0.0'));
        $commandTester->execute(['--repository' => 'owner/repo', 'version' => '1.0.0'], ['interactive' => false]);
    }

    public function testRethrowsTagDeletionFailureWhenDeletingTagOnly(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(500), // delete tag
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete tag reference "1.0.0", got: "500 Internal Server Error"');
        $commandTester->execute(['--repository' => 'owner/repo', '--tag-only' => true, 'version' => '1.0.0'], ['interactive' => false]);
    }

    public function testUserDeclinesDeleteReleaseConfirmation(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient();
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['no']);
        $commandTester->execute(['--repository' => 'owner/repo', 'version' => '1.0.0']);

        $this->assertSame(DeleteRelease::ABORTED, $commandTester->getStatusCode());
        $this->assertStringContainsString('delete release "1.0.0" and its associated Git tag from repository "owner/repo"', $commandTester->getDisplay());
        $this->assertCount(0, $history);
    }

    public function testTagOnlyConfirmationIncludesRepository(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient();
        $tester = new CommandTester($this->createCommand($guzzleClient));
        $tester->setInputs(['no']);
        $tester->execute(['--repository' => 'owner/repo', '--tag-only' => true, 'version' => 'v1.0.0']);

        $this->assertSame(DeleteRelease::ABORTED, $tester->getStatusCode());
        $this->assertStringContainsString('delete Git tag "v1.0.0" from repository "owner/repo"', $tester->getDisplay());
        $this->assertCount(0, $history);
    }

    public function testRecoveryCommandQuotesRepositoryAndTag(): void
    {
        $repository = "owner/repo'&";
        $version = "-release'&v1.2.3";
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 42, 'tag_name' => $version])), // release by tag
            new Response(204), // delete release
            new Response(500), // delete tag
        );
        $tester = new CommandTester($this->createCommand($guzzleClient));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Retry with: imbo-releaser delete --repository '.escapeshellarg($repository).' --tag-only -- '.escapeshellarg($version));
        $tester->execute(['--repository' => $repository, 'version' => $version], ['interactive' => false]);
    }

    public function testRecoveryCommandUsesConfiguredRepository(): void
    {
        $config = new class extends Config {
            public function gitHubRepository(): string
            {
                return 'configured/repo';
            }
        };
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 42, 'tag_name' => '1.0.0'])),
            new Response(204), // delete release
            new Response(500), // delete tag
        );
        $tester = new CommandTester($this->createCommand($guzzleClient, $config));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Retry with: imbo-releaser delete --repository '.escapeshellarg('configured/repo').' --tag-only -- '.escapeshellarg('1.0.0'));
        $tester->execute(['version' => '1.0.0'], ['interactive' => false]);
    }

    public function testInteractPromptsForReleaseWhenNoVersionGiven(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'Release 1.0.0', 'tag_name' => '1.0.0', 'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z'],
                ['id' => 99, 'name' => null, 'tag_name' => '2.0.0', 'draft' => true, 'html_url' => 'url', 'created_at' => '2026-01-02T00:00:00Z'],
            ])), // releases
            new Response(204), // Delete release
            new Response(204), // Delete tag
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo', '2.0.0', 'y']);
        $commandTester->execute([]);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Successfully deleted release', $commandTester->getDisplay());
        $this->assertStringContainsString('Successfully deleted tag', $commandTester->getDisplay());

        $this->assertCount(3, $history);
        $this->assertSame('/repos/owner/repo/releases?per_page=100', (string) $history[0]['request']->getUri());
        $this->assertSame('DELETE', $history[1]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/releases/99', (string) $history[1]['request']->getUri());
        $this->assertSame('/repos/owner/repo/git/refs/tags/2.0.0', (string) $history[2]['request']->getUri());
    }

    public function testDeleteDraftByVersion(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(404), // release by tag
            new Response(200, [], $this->json([[
                'id' => 42, 'name' => 'Draft', 'tag_name' => 'v1.0.0', 'draft' => true,
                'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z',
            ]])), // releases
            new Response(204), // delete release
            new Response(204), // delete tag
        );
        $tester = new CommandTester($this->createCommand($guzzleClient));
        $tester->execute(['--repository' => 'owner/repo', 'version' => 'v1.0.0'], ['interactive' => false]);

        $this->assertSame(DeleteRelease::SUCCESS, $tester->getStatusCode());
        $this->assertCount(4, $history);
        $this->assertSame('/repos/owner/repo/releases/42', (string) $history[2]['request']->getUri());
        $this->assertSame('/repos/owner/repo/git/refs/tags/v1.0.0', (string) $history[3]['request']->getUri());
    }

    /**
     * @return iterable<string,array{status:int,interactive:bool}>
     */
    public static function missingTagProvider(): iterable
    {
        yield '404, explicit version' => ['status' => 404, 'interactive' => false];
        yield '422, explicit version' => ['status' => 422, 'interactive' => false];
        yield '404, interactive selection' => ['status' => 404, 'interactive' => true];
        yield '422, interactive selection' => ['status' => 422, 'interactive' => true];
    }

    #[DataProvider('missingTagProvider')]
    public function testDeleteDraftWithoutTagSucceeds(int $status, bool $interactive): void
    {
        $responses = $interactive ? [] : [new Response(404)]; // release by tag
        $responses[] = new Response(200, [], $this->json([[
            'id' => 42, 'name' => 'Draft', 'tag_name' => 'v1.0.0', 'draft' => true,
            'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z',
        ]])); // releases
        $responses[] = new Response(204); // delete release
        $responses[] = new Response($status); // delete tag
        $responses[] = new Response(404); // confirm absence
        [$guzzleClient, $history] = $this->getGuzzleClient(...$responses);
        $tester = new CommandTester($this->createCommand($guzzleClient));
        $input = ['--repository' => 'owner/repo'];
        if ($interactive) {
            $tester->setInputs(['v1.0.0', 'yes']);
        } else {
            $input['version'] = 'v1.0.0';
        }
        $tester->execute($input, ['interactive' => $interactive]);

        $this->assertSame(DeleteRelease::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Successfully deleted release v1.0.0', $display);
        $this->assertStringContainsString('Tag v1.0.0 does not exist; nothing to delete.', $display);
        $this->assertStringNotContainsString('Successfully deleted tag', $display);
        $this->assertStringNotContainsString('Retry with:', $display);
        $requestCount = $interactive ? 4 : 5;
        $this->assertCount($requestCount, $history);
        $this->assertSame('GET', $history[$requestCount - 1]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/git/ref/tags/v1.0.0', (string) $history[$requestCount - 1]['request']->getUri());
    }

    #[DataProvider('missingTagProvider')]
    public function testTagOnlyDoesNotIgnoreMissingTag(int $status, bool $interactive): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(new Response($status));
        $tester = new CommandTester($this->createCommand($guzzleClient));
        $tester->setInputs(['yes']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete tag reference "v1.0.0"');
        try {
            $tester->execute(['--repository' => 'owner/repo', '--tag-only' => true, 'version' => 'v1.0.0'], ['interactive' => $interactive]);
        } finally {
            $this->assertCount(1, $history);
            $this->assertSame('DELETE', $history[0]['request']->getMethod());
        }
    }

    public function testFailedTagVerificationDoesNotReportSuccess(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 42, 'tag_name' => 'v1.0.0'])),
            new Response(204), // delete release
            new Response(422), // delete tag
            new Response(403), // checking absence is denied
        );
        $tester = new CommandTester($this->createCommand($guzzleClient));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Retry with: imbo-releaser delete --repository '.escapeshellarg('owner/repo').' --tag-only -- '.escapeshellarg('v1.0.0'));
        try {
            $tester->execute(['--repository' => 'owner/repo', 'version' => 'v1.0.0'], ['interactive' => false]);
        } finally {
            $this->assertCount(4, $history);
            $this->assertStringNotContainsString('Successfully deleted tag', $tester->getDisplay());
            $this->assertStringNotContainsString('nothing to delete', $tester->getDisplay());
        }
    }

    public function testDoesNotReuseSelectedReleaseOnNextRun(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'id' => 42, 'name' => 'Draft', 'tag_name' => 'v1.0.0', 'draft' => true,
                'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z',
            ]])), // releases
            new Response(200, [], $this->json(['id' => 99, 'tag_name' => 'v2.0.0'])), // release by tag
            new Response(204), // delete release
            new Response(204), // delete tag
        );
        $tester = new CommandTester($this->createCommand($guzzleClient));
        $tester->setInputs(['v1.0.0', 'no']);
        $tester->execute(['--repository' => 'owner/repo']);
        $this->assertSame(DeleteRelease::ABORTED, $tester->getStatusCode());

        $tester->execute(['--repository' => 'other/repo', 'version' => 'v2.0.0'], ['interactive' => false]);
        $this->assertSame(DeleteRelease::SUCCESS, $tester->getStatusCode());
        $this->assertSame('/repos/other/repo/releases/tags/v2.0.0', (string) $history[1]['request']->getUri());
        $this->assertSame('/repos/other/repo/releases/99', (string) $history[2]['request']->getUri());
    }

    public function testInteractThrowsWhenNoReleasesFound(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([])), // releases
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No releases with supported version tags found in repository "owner/repo".');
        $commandTester->execute([]);
    }

    public function testInteractThrowsWhenReleaseSelectionIsInvalid(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'Release 1.0.0', 'tag_name' => '1.0.0', 'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z'],
            ])), // releases
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['foo', 'bar', 'baz']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value "baz" is invalid');
        $commandTester->execute(['--repository' => 'owner/repo']);
    }

    public function testInteractSkipsPromptWhenVersionProvided(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 10, 'tag_name' => '3.0.0'])), // release by tag
            new Response(204), // Delete release
            new Response(204), // Delete tag
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo', 'y']);
        $commandTester->execute(['version' => '3.0.0']);

        $this->assertSame(DeleteRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertCount(3, $history);
        $this->assertSame('/repos/owner/repo/releases/tags/3.0.0', (string) $history[0]['request']->getUri());
        $this->assertSame('/repos/owner/repo/releases/10', (string) $history[1]['request']->getUri());
        $this->assertSame('/repos/owner/repo/git/refs/tags/3.0.0', (string) $history[2]['request']->getUri());
    }

    public function testInteractSkipsUnversionedReleases(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'Nightly release', 'tag_name' => 'nightly', 'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z'],
            ])), // releases
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No releases with supported version tags found in repository "owner/repo".');
        $commandTester->execute([]);
    }

    private function createCommand(GuzzleClient $guzzleClient, ?ConfigInterface $config = null): DeleteRelease
    {
        return new DeleteRelease(
            new Client($guzzleClient),
            new Resolver($config ?? new Config(), __DIR__),
        );
    }
}
