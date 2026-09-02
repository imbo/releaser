<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use GuzzleHttp\Psr7\Response;
use ImboReleaser\Config;
use ImboReleaser\Config\Resolver;
use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\Exception\RuntimeException;
use ImboReleaser\GitHub\Client;
use ImboReleaser\TestHttpClientTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CreateRelease::class)]
class CreateReleaseTest extends TestCase
{
    use TestHttpClientTrait;

    public function testMissingBranch(): void
    {
        [$guzzleClient] = $this->getGuzzleClient();
        $command = new CreateRelease(new Client($guzzleClient));
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
        $command = new CreateRelease(new Client($guzzleClient));
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
        $command = new CreateRelease(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No valid branches found in the repository');
        $commandTester->execute(['--repository' => 'owner/repo']);
    }

    public function testOneValidBranch(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'main'],
                ['name' => 'develop'],
            ])), // branches
            new Response(200, [], $this->json([[
                'number' => 123,
                'user' => ['login' => 'johndoe'],
                'merged_at' => '2024-01-01T00:00:00Z',
                'title' => 'feat: add new feature',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([])), // tags
            new Response(200, [], $this->json(['commit' => ['sha' => 'branchSha']])), // branch sha
            new Response(201, [], $this->json(['sha' => 'tagSha'])), // tag object creation
            new Response(201), // tag reference creation
            new Response(201, [], $this->json([
                'name' => 'release name',
                'tag_name' => 'v1.1.1',
                'html_url' => '<release-url>',
                'created_at' => '2024-01-01T00:00:00Z',
            ])), // release creation
        );
        $command = new CreateRelease(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['yes']); // release confirmation
        $commandTester->execute(['--repository' => 'owner/repo', '--no-edit' => true, '--name' => 'Release 0.1']);
        $this->assertStringContainsString('Only one branch available (main)', $commandTester->getDisplay());
        $this->assertStringContainsString('You are about to create the release "Release 0.1" for tag "v0.1.0".', $commandTester->getDisplay());
        $this->assertSame(CreateRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertCount(7, $history);

        $req = $history[0]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('/repos/owner/repo/branches', $req->getUri()->getPath());

        $req = $history[1]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('/repos/owner/repo/pulls', $req->getUri()->getPath());

        $req = $history[2]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('/repos/owner/repo/tags', $req->getUri()->getPath());

        $req = $history[3]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('/repos/owner/repo/branches/main', $req->getUri()->getPath());

        $releaseNotes = <<<RELEASE_NOTES
        ## New Features 🚀
        * feat: add new feature by @johndoe in https://github.com/owner/repo/pull/123

        ## New Contributors
        * @johndoe made their first contribution in https://github.com/owner/repo/pull/123

        **Full Changelog**: https://github.com/owner/repo/commits/v0.1.0

        <!-- Release generated by https://github.com/imbo/releaser -->

        RELEASE_NOTES;

        $req = $history[4]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/repos/owner/repo/git/tags', $req->getUri()->getPath());
        $this->assertSame('/repos/owner/repo/git/tags', $req->getUri()->getPath());
        $body = $req->getBody()->getContents();
        $this->assertJson($body);
        /** @var array<string,mixed> $data */
        $data = json_decode($body, true);
        $this->assertSame('v0.1.0', $data['tag']);
        $this->assertSame('branchSha', $data['object']);
        $this->assertSame('commit', $data['type']);
        $this->assertSame($releaseNotes, $data['message']);

        $req = $history[5]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/repos/owner/repo/git/refs', $req->getUri()->getPath());
        $body = $req->getBody()->getContents();
        $this->assertJson($body);

        /** @var array<string,mixed> $data */
        $data = json_decode($body, true);
        $this->assertSame('refs/tags/v0.1.0', $data['ref']);
        $this->assertSame('tagSha', $data['sha']);

        $req = $history[6]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/repos/owner/repo/releases', $req->getUri()->getPath());
        $body = $req->getBody()->getContents();
        $this->assertJson($body);

        /** @var array<string,mixed> $data */
        $data = json_decode($body, true);
        $this->assertSame('Release 0.1', $data['name']);
        $this->assertSame('v0.1.0', $data['tag_name']);
        $this->assertSame($releaseNotes, $data['body']);
        $this->assertFalse($data['generate_release_notes']);
    }

    public function testSelectValidRepositoryAndBranch(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => 'main'],
                ['name' => 'v1'],
                ['name' => 'v2.x'],
            ])), // branches
            new Response(200, [], $this->json([[
                'number' => 123,
                'user' => ['login' => 'johndoe'],
                'merged_at' => '2024-01-01T00:00:00Z',
                'title' => 'feat: add new feature',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([])), // tags
            new Response(200, [], $this->json(['commit' => ['sha' => 'branchSha']])), // branch sha
            new Response(201, [], $this->json(['sha' => 'tagSha'])), // tag object creation
            new Response(201), // tag reference creation
            new Response(201, [], $this->json([
                'name' => 'release name',
                'tag_name' => 'v1.1.1',
                'html_url' => '<release-url>',
                'created_at' => '2024-01-01T00:00:00Z',
            ])), // release creation
        );
        $command = new CreateRelease(new Client($guzzleClient));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo', 'main']);
        $commandTester->execute(['--no-edit' => true]);
        $this->assertSame(CreateRelease::SUCCESS, $commandTester->getStatusCode());
    }

    public function testNoPullRequests(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'name' => 'v1.0.0',
                'commit' => ['sha' => 'sha'],
            ]])), // tags
            new Response(200, [], $this->json([
                'committer' => ['date' => '2024-01-01T00:00:00Z'],
            ])), // commits
            new Response(200, [], $this->json([])), // pull requests
        );
        $command = new CreateRelease(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No pull requests found, aborting release.');
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main']);
    }

    public function testNoPullRequestsSinceLastTag(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 1,
                'user' => ['login' => 'user1'],
                'title' => 'feat: old feature',
                'merged_at' => '2024-01-01T00:00:00Z',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([
                ['name' => 'v1.0.0', 'commit' => ['sha' => 'tagsha']],
            ])), // tags
            new Response(200, [], $this->json([
                'committer' => ['date' => '2024-01-02T00:00:00Z'],
            ])), // commit date for tag (after the PR merged_at)
        );
        $command = new CreateRelease(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No pull requests found for the release.');
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main']);
    }

    public function testReleaseWithExistingTag(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 2,
                'user' => ['login' => 'jane'],
                'title' => 'fix: a bug',
                'merged_at' => '2024-02-01T00:00:00Z',
                'base' => ['ref' => 'main'],
            ], [
                'number' => 1,
                'user' => ['login' => 'john'],
                'title' => 'feat: initial',
                'merged_at' => '2024-01-01T00:00:00Z',
                'base' => ['ref' => 'main'],
            ]])), // pull requests (descending by date)
            new Response(200, [], $this->json([
                ['name' => 'v1.0.0', 'commit' => ['sha' => 'tagsha']],
            ])), // tags
            new Response(200, [], $this->json([
                'committer' => ['date' => '2024-01-15T00:00:00Z'],
            ])), // commit date for tag sha
            new Response(200, [], $this->json(['commit' => ['sha' => 'branchSha']])), // branch sha
            new Response(201, [], $this->json(['sha' => 'newTagSha'])), // tag object creation
            new Response(201), // tag reference creation
            new Response(201, [], $this->json([
                'name' => 'v1.0.1',
                'tag_name' => 'v1.0.1',
                'html_url' => 'https://github.com/owner/repo/releases/tag/v1.0.1',
                'created_at' => '2024-02-02T00:00:00Z',
            ])), // release creation
        );
        $command = new CreateRelease(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['yes']);
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--no-edit' => true]);

        $this->assertSame(CreateRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Release created', $commandTester->getDisplay());
        $this->assertCount(7, $history);
    }

    public function testUserDeclinesConfirmation(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 1,
                'user' => ['login' => 'user1'],
                'title' => 'feat: new feature',
                'merged_at' => '2024-01-01T00:00:00Z',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([])), // tags
        );
        $command = new CreateRelease(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['no']);
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--no-edit' => true]);

        $this->assertSame(CreateRelease::ABORTED, $commandTester->getStatusCode());
        $this->assertStringNotContainsString('Release created', $commandTester->getDisplay());
        $this->assertCount(2, $history);
    }

    public function testInvalidTemplate(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 123,
                'user' => ['login' => 'johndoe'],
                'merged_at' => '2024-01-01T00:00:00Z',
                'title' => 'feat: add new feature',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([])), // tags
        );
        $command = new CreateRelease(new Client($guzzleClient), new Resolver(new Config(), __DIR__));
        $commandTester = new CommandTester($command);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The specified template file "invalid-template" does not exist or is not readable.');
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--template' => 'invalid-template']);
    }
}
