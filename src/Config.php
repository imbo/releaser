<?php declare(strict_types=1);

namespace ImboReleaser;

use PHLAK\SemVer\Version;

class Config implements ConfigInterface
{
    public function initialVersion(): Version
    {
        return new Version('0.1.0');
    }
}
