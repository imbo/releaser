<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\Exception\ReleaseCreationException;
use ImboReleaser\Exception\RuntimeException;
use ImboReleaser\TestHttpClientTrait;
use ImboReleaser\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_slice;

use const DATE_RFC2822;

#[CoversClass(Client::class)]
class ClientTest extends TestCase
{
    use TestHttpClientTrait;

    public function testAcceptsClientInterface(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/repos/owner/repo/tags?per_page=100')
            ->willReturn(new Response(200, [], $this->json([]))); // tags

        $tags = iterator_to_array((new Client($httpClient))->getTags(Repository::fromString('owner/repo')));

        $this->assertSame([], $tags);
    }

    public function testGetBranches(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, ['Link' => '<http://next-page>; rel="next"'], $this->json([
                ['name' => 'main'],
                ['name' => 'develop'],
            ])), // branches (page 1)
            new Response(200, [], $this->json([
                ['name' => 'testing'],
            ])), // branches (page 2)
        );

        $gitHubClient = new Client($guzzleClient);
        $branches = iterator_to_array($gitHubClient->getBranches(Repository::fromString('owner/repo')));

        $this->assertCount(3, $branches);
        $this->assertSame('main', $branches[0]->name);
        $this->assertSame('develop', $branches[1]->name);
        $this->assertSame('testing', $branches[2]->name);

        $this->assertCount(2, $history);
        $this->assertSame('/repos/owner/repo/branches?per_page=100', (string) $history[0]['request']->getUri());
        $this->assertSame('http://next-page', (string) $history[1]['request']->getUri());
    }

    public function testGetReleases(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, ['Link' => '<http://next-page>; rel="next"'], $this->json([
                ['name' => 'Release 1.0.0', 'tag_name' => '1.0.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/1.0.0', 'created_at' => '2026-01-01T00:00:00Z'],
                ['name' => 'Release 1.1.0', 'tag_name' => '1.1.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/1.1.0', 'created_at' => '2026-01-02T00:00:00Z'],
            ])), // releases (page 1)
            new Response(200, [], $this->json([
                ['name' => 'Release 2.0.0', 'tag_name' => '2.0.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/2.0.0', 'created_at' => '2026-01-03T00:00:00Z'],
            ])), // releases (page 2)
        );

        $gitHubClient = new Client($guzzleClient);
        $releases = iterator_to_array($gitHubClient->getReleases(Repository::fromString('owner/repo')));

        $this->assertCount(3, $releases);
        $this->assertSame('Release 1.0.0', $releases[0]->name);
        $this->assertSame('Release 1.1.0', $releases[1]->name);
        $this->assertSame('Release 2.0.0', $releases[2]->name);

        $this->assertCount(2, $history);
        $this->assertSame('/repos/owner/repo/releases?per_page=100', (string) $history[0]['request']->getUri());
        $this->assertSame('http://next-page', (string) $history[1]['request']->getUri());
    }

    public function testGetTags(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['name' => '1.1.1', 'commit' => ['sha' => 'abc123']],
                ['name' => 'some-tag', 'commit' => ['sha' => 'def456']],
            ])), // tags
        );

        $gitHubClient = new Client($guzzleClient);
        $tags = iterator_to_array($gitHubClient->getTags(Repository::fromString('owner/repo')));

        $this->assertCount(2, $tags);
        $this->assertSame('1.1.1', $tags[0]->name);
        $this->assertSame('abc123', $tags[0]->sha);
        $this->assertSame('some-tag', $tags[1]->name);
        $this->assertSame('def456', $tags[1]->sha);

        $this->assertCount(1, $history);
        $this->assertSame('/repos/owner/repo/tags?per_page=100', (string) $history[0]['request']->getUri());
    }

    /**
     * @return iterable<string,array{branchName:string,encodedName:string}>
     */
    public static function branchNameProvider(): iterable
    {
        yield 'main' => ['branchName' => 'main', 'encodedName' => 'main'];
        yield 'slash' => ['branchName' => 'release/1.x', 'encodedName' => 'release%2F1.x'];
        yield 'reserved characters' => ['branchName' => 'release/1+2&x=3#4%5', 'encodedName' => 'release%2F1%2B2%26x%3D3%234%255'];
    }

    #[DataProvider('branchNameProvider')]
    public function testGetPullRequests(string $branchName, string $encodedName): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                // should be included
                ['number' => 4, 'user' => ['login' => 'some-user'], 'title' => 'some title', 'merged_at' => '2026-01-02T00:00:00Z', 'base' => ['ref' => 'main']],

                // skipped because of missing merged_at
                ['number' => 3, 'user' => ['login' => 'some-user'], 'title' => 'some title', 'merged_at' => null, 'base' => ['ref' => 'main']],

                // should be included
                ['number' => 2, 'user' => ['login' => 'some-user'], 'title' => 'some title', 'merged_at' => '2026-01-01T00:00:00Z', 'base' => ['ref' => 'main']],

                // skipped because of missing merged_at
                ['number' => 1, 'user' => ['login' => 'some-user'], 'title' => 'some title', 'merged_at' => null, 'base' => ['ref' => 'main']],
            ])), // pull requests
        );

        $client = new Client($guzzleClient);
        $pullRequests = iterator_to_array($client->getMergedPullRequests(new Branch($branchName), new Repository('owner', 'repo')));

        $this->assertCount(2, $pullRequests);
        $this->assertSame(4, $pullRequests[0]->number);
        $this->assertSame(2, $pullRequests[1]->number);

        $this->assertCount(1, $history);
        $this->assertSame('/repos/owner/repo/pulls?state=closed&sort=created&direction=desc&base='.$encodedName.'&per_page=100', (string) $history[0]['request']->getUri());
        parse_str($history[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame($branchName, $query['base']);
    }

    public function testFetchPaginatedWithErrorResponse(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404, [], 'Not Found'), // tags
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to request data from the GitHub API, got: "404 Not Found"');
        iterator_to_array((new Client($guzzleClient))->getTags(Repository::fromString('owner/repo')));
    }

    public function testFetchPaginatedWithServerErrorResponse(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(500, [], 'Internal Server Error'), // tags
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to request data from the GitHub API, got: "500 Internal Server Error"');
        iterator_to_array((new Client($guzzleClient))->getTags(Repository::fromString('owner/repo')));
    }

    public function testFetchPaginatedWithNoJSON(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], 'some data'), // tags
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Syntax error');
        iterator_to_array((new Client($guzzleClient))->getTags(Repository::fromString('owner/repo')));
    }

    public function testFetchPaginatedWithNonArrayInJSON(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], '"not an array"'), // tags
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected an array, got: "string"');
        iterator_to_array((new Client($guzzleClient))->getTags(Repository::fromString('owner/repo')));
    }

    public function testFetchPaginatedWithInvalidArrayInJSON(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['not', 'valid'])), // tags
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected each item from the GitHub API to be an array, got: "string"');
        iterator_to_array((new Client($guzzleClient))->getTags(Repository::fromString('owner/repo')));
    }

    public function testGetCommitShasBetweenFollowsPagination(): void
    {
        $shas = array_map(static fn (int $number): string => 'sha'.$number, range(1, 251));
        $commits = array_map(static fn (string $sha): array => ['sha' => $sha], $shas);
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, ['Link' => '</comparison?page=2>; rel="next"'], $this->json(['commits' => array_slice($commits, 0, 100)])), // commits (page 1)
            new Response(200, ['Link' => '</comparison?page=3>; rel="next"'], $this->json(['commits' => array_slice($commits, 100, 100)])), // commits (page 2)
            new Response(200, [], $this->json(['commits' => array_slice($commits, 200)])), // commits (page 3)
        );

        $actual = iterator_to_array((new Client($guzzleClient))->getCommitShasBetween(new Repository('owner', 'repo'), 'baseSha', 'release/1+2'));

        $this->assertSame($shas, $actual);
        $this->assertCount(3, $history);
        $this->assertSame('/repos/owner/repo/compare/baseSha...release%2F1%2B2?per_page=100', (string) $history[0]['request']->getUri());
        $this->assertSame('/comparison?page=2', (string) $history[1]['request']->getUri());
        $this->assertSame('/comparison?page=3', (string) $history[2]['request']->getUri());
    }

    public function testGetCommitShasBetweenWithNoNewCommits(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(new Response(200, [], $this->json(['commits' => []]))); // commits

        $this->assertSame([], iterator_to_array((new Client($guzzleClient))->getCommitShasBetween(new Repository('owner', 'repo'), 'sameSha', 'sameSha')));
    }

    /**
     * @return iterable<string,array{data:array<mixed>}>
     */
    public static function invalidComparisonProvider(): iterable
    {
        yield 'missing commits' => ['data' => []];
        yield 'invalid commits' => ['data' => ['commits' => 'invalid']];
        yield 'non-list commits' => ['data' => ['commits' => ['sha' => 'invalid']]];
        yield 'invalid commit' => ['data' => ['commits' => [null]]];
        yield 'missing sha' => ['data' => ['commits' => [[]]]];
        yield 'invalid sha' => ['data' => ['commits' => [['sha' => 123]]]];
        yield 'empty sha' => ['data' => ['commits' => [['sha' => '']]]];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidComparisonProvider')]
    public function testGetCommitShasBetweenRejectsInvalidResponse(array $data): void
    {
        [$guzzleClient] = $this->getGuzzleClient(new Response(200, [], $this->json($data))); // commits

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GitHub comparison response');
        iterator_to_array((new Client($guzzleClient))->getCommitShasBetween(new Repository('owner', 'repo'), 'baseSha', 'headSha'));
    }

    public function testGetCommitShasBetweenFailsWhenComparisonIsUnavailable(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(new Response(404)); // commits

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('404 Not Found');
        iterator_to_array((new Client($guzzleClient))->getCommitShasBetween(new Repository('owner', 'repo'), 'baseSha', 'headSha'));
    }

    public function testGetShaDateTimeWithServerError(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404), // commit
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to get commit data for "abc123", got: "404 Not Found"');
        (new Client($guzzleClient))->getShaDateTime(Repository::fromString('owner/repo'), 'abc123');
    }

    public function testGetShaDateTimeWithMissingCommitter(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['sha' => 'abc123'])), // commit
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "committer" key for commit "abc123"');
        (new Client($guzzleClient))->getShaDateTime(Repository::fromString('owner/repo'), 'abc123');
    }

    public function testGetShaDateTimeWithMissingDate(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['sha' => 'abc123', 'committer' => ['name' => 'Some User']])), // commit
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "committer.date" key for commit "abc123"');
        (new Client($guzzleClient))->getShaDateTime(Repository::fromString('owner/repo'), 'abc123');
    }

    public function testGetShaDateTimeWithInvalidDate(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['committer' => ['date' => 'not-a-date']])), // commit
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "committer.date" value: not-a-date');
        (new Client($guzzleClient))->getShaDateTime(Repository::fromString('owner/repo'), 'abc123');
    }

    public function testGetShaDateTimeWithConnectionError(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new ConnectException('Connection failed', new Request('GET', 'https://api.github.com')), // commit
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to get commit data for "abc123", got: "no response"');
        (new Client($guzzleClient))->getShaDateTime(Repository::fromString('owner/repo'), 'abc123');
    }

    public function testGetShaDateTime(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['sha' => 'abc123', 'committer' => ['date' => '2026-01-01T00:00:00Z']])), // commit
        );

        $date = (new Client($guzzleClient))->getShaDateTime(Repository::fromString('owner/repo'), 'abc123');
        $this->assertSame('Thu, 01 Jan 2026 00:00:00 +0000', $date->format(DATE_RFC2822));

        $this->assertCount(1, $history);
        $this->assertSame('/repos/owner/repo/git/commits/abc123', (string) $history[0]['request']->getUri());
    }

    public function testCreateReleaseWithServerErrorWhenFetchingBranchSha(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404), // branch sha
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to request branch data from the GitHub API for branch "main", got: "404 Not Found"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    public function testCreateReleaseWithInvalidBranchShaData(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['sha' => null])), // branch sha
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "commit.sha" key for branch "main"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    public function testCreateReleaseWithServerErrorWhenCreatingTag(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])), // branch sha
            new Response(422), // tag object creation
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create tag object for version "1.0.0", got: "422 Unprocessable Entity"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    public function testCreateReleaseWithMissingShaInTagResponse(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])), // branch sha
            new Response(200, [], $this->json(['tag' => '1.0.0'])), // tag object creation
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "sha" key for tag "1.0.0"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    public function testCreateReleaseWithServerErrorWhenCreatingRef(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])), // branch sha
            new Response(200, [], $this->json(['sha' => 'tag-sha-456'])), // tag object creation
            new Response(422), // tag reference creation
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create tag reference for version "1.0.0" and sha "tag-sha-456", got: "422 Unprocessable Entity"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    public function testCreateReleaseWithServerErrorWhenCreatingRelease(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])), // branch sha
            new Response(200, [], $this->json(['sha' => 'tag-sha-456'])), // tag object creation
            new Response(201, [], $this->json(['ref' => 'refs/tags/1.0.0'])), // tag reference creation
            new Response(422), // release creation
        );

        $this->expectException(ReleaseCreationException::class);
        $this->expectExceptionMessage('Failed to create GitHub release for version "1.0.0", got: "422 Unprocessable Entity"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    #[DataProvider('branchNameProvider')]
    public function testCreateRelease(string $branchName, string $encodedName): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])), // branch sha
            new Response(200, [], $this->json(['sha' => 'tag-sha-456'])), // tag object creation
            new Response(201, [], $this->json(['ref' => 'refs/tags/1.0.0'])), // tag reference creation
            new Response(201, [], $this->json([
                'name' => 'release name',
                'tag_name' => 'v1.1.1',
                'html_url' => 'https://github.com/owner/repo/releases/tag/v1.0.0',
                'created_at' => '2024-01-01T00:00:00Z',
            ])), // release creation
        );

        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch($branchName), Version::fromString('1.0.0'), 'Release 1.0.0', 'Release 1.0', true, true);

        $this->assertCount(4, $history);
        $this->assertSame('/repos/owner/repo/branches/'.$encodedName, (string) $history[0]['request']->getUri());
        $this->assertSame('GET', $history[0]['request']->getMethod());

        $this->assertSame('/repos/owner/repo/git/tags', (string) $history[1]['request']->getUri());
        $this->assertSame('POST', $history[1]['request']->getMethod());
        $body = $history[1]['request']->getBody()->getContents();
        $this->assertJson($body);
        /** @var array<string,mixed> */
        $tagPayload = json_decode($body, true);
        $this->assertSame('1.0.0', $tagPayload['tag']);
        $this->assertSame('Release 1.0.0', $tagPayload['message']);
        $this->assertSame('branch-sha-123', $tagPayload['object']);
        $this->assertSame('commit', $tagPayload['type']);

        $this->assertSame('/repos/owner/repo/git/refs', (string) $history[2]['request']->getUri());
        $this->assertSame('POST', $history[2]['request']->getMethod());
        $body = $history[2]['request']->getBody()->getContents();
        $this->assertJson($body);
        /** @var array<string,mixed> */
        $refPayload = json_decode($body, true);
        $this->assertSame('refs/tags/1.0.0', $refPayload['ref']);
        $this->assertSame('tag-sha-456', $refPayload['sha']);

        $this->assertSame('/repos/owner/repo/releases', (string) $history[3]['request']->getUri());
        $this->assertSame('POST', $history[3]['request']->getMethod());
        $body = $history[3]['request']->getBody()->getContents();
        $this->assertJson($body);
        /** @var array<string,mixed> */
        $releasePayload = json_decode($body, true);
        $this->assertSame('Release 1.0', $releasePayload['name']);
        $this->assertSame('1.0.0', $releasePayload['tag_name']);
        $this->assertTrue($releasePayload['draft']);
        $this->assertTrue($releasePayload['prerelease']);
    }

    public function testCreateReleaseIsNotPrereleaseByDefault(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])), // branch sha
            new Response(200, [], $this->json(['sha' => 'tag-sha-456'])), // tag object creation
            new Response(201, [], $this->json(['ref' => 'refs/tags/1.0.0'])), // tag reference creation
            new Response(201, [], $this->json([
                'name' => 'release name',
                'tag_name' => '1.0.0',
                'html_url' => 'https://github.com/owner/repo/releases/tag/1.0.0',
                'created_at' => '2024-01-01T00:00:00Z',
            ])), // release creation
        );

        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'Release 1.0.0');

        /** @var array<string,mixed> $releasePayload */
        $releasePayload = json_decode($history[3]['request']->getBody()->getContents(), true);
        $this->assertFalse($releasePayload['prerelease']);
    }

    /**
     * @return array<string,array{string,string,bool,bool}>
     */
    public static function latestReleasePolicyProvider(): array
    {
        return [
            'stable main release' => ['main', 'v2.0.0', false, false],
            'maintenance patch' => ['v1.x', 'v1.0.1', false, false],
            'draft' => ['main', 'v2.0.0', true, false],
            'prerelease' => ['main', 'v2.0.0-rc.1', false, true],
            'draft prerelease' => ['main', 'v2.0.0-rc.1', true, true],
        ];
    }

    #[DataProvider('latestReleasePolicyProvider')]
    public function testCreateReleaseDelegatesLatestSelectionToGitHub(string $branchName, string $version, bool $draft, bool $prerelease): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])), // branch sha
            new Response(200, [], $this->json(['sha' => 'tag-sha-456'])), // tag object creation
            new Response(201, [], $this->json(['ref' => 'refs/tags/'.$version])), // tag reference creation
            new Response(201, [], $this->json([
                'name' => $version,
                'tag_name' => $version,
                'html_url' => 'https://github.com/owner/repo/releases/tag/'.$version,
                'created_at' => '2026-01-01T00:00:00Z',
            ])), // release creation
        );

        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch($branchName), Version::fromString($version), 'Release notes', draft: $draft, prerelease: $prerelease);

        $this->assertCount(4, $history);
        $this->assertSame('POST', $history[3]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/releases', (string) $history[3]['request']->getUri());
        $body = $history[3]['request']->getBody()->getContents();
        $this->assertJson($body);
        /** @var array<string,mixed> $releasePayload */
        $releasePayload = json_decode($body, true);
        $this->assertSame('legacy', $releasePayload['make_latest']);
        $this->assertSame($version, $releasePayload['tag_name']);
        $this->assertSame($draft, $releasePayload['draft']);
        $this->assertSame($prerelease, $releasePayload['prerelease']);
    }

    public function testGetMergedPullRequestsSkipsDrafts(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['number' => 1, 'user' => ['login' => 'user1'], 'title' => 'feat: new feature', 'merged_at' => '2026-01-01T00:00:00Z', 'base' => ['ref' => 'main'], 'draft' => true],
                ['number' => 2, 'user' => ['login' => 'user2'], 'title' => 'fix: a bug', 'merged_at' => '2026-01-02T00:00:00Z', 'base' => ['ref' => 'main'], 'draft' => false],
            ])), // pull requests
        );

        $pullRequests = iterator_to_array((new Client($guzzleClient))->getMergedPullRequests(new Branch('main'), new Repository('owner', 'repo')));

        $this->assertCount(1, $pullRequests);
        $this->assertSame(2, $pullRequests[0]->number);
    }

    public function testGetMergedPullRequestsSkipsMissingUser(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['number' => 1, 'user' => null, 'title' => 'feat: new feature', 'merged_at' => '2026-01-01T00:00:00Z', 'base' => ['ref' => 'main']],
                ['number' => 2, 'user' => ['login' => 'user2'], 'title' => 'fix: a bug', 'merged_at' => '2026-01-02T00:00:00Z', 'base' => ['ref' => 'main']],
            ])), // pull requests
        );

        $pullRequests = iterator_to_array((new Client($guzzleClient))->getMergedPullRequests(new Branch('main'), new Repository('owner', 'repo')));

        $this->assertCount(1, $pullRequests);
        $this->assertSame(2, $pullRequests[0]->number);
    }

    /**
     * @return iterable<string,array{tagName:string,encodedName:string}>
     */
    public static function tagNameProvider(): iterable
    {
        yield 'plain version' => ['tagName' => '1.0.0', 'encodedName' => '1.0.0'];
        yield 'fragment' => ['tagName' => 'v1.2.3#v4.5.6', 'encodedName' => 'v1.2.3%23v4.5.6'];
        yield 'literal percent sequence' => ['tagName' => 'release%231.2.3', 'encodedName' => 'release%25231.2.3'];
        yield 'reserved characters' => ['tagName' => 'release/one+two&x=1-v1.2.3', 'encodedName' => 'release%2Fone%2Btwo%26x%3D1-v1.2.3'];
    }

    #[DataProvider('tagNameProvider')]
    public function testDeleteRelease(string $tagName, string $encodedName): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 12_345, 'tag_name' => $tagName])), // release by tag
            new Response(204), // delete release
        );

        (new Client($guzzleClient))->deleteRelease(Repository::fromString('owner/repo'), Version::fromString($tagName));

        $this->assertCount(2, $history);
        $this->assertSame('/repos/owner/repo/releases/tags/'.$encodedName, (string) $history[0]['request']->getUri());
        $this->assertSame('/repos/owner/repo/releases/tags/'.$tagName, rawurldecode($history[0]['request']->getUri()->getPath()));
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/releases/12345', (string) $history[1]['request']->getUri());
        $this->assertSame('DELETE', $history[1]['request']->getMethod());
    }

    public function testDeleteReleaseWithServerErrorWhenFetchingRelease(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404), // release by tag
            new Response(200, [], $this->json([])), // releases
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to find release for version "1.0.0", got: "404 Not Found"');
        (new Client($guzzleClient))->deleteRelease(Repository::fromString('owner/repo'), Version::fromString('1.0.0'));
    }

    public function testDeleteReleaseWithMissingId(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['tag_name' => '1.0.0'])), // release by tag
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "id" key for release with version "1.0.0"');
        (new Client($guzzleClient))->deleteRelease(Repository::fromString('owner/repo'), Version::fromString('1.0.0'));
    }

    public function testDeleteReleaseWithServerErrorWhenDeletingRelease(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 12_345, 'tag_name' => '1.0.0'])), // release by tag
            new Response(403), // delete release
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete GitHub release with ID 12345 in repository "owner/repo", got: "403 Forbidden"');
        (new Client($guzzleClient))->deleteRelease(Repository::fromString('owner/repo'), Version::fromString('1.0.0'));
    }

    public function testDeleteDraftReleaseFollowsPagination(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(404), // release by tag
            new Response(200, ['Link' => '</repos/owner/repo/releases?per_page=100&page=2>; rel="next"'], $this->json([[
                'id' => 10, 'name' => 'Other draft', 'tag_name' => 'v1.0.0-rc.1', 'draft' => true,
                'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z',
            ]])), // releases (page 1)
            new Response(200, [], $this->json([[
                'id' => 42, 'name' => 'Matching draft', 'tag_name' => 'v1.0.0', 'draft' => true,
                'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z',
            ]])), // releases (page 2)
            new Response(204), // delete release
        );
        (new Client($guzzleClient))->deleteRelease(new Repository('owner', 'repo'), Version::fromString('v1.0.0'));

        $this->assertCount(4, $history);
        $this->assertSame('/repos/owner/repo/releases?per_page=100&page=2', (string) $history[2]['request']->getUri());
        $this->assertSame('DELETE', $history[3]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/releases/42', (string) $history[3]['request']->getUri());
    }

    public function testDeleteReleaseDoesNotSearchDraftsOnPermissionError(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(new Response(403)); // release by tag
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to find release');
        try {
            (new Client($guzzleClient))->deleteRelease(new Repository('owner', 'repo'), Version::fromString('v1.0.0'));
        } finally {
            $this->assertCount(1, $history);
        }
    }

    public function testDeleteDraftReleaseRequiresId(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404), // release by tag
            new Response(200, [], $this->json([[
                'name' => 'Draft', 'tag_name' => 'v1.0.0', 'draft' => true,
                'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z',
            ]])), // releases
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "id" key');
        (new Client($guzzleClient))->deleteRelease(new Repository('owner', 'repo'), Version::fromString('v1.0.0'));
    }

    #[DataProvider('tagNameProvider')]
    public function testDeleteTag(string $tagName, string $encodedName): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(204), // delete tag
        );

        (new Client($guzzleClient))->deleteTag(Repository::fromString('owner/repo'), Version::fromString($tagName));

        $this->assertCount(1, $history);
        $this->assertSame('/repos/owner/repo/git/refs/tags/'.$encodedName, (string) $history[0]['request']->getUri());
        $this->assertSame('/repos/owner/repo/git/refs/tags/'.$tagName, rawurldecode($history[0]['request']->getUri()->getPath()));
        $this->assertSame('DELETE', $history[0]['request']->getMethod());
    }

    public function testDeleteTagWithServerError(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404), // delete tag
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete tag reference "1.0.0", got: "404 Not Found"');
        (new Client($guzzleClient))->deleteTag(Repository::fromString('owner/repo'), Version::fromString('1.0.0'));
    }
}
