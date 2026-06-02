<?php declare(strict_types=1);

use ImboReleaser\Config;
use ImboReleaser\Version;

return new class extends Config {
    public function initialVersion(): Version
    {
        return Version::fromString('0.0.0');
    }
};
