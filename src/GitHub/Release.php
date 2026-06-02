<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use InvalidArgumentException;

use function is_string;
use function sprintf;
use function var_export;

final class Release
{
    public function __construct(public readonly string $htmlUrl)
    {
    }

    /**
     * Create a Release instance from GitHub API data.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromAPI(array $data): self
    {
        $htmlUrl = $data['html_url'] ?? null;
        if (!is_string($htmlUrl)) {
            throw new InvalidArgumentException(sprintf('Missing required "html_url" key: %s', var_export($data, true)));
        }

        return new self($htmlUrl);
    }
}
