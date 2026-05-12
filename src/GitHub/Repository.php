<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use Stringable;

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
}
