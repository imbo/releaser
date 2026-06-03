<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use DateTimeImmutable;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Header;
use ImboReleaser\Version;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function array_key_exists;
use function gettype;
use function is_array;
use function is_string;
use function sprintf;

use const JSON_BIGINT_AS_STRING;
use const JSON_OBJECT_AS_ARRAY;
use const JSON_THROW_ON_ERROR;

final class Client
{
    /**
     * @param GuzzleHttpClient $httpClient an instance of the Guzzle HTTP client to use for making API requests
     */
    public function __construct(private GuzzleHttpClient $httpClient)
    {
    }

    /**
     * Get all branches for the given repository.
     *
     * @return iterable<Branch>
     */
    public function getBranches(Repository $repository): iterable
    {
        return $this->fetchPaginated(
            sprintf('/repos/%s/branches?per_page=100', $repository),
            Branch::fromAPI(...),
        );
    }

    /**
     * Get all releases for the given repository.
     *
     * @return iterable<Release>
     */
    public function getReleases(Repository $repository): iterable
    {
        return $this->fetchPaginated(
            sprintf('/repos/%s/releases?per_page=100', $repository),
            Release::fromAPI(...),
        );
    }

    /**
     * Get all tags for the given repository.
     *
     * @return iterable<Tag>
     */
    public function getTags(Repository $repository): iterable
    {
        return $this->fetchPaginated(
            sprintf('/repos/%s/tags?per_page=100', $repository),
            Tag::fromAPI(...),
        );
    }

    /**
     * Get pull requests merged to a specific branch for the given repository.
     *
     * Drafts and pull requests missing either a user or a merged timestamp are skipped.
     *
     * The returned pull requests are sorted by creation date in descending order.
     *
     * @return iterable<PullRequest>
     */
    public function getMergedPullRequests(Branch $branch, Repository $repository): iterable
    {
        return $this->fetchPaginated(
            sprintf(
                '/repos/%s/pulls?state=closed&sort=created&direction=desc&base=%s&per_page=100',
                $repository,
                $branch->name,
            ),
            PullRequest::fromAPI(...),
            static function (array $item): bool {
                return
                    ($item['draft'] ?? false) === false
                    && ($item['merged_at'] ?? null) !== null
                    && ($item['user'] ?? null) !== null;
            },
        );
    }

    /**
     * Get the commit timestamp for a given SHA in the specified repository.
     *
     * @throws RuntimeException
     */
    public function getShaDateTime(Repository $repository, string $sha): DateTimeImmutable
    {
        try {
            $response = $this->httpClient->get(sprintf('/repos/%s/git/commits/%s', $repository, $sha));
        } catch (ClientException $e) {
            $r = $e->getResponse();
            throw new RuntimeException(sprintf('Failed to get commit data for "%s", got: "%s"', $sha, $this->responseStatus($r)), previous: $e);
        }

        $data = $this->responseToArray($response);

        $committer = $data['committer'] ?? null;
        if (!is_array($committer)) {
            throw new RuntimeException(sprintf('Missing required "committer" key for commit "%s"', $sha));
        }

        $dateString = $committer['date'] ?? null;
        if (!is_string($dateString)) {
            throw new RuntimeException(sprintf('Missing required "committer.date" key for commit "%s"', $sha));
        }

        return new DateTimeImmutable($dateString);
    }

    /**
     * Create a GitHub release.
     */
    public function createRelease(Repository $repository, Branch $branch, Version $version, string $releaseNotes): Release
    {
        $this->createAnnotatedTag($repository, $branch, $version, $releaseNotes);

        try {
            $response = $this->httpClient->post(sprintf('/repos/%s/releases', $repository), [
                'json' => [
                    'tag_name' => (string) $version,
                    'name' => (string) $version,
                    'body' => $releaseNotes,
                    'generate_release_notes' => false,

                    // 'draft' => <bool>,
                    // 'prerelease' => <bool>,
                    // 'discussion_category_name' => '...',
                    // 'make_latest' => 'Can be one of: true, false, legacy',
                ],
            ]);
        } catch (ClientException $e) {
            $r = $e->getResponse();
            throw new RuntimeException(sprintf('Failed to create GitHub release for version "%s", got: "%s"', $version, $this->responseStatus($r)), previous: $e);
        }

        $data = $this->responseToArray($response);

        return Release::fromAPI($data);
    }

    /**
     * Create an annotated tag in the specified repository, pointing to the given branch.
     *
     * @throws RuntimeException
     */
    private function createAnnotatedTag(Repository $repository, Branch $branch, Version $version, string $releaseNotes): void
    {
        $branchSha = $this->getBranchSha($repository, $branch);

        try {
            $response = $this->httpClient->post(sprintf('/repos/%s/git/tags', $repository), [
                'json' => [
                    'tag' => (string) $version,
                    'message' => $releaseNotes,
                    'object' => $branchSha,
                    'type' => 'commit',

                    // 'tagger' => ['name' => '...', 'email' => '...'],
                ],
            ]);
        } catch (ClientException $e) {
            $r = $e->getResponse();
            throw new RuntimeException(sprintf('Failed to create tag object for version "%s", got: "%s"', $version, $this->responseStatus($r)), previous: $e);
        }

        $tagData = $this->responseToArray($response);

        if (!isset($tagData['sha']) || !is_string($tagData['sha'])) {
            throw new RuntimeException(sprintf('Missing required "sha" key for tag "%s"', $version));
        }

        try {
            $this->httpClient->post(sprintf('/repos/%s/git/refs', $repository), [
                'json' => [
                    'ref' => sprintf('refs/tags/%s', $version),
                    'sha' => $tagData['sha'],
                ],
            ]);
        } catch (ClientException $e) {
            $r = $e->getResponse();
            throw new RuntimeException(sprintf('Failed to create tag reference for version "%s" and sha "%s", got: "%s"', $version, $tagData['sha'], $this->responseStatus($r)), previous: $e);
        }
    }

    /**
     * Get the commit SHA for the head of the specified branch in the given repository.
     *
     * @throws RuntimeException
     */
    private function getBranchSha(Repository $repository, Branch $branch): string
    {
        try {
            $response = $this->httpClient->get(sprintf('/repos/%s/branches/%s', $repository, $branch->name));
        } catch (ClientException $e) {
            $r = $e->getResponse();
            throw new RuntimeException(sprintf('Failed to request branch data from the GitHub API for branch "%s", got: "%s"', $branch->name, $this->responseStatus($r)), previous: $e);
        }

        $data = $this->responseToArray($response);

        if (!is_array($data['commit'] ?? null) || !is_string($data['commit']['sha'] ?? null)) {
            throw new RuntimeException(sprintf('Missing required "commit.sha" key for branch "%s"', $branch->name));
        }

        return $data['commit']['sha'];
    }

    /**
     * Fetch paginated results from the GitHub API.
     *
     * This method will automatically follow pagination links provided in the "Link" header of the
     * API response, and yield converted items one by one.
     *
     * @template T
     *
     * @param callable(array<mixed>): T     $convertItem
     * @param ?callable(array<mixed>): bool $filter
     *
     * @return iterable<T>
     *
     * @throws RuntimeException
     */
    private function fetchPaginated(string $url, callable $convertItem, ?callable $filter = null): iterable
    {
        while (null !== $url) {
            [$items, $url] = $this->getJsonAsArray($url);

            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException(sprintf('Expected each item from the GitHub API to be an array, got: "%s"', gettype($item)));
                }

                if (null !== $filter && !$filter($item)) {
                    continue;
                }

                yield $convertItem($item);
            }
        }
    }

    /**
     * Fetch JSON data as an array from a URL.
     *
     * Returns an array containing the decoded JSON data as the first element, and the URL for the
     * next page of results (if any) as the second element.
     *
     * @return array{0:array<mixed>,1:?string}
     *
     * @throws RuntimeException
     */
    private function getJsonAsArray(string $url): array
    {
        try {
            $response = $this->httpClient->get($url);
        } catch (ClientException $e) {
            $r = $e->getResponse();
            throw new RuntimeException(sprintf('Failed to request data from the GitHub API, got: "%s"', $this->responseStatus($r)), previous: $e);
        }

        return [$this->responseToArray($response), $this->getNextUrl($response)];
    }

    /**
     * Get the URL for the next page of results from the "Link" header, if available.
     */
    private function getNextUrl(ResponseInterface $response): ?string
    {
        $header = $response->getHeaderLine('Link');

        /** @var list<array<string>> */
        $links = Header::parse($header);

        foreach ($links as $link) {
            if (array_key_exists('rel', $link) && 'next' === $link['rel']) {
                return trim((string) $link[0], '<>');
            }
        }

        return null;
    }

    /**
     * Convert a GitHub API response to an array.
     *
     * @return array<mixed>
     *
     * @throws RuntimeException
     */
    private function responseToArray(ResponseInterface $response): array
    {
        try {
            $data = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR | JSON_OBJECT_AS_ARRAY | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Failed to decode response body: "%s"', $e->getMessage()), previous: $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Expected an array, got: "%s"', gettype($data)));
        }

        return $data;
    }

    private function responseStatus(ResponseInterface $response): string
    {
        return sprintf('%d %s', $response->getStatusCode(), $response->getReasonPhrase());
    }
}
