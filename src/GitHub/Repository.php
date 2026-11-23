<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use ImboReleaser\Exception\InvalidArgumentException;
use Stringable;

use function preg_match;
use function sprintf;

final class Repository implements Stringable
{
    public function __construct(
        public readonly string $owner,
        public readonly string $repo,
    ) {
        if (!self::isValid((string) $this)) {
            throw new InvalidArgumentException(sprintf('Invalid repository string "%s", expected format "owner/repo"', $this));
        }
    }

    public function __toString(): string
    {
        return sprintf('%s/%s', $this->owner, $this->repo);
    }

    public function url(): string
    {
        return sprintf('https://github.com/%s/%s', rawurlencode($this->owner), rawurlencode($this->repo));
    }

    public static function fromString(string $repository): self
    {
        if (!self::isValid($repository)) {
            throw new InvalidArgumentException(sprintf('Invalid repository string "%s", expected format "owner/repo"', $repository));
        }

        [$owner, $repo] = explode('/', $repository);

        return new self($owner, $repo);
    }

    /**
     * Check the repository format, excluding URL delimiters, encoded input, and dot segments.
     *
     * This is a safety check for API paths, not a complete validation of GitHub's naming rules.
     */
    public static function isValid(string $repository): bool
    {
        return 1 === preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9_-]*[A-Za-z0-9])?/(?!\.{1,2}$)[A-Za-z0-9._-]+$#D', $repository);
    }
}
