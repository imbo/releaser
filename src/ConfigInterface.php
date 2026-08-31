<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\GitHub\Branch;
use ImboReleaser\GitHub\PullRequest;
use ImboReleaser\GitHub\Release;
use ImboReleaser\GitHub\Tag;

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
     * Determine whether a release should be included when listing releases.
     */
    public function filterRelease(Release $release): bool;

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
     * @param list<PullRequest> $pullRequests
     */
    public function determineNextVersion(Tag $currentTag, array $pullRequests): Version;

    /**
     * Get the latest version for a given branch.
     *
     * Override this when your tag-prefix convention requires different maintenance branch matching.
     *
     * @param list<Tag> $tags
     */
    public function getLatestTagForBranch(Branch $branch, array $tags): ?Tag;

    /**
     * Get the template to use for the release notes.
     */
    public function template(): string;

    /**
     * Get the pull request groups for the release process.
     *
     * The keys of the array represent the group names that will be used in the release notes, and
     * the values are lists of pull request types (e.g., "feat", "fix") that belong to each group.
     * The ordering of the keys in the array determines the order of the groups in the release
     * notes. Pull requests that don't match any of the types defined in the groups will be placed
     * in a fallback group, which can be defined using the `fallbackGroup()` method.
     *
     * @return array<string,list<string>>
     */
    public function pullRequestGroups(): array;

    /**
     * The name of the group to use for pull requests that don't match any of the groups defined in
     * `pullRequestGroups()`.
     */
    public function fallbackGroup(): string;

    /**
     * Get the editor to use for editing release notes. This can be overridden by setting the
     * `VISUAL` or `EDITOR` environment variables.
     */
    public function editor(): string;
}
