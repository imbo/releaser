<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\GitHub\Branch;
use ImboReleaser\GitHub\PullRequest;
use ImboReleaser\GitHub\Tag;
use InvalidArgumentException;

use function dirname;
use function in_array;

use const DIRECTORY_SEPARATOR;

class Config implements ConfigInterface
{
    protected const INITIAL_VERSION = 'v0.1.0';
    protected const USERNAMES_TO_EXCLUDE = ['dependabot[bot]'];
    protected const MAIN_BRANCH_NAMES = ['main', 'master'];
    protected const PULL_REQUEST_LABELS_TO_EXCLUDE = ['skip-release'];

    public function initialVersion(): Version
    {
        return Version::fromString(self::INITIAL_VERSION);
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
            in_array($branch->name, self::MAIN_BRANCH_NAMES, true)
            || 1 === preg_match('/^v?\d+(\.\d+)?(\.x)?$/', $branch->name);
    }

    public function filterTag(Tag $tag): bool
    {
        return null !== $tag->version;
    }

    public function filterPullRequest(PullRequest $pullRequest): bool
    {
        return
            null !== $pullRequest->message
            && !in_array($pullRequest->user->login, self::USERNAMES_TO_EXCLUDE, true)
            && empty(array_intersect(self::PULL_REQUEST_LABELS_TO_EXCLUDE, $pullRequest->labels));
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
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'default.twig';
    }

    private function isMainBranch(Branch $branch): bool
    {
        return in_array($branch->name, self::MAIN_BRANCH_NAMES, true);
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
