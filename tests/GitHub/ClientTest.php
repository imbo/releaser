<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use ArrayObject;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use const DATE_RFC2822;
use const JSON_THROW_ON_ERROR;

#[CoversClass(Client::class)]
class ClientTest extends TestCase
{
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
        $branches = iterator_to_array($gitHubClient->getBranches('owner/repo'));

        $this->assertCount(3, $branches);
        $this->assertSame('main', $branches[0]->name);
        $this->assertSame('develop', $branches[1]->name);
        $this->assertSame('testing', $branches[2]->name);

        $this->assertCount(2, $history);
        $this->assertSame('/repos/owner/repo/branches?per_page=100', (string) $history[0]['request']->getUri());
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
        $tags = iterator_to_array($gitHubClient->getTags('owner/repo'));

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
                ['number' => 4, 'user' => ['login' => 'some-user'], 'title' => 'some title', 'merged_at' => '2026-01-02T00:00:00Z', 'base' => ['ref' => 'main']],
                ['number' => 3, 'user' => ['login' => 'some-user'], 'title' => 'some title', 'merged_at' => null, 'base' => ['ref' => 'main']],
                ['number' => 2, 'user' => ['login' => 'some-user'], 'title' => 'some title', 'merged_at' => '2026-01-01T00:00:00Z', 'base' => ['ref' => 'main']],
                ['number' => 1, 'user' => ['login' => 'some-user'], 'title' => 'some title', 'merged_at' => null, 'base' => ['ref' => 'main']],
            ])),
        );

        $pullRequests = iterator_to_array((new Client($guzzleClient))->getMergedPullRequests('owner/repo'));

        $this->assertCount(2, $pullRequests);
        $this->assertSame(4, $pullRequests[0]->number);
        $this->assertSame(2, $pullRequests[1]->number);

        $this->assertCount(1, $history);
        $this->assertSame('/repos/owner/repo/pulls?state=closed&per_page=100', (string) $history[0]['request']->getUri());
    }

    public function testFetchPaginatedWithErrorResponse(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(404, [], 'Not Found'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GitHub API: 404 Not Found');
        iterator_to_array((new Client($guzzleClient))->getTags('owner/repo'));
    }

    public function testFetchPaginatedWithNoJSON(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], 'some data'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GitHub API: Syntax error');
        iterator_to_array((new Client($guzzleClient))->getTags('owner/repo'));
    }

    public function testFetchPaginatedWithNonArrayInJSON(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], '"not an array"'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('items from the GitHub API, got: string');
        iterator_to_array((new Client($guzzleClient))->getTags('owner/repo'));
    }

    public function testFetchPaginatedWithInvalidArrayInJSON(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['not', 'valid'])),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GitHub API to be an array, got: string');
        iterator_to_array((new Client($guzzleClient))->getTags('owner/repo'));
    }

    public function testGetShaDateTimeWithMissingCommitter(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['sha' => 'abc123'])),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "committer"');
        (new Client($guzzleClient))->getShaDateTime('owner/repo', 'abc123');
    }

    public function testGetShaDateTimeWithMissingDate(): void
    {
        [$guzzleClient] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['sha' => 'abc123', 'committer' => ['name' => 'Some User']])),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required "committer.date"');
        (new Client($guzzleClient))->getShaDateTime('owner/repo', 'abc123');
    }

    public function testGetShaDateTime(): void
    {
        [$guzzleClient, $history] = $this->getGuzzleClient(
            new Response(200, [], $this->json(['sha' => 'abc123', 'committer' => ['date' => '2026-01-01T00:00:00Z']])),
        );

        $date = (new Client($guzzleClient))->getShaDateTime('owner/repo', 'abc123');
        $this->assertSame('Thu, 01 Jan 2026 00:00:00 +0000', $date->format(DATE_RFC2822));

        $this->assertCount(1, $history);
        $this->assertSame('/repos/owner/repo/git/commits/abc123', (string) $history[0]['request']->getUri());
    }

    /**
     * @return array{0:GuzzleClient,1:list<array{request:Request,response:Response}>}
     */
    private function getGuzzleClient(Response ...$responses): array
    {
        /** @var list<Response> $responses */
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $history = new ArrayObject();
        $handlerStack->push(Middleware::history($history));

        /** @var list<array{request:Request,response:Response}> $history */
        return [new GuzzleClient(['handler' => $handlerStack]), $history];
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
