<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\Exception\InvalidArgumentException;
use Stringable;

use function sprintf;

final class Version implements Stringable
{
    public const int LOWER = -1;
    public const int EQUAL = 0;
    public const int GREATER = 1;

    public function __construct(
        private ?string $prefix = null,
        private int $major = 0,
        private int $minor = 0,
        private int $patch = 0,
        private ?string $prerelease = null,
    ) {
    }

    public function __toString(): string
    {
        return ($this->prefix ?? '').$this->major.'.'.$this->minor.'.'.$this->patch.(null !== $this->prerelease ? '-'.$this->prerelease : '');
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

    /**
     * Compare this version to another version.
     *
     * Returns Version::LOWER if this version is lower than the other version, Version::EQUAL if
     * they are equal, and Version::GREATER if this version is greater than the other version.
     */
    public function compareTo(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    /**
     * Create a prerelease version using the given identifier and sequence number.
     *
     * @see https://semver.org/spec/v2.0.0.html#spec-item-9
     *
     * @throws InvalidArgumentException
     */
    public function withPrerelease(string $identifier, int $number): self
    {
        if (!preg_match('/^(?!0[0-9]+$)[0-9A-Za-z-]+$/D', $identifier) || $number < 1) {
            throw new InvalidArgumentException(sprintf('Invalid prerelease identifier or number: "%s.%d"', $identifier, $number));
        }

        return new self($this->prefix, $this->major, $this->minor, $this->patch, $identifier.'.'.$number);
    }

    public function isPrerelease(): bool
    {
        return null !== $this->prerelease;
    }

    public function prereleaseNumber(string $identifier): ?int
    {
        if (!preg_match('/^'.preg_quote($identifier, '/').'\.(\d+)$/D', $this->prerelease ?? '', $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Parse a semantic version with an optional prefix containing no whitespace.
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $version): self
    {
        if (!preg_match('/^(?P<prefix>\S*?[^\d\s])?(?P<major>0|[1-9]\d*)\.(?P<minor>0|[1-9]\d*)\.(?P<patch>0|[1-9]\d*)(?:-(?P<prerelease>[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D', $version, $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid version string: "%s"', $version));
        }

        // SemVer forbids leading zeros in every purely numeric prerelease identifier.
        if (preg_match('/(?:^|\.)0[0-9]+(?:\.|$)/D', $matches['prerelease'] ?? '')) {
            throw new InvalidArgumentException(sprintf('Invalid version string: "%s"', $version));
        }

        return new self(
            '' !== $matches['prefix'] ? $matches['prefix'] : null,
            (int) $matches['major'],
            (int) $matches['minor'],
            (int) $matches['patch'],
            '' !== ($matches['prerelease'] ?? '') ? $matches['prerelease'] : null,
        );
    }
}
