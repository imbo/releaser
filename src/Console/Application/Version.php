<?php declare(strict_types=1);

namespace ImboReleaser\Console\Application;

use Composer\InstalledVersions;
use OutOfBoundsException;
use Stringable;

use function is_string;
use function sprintf;
use function str_starts_with;
use function substr;

final class Version implements Stringable
{
    /**
     * The name of this package as defined in composer.json.
     */
    public const string PACKAGE = 'imbo/releaser';

    /**
     * The version string to use when the version cannot be determined from Composer's metadata.
     */
    public const string UNKNOWN_VERSION = 'UNKNOWN';

    public function __construct(private string $package = self::PACKAGE)
    {
    }

    public function __toString(): string
    {
        return $this->getVersion();
    }

    public function getVersion(): string
    {
        try {
            $prettyVersion = InstalledVersions::getPrettyVersion($this->package);
            $reference = InstalledVersions::getReference($this->package);
        } catch (OutOfBoundsException) {
            return self::UNKNOWN_VERSION;
        }

        if (!is_string($prettyVersion) || '' === $prettyVersion) {
            return self::UNKNOWN_VERSION;
        }

        if (str_starts_with($prettyVersion, 'dev-') && is_string($reference)) {
            return sprintf('%s@%s', $prettyVersion, substr($reference, 0, 7));
        }

        return $prettyVersion;
    }
}
