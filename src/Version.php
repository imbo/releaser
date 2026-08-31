<?php declare(strict_types=1);

namespace ImboReleaser;

use InvalidArgumentException;
use Stringable;

use function sprintf;

final class Version implements Stringable
{
    public function __construct(private ?string $prefix = null, private int $major = 0, private int $minor = 0, private int $patch = 0)
    {
    }

    public function __toString(): string
    {
        return ($this->prefix ?? '').$this->major.'.'.$this->minor.'.'.$this->patch;
    }

    public function incrementMajor(): self
    {
        return new self($this->prefix, $this->major + 1, 0, 0);
    }

    public function incrementMinor(): self
    {
        return new self($this->prefix, $this->major, $this->minor + 1, 0);
    }

    public function incrementPatch(): self
    {
        return new self($this->prefix, $this->major, $this->minor, $this->patch + 1);
    }

    public function compareTo(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    /**
     * Parse a semantic version with an optional arbitrary prefix.
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $version): self
    {
        if (!preg_match('/^(?P<prefix>.*?)(?P<major>\d+)\.(?P<minor>\d+)\.(?P<patch>\d+)$/', $version, $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid version string: "%s"', $version));
        }

        return new self(
            '' !== $matches['prefix'] ? $matches['prefix'] : null,
            (int) $matches['major'],
            (int) $matches['minor'],
            (int) $matches['patch'],
        );
    }
}
