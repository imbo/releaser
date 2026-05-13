<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use InvalidArgumentException;
use Stringable;

use function count;
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
        $parts = explode('/', $repository);
        if (2 !== count($parts)) {
            throw new InvalidArgumentException(sprintf('Invalid repository string "%s", expected format "owner/repo"', $repository));
        }

        return new self($parts[0], $parts[1]);
    }
}
