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
use ImboReleaser\GitHub\ReleasePullRequest;
use ImboReleaser\GitHub\ReleaseTag;
use ImboReleaser\TestHttpClientTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

use function sprintf;

#[CoversClass(CreateRelease::class)]
class CreateReleaseTest extends TestCase
{
    use TestHttpClientTrait;

    public function testGroupsTypesCaseInsensitively(): void
    {
        $config = new class extends Config {
            public function pullRequestGroups(): array
            {
                return [...parent::pullRequestGroups(), 'Custom Changes' => ['CUSTOM']];
            }
        };
        $prs = [];
        foreach (['FEAT: uppercase feature', 'Feat: mixed-case feature', 'FIX: uppercase fix', 'custom: custom change'] as $index => $title) {
            $prs[] = [
                'number' => $index + 1,
                'user' => ['login' => 'alice'],
                'title' => $title,
                'merged_at' => '2024-01-01T00:00:00Z',
                'base' => ['ref' => 'main'],
                'merge_commit_sha' => 'merge'.$index,
            ];
        }
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json($prs)), // pull requests
            new Response(200, [], $this->json([['name' => 'v1.0.0', 'commit' => ['sha' => 'tagsha']]])), // tags
            new Response(200, [], $this->json(['commits' => [['sha' => 'merge0'], ['sha' => 'merge1'], ['sha' => 'merge2'], ['sha' => 'merge3']]])), // commits since the tag
        );
        $tester = new CommandTester($this->createCommand($guzzleClient, $config));
        $tester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--dry-run' => true], ['interactive' => false]);

        $this->assertSame(CreateRelease::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('with tag "v1.1.0"', $display);
        $this->assertMatchesRegularExpression('/## New Features[^#]*uppercase feature[^#]*mixed-case feature/s', $display);
        $this->assertMatchesRegularExpression('/## Bug Fixes[^#]*uppercase fix/s', $display);
        $this->assertMatchesRegularExpression('/## Custom Changes[^#]*custom change/s', $display);
        $this->assertStringNotContainsString('Other Changes', $display);
    }

    public function testReleaseNotesIncludeBreakingChangesAndScopes(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                [
                    'number' => 1, 'user' => ['login' => 'alice'],
                    'title' => 'feat(API)!: replace the client',
                    'body' => <<<BODY
                    BREAKING CHANGE: Use `NewClient<T>` instead.
                    Update the configuration.

                    BREAKING-CHANGE: Rename the environment variable.

                    Refs: #42
                    BODY,
                    'merged_at' => '2024-01-01T00:00:00Z', 'base' => ['ref' => 'main'],
                ],
                [
                    'number' => 2, 'user' => ['login' => 'bob'],
                    'title' => 'fix: remove the old option',
                    'body' => 'BREAKING-CHANGE: Use the replacement option.',
                    'merged_at' => '2024-01-02T00:00:00Z', 'base' => ['ref' => 'main'],
                ],
                [
                    'number' => 3, 'user' => ['login' => 'bob'],
                    'title' => 'fix!: remove deprecated behavior',
                    'merged_at' => '2024-01-03T00:00:00Z', 'base' => ['ref' => 'main'],
                ],
                [
                    'number' => 4, 'user' => ['login' => 'bob'],
                    'title' => 'fix(cache): correct expiry',
                    'merged_at' => '2024-01-04T00:00:00Z', 'base' => ['ref' => 'main'],
                ],
            ])), // pull requests
            new Response(200, [], $this->json([])), // tags
            new Response(200, [], $this->json(['commit' => ['sha' => 'branchSha']])), // branch sha
            new Response(201, [], $this->json(['sha' => 'tagSha'])), // tag object creation
            new Response(201), // tag reference creation
            new Response(201, [], $this->json([
                'name' => 'v0.1.0', 'tag_name' => 'v0.1.0',
                'html_url' => 'url', 'created_at' => '2024-01-05T00:00:00Z',
            ])), // release creation
        );
        $tester = new CommandTester($this->createCommand($guzzleClient));
        $tester->execute(['--repository' => 'owner/repo', '--branch' => 'main'], ['interactive' => false]);

        $this->assertSame(CreateRelease::SUCCESS, $tester->getStatusCode());
        /** @var array{message:string} $tag */
        $tag = json_decode((string) $history[3]['request']->getBody(), true);
        /** @var array{body:string} $release */
        $release = json_decode((string) $history[5]['request']->getBody(), true);
        $releaseNotes = <<<RELEASE_NOTES
        ## New Features 🚀
        * feat(API)!: replace the client by @alice in https://github.com/owner/repo/pull/1

          **BREAKING CHANGE:** Use `NewClient<T>` instead.
          Update the configuration.


          **BREAKING CHANGE:** Rename the environment variable.


        ## Bug Fixes 🐛
        * fix!: remove the old option by @bob in https://github.com/owner/repo/pull/2

          **BREAKING CHANGE:** Use the replacement option.

        * fix!: remove deprecated behavior by @bob in https://github.com/owner/repo/pull/3
        * fix(cache): correct expiry by @bob in https://github.com/owner/repo/pull/4

        ## New Contributors
        * @alice made their first contribution in https://github.com/owner/repo/pull/1
        * @bob made their first contribution in https://github.com/owner/repo/pull/2

        **Full Changelog**: https://github.com/owner/repo/commits/v0.1.0

        <!-- Release generated by Imbo Releaser: https://github.com/imbo/releaser -->

        RELEASE_NOTES;

        $this->assertSame($releaseNotes, $tag['message']);
        $this->assertSame($releaseNotes, $release['body']);
    }

    public function testMissingBranch(): void
    {
        [$guzzleClient] = $this->getGuzzleClient();
        $command = $this->createCommand($guzzleClient);
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
            ])), // branches
        );
        $command = $this->createCommand($guzzleClient);
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
            ])), // branches
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No valid branches found in the repository');
        $commandTester->execute(['--repository' => 'owner/repo']);
    }

    public function testCreatesNextPrerelease(): void
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
                'title' => 'feat: support `Map<K, V>` & "quoted values"',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([
                ['name' => 'nightly', 'commit' => ['sha' => 'nightlySha']],
                ['name' => 'v0.1.0-rc.1', 'commit' => ['sha' => 'releaseCandidateSha']],
                ['name' => 'v0.2.0-rc.1', 'commit' => ['sha' => 'otherReleaseCandidateSha']],
            ])), // tags
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
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['yes']); // release confirmation
        $commandTester->execute(['--repository' => 'owner/repo', '--no-edit' => true, '--name' => 'Release 0.1', '--draft' => true, '--prerelease' => 'rc']);
        $this->assertStringContainsString('Only one branch available (main)', $commandTester->getDisplay());
        $this->assertStringContainsString('You are about to create the draft prerelease "Release 0.1" for tag "v0.1.0-rc.2" in repository "owner/repo".', $commandTester->getDisplay());
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
        * feat: support `Map<K, V>` & "quoted values" by @johndoe in https://github.com/owner/repo/pull/123

        ## New Contributors
        * @johndoe made their first contribution in https://github.com/owner/repo/pull/123

        **Full Changelog**: https://github.com/owner/repo/commits/v0.1.0-rc.2

        <!-- Release generated by Imbo Releaser: https://github.com/imbo/releaser -->

        RELEASE_NOTES;

        $req = $history[4]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/repos/owner/repo/git/tags', $req->getUri()->getPath());
        $this->assertSame('/repos/owner/repo/git/tags', $req->getUri()->getPath());
        $body = $req->getBody()->getContents();
        $this->assertJson($body);
        /** @var array<string,mixed> $data */
        $data = json_decode($body, true);
        $this->assertSame('v0.1.0-rc.2', $data['tag']);
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
        $this->assertSame('refs/tags/v0.1.0-rc.2', $data['ref']);
        $this->assertSame('tagSha', $data['sha']);

        $req = $history[6]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/repos/owner/repo/releases', $req->getUri()->getPath());
        $body = $req->getBody()->getContents();
        $this->assertJson($body);

        /** @var array<string,mixed> $data */
        $data = json_decode($body, true);
        $this->assertSame('Release 0.1', $data['name']);
        $this->assertSame('v0.1.0-rc.2', $data['tag_name']);
        $this->assertSame($releaseNotes, $data['body']);
        $this->assertFalse($data['generate_release_notes']);
        $this->assertTrue($data['draft']);
        $this->assertTrue($data['prerelease']);
    }

    public function testRejectsPrereleaseWithLeadingZero(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient();
        $commandTester = new CommandTester($this->createCommand($guzzleClient));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid prerelease identifier or number: "01.1"');
        try {
            $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--prerelease' => '01'], ['interactive' => false]);
        } finally {
            $this->assertCount(0, $history);
        }
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
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['owner/repo', 'main']);
        $commandTester->execute(['--no-edit' => true]);
        $this->assertSame(CreateRelease::SUCCESS, $commandTester->getStatusCode());
    }

    public function testNoPullRequests(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([])), // pull requests
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No merged pull requests were returned for branch "main" in repository "owner/repo".');
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main']);
    }

    public function testFilterPullRequest(): void
    {
        $config = new class extends Config {
            public function filterPullRequest(ReleasePullRequest $pullRequest): bool
            {
                return false;
            }
        };
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 1,
                'user' => ['login' => 'user1'],
                'title' => 'feat: new feature',
                'merged_at' => '2024-01-01T00:00:00Z',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
        );
        $command = $this->createCommand($guzzleClient, $config);
        $commandTester = new CommandTester($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No eligible pull requests remain');
        try {
            $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main'], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        } finally {
            $this->assertStringContainsString('Skipping pull request #1: excluded by filterPullRequest() in the configuration.', $commandTester->getDisplay());
        }
    }

    public function testSkipsNonConventionalPullRequestsBeforeFiltering(): void
    {
        $config = new class extends Config {
            public function filterPullRequest(ReleasePullRequest $pullRequest): bool
            {
                return true;
            }
        };
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 1,
                'user' => ['login' => 'user1'],
                'title' => 'New feature',
                'merged_at' => '2024-01-01T00:00:00Z',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
        );
        $command = $this->createCommand($guzzleClient, $config);
        $commandTester = new CommandTester($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No eligible pull requests remain');
        try {
            $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main'], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        } finally {
            $this->assertStringContainsString('Skipping pull request #1: its title or body is not a valid Conventional Commit message.', $commandTester->getDisplay());
        }
    }

    public function testNoPullRequestsSinceLastTag(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 1,
                'user' => ['login' => 'user1'],
                'title' => 'feat: old feature',
                'merged_at' => '2024-01-01T00:00:00Z',
                'merge_commit_sha' => 'tagsha',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([
                ['name' => 'v1.0.0', 'commit' => ['sha' => 'tagsha']],
            ])), // tags
            new Response(200, [], $this->json([
                'commits' => [],
            ])), // no commits since the tag
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No eligible pull requests match the changes since tag "v1.0.0" on branch "main".');
        try {
            $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main'], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        } finally {
            $this->assertStringContainsString('Skipping pull request #1: its merge commit is not in the changes since tag "v1.0.0".', $commandTester->getDisplay());
        }
    }

    public function testReleaseMembershipUsesCommitsInsteadOfTimestamps(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                [
                    'number' => 3,
                    'user' => ['login' => 'alice'],
                    'title' => 'fix: new fix',
                    'merged_at' => '2024-01-01T00:00:00Z',
                    'merge_commit_sha' => 'newFixSha',
                    'base' => ['ref' => 'main'],
                ],
                [
                    'number' => 2,
                    'user' => ['login' => 'bob'],
                    'title' => 'fix: fix with an earlier timestamp',
                    'merged_at' => '2023-12-31T23:59:59Z',
                    'merge_commit_sha' => 'earlierFixSha',
                    'base' => ['ref' => 'main'],
                ],
                [
                    'number' => 1,
                    'user' => ['login' => 'alice'],
                    'title' => 'feat!: already released breaking change',
                    // GitHub recorded the merge one second after the tagged commit.
                    'merged_at' => '2024-01-01T00:00:01Z',
                    'merge_commit_sha' => 'tagsha',
                    'base' => ['ref' => 'main'],
                ],
            ])), // pull requests
            new Response(200, [], $this->json([
                ['name' => 'v1.0.0', 'commit' => ['sha' => 'tagsha']],
            ])), // tags
            new Response(200, [], $this->json([
                'base_commit' => ['sha' => 'tagsha', 'commit' => ['committer' => ['date' => '2024-01-01T00:00:00Z']]],
                'commits' => [['sha' => 'earlierFixSha'], ['sha' => 'newFixSha']],
            ])), // commits since the tag
        );
        $commandTester = new CommandTester($this->createCommand($guzzleClient));
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--dry-run' => true], ['interactive' => false]);

        $this->assertSame(CreateRelease::SUCCESS, $commandTester->getStatusCode());
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('with tag "v1.0.1"', $display);
        $this->assertStringContainsString('new fix', $display);
        $this->assertStringContainsString('fix with an earlier timestamp', $display);
        $this->assertStringNotContainsString('already released breaking change', $display);
        $this->assertStringNotContainsString('Skipping pull request', $display);
        $this->assertStringNotContainsString('@alice made their first contribution', $display);
        $this->assertStringContainsString('@bob made their first contribution', $display);
        $this->assertCount(3, $history);
        $this->assertSame('/repos/owner/repo/compare/tagsha...main?per_page=100', (string) $history[2]['request']->getUri());
    }

    public function testAlreadyReleasedPullRequestWithLaterMergeTimestampDoesNotCreateRelease(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 84,
                'user' => ['login' => 'alice'],
                'title' => 'feat!: already released change',
                'merged_at' => '2026-09-11T05:44:09Z',
                'merge_commit_sha' => 'tagsha',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([
                ['name' => 'v1.0.0', 'commit' => ['sha' => 'tagsha']],
            ])), // tags
            new Response(200, [], $this->json([
                'base_commit' => ['sha' => 'tagsha', 'commit' => ['committer' => ['date' => '2026-09-11T05:44:08Z']]],
                'commits' => [],
            ])), // commits since the tag
        );
        $commandTester = new CommandTester($this->createCommand($guzzleClient));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No eligible pull requests match the changes since tag "v1.0.0"');
        try {
            $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main'], ['interactive' => false]);
        } finally {
            $this->assertCount(3, $history);
            foreach ($history as $transaction) {
                $this->assertSame('GET', $transaction['request']->getMethod());
            }
        }
    }

    public function testSkipsPullRequestsWithoutMergeCommitSha(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 1,
                'user' => ['login' => 'alice'],
                'title' => 'feat!: change with null SHA',
                'merged_at' => '2024-01-02T00:00:00Z',
                'merge_commit_sha' => null,
                'base' => ['ref' => 'main'],
            ], [
                'number' => 2,
                'user' => ['login' => 'bob'],
                'title' => 'feat: change with missing SHA',
                'merged_at' => '2024-01-02T00:00:00Z',
                'base' => ['ref' => 'main'],
            ], [
                'number' => 3,
                'user' => ['login' => 'charlie'],
                'title' => 'fix: included fix',
                'merged_at' => '2024-01-03T00:00:00Z',
                'merge_commit_sha' => 'fixSha',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([
                ['name' => 'v1.0.0', 'commit' => ['sha' => 'tagsha']],
            ])), // tags
            new Response(200, [], $this->json(['commits' => [['sha' => 'fixSha']]])), // commits since the tag
        );
        $commandTester = new CommandTester($this->createCommand($guzzleClient));

        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--dry-run' => true], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertSame(CreateRelease::SUCCESS, $commandTester->getStatusCode());
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('Skipping pull request #1: GitHub did not provide a merge commit ID.', $display);
        $this->assertStringContainsString('Skipping pull request #2: GitHub did not provide a merge commit ID.', $display);
        $this->assertStringContainsString('with tag "v1.0.1"', $display);
        $this->assertStringContainsString('included fix', $display);
        $this->assertStringNotContainsString('change with null SHA', $display);
        $this->assertStringNotContainsString('change with missing SHA', $display);
        $this->assertStringNotContainsString('@alice made their first contribution', $display);
        $this->assertStringNotContainsString('@bob made their first contribution', $display);
        $this->assertStringContainsString('@charlie made their first contribution', $display);
        $this->assertCount(3, $history);
    }

    public function testFilterTag(): void
    {
        $config = new class extends Config {
            public function filterTag(ReleaseTag $tag): bool
            {
                return false;
            }
        };
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 1,
                'user' => ['login' => 'user1'],
                'title' => 'feat: new feature',
                'merged_at' => '2024-01-01T00:00:00Z',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([
                ['name' => 'v1.0.0', 'commit' => ['sha' => 'tagSha']],
            ])), // tags
        );
        $command = $this->createCommand($guzzleClient, $config);
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['no']);
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--no-edit' => true]);

        $this->assertSame(CreateRelease::ABORTED, $commandTester->getStatusCode());
        $this->assertCount(2, $history);
    }

    public function testFilteredPrereleaseTagStillAdvancesPrereleaseNumber(): void
    {
        $config = new class extends Config {
            public function filterTag(ReleaseTag $tag): bool
            {
                return !$tag->version->isPrerelease();
            }
        };
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 1,
                'user' => ['login' => 'user1'],
                'title' => 'feat: new feature',
                'merged_at' => '2024-01-01T00:00:00Z',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([
                ['name' => 'v0.1.0-rc.1', 'commit' => ['sha' => 'tagSha']],
            ])), // tags
        );
        $command = $this->createCommand($guzzleClient, $config);
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--repository' => 'owner/repo',
            '--branch' => 'main',
            '--prerelease' => 'rc',
            '--no-edit' => true,
            '--dry-run' => true,
        ], ['interactive' => false]);

        $this->assertSame(CreateRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('with tag "v0.1.0-rc.2"', $commandTester->getDisplay());
    }

    public function testRejectsExistingTagExcludedByFilter(): void
    {
        $config = new class extends Config {
            public function filterTag(ReleaseTag $tag): bool
            {
                return false;
            }
        };
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 1,
                'user' => ['login' => 'user1'],
                'title' => 'feat: new feature',
                'merged_at' => '2024-01-01T00:00:00Z',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([
                ['name' => 'v0.1.0', 'commit' => ['sha' => 'tagSha']],
            ])), // tags
        );
        $command = $this->createCommand($guzzleClient, $config);
        $commandTester = new CommandTester($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tag "v0.1.0" already exists in repository "owner/repo".');
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--no-edit' => true], ['interactive' => false]);
    }

    public function testReleaseWithExistingTag(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 2,
                'user' => ['login' => 'jane'],
                'title' => 'fix: a bug',
                'merged_at' => '2024-02-01T00:00:00Z',
                'merge_commit_sha' => 'fixSha',
                'base' => ['ref' => 'main'],
            ], [
                'number' => 1,
                'user' => ['login' => 'john'],
                'title' => 'feat: initial',
                'merged_at' => '2024-01-01T00:00:00Z',
                'merge_commit_sha' => 'tagsha',
                'base' => ['ref' => 'main'],
            ]])), // pull requests (descending by date)
            new Response(200, [], $this->json([
                ['name' => 'v1.0.0', 'commit' => ['sha' => 'tagsha']],
            ])), // tags
            new Response(200, [], $this->json([
                'commits' => [['sha' => 'fixSha']],
            ])), // commits since the tag
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
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['yes']);
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--no-edit' => true]);

        $this->assertSame(CreateRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Release created', $commandTester->getDisplay());
        $this->assertCount(7, $history);

        /** @var array{tag:string} $tagData */
        $tagData = json_decode($history[4]['request']->getBody()->getContents(), true);
        $this->assertSame('v1.0.1', $tagData['tag']);
    }

    public function testNewContributorsAreDeterminedByMergeDate(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                // Created last but merged before the previous release.
                'number' => 3,
                'user' => ['login' => 'alice'],
                'title' => 'fix: earlier contribution',
                'merged_at' => '2024-01-10T00:00:00Z',
                'merge_commit_sha' => 'oldAliceSha',
                'base' => ['ref' => 'main'],
            ], [
                // Created before #3 but merged after the previous release.
                'number' => 2,
                'user' => ['login' => 'alice'],
                'title' => 'fix: later contribution',
                'merged_at' => '2024-03-01T00:00:00Z',
                'merge_commit_sha' => 'newAliceSha',
                'base' => ['ref' => 'main'],
            ], [
                // Created first and merged after the previous release.
                'number' => 1,
                'user' => ['login' => 'bob'],
                'title' => 'fix: new contribution',
                'merged_at' => '2024-03-02T00:00:00Z',
                'merge_commit_sha' => 'bobSha',
                'base' => ['ref' => 'main'],
            ]])), // pull requests (creation date descending)
            new Response(200, [], $this->json([
                ['name' => 'v1.0.0', 'commit' => ['sha' => 'tagsha']],
            ])), // tags
            new Response(200, [], $this->json([
                'commits' => [['sha' => 'newAliceSha'], ['sha' => 'bobSha']],
            ])), // commits since the tag
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--repository' => 'owner/repo',
            '--branch' => 'main',
            '--no-edit' => true,
            '--dry-run' => true,
        ], ['interactive' => false]);

        $this->assertSame(CreateRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringNotContainsString('@alice made their first contribution', $commandTester->getDisplay());
        $this->assertStringContainsString('@bob made their first contribution', $commandTester->getDisplay());
    }

    public function testDryRunDoesNotCreateRelease(): void
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
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--repository' => 'owner/repo',
            '--branch' => 'main',
            '-d' => true,
            '--name' => 'Release 0.1',
        ], ['interactive' => false]);

        $this->assertSame(CreateRelease::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Would create release "Release 0.1" with tag "v0.1.0" in "owner/repo".', $commandTester->getDisplay());
        $this->assertStringContainsString('Release notes:', $commandTester->getDisplay());
        $this->assertCount(2, $history);
    }

    public function testReportsRecoveryCommandWhenReleaseCreationFails(): void
    {
        $config = new class extends Config {
            protected function initialVersionString(): string
            {
                return "-release'&v1.2.3";
            }
        };
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 1,
                'user' => ['login' => 'user1'],
                'title' => 'feat: new feature',
                'merged_at' => '2024-01-01T00:00:00Z',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([])), // tags
            new Response(200, [], $this->json(['commit' => ['sha' => 'branchSha']])), // branch sha
            new Response(201, [], $this->json(['sha' => 'tagSha'])), // tag object creation
            new Response(201), // tag reference creation
            new Response(422), // release creation
        );
        $command = $this->createCommand($guzzleClient, $config);
        $commandTester = new CommandTester($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Delete the tag before retrying: imbo-releaser delete --tag-only -- '-release'\\''&v1.2.3'");
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--no-edit' => true], ['interactive' => false]);
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
        $command = $this->createCommand($guzzleClient);
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
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The specified template file "invalid-template" does not exist or is not readable.');
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--template' => 'invalid-template']);
    }

    public function testInvalidReleaseNotesTemplate(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([[
                'number' => 1,
                'user' => ['login' => 'user1'],
                'title' => 'feat: new feature',
                'merged_at' => '2024-01-01T00:00:00Z',
                'base' => ['ref' => 'main'],
            ]])), // pull requests
            new Response(200, [], $this->json([])), // tags
        );
        $command = $this->createCommand($guzzleClient);
        $commandTester = new CommandTester($command);
        $template = __DIR__.'/../fixtures/invalid-template.twig';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Failed to render release notes template "%s"', $template));
        $commandTester->execute(['--repository' => 'owner/repo', '--branch' => 'main', '--template' => $template]);
    }

    private function createCommand(GuzzleClient $guzzleClient, ?ConfigInterface $config = null): CreateRelease
    {
        return new CreateRelease(
            new Client($guzzleClient),
            new Resolver($config ?? new Config(), __DIR__),
        );
    }
}
