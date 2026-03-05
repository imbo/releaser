<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\GitHub\Branch;
use PHLAK\SemVer\Version;

class Config implements ConfigInterface
{
    public function initialVersion(): Version
    {
        return new Version('0.1.0');
    }

    public function gitHubRepository(): ?string
    {
        return null;
    }

    public function branch(): ?string
    {
        return null;
    }

    public function filterBranch(Branch $branch): bool
    {
        return
            'main' === $branch->name
            || 'master' === $branch->name
            || 1 === preg_match('/^v?\d+(\.\d+)?(\.x)?$/', $branch->name);
    }
}
