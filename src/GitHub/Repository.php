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
    }

    public function __toString(): string
    {
        return sprintf('%s/%s', $this->owner, $this->repo);
    }

    public function url(): string
    {
        return sprintf('https://github.com/%s/%s', $this->owner, $this->repo);
    }

    public static function fromString(string $repository): self
    {
        if (!self::isValid($repository)) {
            throw new InvalidArgumentException(sprintf('Invalid repository string "%s", expected format "owner/repo"', $repository));
        }

        [$owner, $repo] = explode('/', $repository);

        return new self($owner, $repo);
    }

    public static function isValid(string $repository): bool
    {
        return 1 === preg_match('#^[^\s/]+/[^\s/]+$#', $repository);
    }
}
