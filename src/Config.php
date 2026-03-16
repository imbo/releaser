<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\GitHub\Branch;
use ImboReleaser\GitHub\PullRequest;
use ImboReleaser\GitHub\Tag;
use InvalidArgumentException;
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

    public function filterTag(Tag $tag): bool
    {
        return null !== $tag->version;
    }

    public function filterPullRequest(PullRequest $pullRequest): bool
    {
        return null !== $pullRequest->message;
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
    public function determineNextVersion(Tag $currentTag, array $pullRequests): Version
    {
        if (null === $currentTag->version) {
            throw new InvalidArgumentException('The current tag does not have a valid version');
        }

        if (empty($pullRequests)) {
            throw new InvalidArgumentException('At least one pull request must be provided to determine the next version');
        }

        $hasBreakingChange = false;
        $hasFeature = false;

        foreach ($pullRequests as $pullRequest) {
            if (null === $pullRequest->message) {
                continue;
            }

            if ($pullRequest->message->hasBreakingChanges()) {
                $hasBreakingChange = true;
                break;
            }

            if ('feat' === $pullRequest->message->getType()->toString()) {
                $hasFeature = true;
            }
        }

        $nextVersion = clone $currentTag->version;
        if ($hasBreakingChange) {
            $nextVersion->incrementMajor()->setMinor(0)->setPatch(0);
        } elseif ($hasFeature) {
            $nextVersion->incrementMinor()->setPatch(0);
        } else {
            $nextVersion->incrementPatch();
        }

        return $nextVersion;
    }

    public function getLatestTagForBranch(Branch $branch, array $tags): ?Tag
    {
        $tags = array_values(array_filter($tags, static fn (Tag $tag): bool => null !== $tag->version));

        if (empty($tags)) {
            return null;
        }

        usort($tags, static fn (Tag $a, Tag $b): int => version_compare((string) $b->version, (string) $a->version));

        if ($this->isMainBranch($branch)) {
            return $tags[0];
        }

        foreach ($tags as $tag) {
            if (str_starts_with(ltrim($tag->name, 'v'), ltrim($branch->name, 'v').'.')) {
                return $tag;
            }
        }

        return null;
    }

    public function template(): string
    {
        return 'default';
    }

    private function isMainBranch(Branch $branch): bool
    {
        return 'main' === $branch->name || 'master' === $branch->name;
    }
}
