<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\GitHub\ReleasePullRequest;
use ImboReleaser\GitHub\Repository;

class TemplateData
{
    public function __construct(
        private readonly Version $nextVersion,
        private readonly Repository $repository,
        /** @var list<ReleasePullRequest> */
        private readonly array $pullRequests,
        /** @var array<string,list<ReleasePullRequest>> */
        private readonly array $groupedPullRequests,
        /** @var array<string,ReleasePullRequest> */
        private readonly array $newContributors,
        private readonly ?string $releaserVersion,
    ) {
    }

    /**
     * Return the template context as an associative array.
     *
     * @return array{nextVersion:Version,repository:Repository,pullRequests:list<ReleasePullRequest>,groupedPullRequests:array<string,list<ReleasePullRequest>>,newContributors:array<string,ReleasePullRequest>,releaserVersion:?string}
     */
    public function toContext(): array
    {
        return [
            'nextVersion' => $this->nextVersion,
            'repository' => $this->repository,
            'pullRequests' => $this->pullRequests,
            'groupedPullRequests' => $this->groupedPullRequests,
            'newContributors' => $this->newContributors,
            'releaserVersion' => $this->releaserVersion,
        ];
    }
}
