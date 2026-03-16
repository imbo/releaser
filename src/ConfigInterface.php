<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\GitHub\Branch;
use ImboReleaser\GitHub\PullRequest;
use ImboReleaser\GitHub\Tag;
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

    /**
     * Determine whether a tag should be included in the release process.
     */
    public function filterTag(Tag $tag): bool;

    /**
     * Determine whether a pull request should be included in the release process.
     */
    public function filterPullRequest(PullRequest $pullRequest): bool;

    /**
     * Determine the version of the next release.
     *
     * @param array<PullRequest> $pullRequests
     */
    public function determineNextVersion(Tag $currentTag, array $pullRequests): Version;

    /**
     * Get the latest version for a given branch.
     *
     * @param array<Tag> $tags
     */
    public function getLatestTagForBranch(Branch $branch, array $tags): ?Tag;

    /**
     * Get the template to use for the release notes.
     */
    public function template(): string;
}
