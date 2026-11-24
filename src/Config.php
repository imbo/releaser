<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\Exception\RuntimeException;
use ImboReleaser\GitHub\Branch;
use ImboReleaser\GitHub\ReleasePullRequest;
use ImboReleaser\GitHub\ReleaseTag;

use function dirname;
use function in_array;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;

class Config implements ConfigInterface
{
    public function initialVersion(): Version
    {
        return Version::fromString($this->initialVersionString());
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
            in_array($branch->name, $this->mainBranchNames(), true)
            || 1 === preg_match('/^v?\d+(\.\d+)?(\.x)?$/D', $branch->name);
    }

    public function filterTag(ReleaseTag $tag): bool
    {
        return true;
    }

    public function filterPullRequest(ReleasePullRequest $pullRequest): bool
    {
        return
            !in_array($pullRequest->user->login, $this->usernamesToExclude(), true)
            && empty(array_intersect($this->pullRequestLabelsToExclude(), $pullRequest->labels));
    }

    /**
     * Determine the version for the next release.
     *
     * The following rules are applied to determine the next version:
     *
     * - If a pull request has a breaking change, the major version is incremented.
     * - If a pull request is a feature, the minor version is incremented.
     * - Otherwise, the patch version is incremented.
     */
    public function determineNextVersion(ReleaseTag $currentTag, array $pullRequests): Version
    {
        if ([] === $pullRequests) {
            throw new InvalidArgumentException('At least one pull request must be provided to determine the next version');
        }

        $hasBreakingChange = false;
        $hasFeature = false;

        foreach ($pullRequests as $pullRequest) {
            if ($pullRequest->message->hasBreakingChanges()) {
                $hasBreakingChange = true;
                break;
            }

            if ('feat' === strtolower($pullRequest->message->getType()->toString())) {
                $hasFeature = true;
            }
        }

        if ($hasBreakingChange) {
            return $currentTag->version->incrementMajor();
        }

        if ($hasFeature) {
            return $currentTag->version->incrementMinor();
        }

        return $currentTag->version->incrementPatch();
    }

    /**
     * Keep major maintenance branches within their major version, and minor branches within their
     * minor version.
     *
     * Main branches and branches with custom names have no default version bounds. Override this
     * method alongside getLatestTagForBranch() when using a different release-line policy.
     *
     * @throws RuntimeException
     */
    public function validateVersionForBranch(Branch $branch, Version $version): void
    {
        if ($this->isMainBranch($branch) || !preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.x)?$/D', $branch->name, $matches)) {
            return;
        }

        try {
            $minimum = Version::fromString(sprintf('%s.%s.0', $matches[1], $matches[2] ?? '0'));
            $maximum = Version::fromString(sprintf('%s.%s.%d', $matches[1], $matches[2] ?? (string) PHP_INT_MAX, PHP_INT_MAX));
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(sprintf('Maintenance branch "%s" does not name a supported version line.', $branch->name), previous: $e);
        }

        if (Version::LOWER === $version->compareTo($minimum) || Version::GREATER === $version->compareTo($maximum)) {
            throw new RuntimeException(sprintf('Cannot release version "%s" from maintenance branch "%s": the version is outside its release line. Review the pull requests or initialVersion(), or select a compatible release branch.', $version, $branch->name));
        }
    }

    public function getLatestTagForBranch(Branch $branch, array $tags): ?ReleaseTag
    {
        $tags = array_values(array_filter($tags, static fn (ReleaseTag $tag): bool => !$tag->version->isPrerelease()));
        if ([] === $tags) {
            return null;
        }

        usort($tags, static function (ReleaseTag $a, ReleaseTag $b): int {
            return $b->version->compareTo($a->version);
        });

        if ($this->isMainBranch($branch)) {
            return $tags[0];
        }

        $branchName = rtrim(ltrim($branch->name, 'v'), '.x');
        foreach ($tags as $tag) {
            // Match maintenance branches such as v2, 2.x, or v2.3.x to tags such as v2.3.1 or 2.3.1.
            if (str_starts_with(ltrim($tag->name, 'v'), $branchName.'.')) {
                return $tag;
            }
        }

        return null;
    }

    public function template(): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'default.twig';
    }

    public function pullRequestGroups(): array
    {
        return [
            'New Features 🚀' => ['feat'],
            'Bug Fixes 🐛' => ['fix'],
            'Documentation 📚' => ['docs'],
        ];
    }

    public function fallbackGroup(): string
    {
        return 'Other Changes ✨';
    }

    public function editor(): string
    {
        return 'vi';
    }

    protected function initialVersionString(): string
    {
        return 'v0.1.0';
    }

    /**
     * @return list<string>
     */
    protected function usernamesToExclude(): array
    {
        return ['dependabot[bot]'];
    }

    /**
     * @return list<string>
     */
    protected function mainBranchNames(): array
    {
        return ['main', 'master'];
    }

    /**
     * @return list<string>
     */
    protected function pullRequestLabelsToExclude(): array
    {
        return ['skip-release'];
    }

    private function isMainBranch(Branch $branch): bool
    {
        return in_array($branch->name, $this->mainBranchNames(), true);
    }
}
