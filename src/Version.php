<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\Exception\RuntimeException;
use Stringable;

use function sprintf;

use const PHP_INT_MAX;

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
        private ?string $buildMetadata = null,
    ) {
    }

    public function __toString(): string
    {
        return ($this->prefix ?? '').$this->major.'.'.$this->minor.'.'.$this->patch.(null !== $this->prerelease ? '-'.$this->prerelease : '').(null !== $this->buildMetadata ? '+'.$this->buildMetadata : '');
    }

    public function incrementMajor(): self
    {
        if (PHP_INT_MAX === $this->major) {
            throw new RuntimeException(sprintf('Cannot increment major version component beyond the maximum supported integer (%d): "%s"', PHP_INT_MAX, $this));
        }

        return new self($this->prefix, $this->major + 1, 0, 0);
    }

    public function incrementMinor(): self
    {
        if (PHP_INT_MAX === $this->minor) {
            throw new RuntimeException(sprintf('Cannot increment minor version component beyond the maximum supported integer (%d): "%s"', PHP_INT_MAX, $this));
        }

        return new self($this->prefix, $this->major, $this->minor + 1, 0);
    }

    public function incrementPatch(): self
    {
        if (PHP_INT_MAX === $this->patch) {
            throw new RuntimeException(sprintf('Cannot increment patch version component beyond the maximum supported integer (%d): "%s"', PHP_INT_MAX, $this));
        }

        return new self($this->prefix, $this->major, $this->minor, $this->patch + 1);
    }

    /**
     * Compare the major, minor, and patch numbers to another version.
     *
     * Prefixes, prerelease suffixes, and build metadata are ignored, so v1.2.3-rc.1 and 1.2.3 compare as equal.
     * This is not a full SemVer precedence comparison.
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

        return new self($this->prefix, $this->major, $this->minor, $this->patch, $identifier.'.'.$number, $this->buildMetadata);
    }

    public function isPrerelease(): bool
    {
        return null !== $this->prerelease;
    }

    /**
     * @throws RuntimeException if a matching sequence cannot be represented as an integer
     */
    public function prereleaseNumber(string $identifier): ?int
    {
        if (!preg_match('/^'.preg_quote($identifier, '/').'\.(\d+)$/D', $this->prerelease ?? '', $matches)) {
            return null;
        }

        $number = (int) $matches[1];
        if ($matches[1] !== (string) $number) {
            throw new RuntimeException(sprintf('Prerelease sequence exceeds the maximum supported integer (%d): "%s"', PHP_INT_MAX, $this));
        }

        return $number;
    }

    /**
     * Parse a semantic version with an optional prefix containing no whitespace.
     *
     * Major, minor, and patch components must fit within PHP's integer range.
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $version): self
    {
        $versionWithoutMetadata = $version;
        $buildMetadata = null;
        if (preg_match('/^(.*?\d+\.\d+\.\d+[^+]*?)\+(.*)$/Ds', $version, $metadata)) {
            $versionWithoutMetadata = $metadata[1];
            $buildMetadata = $metadata[2];
            if (!preg_match('/^[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*$/D', $buildMetadata)) {
                throw new InvalidArgumentException(sprintf('Invalid version string: "%s"', $version));
            }
        }

        if (!preg_match('/^(?P<prefix>\S*?[^\d\s])?(?P<major>0|[1-9]\d*)\.(?P<minor>0|[1-9]\d*)\.(?P<patch>0|[1-9]\d*)(?:-(?P<prerelease>[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D', $versionWithoutMetadata, $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid version string: "%s"', $version));
        }

        // SemVer forbids leading zeros in every purely numeric prerelease identifier.
        if (preg_match('/(?:^|\.)0[0-9]+(?:\.|$)/D', $matches['prerelease'] ?? '')) {
            throw new InvalidArgumentException(sprintf('Invalid version string: "%s"', $version));
        }

        foreach (['major', 'minor', 'patch'] as $component) {
            // An overflowing integer cast silently clamps the value instead of rejecting it.
            if ($matches[$component] !== (string) (int) $matches[$component]) {
                throw new InvalidArgumentException(sprintf('Version %s component exceeds the maximum supported integer (%d): "%s"', $component, PHP_INT_MAX, $version));
            }
        }

        return new self(
            '' !== $matches['prefix'] ? $matches['prefix'] : null,
            (int) $matches['major'],
            (int) $matches['minor'],
            (int) $matches['patch'],
            '' !== ($matches['prerelease'] ?? '') ? $matches['prerelease'] : null,
            $buildMetadata,
        );
    }
}
