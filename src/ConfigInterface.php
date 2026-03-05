<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\GitHub\Branch;
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

    /**
     * Get the GitHub repository to use for the release process, in the format "owner/repo". If null
     * is returned, the user must specify a repository manually or by using the CLI option.
     */
    public function gitHubRepository(): ?string;

    /**
     * Get the branch to use for the release process. If null is returned, the user must select a
     * branch interactively or by using the CLI option.
     */
    public function branch(): ?string;

    /**
     * Determine whether a branch should be included in the release process.
     */
    public function filterBranch(Branch $branch): bool;
}
