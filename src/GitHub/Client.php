<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use DateTimeImmutable;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Header;
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
    public function getBranches(string $repository): iterable
    {
        return $this->fetchPaginated(
            sprintf('/repos/%s/branches?per_page=100', $repository),
            Branch::fromAPI(...),
        );
    }

    /**
     * Get all tags for the given repository.
     *
     * @return iterable<Tag>
     */
    public function getTags(string $repository): iterable
    {
        return $this->fetchPaginated(
            sprintf('/repos/%s/tags?per_page=100', $repository),
            Tag::fromAPI(...),
        );
    }

    /**
     * Get all merged pull requests for the given repository.
     *
     * @return iterable<PullRequest>
     */
    public function getMergedPullRequests(string $repository): iterable
    {
        return $this->fetchPaginated(
            sprintf('/repos/%s/pulls?state=closed&per_page=100', $repository),
            PullRequest::fromAPI(...),
            filter: static function (array $item): bool {
                return
                    ($item['merged_at'] ?? null) !== null
                    && ($item['user'] ?? null) !== null;
            },
        );
    }

    /**
     * Get the commit timestamp for a given SHA in the specified repository.
     *
     * @throws RuntimeException
     */
    public function getShaDateTime(string $repository, string $sha): DateTimeImmutable
    {
        [$data] = $this->getJsonAsArray(sprintf('/repos/%s/git/commits/%s', $repository, $sha));
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
                    throw new RuntimeException(sprintf('Expected each item from the GitHub API to be an array, got: %s', gettype($item)));
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
            throw new RuntimeException(sprintf('Failed to request data from the GitHub API: %d %s', $r->getStatusCode(), $r->getReasonPhrase()));
        }

        try {
            $items = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR | JSON_OBJECT_AS_ARRAY | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Failed to decode response body from the GitHub API: %s', $e->getMessage()), previous: $e);
        }

        if (!is_array($items)) {
            throw new RuntimeException(sprintf('Expected an array of items from the GitHub API, got: %s', gettype($items)));
        }

        return [$items, $this->getNextUrl($response)];
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
}
