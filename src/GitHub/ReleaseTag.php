<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use ImboReleaser\Version;
use Stringable;

final class ReleaseTag implements Stringable
{
    public readonly Version $version;

    public function __construct(public readonly string $name, public readonly string $sha)
    {
        $this->version = Version::fromString($this->name);
    }

    /**
     * Create a release tag from a raw GitHub tag.
     */
    public static function fromTag(Tag $tag): self
    {
        return new self($tag->name, $tag->sha);
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
