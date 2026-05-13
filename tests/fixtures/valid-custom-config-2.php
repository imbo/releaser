<?php declare(strict_types=1);

use ImboReleaser\Config;
use PHLAK\SemVer\Version;

return new class extends Config {
    public function initialVersion(): Version
    {
        return new Version('1.0.0');
    }
};
