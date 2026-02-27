<?php declare(strict_types=1);

namespace ImboReleaser;

use PHLAK\SemVer\Version;

interface ConfigInterface
{
    /**
     * Get the initial version.
     *
     * If no releases are found in the repository, this version will be used as the version for the
     * first release.
     */
    public function initialVersion(): Version;
}
