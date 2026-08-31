<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use GuzzleHttp\Psr7\Response;
use ImboReleaser\TestHttpClientTrait;
use ImboReleaser\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use const DATE_RFC2822;

#[CoversClass(Client::class)]
class ClientTest extends TestCase
{
    use TestHttpClientTrait;

    public function testGetBranches(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, ['Link' => '<http://next-page>; rel="next"'], $this->json([
                ['name' => 'main'],
                ['name' => 'develop'],
            ])),
            new Response(200, [], $this->json([
                ['name' => 'testing'],
            ])),
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
            ])),
            new Response(200, [], $this->json([
                ['name' => 'Release 2.0.0', 'tag_name' => '2.0.0', 'html_url' => 'https://github.com/owner/repo/releases/tag/2.0.0', 'created_at' => '2026-01-03T00:00:00Z'],
            ])),
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
            ])),
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

    public function testGetPullRequests(): void
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
            ])),
        );

        $client = new Client($guzzleClient);
        $pullRequests = iterator_to_array($client->getMergedPullRequests(new Branch('main'), new Repository('owner', 'repo')));

        $this->assertCount(2, $pullRequests);
        $this->assertSame(4, $pullRequests[0]->number);
        $this->assertSame(2, $pullRequests[1]->number);

        $this->assertCount(1, $history);
        $this->assertSame('/repos/owner/repo/pulls?state=closed&sort=created&direction=desc&base=main&per_page=100', (string) $history[0]['request']->getUri());
    }

    public function testFetchPaginatedWithErrorResponse(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404, [], 'Not Found'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to request data from the GitHub API, got: "404 Not Found"');
        iterator_to_array((new Client($guzzleClient))->getTags(Repository::fromString('owner/repo')));
    }

    public function testFetchPaginatedWithServerErrorResponse(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(500, [], 'Internal Server Error'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to request data from the GitHub API, got: "500 Internal Server Error"');
        iterator_to_array((new Client($guzzleClient))->getTags(Repository::fromString('owner/repo')));
    }

    public function testFetchPaginatedWithNoJSON(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], 'some data'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Syntax error');
        iterator_to_array((new Client($guzzleClient))->getTags(Repository::fromString('owner/repo')));
    }

    public function testFetchPaginatedWithNonArrayInJSON(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], '"not an array"'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected an array, got: "string"');
        iterator_to_array((new Client($guzzleClient))->getTags(Repository::fromString('owner/repo')));
    }

    public function testFetchPaginatedWithInvalidArrayInJSON(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['not', 'valid'])),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected each item from the GitHub API to be an array, got: "string"');
        iterator_to_array((new Client($guzzleClient))->getTags(Repository::fromString('owner/repo')));
    }

    public function testGetShaDateTimeWithServerError(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to get commit data for "abc123", got: "404 Not Found"');
        (new Client($guzzleClient))->getShaDateTime(Repository::fromString('owner/repo'), 'abc123');
    }

    public function testGetShaDateTimeWithMissingCommitter(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['sha' => 'abc123'])),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "committer" key for commit "abc123"');
        (new Client($guzzleClient))->getShaDateTime(Repository::fromString('owner/repo'), 'abc123');
    }

    public function testGetShaDateTimeWithMissingDate(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['sha' => 'abc123', 'committer' => ['name' => 'Some User']])),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "committer.date" key for commit "abc123"');
        (new Client($guzzleClient))->getShaDateTime(Repository::fromString('owner/repo'), 'abc123');
    }

    public function testGetShaDateTime(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['sha' => 'abc123', 'committer' => ['date' => '2026-01-01T00:00:00Z']])),
        );

        $date = (new Client($guzzleClient))->getShaDateTime(Repository::fromString('owner/repo'), 'abc123');
        $this->assertSame('Thu, 01 Jan 2026 00:00:00 +0000', $date->format(DATE_RFC2822));

        $this->assertCount(1, $history);
        $this->assertSame('/repos/owner/repo/git/commits/abc123', (string) $history[0]['request']->getUri());
    }

    public function testCreateReleaseWithServerErrorWhenFetchingBranchSha(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to request branch data from the GitHub API for branch "main", got: "404 Not Found"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    public function testCreateReleaseWithInvalidBranchShaData(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['sha' => null])),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "commit.sha" key for branch "main"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    public function testCreateReleaseWithServerErrorWhenCreatingTag(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])),
            new Response(422),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create tag object for version "1.0.0", got: "422 Unprocessable Entity"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    public function testCreateReleaseWithMissingShaInTagResponse(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])),
            new Response(200, [], $this->json(['tag' => '1.0.0'])),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "sha" key for tag "1.0.0"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    public function testCreateReleaseWithServerErrorWhenCreatingRef(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])),
            new Response(200, [], $this->json(['sha' => 'tag-sha-456'])),
            new Response(422),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create tag reference for version "1.0.0" and sha "tag-sha-456", got: "422 Unprocessable Entity"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    public function testCreateReleaseWithServerErrorWhenCreatingRelease(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])),
            new Response(200, [], $this->json(['sha' => 'tag-sha-456'])),
            new Response(201, [], $this->json(['ref' => 'refs/tags/1.0.0'])),
            new Response(422),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create GitHub release for version "1.0.0", got: "422 Unprocessable Entity"');
        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'some message');
    }

    public function testCreateRelease(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['commit' => ['sha' => 'branch-sha-123']])),
            new Response(200, [], $this->json(['sha' => 'tag-sha-456'])),
            new Response(201, [], $this->json(['ref' => 'refs/tags/1.0.0'])),
            new Response(201, [], $this->json([
                'name' => 'release name',
                'tag_name' => 'v1.1.1',
                'html_url' => 'https://github.com/owner/repo/releases/tag/v1.0.0',
                'created_at' => '2024-01-01T00:00:00Z',
            ])),
        );

        (new Client($guzzleClient))->createRelease(Repository::fromString('owner/repo'), new Branch('main'), Version::fromString('1.0.0'), 'Release 1.0.0');

        $this->assertCount(4, $history);
        $this->assertSame('/repos/owner/repo/branches/main', (string) $history[0]['request']->getUri());
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
        $this->assertSame('1.0.0', $releasePayload['name']);
        $this->assertSame('1.0.0', $releasePayload['tag_name']);
    }

    public function testGetMergedPullRequestsSkipsDrafts(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json([
                ['number' => 1, 'user' => ['login' => 'user1'], 'title' => 'feat: new feature', 'merged_at' => '2026-01-01T00:00:00Z', 'base' => ['ref' => 'main'], 'draft' => true],
                ['number' => 2, 'user' => ['login' => 'user2'], 'title' => 'fix: a bug', 'merged_at' => '2026-01-02T00:00:00Z', 'base' => ['ref' => 'main'], 'draft' => false],
            ])),
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
            ])),
        );

        $pullRequests = iterator_to_array((new Client($guzzleClient))->getMergedPullRequests(new Branch('main'), new Repository('owner', 'repo')));

        $this->assertCount(1, $pullRequests);
        $this->assertSame(2, $pullRequests[0]->number);
    }

    public function testDeleteRelease(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 12_345, 'tag_name' => '1.0.0'])),
            new Response(204),
        );

        (new Client($guzzleClient))->deleteRelease(Repository::fromString('owner/repo'), Version::fromString('1.0.0'));

        $this->assertCount(2, $history);
        $this->assertSame('/repos/owner/repo/releases/tags/1.0.0', (string) $history[0]['request']->getUri());
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('/repos/owner/repo/releases/12345', (string) $history[1]['request']->getUri());
        $this->assertSame('DELETE', $history[1]['request']->getMethod());
    }

    public function testDeleteReleaseWithServerErrorWhenFetchingRelease(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to find release for version "1.0.0", got: "404 Not Found"');
        (new Client($guzzleClient))->deleteRelease(Repository::fromString('owner/repo'), Version::fromString('1.0.0'));
    }

    public function testDeleteReleaseWithMissingId(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['tag_name' => '1.0.0'])),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "id" key for release with version "1.0.0"');
        (new Client($guzzleClient))->deleteRelease(Repository::fromString('owner/repo'), Version::fromString('1.0.0'));
    }

    public function testDeleteReleaseWithServerErrorWhenDeletingRelease(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['id' => 12_345, 'tag_name' => '1.0.0'])),
            new Response(403),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete GitHub release for version "1.0.0", got: "403 Forbidden"');
        (new Client($guzzleClient))->deleteRelease(Repository::fromString('owner/repo'), Version::fromString('1.0.0'));
    }

    public function testDeleteTag(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(204),
        );

        (new Client($guzzleClient))->deleteTag(Repository::fromString('owner/repo'), Version::fromString('1.0.0'));

        $this->assertCount(1, $history);
        $this->assertSame('/repos/owner/repo/git/refs/tags/1.0.0', (string) $history[0]['request']->getUri());
        $this->assertSame('DELETE', $history[0]['request']->getMethod());
    }

    public function testDeleteTagWithServerError(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete tag reference "1.0.0", got: "404 Not Found"');
        (new Client($guzzleClient))->deleteTag(Repository::fromString('owner/repo'), Version::fromString('1.0.0'));
    }
}
