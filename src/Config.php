<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\GitHub\Branch;
use ImboReleaser\GitHub\PullRequest;
use ImboReleaser\GitHub\Release;
use ImboReleaser\GitHub\Tag;

use function dirname;
use function in_array;

use const DIRECTORY_SEPARATOR;

class Config implements ConfigInterface
{
    protected const string INITIAL_VERSION = 'v0.1.0';
    /** @var list<string> */
    protected const array USERNAMES_TO_EXCLUDE = ['dependabot[bot]'];
    /** @var list<string> */
    protected const array MAIN_BRANCH_NAMES = ['main', 'master'];
    /** @var list<string> */
    protected const array PULL_REQUEST_LABELS_TO_EXCLUDE = ['skip-release'];

    public function initialVersion(): Version
    {
        return Version::fromString(static::INITIAL_VERSION);
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
            in_array($branch->name, static::MAIN_BRANCH_NAMES, true)
            || 1 === preg_match('/^v?\d+(\.\d+)?(\.x)?$/', $branch->name);
    }

    public function filterRelease(Release $release): bool
    {
        return null !== $release->version;
    }

    public function filterTag(Tag $tag): bool
    {
        return null !== $tag->version;
    }

    public function filterPullRequest(PullRequest $pullRequest): bool
    {
        return
            null !== $pullRequest->message
            && !in_array($pullRequest->user->login, static::USERNAMES_TO_EXCLUDE, true)
            && empty(array_intersect(static::PULL_REQUEST_LABELS_TO_EXCLUDE, $pullRequest->labels));
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

        if ([] === $pullRequests) {
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

        if ($hasBreakingChange) {
            return $currentTag->version->incrementMajor();
        }

        if ($hasFeature) {
            return $currentTag->version->incrementMinor();
        }

        return $currentTag->version->incrementPatch();
    }

    public function getLatestTagForBranch(Branch $branch, array $tags): ?Tag
    {
        $versionedTags = array_values(array_filter($tags, static fn (Tag $tag): bool => null !== $tag->version));

        if ([] === $versionedTags) {
            return null;
        }

        usort($versionedTags, static function (Tag $a, Tag $b): int {
            if (null === $a->version || null === $b->version) {
                return 0;
            }

            return $b->version->compareTo($a->version);
        });

        if ($this->isMainBranch($branch)) {
            return $versionedTags[0];
        }

        foreach ($versionedTags as $tag) {
            // Match maintenance branches such as v2 or 2.x to tags such as v2.1.0 or 2.1.0.
            if (str_starts_with(ltrim($tag->name, 'v'), ltrim($branch->name, 'v').'.')) {
                return $tag;
            }
        }

        return null;
    }

    public function template(): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'default.twig';
    }

    private function isMainBranch(Branch $branch): bool
    {
        return in_array($branch->name, static::MAIN_BRANCH_NAMES, true);
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
}
